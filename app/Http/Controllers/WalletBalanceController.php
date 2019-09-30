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


        $balance->lockForUpdate()->first();
        return response([

            "code"          => '00',
            "description"   => 'success',
            'balance'       =>  $balance->balance

        ]);

    }


    protected function balance_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',


        ]);


    }


}

