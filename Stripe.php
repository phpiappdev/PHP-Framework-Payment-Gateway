<?php

namespace PickBazar\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Exception;

class Stripe extends Model
{

    public $guarded = ['id'];

    public static function createCustomer($user){
        try{
            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));

            $stripeCustomer = $stripe->customers->create([
                'name' => $user->name,
                'description' => 'Registed customer with id :'.$user->id,
                'email' => $user->email,
            ]);

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $stripeCustomer;

    }

    public static function addBankDetails($user){
        try{
            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));
            return $stripe->setupIntents->create([
                'payment_method_types' => ['au_becs_debit'],
                'customer' => $user->stripe_cus_id,
              ]);

        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
    }

    //For adding cards or banks
    public static function createSource($user , $sourceId){

        try{

            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));

            $source = $stripe->customers->createSource(
                $user->stripe_cus_id,
                ['source' => $sourceId]
            );

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $source;
    }

    //For deleteing cards or banks
    public static function deleteSource($user , $cardBankId){

        try{

            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));

            $stripe->customers->deleteSource(
                $user->stripe_cus_id,
                $cardBankId,
            []
            );

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return true;

    }

    //get card or bank detail
    public static function getSource($user,$id){

        try{

            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));

            $source = $stripe->customers->retrieveSource(
                $user->stripe_cus_id,
                $id,
                []
            );

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $source;
    }

    public static function makePayment($currency,$source,$amount,$customerStripeId){
        try{
            $stripe = new \Stripe\StripeClient(config('app.stripe_secret_key'));
            $paymentData=[
                'amount' => $amount*100,
                'currency' => $currency,
                'source' => $source,
            ];
            if(isset($customerStripeId)){
                $paymentData['customer']= $customerStripeId;
            }
            // return $paymentData;

             $result= $stripe->charges->create($paymentData);
              return $result;
        }
        catch(Exception $e){
            throw new Exception($e->getMessage());
        }
    }
}
