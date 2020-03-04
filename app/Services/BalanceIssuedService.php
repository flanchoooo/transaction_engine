<?php

namespace App\Services;

use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class BalanceIssuedService
{
    public static function sendTransaction($id,$amount,$account_number,$narration,$rrn){

        $branch_id = substr($account_number, 0, 3);
        $destination_account_credit = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $account_number,
            'trx_description_id'    => '007',
            'trx_description'       => "Balance fees | $narration | $rrn",
            'trx_amount'            => - $amount);


        $zimswitch_debit = array(
            'serial_no'             => $id,
            'our_branch_id'         =>$branch_id,
            'account_id'            => ZIMSWITCH,
            'trx_description_id'    => '008',
            'trx_description'       => "Balance fees | $narration | $rrn",
            'trx_amount'            =>   $amount);


        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $destination_account_credit,
                        $zimswitch_debit,
                    ),
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){
                return array(
                    'code'           => $response->code,
                    'description'   => $response->description
                );

            }

            LoggingService::message('Balance successfully processed'.$account_number);
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,Balance:$account_number :  $exception");
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process Balance txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }
}