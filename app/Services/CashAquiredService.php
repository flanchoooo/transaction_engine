<?php

namespace App\Services;

use App\BRJob;
use App\Devices;
use App\Merchant;
use App\MerchantAccount;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class CashAquiredService
{
    public static function sendTransaction($id,$amount,$cash,$account_number,$narration,$reference){
        $merchant_id        = Devices::where('imei', $narration)->first();
        $merchant_account   = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();

        $fees_charged = FeesCalculatorService::calculateFees(
            $amount,
            $cash,
            PURCHASE_CASH_BACK_BANK_X,
            $merchant_id->merchant_id,$merchant_account->account_number

        );

        $branch_id = substr($account_number, 0, 3);
        $debit_zimswitch  = array(
            'serial_no'          => $id,
            'our_branch_id'       => $branch_id,
            'account_id'         => ZIMSWITCH,
            'trx_description_id'  => '007',
            'trx_description'    => 'POS SALE & CASH RRN'.$reference,
            'trx_amount'         => '-' . $amount );

        $credit_merchant_purchase = array(
            'serial_no'          => $id,
            'our_branch_id'      => $branch_id,
            'account_id'        => $merchant_account->account_number,
            'trx_description_id' => '008',
            'trx_description'   => 'POS SALE & CASH RRN'.$reference,
            'trx_amount'        => $amount);



        $debit = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => ZIMSWITCH,
            'trx_description_id'    => '007',
            'trx_description'       => 'POS SALE Acquirer Fee:'.$reference,
            'trx_amount'            => '-' . $fees_charged['acquirer_fee']);


        $credit = array(
            'serial_no'             => $id,
            'our_branch_id'         => substr($merchant_account->account_number, 0, 3),
            'account_id'             => REVENUE,
            'trx_description_id'    => '008',
            'trx_description'       => 'POS SALE Acquirer Fee:'.$reference,
            'trx_amount'            => $fees_charged['acquirer_fee']);


        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Purchase', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $credit_merchant_purchase,
                        $debit,
                        $debit_zimswitch,
                        $credit,
                    ),
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){
                LoggingService::message("Cash transaction failed: $response->description : $account_number");
                return array(
                    'code'           => $response->code,
                    'description'   => $response->description
                );
            }


            LoggingService::message("Cash transaction processed successfully for account:$account_number");
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS process cash txn: $account_number". $exception);
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