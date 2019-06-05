<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 11/2/18
 * Time: 3:57 PM
 */

namespace App\Services;

use App\Fee;
use App\Transaction;
use Carbon\Carbon;
use GuzzleHttp;
use GuzzleHttp\Client;
use http\Env\Request;
use Services\TokenService;
use GuzzleHttp\Exception\RequestException;


class LimitCheckerService
{
    public static function checkLimit($product_id,$card)
    {




        $fee_result = Fee::where('product_id', $product_id)->get();

        foreach ($fee_result as $res){

        $max_daily_limit = $res->max_daily_limit;


        }
         $max_daily_limit;

        //return Carbon::today()->toDateString();

        $txn_result = Transaction::all()
            ->where('transaction_date',Carbon::today()->toDateString())
            ->where('card',$card)->sum('debit');


        if($txn_result > $max_daily_limit){

            return array(
                //Transaction not permitted to cardholder
                'code' => '57',

            );

        }else {

            return array(

                'code' => '00' ,
            );

        }



    }

}