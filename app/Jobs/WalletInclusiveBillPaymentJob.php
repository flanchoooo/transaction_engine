<?php

namespace App\Jobs;
use App\Services\SmsNotificationService;
use App\TransactionType;
use App\Wallet;
use App\WalletTransactions;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletInclusiveBillPaymentJob extends Job
{



    protected  $source_mobile;
    protected  $biller_mobile;
    protected  $transaction_amount;
    protected  $fee;
    protected  $tax;
    protected  $reference;
    protected  $bill_payment_id;







    public function __construct($source_mobile,$biller_mobile,$transaction_amount,$fee,$tax,$reference,$bill_payment_id){

        $this->source_mobile                = $source_mobile;
        $this->biller_mobile                = $biller_mobile;
        $this->transaction_amount           = $transaction_amount;
        $this->fee                          = $fee;
        $this->tax                          = $tax;
        $this->reference                    = $reference;
        $this->bill_payment_id              = $bill_payment_id;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        DB::beginTransaction();
        try {

            $source      = Wallet::whereMobile($this->source_mobile);
            $revenue    = Wallet::whereMobile(WALLET_REVENUE);
            $tax        = Wallet::whereMobile(WALLET_TAX);
            $biller     = Wallet::whereMobile($this->biller_mobile);


            $tax_account = $tax->lockForUpdate()->first();
            $tax_account->balance += $this->tax;
            $tax_account->save();

            $revenue_account = $revenue->lockForUpdate()->first();
            $revenue_account->balance += $this->fee;
            $revenue_account->save();

            $source_account = $source->lockForUpdate()->first();
            $source_account->balance-= $this->transaction_amount;
            $source_account->save();


            $ttransaction = $this->transaction_amount - $this->fee;
            $biller_account =  $biller->lockForUpdate()->first();
            $biller_account->balance += $ttransaction;
            $biller_account->save();


            $agent_new_balance              = $source_account->balance;
            $reference                      = $this->reference;
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = $this->bill_payment_id;
            $transaction->tax               =  $this->tax;
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = $this->transaction_amount;
            $transaction->account_debited   = $this->source_mobile;
            $transaction->total_credited    = '0.00';
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_credited  = $this->biller_mobile;
            $transaction->balance_after_txn = $agent_new_balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();

            $biller_balance                 = $biller_account->balance;
          $transaction                    = new WalletTransactions();
          $transaction->txn_type_id       = $this->bill_payment_id;
          $transaction->tax               = '0.00';
          $transaction->revenue_fees      = '0.00';
          $transaction->zimswitch_fee     = '0.00';
          $transaction->transaction_amount= $this->transaction_amount;
          $transaction->total_debited     = '0.00';
          $transaction->total_credited    = $ttransaction;
          $transaction->switch_reference  = $reference;
          $transaction->batch_id          = $reference;
          $transaction->transaction_status= 1;
          $transaction->account_debited   = $this->source_mobile;
          $transaction->account_credited  = $this->biller_mobile;
          $transaction->balance_after_txn = $biller_balance;
          $transaction->description       = 'Transaction successfully processed.';
          $transaction->save();


            DB::commit();

            $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
            $sender_balance = money_format(CURRENCY.'%i', $source_account->balance);
            $bill = TransactionType::find($this->bill_payment_id)->name;


            SmsNotificationService::send(
                '1',
                '',
                '',
                '',
                '',
                $this->source_mobile,
                "You have successfully paid for $bill worth $amount, your new balance is : $sender_balance Thank you for using ". env('SMS_SENDER'). ' .'

            );


        }catch (Exception $e){

            DB::rollBack();
            WalletTransactions::create([
                'txn_type_id'       => $this->bill_payment_id,
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
                'description'       => 'Transaction was reversed for mobbile:' . $this->agent_mobile,
            ]);


        }





    }


}
