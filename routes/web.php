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

Route::get('/thank-you', function () {
    return view('thank-you-page');
});

// CMS admin (session-auth, server holds GitHub token).
// Note: prefix is /cms (not /admin) to avoid conflict with the static UI in public/admin/.
Route::prefix('cms')->group(function () {
    Route::post('/login',  [\App\Http\Controllers\AdminCmsController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\AdminCmsController::class, 'logout']);
    Route::get('/status',  [\App\Http\Controllers\AdminCmsController::class, 'status']);
    Route::get('/load',    [\App\Http\Controllers\AdminCmsController::class, 'load']);
    Route::post('/save',   [\App\Http\Controllers\AdminCmsController::class, 'save']);
    Route::post('/upload', [\App\Http\Controllers\AdminCmsController::class, 'upload']);
});
