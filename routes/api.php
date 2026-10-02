<?php

use App\Http\Controllers\ContactController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WaitlistController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::post('/contact', [ContactController::class, 'store']);
Route::post('/create-checkout-session', [PaymentController::class, 'createCheckoutSession'])->middleware(['throttle:10,1']); 

Route::post('/webhook/stripe', [StripeWebhookController::class, 'handle']);

Route::get('/availability', [WaitlistController::class, 'availability']);
Route::post('/waitlist/join', [WaitlistController::class, 'join'])->middleware(['throttle:5,1']);

// Automatic new-class emails; hourly from the Shuhai Marketing cron (token-protected).
Route::post('/class-announcements/run', [\App\Http\Controllers\ClassAnnouncementController::class, 'run'])->middleware(['throttle:10,1']);

