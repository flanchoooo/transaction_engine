<?php

namespace App\Jobs;

use App\PendingTxn;
use App\Transaction;
use App\TransactionType;

class ProcessPendingTxns extends Job
{
    protected $transaction_type;


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
    public function __construct(){

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){


         //PendingTxn::where('state', 0)->delete();


         PendingTxn::create([


            'imei' => '86500003412556',
            'amount' => '10.00',
            'transaction_type' => '1',
            'state' => 1,
            'card_number' => '6500006189955222',

        ]);




    }


}
