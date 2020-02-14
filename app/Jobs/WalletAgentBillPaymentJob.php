<?php

namespace App\Jobs;
use App\Wallet;
use App\WalletTransactions;
use Illuminate\Support\Facades\DB;

class WalletAgentBillPaymentJob extends Job
{



    protected  $agent_mobile;
    protected  $biller_mobile;
    protected  $transaction_amount;
    protected  $inclusive_agent_portion;
    protected  $inclusive_revenue_portion;
    protected  $tax;
    protected  $reference;
    protected  $bill_payment_id;







    public function __construct($agent_mobile,$biller_mobile,$transaction_amount,$inclusive_agent_portion,$inclusive_revenue_portion,$tax,$reference,$bill_payment_id){

        $this->agent_mobile                 = $agent_mobile;
        $this->biller_mobile                = $biller_mobile;
        $this->transaction_amount           = $transaction_amount;
        $this->inclusive_agent_portion      = $inclusive_agent_portion;
        $this->inclusive_revenue_portion    = $inclusive_revenue_portion;
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

            $agent      = Wallet::whereMobile($this->agent_mobile);
            $revenue    = Wallet::whereMobile(WALLET_REVENUE);
            $tax        = Wallet::whereMobile(WALLET_TAX);
            $biller     = Wallet::whereMobile($this->biller_mobile);

            $tax_account = $tax->lockForUpdate()->first();
            $tax_account->balance += $this->tax;
            $tax_account->save();

            $revenue_account = $revenue->lockForUpdate()->first();
            $revenue_account->balance += $this->inclusive_revenue_portion;
            $revenue_account->save();

            $agent_account = $agent->lockForUpdate()->first();
            $agent_account->balance-=$this->transaction_amount;
            $agent_account->commissions+=$this->inclusive_agent_portion;
            $agent_account->save();

            $total = $this->inclusive_agent_portion + $this->inclusive_revenue_portion + $this->tax;
            $ttransaction = $this->transaction_amount - $total;
            $biller_account =  $biller->lockForUpdate()->first();
            $biller_account->balance += $ttransaction;
            $biller_account->save();


            $agent_new_balance              = $agent_account->balance;
            $reference                      = $this->reference;
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = $this->bill_payment_id;
            $transaction->tax               =  $this->tax;
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = $this->transaction_amount;
            $transaction->account_debited   = $this->agent_mobile;
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
          $transaction->account_debited   = $this->agent_mobile;
          $transaction->account_credited  = $this->biller_mobile;
          $transaction->balance_after_txn = $biller_balance;
          $transaction->description       = 'Transaction successfully processed.';
          $transaction->save();


            DB::commit();


        }catch (\Exception $e){

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
