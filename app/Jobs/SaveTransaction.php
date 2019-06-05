<?php

namespace App\Jobs;

use App\Transaction;
use App\TransactionType;

class SaveTransaction extends Job
{
    protected $transaction_type;
    protected $status;
    protected $account;
    protected $card_number;
    protected $debit;
    protected $credit;
    protected $reference;
    protected $fee;
    protected $merchant;

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
    public function __construct($transaction_type, $status, $account, $card_number, $credit, $debit,  $fee, $reference, $merchant){
        //
        $this->transaction_type = $transaction_type;
        $this->status = $status;
        $this->account = $account;
        $this->card_number = $card_number;
        $this->debit = $debit;
        $this->credit = $credit;
        $this->reference = $reference;
        $this->fee = $fee;
        $this->merchant = $merchant;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        $transaction_type = TransactionType::find($this->transaction_type);

        Transaction::create([
            'transaction_type' => $this->transaction_type,
            'status'           => $this->status,
            'account'          => $this->account,
            'pan'              => $this->card_number,
            'credit'           => $this->credit,
            'debit'            => $this->debit,
            'description'      => $transaction_type->name,
            'fee'              => $this->fee,
            'batch_id'         => $this->reference,
            'merchant'         => $this->merchant
        ]);


    }


}
