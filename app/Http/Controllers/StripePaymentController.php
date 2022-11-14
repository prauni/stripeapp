<?php
    
namespace App\Http\Controllers;
     
use Illuminate\Http\Request;
use Session;
use Stripe;
     
class StripePaymentController extends Controller
{
    public function stripe(){
        $productData = [
                        ['name'=>'Personal', 'price'=>30], 
                        ['name'=>'Business', 'price'=>50], 
                        ['name'=>'Enterprise', 'price'=>80], 
                    ];
        
        foreach($productData as $key=>$prod){
            $productData[$key]['default_price'] = $this->fetchProduct($prod['name']) ?? $this->createProduct($prod['name'], 100*$prod['price']);
        }

        return view('home', compact('productData'));
    }

    private function fetchProduct($product_name){
        $price_id = NULL;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/products/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'query=name:\''.$product_name.'\'');

        curl_setopt($ch, CURLOPT_USERPWD,  env('STRIPE_SECRET') . ':' . '');
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);
        if(!empty($result['data'])){
            foreach($result['data'] as $val){
                $price_id = $val['default_price'];
            }
        }

        return $price_id;
    }

    private function createProduct($product_name, $product_price){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/products');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'name='.$product_name.'&active=true&default_price_data[recurring][interval]=year&default_price_data[currency]=usd&default_price_data[unit_amount_decimal]='.$product_price);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);
        return $result['default_price'] ?? NULL;
    }

    public function stripePost(Request $request)
    {
        $customer_name      = $request->customer_name;
        $customer_emailid   = $request->customer_emailid;
        $card_number        = $request->card_number;
        $card_cvc           = $request->card_cvc;
        $card_exp_month     = $request->card_exp_month;
        $card_exp_year      = $request->card_exp_year;
        $price_id           = $request->default_price;

        $customer_id            = $this->searchCustomerId($customer_emailid) ?? $this->createCustomerId($customer_name, $customer_emailid);
        $paymethodId            = $customer_id ? $this->createPaymentMethodId($card_number, $card_cvc, $card_exp_month, $card_exp_year) : NULL;

        $setCustomerPaymentId       = $paymethodId ? $this->attachCustomerPaymentId($paymethodId, $customer_id) : NULL;
        $defaultCustomerPaymentId   = $setCustomerPaymentId ? $this->setCustomerDefaultPaymentId($paymethodId, $customer_id) : NULL;
        $subscriptionId             = $this->createSubscription($customer_id, $price_id);
        
        Session::flash('success', 'Payment successful! <br>Subscription Id : '.$subscriptionId);
        return back();
    }


    private function searchCustomerId($customer_emailid)
    {
        $customer_id    = NULL;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'query=email:\''.$customer_emailid.'\'');
        curl_setopt($ch, CURLOPT_USERPWD,  env('STRIPE_SECRET') . ':' . '');
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);
        if(!empty($result['data'])){
            foreach($result['data'] as $cust){
                $customer_id = $cust['id'];
            }
        }

        return $customer_id;
    }

    private function createCustomerId($customer_name, $customer_emailid){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "name=".$customer_name."&email=".$customer_emailid);
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);
        return  $result['id'] ?? NULL;
    }

    private function createPaymentMethodId($card_number, $card_cvc, $card_exp_month, $card_exp_year){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "type=card&card[number]=".$card_number."&card[exp_month]=".$card_exp_month."&card[exp_year]=".$card_exp_year."&card[cvc]=".$card_cvc);
        
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');
        
        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);
        return $result['id'] ?? NULL;
    }

    private function attachCustomerPaymentId($paymethodId, $customer_id){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods/'.$paymethodId.'/attach');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "customer=".$customer_id);
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');
        
        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        return TRUE;
    }

    private function setCustomerDefaultPaymentId($paymethodId, $customer_id){
        
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers/'.$customer_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "invoice_settings[default_payment_method]=".$paymethodId);
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');
        
        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);
        
        return TRUE;
    }

    private function createSubscription($customer_id, $price_id){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/subscriptions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "customer=".$customer_id."&items[0][price]=".$price_id);
        curl_setopt($ch, CURLOPT_USERPWD, env('STRIPE_SECRET') . ':' . '');
        
        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result, TRUE);

        return $result['id'] ?? FALSE;
    }

}