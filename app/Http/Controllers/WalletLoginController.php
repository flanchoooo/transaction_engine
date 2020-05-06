<?php

namespace App\Http\Controllers;

use App\Services\AESEncryption;
use App\Services\OtpService;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
                return response([
                    'code' => '100',
                    'description' => 'Invalid login credentials',
                ]);
            }

            if($wallet->state != "ACTIVE"){
                return response([
                    'code' => '117',
                    'description' => 'Account is blocked',
                ]);
            }

            if($wallet->auth_attempts > 2){
                $wallet->state = "BLOCKED";
                $wallet->save();
                DB::commit();
                return response([
                    'code' => '117',
                    'description' => 'Account is blocked',
                ]);
            }

            /*if($wallet->device_uuid != $request->device_uuid){
                return response([
                    'code' => '100',
                    'description' => 'Device is not trusted',
                ]);
            }*/

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $wallet->auth_attempts+=1;
                $wallet->save();
                DB::commit();
                return response([
                    'code' => '807',
                    'description' => 'Invalid login credentials',
                ]);
            }

            if (Hash::check($pin["pin"], $wallet->pin)) {
                $wallet->auth_attempts = 0;
                $wallet->save();
                return response([
                    'code'          => '000',
                    'description'   => 'Login successful',
                    'data'          => $wallet
                ]);
            }


            $wallet->auth_attempts+=1;
            $wallet->save();
            DB::commit();
            return response([
                'code' => '902',
                'description' => 'Invalid login credentials',
            ]);

        }catch (\Exception $exception){
            DB::rollback();
            return response([
                'code' => '100',
                'description' => 'Login Failed.',
            ]);
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
                return response([
                    'code' => '100',
                    'description' => 'Wallet account is not registered.',
                ]);
            }

            if($wallet->state != "ACTIVE"){
                return response([
                    'code'          => '000',
                    'description'   => 'Preauth successful.',
                    'data'          => $wallet
                ]);
            }



        }catch (\Exception $exception){
            DB::rollback();
            return response([
                'code' => '100',
                'description' => 'Your request could not be processed.',
            ]);
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

}

