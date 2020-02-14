<?php

namespace App\Jobs;

use App\Services\SmsNotificationService;
use App\Wallet;
use App\WalletTransactions;
use Illuminate\Support\Facades\DB;

class WalletCashInJob extends Job
{

    protected $agent_mobile;
    protected $revenue_mobile;
    protected $recipient_mobile;
    protected $transaction_amount;
    protected $fee;
    protected $reference;





    public function __construct($agent_mobile,$revenue_mobile,$recipient_mobile,$transaction_amount,$fee,$reference){
        //
        $this->agent_mobile       = $agent_mobile;
        $this->revenue_mobile     = $revenue_mobile;
        $this->recipient_mobile   = $recipient_mobile;
        $this->transaction_amount = $transaction_amount;
        $this->fee                = $fee;
        $this->reference          = $reference;


    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        DB::beginTransaction();
        try {

            $agent_account          = Wallet::whereMobile($this->agent_mobile);
            $revenue_account        = Wallet::whereMobile($this->revenue_mobile);
            $destination_mobile     = Wallet::whereMobile($this->recipient_mobile);

            $revenue_mobile = $revenue_account->lockForUpdate()->first();
            $revenue_mobile->balance -= $this->fee;
            $revenue_mobile->save();

            $agent_mobile = $agent_account->lockForUpdate()->first();
            $agent_mobile->commissions += $this->fee;
            $agent_mobile->balance -= $this->transaction_amount;
            $agent_mobile->save();

            $receiving_wallet = $destination_mobile->lockForUpdate()->first();
            $receiving_wallet->balance += $this->transaction_amount;
            $receiving_wallet->save();



            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_IN;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '-'.$this->fee;
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = $this->transaction_amount;
            $transaction->total_credited    = '0.00';
            $transaction->switch_reference  = $this->reference;
            $transaction->batch_id          = $this->reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $agent_mobile->mobile;
            $transaction->account_credited  = $receiving_wallet->mobile;
            $transaction->balance_after_txn = $agent_mobile->balance;
            $transaction->commissions       = $this->fee;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();

            //Credit Recipient with amount.
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_IN;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = '0.00';
            $transaction->total_credited    = $this->transaction_amount;
            $transaction->switch_reference  = $this->reference;
            $transaction->batch_id          = $this->reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $agent_mobile->mobile;
            $transaction->account_credited  = $receiving_wallet->mobile;
            $transaction->balance_after_txn = $receiving_wallet->balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();


            DB::commit();

            $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
            $sender_balance = money_format(CURRENCY.'%i', $agent_mobile->balance);
            $receiver_balance =money_format(CURRENCY.'%i', $receiving_wallet->balance);
            $sender_name  =  $agent_mobile->first_name.' '.$agent_mobile->last_name;
            $commission = money_format(CURRENCY.'%i',  $agent_mobile->commissions);

            SmsNotificationService::send(
                '2',
                $this->agent_mobile,
                "Cash-in of $amount into mobile $receiving_wallet->mobile was successful. New Float balance:$sender_balance Commissions balance:$commission",
                $this->recipient_mobile,
                "Cash-in of $amount was successful your new balance is $receiver_balance via Agent Name:$sender_name,Agent Code:$agent_mobile->business_code. Reference $this->reference",
                '',
                ''

            );


        }catch (\Exception $e){

            DB::rollBack();
            WalletTransactions::create([

                'txn_type_id'       => CASH_IN,
                'tax'               => '0.00',
                'revenue_fees'      => '0.00',
                'interchange_fees'  => '0.00',
                'zimswitch_fee'     => '0.00',
                'transaction_amount'=> '0.00',
                'total_debited'     => '0.00',
                'total_credited'    => '0.00',
                'batch_id'          => '',
                'switch_reference'  => '',
                'merchant_id'       => '',
                'transaction_status'=> 0,
                'pan'               => '',
                'description'       => 'Transaction was reversed for mobbile:' . $this->recipient_mobile,


            ]);

            SmsNotificationService::send(
                '1',
                '',
                '',
                '',
                '',
                $this->agent_mobile,
                "Transaction with reference: $this->reference failed and the reversal was successfully processed, please try again later. Thank you for using ". env('SMS_SENDER'). ' .'

            );


        }





    }


}
