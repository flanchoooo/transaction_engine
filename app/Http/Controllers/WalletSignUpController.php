<?php

namespace App\Http\Controllers;

use App\Services\AESEncryption;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;



class WalletSignUpController extends Controller
{
    public function wallet_sign_up(Request $request){
        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                return response([
                    'code' => '807',
                    'description' => 'Invalid authorization credentials',
                ]);
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
                ]);
            }

            return response([
                'code' => '100',
                'description' => 'Registration failed,please contact support for assistance',
            ]);
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
                ]);
            }

            if($user->auth_attempts > 2){
                $user->state = "BLOCKED";
                $user->save();
                DB::commit();
                return response([
                    'code' => '100',
                    'description' => 'Account is blocked',
                ]);
            }


            $new_pin = AESEncryption::decrypt($request->new_pin);
            if($new_pin["pin"] == false){
                DB::commit();
                return response([
                    'code' => '807',
                    'description' => 'Invalid new pin credentials',
                ]);
            }


            $old_pin = AESEncryption::decrypt($request->old_pin);
            if($old_pin["pin"] == false){
                $user->auth_attempts+=1;
                $user->save();
                DB::commit();
                return response([
                    'code' => '807',
                    'description' => 'Invalid old pin credentials',
                ]);
            }


            if (!Hash::check($old_pin["pin"], $user->pin)) {
                $user->auth_attempts+=1;
                $user->save();
                DB::commit();
                return response([
                    'code' => '100',
                    'description' => 'Incorrect pin',
                ]);
            }


            if (Hash::check($new_pin["pin"], $user->pin)) {
                return array('code'        => '01',
                    'description' => 'Your new PIN cannot match your previous PIN',
                );
            }




            $user->pin = Hash::make($new_pin["pin"]);
            $user->save();
            DB::commit();
            return response([
                'code' => '000',
                'description' => 'Pin change successful',
            ]);


        }catch (\Exception $exception){

            return $exception;
            DB::rollback();
            return response([
                'code' => '100',
                'description' => 'Pin change failed.',
            ]);
        }

    }





    protected function wallet_kyc(Array $data)
    {
        return Validator::make($data, [
            'mobile'            => 'required | string |min:0|max:20',
            'first_name'        => 'required | max:64',
            'last_name'         => 'required',
            'nationality'       => 'required',
            'national_id'       => 'required',
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

