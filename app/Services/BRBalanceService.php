<?php

namespace App\Services;

use App\BRJob;

use GuzzleHttp;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;


class BRBalanceService
{

    public static function br_balance($account_number)
    {


       return $balance_res = CheckBalanceService::checkBalance($account_number);
        if($balance_res["code"] != '000'){
            return array(
                'code'          => $balance_res["code"],
                'description'   => $balance_res["description"],
            );
        }

    return    $amounts = BRJob::where('source_account',$account_number)
            ->whereIn('txn_status',['FAILED_','FAILED','PROCESSING','PENDING'])
            ->where('txn_type', '!=',  '156579070528551244')
            ->get()->sum(['amount_due']);

        $balance = $balance_res["available_balance"] - $amounts;
        return array (
            'code'                  => '000',
            'available_balance'     => "$balance",
            'ledger_balance'        => "$balance",
        );

    }


}