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


class PurchaseOnUsService
{
    public static function sendTransaction($id,$amount,$account_number,$merchant){

        $merchant_id        = Devices::where('imei', $merchant)->lockForUpdate()->first();
        $merchant_account   = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
        $merchant_name = Merchant::find($merchant_id->merchant_id)->name;
        $fees_charged = FeesCalculatorService::calculateFees($amount, '0.00', PURCHASE_ON_US, $merchant_id->merchant_id,$account_number);
        $fees_total = $fees_charged['fees_charged'] - $fees_charged['tax'];

        $debit_client_amount        = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($account_number, 0, 3),
            'account_id'                => $account_number,
            'trx_description_id'        => '007',
            'trx_description'           => "Pos purchase | $account_number| $merchant_name",
            'trx_amount'                => '-' . $amount);

        $debit_client_fees          = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($account_number, 0, 3),
            'account_id'                => $account_number,
            'trx_description_id'        => '007',
            'trx_description'           => "Purchase fees | $account_number| $merchant_name",
            'trx_amount'                => '-' . $fees_total);

        $credit_revenue_fees        = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($account_number, 0, 3),
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "Acquirer fee | $account_number| $merchant_name",
            'trx_amount'                => $fees_charged['acquirer_fee']);


        $tax_credit        = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($account_number, 0, 3),
            'account_id'                => $account_number,
            'trx_description_id'        => '008',
            'trx_description'           => "Transaction tax | $account_number| $merchant_name",
            'trx_amount'                =>  $fees_charged['tax']);

        $tax_debit         = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($account_number, 0, 3),
            'account_id'                => TAX,
            'trx_description_id'        => '008',
            'trx_description'           => "Transaction tax | $account_number| $merchant_name",
            'trx_amount'                =>  - $fees_charged['tax']);

        $credit_merchant_account    = array(
            'serial_no'                 => $id,
            'our_branch_id'             => substr($merchant_account->account_number, 0, 3),
            'account_id'                => $merchant_account->account_number,
            'trx_description_id'        => '008',
            'trx_description'           => "Pos purchase | $account_number",
            'trx_amount'                =>  $amount);


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
                'headers' => ['Authorization' => 'Purchase', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' =>   array(
                    $debit_client_amount,
                    $debit_client_fees,
                    $credit_revenue_fees,
                    $tax_credit,
                    $credit_merchant_account,
                    $tax_debit)]
            ]);



            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){
                LoggingService::message("Purchase transaction failed: $response->description : $account_number");
                return array(
                    'code'           => $response->code,
                    'description'    => $response->description
                );
            }


            LoggingService::message("Purchase transaction processed successfully | $account_number | $id ");
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