<?php

namespace App\Services;

use App\Account;
use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class ElectricityService
{
    public static function sendTransaction($id,$amount,$account_number,$narration,$mobile){

        $source_debit = array(
            'serial_no'          =>  $id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $account_number,
            'trx_description_id' => '007',
            'trx_description'    => "$narration | $mobile",
            'trx_amount'         => -$amount);


        $destination_credit = array(
            'serial_no'          => $id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => Account::find(7)->account_number,
            'trx_description_id' => '008',
            'trx_description'    => "$narration | $mobile",
            'trx_amount'         => $amount);



        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $source_debit,
                        $destination_credit,
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

            LoggingService::message('Zesa transaction processed successfully'.$account_number);
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,Zesa :$account_number :  $exception");
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process Zesa txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }


}