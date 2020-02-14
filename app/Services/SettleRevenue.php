<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;
use App\Wallet;
use App\WalletTransactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;



class SettleRevenue
{


    public static function settle()
    {

        DB::beginTransaction();

        /*
         * Steps
         *
         * Destroy e-value in wallet, debit the trust account GL and credit the revenue GL in BR.
         */
        try {

            $revenue             = Wallet::whereMobile(WALLET_REVENUE);
            $revenue_account     =  $revenue->lockForUpdate()->first();
            $total_balance       = $revenue_account->balance;
            $revenue_account->balance    -= $total_balance;
            $revenue_account->save();
            $reference = generateUniqueId::generateID(10, false);

            if($total_balance == '0.000'){
                return;
            }


            $token = json_decode(TokenServiceZM::getToken());
            $headers = array(
                'Accept'        => 'application/json',
                'Authorization' => $token->responseBody,
            );

            try {

                $credit_revenue_gl              = array(
                    'serial_no'                 => '472100',
                    'our_branch_id'             => '001',
                    'account_id'                =>  REVENUE_GL,
                    'trx_description_id'        => '008',
                    'trx_description'           => 'Wallet revenue settlement (C)'.$reference ,
                    'trx_amount'                => $total_balance);

                $debit_wallet_trust              = array(
                    'serial_no'                 => '472100',
                    'our_branch_id'             => '001',
                    'account_id'                => WALLET_TRUST_ACCOUNT,
                    'trx_description_id'        => '008',
                    'trx_description'           => 'Wallet revenue settlement (D)'.$reference ,
                    'trx_amount'                => '-' . $total_balance);



                $c = new Client();
                $r = $c->post(env('BR_BASE_URL') . '/api/internal-transfer', [

                    'headers' => $headers,
                    'json' => [
                        'bulk_trx_postings' => array(
                            $credit_revenue_gl,
                            $debit_wallet_trust
                        ),
                    ]

                ]);

                $response = json_decode($r->getBody()->getContents());
                if($response->code != '00'){
                    WalletTransactions::create([
                        'txn_type_id'       => DESTROY_E_VALUE,
                        'account_debited'   => WALLET_REVENUE,
                        'description'       => '01: Failed to process settlement request, contact administrator for assistance.',
                    ]);

                    return;
                }

                $source_new_balance             = $revenue_account->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = DESTROY_E_VALUE;
                $transaction->transaction_amount= $total_balance;
                $transaction->total_debited     = $total_balance;
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = WALLET_REVENUE;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.'.'CBS reference:'.$response->transaction_batch_id;
                $transaction->save();
                DB::commit();


            }catch (RequestException $requestException){
                WalletTransactions::create([
                    'txn_type_id'       => DESTROY_E_VALUE,
                    'account_debited'   => WALLET_REVENUE,
                    'description'       => '02: Failed to process settlement request, contact administrator for assistance.',
                ]);
                return;
            }

        }catch (\Exception $e){
            WalletTransactions::create([
                'txn_type_id'       => DESTROY_E_VALUE,
                'account_debited'   => WALLET_REVENUE,
                'description'       => '03: Failed to process settlement request, contact administrator for assistance.',
            ]);
            return;
        }




    }



}

