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


class PurchaseCashService
{
    public static function sendTransaction($id,$amount,$cash,$account_number,$narration,$reference){



        $merchant_id        = Devices::where('imei', $narration)->first();
        $merchant_account   = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
        $merchant_name = Merchant::find($merchant_id->merchant_id)->name;
        $fees_charged = FeesCalculatorService::calculateFees($amount, '0.00', PURCHASE_CASH_BACK_ON_US, $merchant_id->merchant_id,$account_number);
        $fees_total = $fees_charged['fees_charged'] - $fees_charged['tax'];

        $total  = $amount + $cash;
        $branch_id = substr($merchant_account->account_number, 0, 3);

        $debit_client_amount_cashback_amount = array(
            'serial_no'          => $id,
            'our_branch_id'      => $branch_id,
            'account_id'         => $account_number,
            'trx_description_id' => '007',
            'trx_description'    => "PURCHASE + CASH | $merchant_name | $reference",
            'trx_amount'         => '-' . $total);


        $debit_client_fees = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $account_number,
            'trx_description_id'    => '007',
            'trx_description'       =>  "PURCHASE + CASH Fees | $merchant_name | $reference",
            'trx_amount'            => '-' . $fees_total);

        $credit_revenue_fees = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "PURCHASE + CASH REVENUE | $reference",
            'trx_amount'                => $fees_charged['acquirer_fee']);

        $tax_account_credit = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => TAX,
            'trx_description_id'        => '008',
            'trx_description'           => "PURCHASE + CASH TAX | $reference",
            'trx_amount'                => $fees_charged['tax']);

        $credit_revenue_cashback_fee = array(
            'serial_no'                 => $id,
            'our_branch_id'             => $branch_id,
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "PURCHASE + CASH FEES | $reference",
            'trx_amount'                => $fees_charged['cash_back_fee']);

        $credit_merchant_account = array(
            'serial_no'             => $id,
            'our_branch_id'         => $branch_id,
            'account_id'            => $merchant_account->account_number,
            'trx_description_id'    => '008',
            'trx_description'       => "PURCHASE + CASH | $reference",
            'trx_amount'            => $cash);

        $credit_merchant_cashback_amount = array(
            'serial_no'          => $id,
            'our_branch_id'       => $branch_id,
            'account_id'         => $merchant_account->account_number,
            'trx_description_id'  => '008',
            'trx_description'    => "PURCHASE + CASH | $reference",
            'trx_amount'         =>   $amount);


        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_amount_cashback_amount,
                        $debit_client_fees,
                        $credit_revenue_fees,
                        $tax_account_credit,
                        $credit_revenue_cashback_fee,
                        $credit_merchant_account,
                        $credit_merchant_cashback_amount,


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

            LoggingService::message('Purchase + Cash transaction processed successfully'.$account_number);
            return array(
                'code'           => "00",
                'description'      => $response->transaction_batch_id
            );


        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message("01:Error reaching CBS,Purchase + Cash  :$account_number :  $exception");
                return array(
                    'code'           => '01',
                    'description'   =>  'Failed to reach CBS'
                );

            } else {
                LoggingService::message("01:Error reaching CBS process , Purchase + Cash  txn: $account_number". $e->getMessage());
                return array(
                    'code'           => '01',
                    'description'   =>  $e->getMessage()
                );

            }
        }
        
        
    }


}