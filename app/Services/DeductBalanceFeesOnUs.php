<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 11/2/18
 * Time: 3:57 PM
 */

namespace App\Services;

use App\Transactions;
use GuzzleHttp;
use GuzzleHttp\Client;
use http\Env\Request;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class DeductBalanceFeesOnUs
{
    public static function deduct($account_number,$fees_charged,$merchant_id,$card_number)
    {

        $branch_id = substr($account_number, 0, 3);
        $account_debit = array(
            'serial_no'          => '472100',
            'our_branch_id'       => $branch_id,
            'account_id'         => $account_number,
            'trx_description_id'  => '007',
            'trx_description'    => 'Balance enquiry',
            'trx_amount'         => '-' . $fees_charged);

        $bank_revenue_credit    = array(
            'serial_no'          => '472100',
            'our_branch_id'       => $branch_id,
            'account_id'         => REVENUE,
            'trx_description_id'  => '008',
            'trx_description'    => "Balance enquiry on us,credit revenue with fees",
            'trx_amount'         => $fees_charged);


        $client = new Client();

        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Deduct', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $bank_revenue_credit,
                    ),
                ],
            ]);


            $response = json_decode($result->getBody()->getContents());

            Transactions::create([

                'txn_type_id'         => BALANCE_ON_US,
                'tax'                 => '0.00',
                'revenue_fees'        => $fees_charged,
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => $fees_charged,
                'total_credited'      => '0.00',
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $response->transaction_batch_id,
                'merchant_id'         => $merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $account_number,
                'pan'                 => $card_number,
                'description'         => 'Transaction successfully processed.',
            ]);

            return array(
                'code'  => '00',
                'batch' => $response->transaction_batch_id);


        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Account Number:'.$account_number.' '.$exception);
                Transactions::create([

                    'txn_type_id'       => BALANCE_ON_US,
                    'tax'               => '0.00',
                    'revenue_fees'      => '0.00',
                    'interchange_fees'  => '0.00',
                    'zimswitch_fee'     => '0.00',
                    'transaction_amount'=> '0.00',
                    'total_debited'     => '0.00',
                    'total_credited'    => '0.00',
                    'batch_id'          => '',
                    'switch_reference'  => '',
                    'merchant_id'       => $merchant_id,
                    'transaction_status'=> 0,
                    'account_debited'   => $account_number,
                    'pan'               => $card_number,
                    'description'       => 'Failed to process BR transaction',

                ]);

                return array(
                    'code'  => '01',
                    'description' => $exception);


            }
            else{

                Log::debug('Account Number:'.$account_number.' '.$e->getMessage());

                Transactions::create([
                    'txn_type_id'       => BALANCE_ON_US,
                    'tax'               => '0.00',
                    'revenue_fees'      => '0.00',
                    'interchange_fees'  => '0.00',
                    'zimswitch_fee'     => '0.00',
                    'transaction_amount'=> '0.00',
                    'total_debited'     => '0.00',
                    'total_credited'    => '0.00',
                    'batch_id'          => '',
                    'switch_reference'  => '',
                    'merchant_id'       => $merchant_id,
                    'transaction_status'=> 0,
                    'account_debited'   => $account_number,
                    'pan'               => $card_number,
                    'description'       => 'Failed to process transaction.',


                ]);

                return array(
                    'code'  => '01',
                    'description' => $e->getMessage());

            }
        }














    }

}