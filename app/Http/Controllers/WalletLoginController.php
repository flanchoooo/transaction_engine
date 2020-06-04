<?php

namespace App\Http\Controllers;

use App\OTP;
use App\Services\AESCtrl;
use App\Services\AESEncryption;
use App\Services\OTPService;
use App\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;



class WalletLoginController extends Controller
{

    public function login(Request $request){
        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {
            $wallet = Wallet::whereMobile($request->mobile)->first();
            if(!isset($wallet)){
                return response(['code' => '100', 'description' => 'Invalid login credentials']);
            }

            if($wallet->state != "ACTIVE"){
                return response(['code' => '100', 'description' => 'Account is blocked',]);
            }

            if($wallet->auth_attempts > 2){
                $wallet->state = "BLOCKED";
                $wallet->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Account is blocked']);
            }

            
            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $wallet->auth_attempts+=1;
                $wallet->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid login credentials', 'error_message' => $pin["error_message"]]);
            }

            if (Hash::check($pin["pin"], $wallet->pin)) {


                if($wallet->device_uuid != $request->device_uuid){
                    OTPService::generateOtp($request->mobile,'LOGIN');
                    return response(['code' => '112', 'description' => 'Please provide OTP',]);
                }

                if($wallet->verified != 1){
                    OTPService::generateOtp($request->mobile,'LOGIN');
                    return response(['code' => '113', 'description' => 'Please provide OTP',]);
                }
                $wallet->auth_attempts = 0;
                $wallet->verified = 1;
                $wallet->save();
                DB::commit();
                return response(['code' => '000', 'description' => 'Login successful', 'data'=> $wallet]);
            }

            $wallet->auth_attempts+=1;
            $wallet->save();
            DB::commit();
            return response(['code' => '100','description' => 'Invalid login credentials',]);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Login Failed.', 'error_message' => $exception->getMessage(),]);
        }

    }


    public function login_(Request $request){
        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {
            $wallet = Wallet::whereMobile($request->mobile)->first();
            if(!isset($wallet)){
                return response(['code' => '100', 'description' => 'Invalid login credentials']);
            }

            if($wallet->state != "ACTIVE"){
                return response(['code' => '100', 'description' => 'Account is blocked',]);
            }

            if($wallet->auth_attempts > 2){
                $wallet->state = "BLOCKED";
                $wallet->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Account is blocked']);
            }

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $wallet->auth_attempts+=1;
                $wallet->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid login credentials']);
            }

            if (Hash::check($pin["pin"], $wallet->pin)) {


                if($wallet->device_uuid != $request->device_uuid){
                    OTPService::generateOtp($request->mobile,'LOGIN');
                    return response(['code' => '112', 'description' => 'Please provide OTP',]);
                }

                if($wallet->verified != 1){
                    OTPService::generateOtp($request->mobile,'LOGIN');
                    return response(['code' => '113', 'description' => 'Please provide OTP',]);
                }
                $wallet->auth_attempts = 0;
                $wallet->verified = 1;
                $wallet->save();
                DB::commit();
                return response(['code' => '000', 'description' => 'Login successful', 'data'=> $wallet]);
            }

            $wallet->auth_attempts+=1;
            $wallet->save();
            DB::commit();
            return response(['code' => '100','description' => 'Invalid login credentials',]);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Login Failed.',]);
        }

    }

    public function preauth(Request $request){
        $validator = $this->wallet_preauth($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {
            $wallet = Wallet::whereMobile($request->mobile)->first();
            if(!isset($wallet)){
                return response(['code' => '100', 'description' => 'Wallet account is not registered.',]);
            }
            return response(['code'          => '000','description'   => 'Preauth successful.', 'data' => $wallet]);
        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Your request could not be processed.',]);
        }

    }

    public function validateOtp(Request $request){
        $validator = $this->otp_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {
            $wallet = Wallet::whereMobile($request->mobile)->first();
             $saved_otp = OTP::whereMobile($request->mobile)->first();
            if(!isset($wallet)){
                return response(['code' => '100', 'description' => 'Wallet account is not registered.',],400);
            }

            if($wallet->state != "ACTIVE"){
                return response(['code' => '100', 'description' => 'Account is blocked',],201);
            }

            if($wallet->auth_attempts > 2){
                $wallet->state = "BLOCKED";
                $wallet->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Account is blocked'],201);
            }


            if(!isset($saved_otp)){
                return response(['code' => '100', 'description' => 'Invalid mobile',],400);
            }

            if ($saved_otp["otp"] != $request->otp) {
                if($saved_otp->attempts > 2){
                    $wallet->state = "BLOCKED";
                    $wallet->verified = 0;
                    $wallet->save();
                    DB::commit();
                }
                $saved_otp->attempts +=1;
                $saved_otp->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid OTP',],201);
            }

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $wallet->auth_attempts+=1;
                $wallet->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid credentials'],201);
            }

            if (!Hash::check($pin["pin"], $wallet->pin)) {
                $wallet->auth_attempts +=1;
                $wallet->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid login credentials'],201);
            }

            $wallet->auth_attempts =0;
            $wallet->verified =1;
            $wallet->device_uuid =$request->device_uuid;
            $wallet->save();

            $saved_otp->attempts=0;
            $saved_otp->save();
            DB::commit();
            return response(['code'     => '000','description'   => 'OTP successfully verified', 'data' => $wallet]);
        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Your request could not be processed.',],500);
        }

    }

    protected function wallet_kyc(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
            'pin'               => 'required',
            'device_uuid'       => 'required',
        ]);
    }

    protected function wallet_preauth(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
        ]);
    }

    protected function otp_validation(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
            'otp'               => 'required',
            'pin'               => 'required',
            'device_uuid'       => 'required',
        ]);
    }

}

