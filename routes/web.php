<?php

use App\Http\Controllers\V1\ImageController;
use App\Http\Controllers\V1\ScheduleController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\VerifyEmailController;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

Route::get("/images/{sector}/{id}/{fileName}", function($sector, $id, $fileName) {
    $folder_name = "private/images/$sector/$id";

    $path = $folder_name.'/'.$fileName;

    if(!Storage::exists($path)){
        abort(404);
    }

    return Storage::response($path);
});

Route::get('/', function () {
    return view('layouts.light');
});

Route::get('/verified', function () {
    return "Berhasil verifikasi email! Silahkan kembali ke aplikasi KitaGerak...";
});


Route::get('/test', [ScheduleController::class, "generateSchedule"]);
Route::get('/images/{fileName}', [ImageController::class, "show"]);

Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, '__invoke']);

Auth::routes(['verify' => true]);
Route::get('/images/{folder}/{fileName?}', [ImageController::class, "show"]);

Route::get('/payment-success', function() {
    //TODO:: Create an UI
    return "Pembayaran Berhasil";
});

Route::get('/payment-failed', function() {
    //TODO:: Create an UI
    return "Pembayaran Gagal";
});

Route::group(['prefix' => 'venues'], function(){
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{venueId}/detail', [VenueController::class, 'detail']);
    Route::post('/{venueId}/accept', [VenueController::class, 'acceptVenueRegistration']);
});