<?php
    
namespace App\Http\Controllers;
     
use Illuminate\Http\Request;
use App\Services\Exception;
use Session;
use Stripe;
use Log;
     
class StripePaymentCtrl extends Controller
{
	public function __construct()
    {
        $this->stripe 	= new \Stripe\StripeClient(env('STRIPE_SECRET'));;
    }
	
    public function stripe(){
		$productData = [
                        ['name'=>'New Personal', 'price'=>300], 
                        ['name'=>'New Business', 'price'=>500], 
                        ['name'=>'New Enterprise', 'price'=>800], 
                    ];
        
        foreach($productData as $key=>$prod){
			$productData[$key]['default_price'] = $this->fetchProduct($prod['name']) ?? $this->createProduct($prod['name'], 100*$prod['price']);
        }

        return view('home', compact('productData'));
    }

    private function fetchProduct($product_name){
		
		$products 	= $this->stripe->products->search(['query' => 'active:\'true\' AND name:\''.$product_name.'\'']);
		return collect($products->data)->first()->default_price ?? NULL;
    }

    private function createProduct($product_name, $product_price){
		$data = [
					'name' => $product_name,
					'active' => true,
					'default_price_data[recurring][interval]' => 'year',
					'default_price_data[currency]' => 'usd',
					'default_price_data[unit_amount_decimal]' => $product_price
				];
		$products 	= $this->stripe->products->create($data);
		return collect($products)['default_price'] ?? NULL;
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
		$result 	= $this->stripe->customers->search(['query' => 'email:\''.$customer_emailid.'\'']);
		return collect($result->data)->first()->id ?? NULL;
    }

    private function createCustomerId($customer_name, $customer_emailid){
		$data = [
					"name" 	=> $customer_name,
					"email" => $customer_emailid
				];
		$result 	= $this->stripe->customers->create($data);
		return collect($result)['id'] ?? NULL;
    }

    private function createPaymentMethodId($card_number, $card_cvc, $card_exp_month, $card_exp_year){
		$data = [
					'type' => 'card',
					'card' => [
						'number' => $card_number,
						'exp_month' => $card_exp_month,
						'exp_year' => $card_exp_year,
						'cvc' => $card_cvc,
					]
				];
		$result 	= $this->stripe->paymentMethods->create($data);
		return collect($result)['id'] ?? NULL;
    }

    private function attachCustomerPaymentId($paymethodId, $customer_id){
		try{
			$data = ["customer"=>$customer_id];
			$result 	= $this->stripe->paymentMethods->attach($paymethodId, $data);
			dd(145, collect($result));
		}
		catch(\Exception $e){
			Log::error($e);
			return FALSE;
		}
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