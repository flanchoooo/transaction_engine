<?php

namespace App\Services;

use App\BRJob;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class ZipitSendService
{
    public static function sendTransaction($id, $amount, $account_number, $narration, $rrn)
    {


        $branch_id = substr($account_number, 0, 3);
        $fees_result = FeesCalculatorService::calculateFees(
            $amount,
            '0.00',
            ZIPIT_SEND,
            HQMERCHANT, $account_number

        );

        $account_debit = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $account_number,
            'trx_description_id'    => '007',
            'trx_description'       => "Zipit send | $account_number | $narration | RRN:$rrn",
            'trx_amount'            => -$amount);

        $account_debit_fees = array(
            'serial_no'            => $id,
            'our_branch_id'        => $branch_id,
            'account_id' => $account_number,
            'trx_description_id' => '007',
            'trx_description' => "Zipit fees | $account_number | RRN:$rrn",
            'trx_amount' => '-' . $fees_result['fees_charged']);

        $destination_credit_zimswitch = array(
            'serial_no' => $id,
            'our_branch_id' => $branch_id,
            'account_id' => ZIMSWITCH,
            'trx_description_id' => '008',
            'trx_description' => "Zipit send  | $account_number | RRN:$rrn",
            'trx_amount' => $amount);

        $bank_revenue_credit = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id' => REVENUE,
            'trx_description_id' => '008',
            'trx_description' => "Acquirer fee | $account_number | RRN: $rrn",
            'trx_amount' => $fees_result['acquirer_fee']);

        $tax_credit = array(
            'serial_no' => $id,
            'our_branch_id' => $branch_id,
            'account_id' => TAX,
            'trx_description_id' => '008',
            'trx_description' => "Transaction tax | $account_number| RRN:$rrn",
            'trx_amount' => $fees_result['tax']);

        $zimswitch_fees = array(
            'serial_no' => $id,
            'our_branch_id' => $branch_id,
            'account_id' => REVENUE,
            'trx_description_id' => '008',
            'trx_description' => "Z06 Switch fee | $account_number | RRN:$rrn",
            'trx_amount' => $fees_result['zimswitch_fee']);




        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Zipit', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $account_debit_fees,
                        $destination_credit_zimswitch,
                        $bank_revenue_credit,
                        $tax_credit,
                        $zimswitch_fees
                    )
                ]

            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00') {
                return array(
                    'code' => $response->code,
                    'description' => $response->description
                );

            }

            LoggingService::message('Zipit send transaction processed successfully' . $account_number);
            return array(
                'code' => "00",
                'description' => $response->transaction_batch_id
            );


        } catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,zipit send :$account_number :  $exception");
                return array(
                    'code' => '01',
                    'description' => 'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process zipit send txn: $account_number" . $e->getMessage());
                return array(
                    'code' => '01',
                    'description' => $e->getMessage()
                );

            }

        }

    }

}

        
        



