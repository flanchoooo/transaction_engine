<?php

namespace App\Http\Controllers;




use App\WalletBalance;
use App\WalletHistory;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;




class WalletBalanceController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function balance_request(Request $request){

        $validator = $this->balance_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }



        $balance = WalletBalance::where('mobile',$request->source_mobile)->first();


        if(!isset($balance)){

            return response([

                "code" => '01',
                "description" => 'failed',
                "message" => 'No transaction found for mobile:'.$request->source_mobile,


            ]);
        }


        $mobi = substr_replace($request->source_mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = $request->bill_payment_id . $time_stamp . $mobi;


        WalletTransactions::create([

            'txn_type_id'         => WALLET_BALANCE,
            'tax'                 => '0.00',
            'revenue_fees'        => '0.00',
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => '0.00',
            'total_debited'       =>'0.00',
            'total_credited'      => '0.00',
            'batch_id'            => $reference,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $request->source_mobile,
            'pan'                 => '',
            'merchant_account'    => '',


        ]);


        return response([

            "code" => '00',
            "description" => 'success',
            'balance' =>   $balance->balance

        ]);

    }






    protected function balance_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',


        ]);


    }


}

