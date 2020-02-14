<?php

namespace App\Jobs;

use App\Accounts;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletPostPurchaseTxns;
use App\WalletTransaction;
use App\WalletTransactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;

class PostWalleBalanceEnqJob extends Job
{

    protected $txn_reference;





    /**
     * Create a new job instance.
     *
     * @param $transaction_type
     * @param $status
     * @param $account
     * @param $card_number
     * @param $debit
     * @param $credit
     * @param $reference
     * @param $fee
     * @param $merchant
     */
    public function __construct($txn_reference){



        $this->txn_reference = $txn_reference;


    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){


        //Declarations
        $post_txns = WalletPostPurchaseTxns::where('batch_id',$this->txn_reference )->first();
        $zimswitch_ = Wallet::where('mobile','263700000004')->first();
        $zimswitch = Accounts::find(1);
        $trust_account = Accounts::find(6);


        //Destroy E-Value
        $deduct = $post_txns->zimswitch_fees;

        try{



            $zimswitch_->lockForUpdate()->first();
            $zimswitch_new_balance_ = $zimswitch_->balance - $deduct;
            $zimswitch_->balance = number_format((float)$zimswitch_new_balance_, 4, '.', '');
            $zimswitch_->save();

            $post_txns->status = 1;
            $post_txns->save();


            DB::commit();
        }catch (\Exception $e){


            DB::rollback();

            WalletTransactions::create([

                'txn_type_id'         => DESTROY_E_VALUE,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => $this->txn_reference,
                'switch_reference'    => $this->txn_reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $zimswitch_->mobile,
                'pan'                 => str_limit($post_txns->card_number,16,''),
                'description'         => 'Failed to destroy e-value',


            ]);





            return response([

                'code' => '400',
                'description' => 'Failed to destroy Zimswitch E-Value',

            ]) ;



        };


        //BR Transactions
        $debit_trust_purchase = array('serial_no' => '472100',
            'our_branch_id' => '001',
            'account_id' => $trust_account->account_number,
            'trx_description_id' => '007',
            'TrxDescription' => 'Debit Trust Account with zimswitch fees'.$post_txns->batch_id,
            'TrxAmount' => '-' . $post_txns->zimswitch_fees);


        $credit_zimswitch_fee = array('serial_no' => '472100',
            'our_branch_id' => '001',
            'account_id' => $zimswitch->account_number,
            'trx_description_id' => '008',
            'TrxDescription' => 'Credit Zimswitch with fees'.$post_txns->batch_id,
            'TrxAmount' => $post_txns->zimswitch_fees);


        $auth = TokenService::getToken();
        $client = new Client();

        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(

                        $debit_trust_purchase,
                        $credit_zimswitch_fee,

                    ),
                ]
            ]);


            $response = json_decode($result->getBody()->getContents());




            Transactions::create([

                'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => $post_txns->zimswitch_fees,
                'transaction_amount'  => '0.00',
                'total_debited'       => $post_txns->zimswitch_fees,
                'total_credited'      => $post_txns->zimswitch_fees,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $response->transaction_batch_id,
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => $trust_account->account_number,
                'pan'                 =>  str_limit($post_txns->card_number,16,''),


            ]);






            //$response_ = $result->getBody()->getContents();
            WalletTransactions::create([

                'txn_type_id'         => DESTROY_E_VALUE,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => $post_txns->zimswitch_fees,
                'transaction_amount'  => '0.00',
                'total_debited'       => $post_txns->zimswitch_fees,
                'total_credited'      => $post_txns->zimswitch_fees,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $this->txn_reference,
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => $zimswitch_->mobile,
                'pan'                 => str_limit($post_txns->card_number,16,''),
                'description'         => 'DESTROY E VALUE',


            ]);


            WalletTransactions::create([

                'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => $post_txns->zimswitch_fees,
                'transaction_amount'  => '0.00',
                'total_debited'       => $deduct,
                'total_credited'      => $deduct,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $this->txn_reference,
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => $post_txns->mobile,
                'pan'                 => str_limit($post_txns->card_number,16,''),


            ]);



            WalletPostPurchaseTxns::destroy($post_txns->id);


        }catch (ClientException $exception){


            Transactions::create([

                'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $post_txns->mobile,
                'pan'                 => str_limit($post_txns->card_number,16,''),
                'description'          => 'Failed to process transaction,error 91',


            ]);

            return array('code' => '91',
                'error' => $exception);



        };











    }


}
