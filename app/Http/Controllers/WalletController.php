<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\License;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Wallet;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;




class WalletController extends Controller
{

    public function wallet_sign_up(Request $request){


        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

         $pin  = $request->pin;

        $account_prefix = '0012';
        $account = mt_rand(10000000, 99999999);
        $account_number = $account_prefix.$account;


        $mobile_number_length = strlen($request->mobile);


      if ($mobile_number_length < 10){

          return response([

              'code' => '01',
              'description' => 'Invalid Mobile number'

          ]);

      }



       /* if ($mobile_number_length = 10){

           $out = ltrim($request->mobile, "0");
           $mobile_code = License::find(1);
           $mobile = $mobile_code->mobile_code.$out;

        }

       */




       try {




             Wallet::create([

               'mobile' => $request->mobile,
               'account_number' => $account_number,
               'first_name' => $request->first_name,
               'last_name' => $request->last_name,
               'gender' => $request->gender,
               'dob' => $request->dob,
               'national_id' => $request->national_id,
               'state' => '1',
               'wallet_cos_id' => 1,
               'auth_attempts' => 0,
               'pin' => Hash::make($pin),



           ]);


           return response([

               'code' => '00',
               'description' => 'Registration Successful.',


           ]);

       } catch (QueryException $queryException){


           return response([

               'code' => '01',
               'description' => 'Mobile already registered',

           ]);


       }




    }
    protected function wallet_kyc(Array $data)
    {
        return Validator::make($data, [
            'mobile' => 'required',
            'first_name' => 'required',
            'last_name' => 'required',
            'gender' => 'required',
            'national_id' => 'required',
            'dob' => 'required',
            'pin' => 'required',


        ]);


    }

}

