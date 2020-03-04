<?php

namespace App\Services;

use App\Account;
use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class HotRechargeService
{
    public static function sendTransaction($id,$amount,$account_number,$narration,$mobile){


        $revenue_account = Account::find(9);
        $tax_account = Account::find(4);
        $destination =  Account::find(6)->account_number;
        $fees = IBFeesCalculatorService::calculateFees($amount,30,$account_number,$destination);
        $source_debit = array(
            'serial_no'          => $id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $account_number,
            'trx_description_id' => '007',
            'trx_description'    => $narration,
            'trx_amount'         => - $amount);

        $source_fees = array(
            'serial_no'          =>$id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $account_number,
            'trx_description_id' => '007',
            'trx_description'    => "Airtime Purchase Fees Debit : $account_number to $mobile",
            'trx_amount'         => '-' . $fees['fees_charged']);


        $destination_credit = array(
            'serial_no'          =>$id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $destination,
            'trx_description_id' => '008',
            'trx_description'    => $narration,
            'trx_amount'         => $amount);

        $revenue_credit = array(
            'serial_no'          =>$id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $revenue_account->account_number,
            'trx_description_id' => '008',
            'trx_description'    => "Revenue Account Credit : Airtime Purchase $account_number to $mobile",
            'trx_amount'         => $fees['revenue_fee']);

        $tax_credit = array(
            'serial_no'          =>$id,
            'our_branch_id'      => substr($account_number, 0, 3),
            'account_id'         => $tax_account->account_number,
            'trx_description_id' => '008',
            'trx_description'    => "Tax Account Credit : Airtime Purchase $account_number to $mobile",
            'trx_amount'         => $fees['tax_fee']);




        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipitreceive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $source_debit,
                        $source_fees,
                        $destination_credit,
                        $revenue_credit,
                        $tax_credit,

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

            LoggingService::message('Airtime transaction processed successfully'.$account_number);
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,Airtime receive :$account_number :  $exception");
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process Airtime receive txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }


}