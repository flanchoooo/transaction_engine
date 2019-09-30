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
    public static function checkLimit($account_number)
    {

        $transactions  = Transactions::where('account_debited',$request->br_account)
            ->where('txn_type_id',ZIPIT_SEND)
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

        if($transactions > $fees_result['maximum_daily'] ){

            return response([
                'code' => '902',
                'description' => 'You have reached your transaction limit for the day.',

            ]);
        }




    }

}