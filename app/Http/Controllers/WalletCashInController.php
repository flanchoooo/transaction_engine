<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\License;
use App\Merchant;
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




class WalletCashInController extends Controller
{




    public function cash_in_business(Request $request){


        $validator = $this->wallet_kyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }




        //Merchant Authentication
        $business = Merchant::find($request->business_id);

            $hasher = app()->make('hash');

            if (!$hasher->check($request->pin, $business->pin)){

                return response([

                    'code' => '01',
                    'description' => 'Authentication Failed',

                ]);

        }


        // ISO Mobile number formatting
        $out = ltrim($request->client_mobile, "0");
        $mobile_code = License::find(1);
        $mobile = $mobile_code->mobile_code.$out;



       $client_mobile = Wallet::where('mobile',$mobile)->first();

        if(!isset($client_mobile)){

            return response([

                'code' => '01',
                'description' => 'Mobile not registered',

            ]);


        }



        $debit_business = - $request->debit_amount;
        $credit_wallet = $request->credit_amount;
        $txn = $debit_business + $credit_wallet;



        if($txn != 0) {

            return response([

                'code' => '01',
                'description' => 'Sum must be zero.',

            ]);

        }


        //Deduct funds from merchant account
        $merchant_balance =  $business->balance + $debit_business;
        $business->balance=$merchant_balance;
        $business->save();


        //Credit wallet
        $new_wallet_balance =  $client_mobile->balance +  $credit_wallet;
        $client_mobile->balance=$new_wallet_balance;
        $client_mobile->save();



        return response([

            'code' => '00',
            'description' => 'Transaction was successfully processed',

        ]);








        //Cash in










    }




    protected function wallet_kyc(Array $data)
    {
        return Validator::make($data, [
            'pin' => 'required',
            'business_id' => 'required',
            'debit_amount' => 'required',
            'credit_amount' => 'required',
            'client_mobile' => 'required|max:10|min:10',



        ]);


    }


}

