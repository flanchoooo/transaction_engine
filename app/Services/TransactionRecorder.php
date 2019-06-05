<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;




use App\Transaction;
use App\User;
use Carbon\Carbon;


class TransactionRecorder
{
    public static function recordTxn($type,$amount,$merchant,$card,$description,$status,$channel,$fee,$batch_id,$account,$credit,$debit)
    {

        $var = substr_replace($card, str_repeat("*", 6), 6, 6);
        $pci = substr($var, 0, -4);

        return Transaction::create([

            'transaction_type' => $type,
            'amount' => $amount,
            'transaction_date' => Carbon::today()->toDateString(),
            'status' => $status,
            'merchant' => $merchant,
            'account' =>$account,
            'pan' =>  $pci,
            'card' => $card,
            'credit' => $credit,
            'debit' => $debit,
            'description' => $description,
            'channel' => $channel,
            'fee' => $fee,
            'batch_id' => $batch_id,
        ]);



    }

}