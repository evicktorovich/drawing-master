<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('main-page');
});

Route::get('/thank-you', [\App\Http\Controllers\ThankYouController::class, 'show']);

// Shareable page per class — same site, but with that class's title, preview
// image and sign-up popup. Slugs are built in App\Support\EventLinks.
Route::get('/event/{slug}', [\App\Http\Controllers\EventPageController::class, 'show'])
    ->where('slug', '[^/]+');

// Public unsubscribe landing for broadcast emails (CASL). GET = footer link / header
// click; POST = RFC 8058 one-click (List-Unsubscribe-Post). Params read from query.
Route::match(['get', 'post'], '/unsubscribe', [\App\Http\Controllers\AdminCmsController::class, 'unsubscribe']);

// Opens (1×1 image) and clicks (redirect to the class page) of campaign emails.
Route::get('/e/o', [\App\Http\Controllers\EmailTrackingController::class, 'open']);
Route::get('/e/c', [\App\Http\Controllers\EmailTrackingController::class, 'click']);

// CMS admin (session-auth, server holds GitHub token).
// Note: prefix is /cms (not /admin) to avoid conflict with the static UI in public/admin/.
Route::prefix('cms')->group(function () {
    Route::post('/login',  [\App\Http\Controllers\AdminCmsController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\AdminCmsController::class, 'logout']);
    Route::get('/status',  [\App\Http\Controllers\AdminCmsController::class, 'status']);
    Route::get('/_diag',   [\App\Http\Controllers\AdminCmsController::class, 'diag']);
    Route::get('/load',    [\App\Http\Controllers\AdminCmsController::class, 'load']);
    Route::post('/save',   [\App\Http\Controllers\AdminCmsController::class, 'save']);
    Route::post('/upload', [\App\Http\Controllers\AdminCmsController::class, 'upload']);
    Route::get('/orders',  [\App\Http\Controllers\AdminCmsController::class, 'orders']);
    Route::get('/clients', [\App\Http\Controllers\AdminCmsController::class, 'clients']);
    Route::get('/audience', [\App\Http\Controllers\AdminCmsController::class, 'audience']);
    Route::post('/broadcast/send', [\App\Http\Controllers\AdminCmsController::class, 'sendBroadcast']);
    Route::get('/email-stats', [\App\Http\Controllers\AdminCmsController::class, 'emailStats']);
});
