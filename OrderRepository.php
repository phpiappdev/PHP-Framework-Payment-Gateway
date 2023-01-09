<?php


namespace PickBazar\Database\Repositories;

use App\Mail\OrderReceived;
use App\Mail\PlaceOrder;
use App\Models\User as ModelsUser;
use Exception;
use Ignited\LaravelOmnipay\Facades\OmnipayFacade as Omnipay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PickBazar\Database\Models\Balance;
use PickBazar\Database\Models\Coupon;
use PickBazar\Database\Models\Cart;
use PickBazar\Database\Models\Order;
use PickBazar\Database\Models\Product;
use PickBazar\Database\Models\Settings;
use PickBazar\Database\Models\Stripe;
use PickBazar\Database\Models\User;
use PickBazar\Events\OrderCreated;
use PickBazar\Exceptions\PickbazarException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Prettus\Validator\Exceptions\ValidatorException;
use PickBazar\Http\Controllers\CartController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PickBazar\Database\Models\Shop;
use PickBazar\Database\Models\ShopAvailability;
use PickBazar\Database\Models\Wallet;
use PickBazar\Database\Models\Zip;
use PickBazar\Http\Controllers\ShopController;
use PickBazar\Database\Repositories\ShopRepository;

use function PHPUnit\Framework\isEmpty;

class OrderRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'tracking_number' => 'like',
        'shop_id',
    ];
    /**
     * @var string[]
     */
    protected $dataArray = [
        'tracking_number',
        'customer_id',
        'shop_id',
        'status',
        'amount',
        'sales_tax',
        'paid_total',
        'total',
        'delivery_time',
        'delivery_date',
        'payment_gateway',
        'discount',
        'coupon_id',
        'payment_id',
        'logistics_provider',
        'billing_address',
        'shipping_address',
        'delivery_fee',
        'customer_contact',
        'payer_id',
        'latitude',
        'longitude'
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Order::class;
    }

    /**
     * @param $request
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     */
    public function storeOrder($request)
    {

        if (isset($request->billing_address['postcode'])) {
            $postalData = Zip::where('code', $request->billing_address['postcode'])->where('approved', true)->get();
            if ($postalData->count() == 0)
                return ['status' => 0, 'message' => 'Sorry! We are currently not making deliveries to your postal code. Please contact our Help Desk for more information.'];
        }

        try {
            $cart = new CartController();
            $cartval = $cart->cartListing($request);

            foreach ($cartval['data'] as $key => $value) {
                $products[$key]['product_id'] = $value['productID'];
                $products[$key]['order_quantity'] = $value['productQuantity'];
                $products[$key]['unit_price'] = $value['productPrice'];
                $products[$key]['subtotal'] = $value['productSubTotalPrice'];
            }

            $temp = array();
            $availableDays = ShopAvailability::select('day')->where('shop_id', $request->shop_id)->get()->toArray();
            $temp = array_unique(array_merge($temp, array_column($availableDays, 'day')));

            //get the delivery Date
            $deliveryDate = ShopController::nextDates($temp, 'order');

            $request['products'] = $products;
            $request['amount'] = $cartval['subTotal'];
            $request['sales_tax'] = 0;
            $request['paid_total'] = 0;
            $request['delivery_time'] = 0;
            $request['delivery_date'] = $deliveryDate[0];
            $request['status'] = 1;
            $request['uid'] = $request->user_id;
            $request['tracking_number'] = Str::random(12);
            $request['customer_id'] = $request->user_id;
            $discount = $this->calculateDiscount($request);

            if ($discount) {
                $request['paid_total'] = $request['amount'] + $request['sales_tax'] + $request['delivery_fee'] - $discount;
                $request['total'] = $request['amount'] + $request['sales_tax'] + $request['delivery_fee'] - $discount;
                $request['discount'] =  $discount;
            } else {
                $request['paid_total'] = $request['amount'] + $request['sales_tax'] + $request['delivery_fee'];
                $request['total'] = $request['amount'] + $request['sales_tax'] + $request['delivery_fee'];
            }

            $payment_gateway = isset($request['payment_gateway']) ? $request['payment_gateway'] : 'stripe';

            switch ($payment_gateway) {
                case 'cod':
                    // Cash on Delivery no need to capture payment
                    return ["status" => 1, "message" => 'Order Placed', "data" => $this->createOrder($request)];
                    break;

                case 'paypal':
                    // For default gateway no need to set gateway
                    return ["status" => 1, "message" => 'Order Placed', "data" => $this->createOrder($request)];
                    break;

                case 'wallet':
                    $walletBalance = Wallet::checkWalletBalance($request->wallet_id);

                    if ($walletBalance[0] > 0 && $walletBalance[0] >= (int)$request['total']) {
                        $orderData = $this->createOrder($request);
                        if ($orderData) {
                            Wallet::deductAmount($request['total'], $request->wallet_id, $payment_gateway, $orderData->tracking_number);
                            return ["status" => 1, "message" => 'Order Placed', "data" => $orderData];
                        }
                    } else {
                        return ['status' => 0, 'message' => 'insufficiant balance'];
                    }
                    break;
            }

            if (!isset($request->source)) {
                $user_data = User::with(['address', 'card', 'profile'])->where('id', (int)$request['uid'])->get();

                if (isset($user_data[0]->card[0]->stripe_card_id)) {
                    $request->customerStripeId = $user_data[0]->stripe_cus_id;
                    $request->source = $user_data[0]->card[0]->stripe_card_id;
                    $request['customer_contact'] = $user_data[0]->address[0]->address[0]['apt'];
                    $request->billing_address = $user_data[0]->address[0]->address;
                } else {
                    return ['status' => 0, 'message' => 'Please add card details'];
                }
            } else {
                $request->source = $request->source;
                $request->customerStripeId = $request->customerStripeId;
            }

            $response = $this->capturePayment($request);

            if (isset($response->id)) {
                $payment_id = $response->id;
                $request['payment_id'] = $payment_id;
                $order = $this->createOrder($request);

                return $order;
            }
            else {
                return ['status' => 0, 'message' => 'Payment Failed'];
            }
        } catch (Exception $e) {
            return ['status' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param $request
     * @return mixed
     */
    protected function capturePayment($request)
    {
        try {
            $settings = Settings::first();
            $currency = $settings['options']['currency'];
        } catch (\Throwable $th) {
            $currency = 'USD';
        }
        $amount = round($request['paid_total'], 2);
        $payment_info = array(
            'amount'   => $amount,
            'currency' => $currency,
        );
        if ($request->payment_gateway === 'stripe') {
            $payment_info['token'] = $request['token'];
        } else {
            $payment_info['card'] = Omnipay::creditCard($request['card']);
        }
        try {

            $transaction = Stripe::makePayment($currency, $request->source, $amount, $request->customerStripeId);
        } catch (Exception $e) {
            return response()->json(["status" => 2, "message" => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $transaction;
    }

    /**
     * @param $request
     * @return array|LengthAwarePaginator|Collection|mixed
     */
    protected function createOrder($request)
    {

        try {
            $orderInput = $request->only($this->dataArray);
            $products = $this->processProducts($request['products']);
            $order = $this->create($orderInput);
            $order->products()->attach($products);
            $this->createChildOrder($order->id, $request);
            $this->calculateShopIncome($order);
            $order->children = $order->children;
            Cart::where('user_id', $request->user_id)->delete();

            $owner_details = Shop::where('id', $request['shop_id'])->get()->toArray();
            $vendor_details = User::where('id', $owner_details[0]['owner_id'])->first()->toArray();

            Mail::to(Auth::user()->email)->send(new PlaceOrder($order, $owner_details));
            Mail::to($vendor_details['email'])->cc('deepak.sharma7206@gmail.com')->send(new OrderReceived($vendor_details['name'], $order));

            return $order;
        } catch (Exception $e) {
            return ['status' => 0, 'message' => $e->getMessage(), 'data' => 'create order exception'];
        }
    }

    protected function calculateShopIncome($parent_order)
    {
        foreach ($parent_order->children as  $order) {
            $balance = Balance::where('shop_id', '=', $order->shop_id)->first();
            $adminCommissionRate = $balance->admin_commission_rate;
            $shop_earnings = ($order->total * (100 - $adminCommissionRate)) / 100;
            $balance->total_earnings = $balance->total_earnings + $shop_earnings;
            $balance->current_balance = $balance->current_balance + $shop_earnings;
            $balance->save();
        }
    }

    protected function processProducts($products)
    {
        foreach ($products as $key => $product) {
            if (!isset($product['variation_option_id'])) {
                $products[$key] = $product;
            }
        }
        return $products;
    }

    protected function calculateDiscount($request)
    {
        try {
            if (!isset($request['coupon_id'])) {
                return false;
            }
            $coupon = Coupon::findOrFail($request['coupon_id']);
            if (!$coupon->is_valid) {
                return false;
            }
            switch ($coupon->type) {
                case 'percentage':
                    return ($request['amount'] * $coupon->amount) / 100;
                case 'fixed':
                    return $coupon->amount;
                    break;
                case 'free_shipping':
                    return isset($request['delivery_fee']) ? $request['delivery_fee'] : false;
                    break;
            }
            return false;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public function createChildOrder($id, $request)
    {
        $products = $request->products;
        $productsByShop = [];

        foreach ($products as $key => $cartProduct) {
            $product = Product::findOrFail($cartProduct['product_id']);
            $productsByShop[$product->shop_id][] = $cartProduct;
            // print_r($cartProduct);die;
            Product::where('id', $cartProduct['product_id'])->decrement('quantity', $cartProduct['order_quantity']);
        }

        foreach ($productsByShop as $shop_id => $cartProduct) {
            $amount = array_sum(array_column($cartProduct, 'subtotal'));
            $orderInput = [
                'tracking_number' => Str::random(12),
                'shop_id' => $shop_id,
                'status' => $request->status,
                'customer_id' => $request->customer_id,
                'shipping_address' => $request->billing_address,
                'billing_address' => $request->billing_address,
                'customer_contact' => $request->customer_contact,
                'delivery_time' => $request->delivery_time,
                'delivery_fee' => 0,
                'sales_tax' => 0,
                'discount' => 0,
                'parent_id' => $id,
                'amount' => $amount,
                'total' => $amount,
                'paid_total' => $amount,
                'delivery_company_id' => 1,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ];
            $order = $this->create($orderInput);
            $order->products()->attach($cartProduct);

        }
    }
}
