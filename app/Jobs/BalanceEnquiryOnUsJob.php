<?php

namespace App\Jobs;

use App\Accounts;
use App\Services\TokenService;
use App\Transactions;
use App\TransactionType;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class BalanceEnquiryOnUsJob extends Job
{
    protected $fees_charged;
    protected $account_number;
    protected $merchant_id;
    protected $card_number;

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
    public function __construct($fees_charged, $account_number,$merchant_id,$card_number){
        //
        $this->fees_charged     = $fees_charged;
        $this->account_number   = $account_number;
        $this->merchant_id      = $merchant_id;
        $this->card_number      = $card_number;


    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        $branch_id = substr($this->account_number, 0, 3);
        $authentication  = TokenService::getToken();

        $account_debit = array(
            'serial_no'          => '472100',
            'our_branch_id'       => $branch_id,
            'account_id'         => $this->account_number,
            'trx_description_id'  => '007',
            'TrxDescription'    => 'Balance enquiry on us,debit fees',
            'TrxAmount'         => '-' . $this->fees_charged);

        $bank_revenue_credit    = array(
            'serial_no'          => '472100',
            'our_branch_id'       => $branch_id,
            'account_id'         => REVENUE,
            'trx_description_id'  => '008',
            'TrxDescription'    => "Balance enquiry on us,credit revenue with fees",
            'TrxAmount'         => $this->fees_charged);


        $client = new Client();

        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
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
                'revenue_fees'        => $this->fees_charged,
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => $this->fees_charged,
                'total_credited'      => '0.00',
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $response->transaction_batch_id,
                'merchant_id'         => $this->merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $this->account_number,
                'pan'                 => $this->card_number,
                'description'         => 'Transaction successfully processed.',


            ]);

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Account Number:'.$this->account_number.' '.$exception);

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
                    'merchant_id'       => $this->merchant_id,
                    'transaction_status'=> 0,
                    'account_debited'   => $this->account_number,
                    'pan'               => $this->card_number,
                    'description'       => 'Failed to process BR transaction',

                ]);

                /*return array(
                    'code'  => '01',
                    'description' => $results->message);
                */

            }
            else{

                Log::debug('Account Number:'.$this->account_number.' '.$e->getMessage());
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
                    'merchant_id'       => $this->merchant_id,
                    'transaction_status'=> 0,
                    'account_debited'   => $this->account_number,
                    'pan'               => $this->card_number,
                    'description'       => 'Failed to process transaction.',


                ]);

                /*return array(
                    'code'  => '01',
                    'description' => $e->getMessage());
                */
            }
        }



    }


}
