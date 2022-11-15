<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StripePaymentCtrl;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', [StripePaymentCtrl::class, 'stripe']);

Route::controller(StripePaymentCtrl::class)->group(function(){
    //Route::get('stripe', 'stripe');
    Route::post('stripe', 'stripePost')->name('stripe.post');
});