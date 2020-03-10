<?php

namespace App\Services;

use App\BRJob;
use App\Devices;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class MerchantServiceFee
{
    public static function sendTransaction($id,$amount,$account_number,$reference){



        $branch_id = substr($account_number, 0, 3);
        $debit_merchant             = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => REVENUE,
            'trx_description_id'    => '007',
            'trx_description'       => "Merchant service fee | $account_number | $reference ",
            'trx_amount'            => $amount);


        $credit_revenue             = array(
            'serial_no'             => $id,
            'our_branch_id'         =>$branch_id,
            'account_id'            => $account_number,
            'trx_description_id'    => '008',
            'trx_description'       => "Merchant service fee | $account_number | $reference ",
            'trx_amount'            =>   - $amount);

        $response =  DuplicateTxnCheckerService::check($id);
        if($response["code"] != "00"){
            return array(
                'code'           => '01',
                'description '   => 'Transaction already processed.'
            );
        }


        try {

            $client = new Client();
            $response =  DuplicateTxnCheckerService::check($id);
            if($response["code"] != "00"){
                LoggingService::message("Duplicate transaction detected | $account_number | $id ");
                return array(
                    'code'           => '01',
                    'description '   => 'Transaction already processed.'
                );
            }
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_merchant,
                        $credit_revenue,
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

            LoggingService::message("MDR transaction processed successfully | $account_number | $id ");
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,MDR :$account_number :  $exception");
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process MDR txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }


}