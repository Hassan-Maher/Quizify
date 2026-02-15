<?php

namespace App\Http\Controllers\Api;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\ForgotPasswordOtpMail;
use App\Mail\SendOtpMail;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Mail;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $validated_data = $request->validated();

        $user = User::create($validated_data);

        if (!$user)
            return ApiResponse::sendResponse(505, 'account failed to created', ['is_created' => false]);

        $otp = $user->otp()->create([
            'expires_at' => now()->addMinutes(5),
            'code' => rand(100000, 999999),
        ]);

        try {
            Mail::to($user->email)->send(new SendOtpMail($otp->getAttribute('code')));
        } catch (\Exception $e) {
            return ApiResponse::sendResponse(500, 'User created but failed to send email', [
                'user' => new UserResource($user),
                'error' => $e->getMessage()
            ]);
        }


        return ApiResponse::sendResponse(201, ' regiter successed ,  please verifiy your email using the OTP code we sent ', [
            'user' => new UserResource($user),
        ]);
    }

    public function verifyRegisterOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'code' => ['required', 'digits:6'],
        ], [], []);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, $validator->messages()->first(), $validator->messages()->all());
        }

        $user = User::with('otp')->where('email', $request->email)->first();
        $otp = $user->otp;

        if (!$otp)
            return ApiResponse::sendResponse(404, 'User Is Not Contain Register Otp', ['is_found' => false]);

        if ($otp->code != $request->code) {
            return ApiResponse::sendResponse(404, 'Code Is Not Valid', ['is_valid' => false]);
        }

        if ($otp->expires_at < now())
            return ApiResponse::sendResponse(403, 'Code Is Expired ,  please resend code', ['is_expires' => true]);

        $user->update(['is_verified' => true]);
        $token = $user->createToken('registerToken')->plainTextToken;

        $otp->delete();

        return ApiResponse::sendResponse(200, 'account has been created successfully', [
            'user' => new UserResource($user),
            'token' => $token
        ]);



    }

    public function resendCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ], [], []);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, $validator->messages()->first(), $validator->messages()->all());
        }

        $user = User::firstWhere('email', $request->email);


        $new_code = rand(100000, 999999);

        $record = $user->otp()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code' => $new_code,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        try {
            Mail::to($user->email)->send(new SendOtpMail($record->getAttribute('code')));
        } catch (\Exception $e) {
            return ApiResponse::sendResponse(500, 'User created but failed to send email', [
                'user' => new UserResource($user),
                'error' => $e->getMessage()
            ]);
        }

        if ($record)
            return ApiResponse::sendResponse(200, 'code resend successfully', [
                'user' => new UserResource($user),
                'new_code' => $new_code,
            ]);
    }

    public function login(LoginRequest $request)
    {
        $validated_data = $request->validated();

        if (!Auth::attempt($validated_data)) {
            return ApiResponse::sendResponse(403, 'Password Is Not Valid', ['is_valid' => false]);
        }

        $user = Auth::user();

        if(!$user->is_verified)
        {
            $code = rand(100000, 999999);

            $record = $user->otp()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'code'       => $code,
                    'expires_at' => now()->addMinutes(5),
                ]
            );
            if($record)
                return ApiResponse::sendResponse(403 , 'You Must Verify Register Otp' , [
                'is_verified' => false,
                'code' => $code
                ]);    
        }

        $token = $user->createToken('loginToken')->plainTextToken;

        return ApiResponse::sendResponse(200, 'Login Successfully', [
            'user' => new UserResource($user),
            'token' => $token
        ]);
    }

    public function forgetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ], [], []);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, $validator->messages()->first(), $validator->messages()->all());
        }

        $user = User::firstWhere('email', $request->email);


        $code = rand(100000, 999999);
        $record = $user->otp()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(5)
            ]
        );

        try {
            Mail::to($user->email)->send(new ForgotPasswordOtpMail($record->getAttribute('code'), $user->email));
        } catch (\Exception $e) {
            return ApiResponse::sendResponse(500, 'User created but failed to send email', [
                'user' => new UserResource($user),
                'error' => $e->getMessage()
            ]);
        }

        if ($record)
            return ApiResponse::sendResponse(200, 'code has been send successfully', [
                'user' => new UserResource($user),
                'code' => $code
            ]);
    }

    public function verifyPasswordOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'code' => ['required', 'digits:6'],
        ], [], []);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, $validator->messages()->first(), $validator->messages()->all());
        }

        $user = User::with('otp')->where('email', $request->email)->first();


        $otp = $user->otp;
        if (!$otp)
            return ApiResponse::sendResponse(404, 'User Is Not Contain Otp', ['is_found' => false]);

        if ($otp->code != $request->code) {
            return ApiResponse::sendResponse(404, 'Code Is Not Valid', ['is_valid' => false]);
        }

        if ($otp->expires_at < now())
            return ApiResponse::sendResponse(403, 'Code Is Expired ,  please resend code', ['is_expires' => true]);

        $otp->delete();
        return ApiResponse::sendResponse(200, 'Code Verified Successfully , You Can Reset Password', ['can_reset' => true]);
    }

    public function ResetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [], []);

        if ($validator->fails()) {
            return ApiResponse::sendResponse(422, $validator->messages()->first(), $validator->messages()->all());
        }

        $user = User::with('otp')->firstWhere('email', $request->email);

        // لو معملش تاكيد للكود بتاعه 
        if ($user->otp)
            return ApiResponse::sendResponse(403, 'You Have Code Not Active Please Check Reset Code And Register code', []);


        $record = $user->update(['password' => $request->password]);
        if ($record)
            return ApiResponse::sendResponse(201, 'password reset successfully', []);

    }




}
