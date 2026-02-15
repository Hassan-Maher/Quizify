<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;


Route::controller(AuthController::class)->group(function(){
    Route::post('/register' , 'register');
    Route::post('/verify-otp-register' , 'verifyRegisterOtp');
    Route::post('/resend code' , 'resendCode');
    Route::post('/login' , 'login');
    Route::post('/forget-password-otp'  , 'forgetPassword');
    Route::post('/verify-password-otp' , 'verifyPasswordOtp');
    Route::post('/reset-Password'       , 'ResetPassword');

});



