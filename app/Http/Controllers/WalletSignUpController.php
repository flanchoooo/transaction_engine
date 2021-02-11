<?php

namespace App\Http\Controllers;

use App\Services\AESEncryption;
use App\Services\OTPService;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;



class WalletSignUpController extends Controller
{
    function encrypt($plaintext, $password) {
        $method = "AES-256-CBC";
        $key = hash('sha256', $password, true);
        $iv = openssl_random_pseudo_bytes(16);

        $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        $hash = hash_hmac('sha256', $ciphertext . $iv, $key, true);

        return $iv . $hash . $ciphertext;
    }

    function decrypt($ivHashCiphertext, $password) {
        $method = "AES-256-CBC";
        $iv = substr($ivHashCiphertext, 0, 16);
        $hash = substr($ivHashCiphertext, 16, 32);
        $ciphertext = substr($ivHashCiphertext, 48);
        $key = hash('sha256', $password, true);

        if (!hash_equals(hash_hmac('sha256', $ciphertext . $iv, $key, true), $hash)) return null;

        return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
    }

    public function wallet_sign_up(Request $request){
        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                return response([
                    'code' => '807',
                    'description' => 'Invalid authorization credentials',
                ],401);
            }

            $wallet = new  Wallet();
            $wallet->mobile         = $request->mobile;
            $wallet->first_name     = $request->first_name;
            $wallet->last_name      = $request->last_name;
            $wallet->nationality    = $request->nationality;
            $wallet->biometric      = $request->biometric;
            $wallet->device_uuid    = $request->device_uuid;
            $wallet->pin            = Hash::make($pin["pin"]);
            $wallet->wallet_type    = $request->wallet_type;
            $wallet->state          = "ACTIVE";
            $wallet->wallet_cos_id  = 1;
            $wallet->gender         = $request->gender;
            $wallet->national_id    = $request->national_id;
            $wallet->qr_reference   = Carbon::now()->timestamp;;
            $wallet->verified       = 0;
            $wallet->save();
            DB::commit();

            OTPService::generateOtp($request->mobile,'REGISTRATION');
            return response([
                'code'          => '000',
                'description'   => 'Wallet registration successful',
                'data'          => $wallet
            ]);

        }catch (\Exception $exception){
            DB::rollback();
            $code = $exception->getCode();
            if($code == "23000"){
                return response([
                    'code'          => '100',
                    'description'   => 'Mobile wallet details already registered.',
                    'error_message' => $exception->getMessage(),
                ],500);
            }

            return response([
                'code' => '100',
                'description' => 'Registration failed,please contact support for assistance',
                'error_message' => $exception->getMessage(),
            ],500);
        }

    }


    public function changePin(Request $request){
        $validator = $this->wallet_pin($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {

            $user = Wallet::whereMobile($request->mobile)->first();
            if(!isset($user)){
                return response([
                    'code' => '100',
                    'description' => 'Invalid credentials',
                ],201);
            }

            if($user->auth_attempts > 2){
                $user->state = "BLOCKED";
                $user->save();
                DB::commit();
                return response([
                    'code' => '100',
                    'description' => 'Account is blocked',
                ],201);
            }


            $new_pin = AESEncryption::decrypt($request->new_pin);
            if($new_pin["pin"] == false){
                DB::commit();
                return response([
                    'code' => '807',
                    'description' => 'Invalid new pin credentials',
                ],201);
            }


            $old_pin = AESEncryption::decrypt($request->old_pin);
            if($old_pin["pin"] == false){
                $user->auth_attempts+=1;
                $user->save();
                DB::commit();
                return response([
                    'code' => '807',
                    'description' => 'Invalid old pin credentials',
                    'error_message' => $old_pin["error_message"],
                ],201);
            }


            if (!Hash::check($old_pin["pin"], $user->pin)) {
                $user->auth_attempts+=1;
                $user->save();
                DB::commit();
                return response([
                    'code' => '100',
                    'description' => 'Incorrect pin',
                ],201);
            }


            if (Hash::check($new_pin["pin"], $user->pin)) {

                return response([
                    'code' => '100',
                    'description' => 'Your new PIN cannot match your previous PIN',
                ],201);
            }




            $user->pin = Hash::make($new_pin["pin"]);
            $user->save();
            DB::commit();
            return response([
                'code' => '000',
                'description' => 'Pin change successful',
            ]);


        }catch (\Exception $exception){
            DB::rollback();
            return response([
                'code' => '100',
                'description' => 'Pin change failed.',
            ],500);
        }

    }


    protected function wallet_kyc(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
            'first_name'        => 'required | max:64',
            'last_name'         => 'required',
            'nationality'       => 'required',
            'pin'               => 'required',
            'biometric'         => 'required',
            'device_uuid'       => 'required',
            'wallet_type'       => 'required',
            'gender'            => 'required',

        ]);


    }

    protected function wallet_pin(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
            'old_pin'               => 'required',
            'new_pin'               => 'required',

        ]);


    }

}

