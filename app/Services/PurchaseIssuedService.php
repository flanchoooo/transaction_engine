<?php

namespace App\Services;

use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class PurchaseIssuedService
{
    public static function sendTransaction($id,$amount,$account_number,$narration,$rrn,$reference){

        $fees_charged = FeesCalculatorService::calculateFees($amount, '0.00', PURCHASE_OFF_US, HQMERCHANT,$account_number);
        $fees_total = $fees_charged['fees_charged'] - $fees_charged['tax'];
        $branch_id = substr($account_number, 0, 3);

        $debit_client_purchase_amount = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => $account_number,
            'trx_description_id'        => '007',
            'trx_description'           => "POS Purchase | $narration | $account_number | $rrn",
            'trx_amount'                => '-' . $amount);

        $tax = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => $account_number,
            'trx_description_id'        => '007',
            'trx_description'           =>  "Transaction tax | $narration | $account_number | $rrn",
            'trx_amount'                => '-' . $fees_charged['tax']);


        $debit_client_fees = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => $account_number,
            'trx_description_id'        => '007',
            'trx_description'           =>  "Fees | $narration | $account_number | $rrn",
            'trx_amount'                => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => TAX,
            'trx_description_id'        => '008',
            'trx_description'           =>  "Transaction tax | $narration | $account_number | $rrn",
            'trx_amount'                => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => "POS Purchase | $narration | $account_number | $rrn",
            'trx_amount'                =>  $amount);


        $acquirer_fee = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "Acquirer fee | $narration | $account_number | $rrn",
            'trx_amount'                =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => "Z06 - Switch fee | $narration | $account_number | $rrn",
            'trx_amount'                =>  $fees_charged['zimswitch_fee']);

        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Purchase', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_zimswitch_amount,
                        $tax
                    ),
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){
                LoggingService::message("Purchase transaction failed: $response->description : $account_number");
                return array(
                    'code'           => $response->code,
                    'description'   => $response->description
                );
            }


            LoggingService::message("Purchase transaction processed successfully for account | $account_number | $id ");
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS process purchase txn: $account_number". $exception);
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );
            } else {

                LoggingService::message("01:Error reaching CBS process purchase txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );
            }
        }
    }


}