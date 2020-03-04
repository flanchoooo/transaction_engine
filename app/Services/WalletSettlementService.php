<?php

namespace App\Services;

use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class WalletSettlementService
{
    public static function sendTransaction($id,$amount,$source,$destination,$narration){


        $accounts  = strlen($source);
        if($accounts <= 8){
            $branch_id = substr($destination,0,3);
        }else{
            $branch_id = substr($source,0,3);
        }


        $account_debit = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $source,
            'trx_description_id'    => '007',
            'trx_description'       => "$narration",
            'trx_amount'            => '-' . $amount);

        $bank_revenue_credit    = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $destination,
            'trx_description_id'    => '008',
            'trx_description'       => "$narration",
            'trx_amount'            =>  $amount);



        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $bank_revenue_credit,
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

            LoggingService::message('Wallet transaction processed successfully');
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message('Wallet transaction failed,Failed to reach CBS');
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message('Wallet transaction failed');
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }


}