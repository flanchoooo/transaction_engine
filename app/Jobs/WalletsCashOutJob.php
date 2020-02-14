<?php

namespace App\Jobs;

use App\Services\SmsNotificationService;
use App\Wallet;
use App\WalletTransactions;
use Illuminate\Support\Facades\DB;

class WalletsCashOutJob extends Job
{

    protected $agent_mobile;
    protected $revenue_mobile;
    protected $source_mobile;
    protected $transaction_amount;
    protected $exclusive_revenue_portion;
    protected $exclusive_agent_portion;
    protected $reference;


    public function __construct($source_mobile,$exclusive_agent_portion,$exclusive_revenue_portion,$agent_mobile,$revenue_mobile,$transaction_amount,$reference){

        $this->agent_mobile       = $agent_mobile;
        $this->revenue_mobile     = $revenue_mobile;
        $this->source_mobile   = $source_mobile;
        $this->transaction_amount = $transaction_amount;
        $this->reference          = $reference;
        $this->exclusive_revenue_portion  = $exclusive_revenue_portion;
        $this->exclusive_agent_portion    = $exclusive_agent_portion;


    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        DB::beginTransaction();
        try {

            $agent_account          = Wallet::whereBusinessCode($this->agent_mobile);
            $revenue_account        = Wallet::whereMobile($this->revenue_mobile);
            $source_mobile          = Wallet::whereMobile($this->source_mobile);


            $total_deductions = $this->transaction_amount + $this->exclusive_revenue_portion + $this->exclusive_agent_portion;
            $source_mobile = $source_mobile->lockForUpdate()->first();
            $source_mobile->balance -= $total_deductions;
            $source_mobile->save();

            $revenue_mobile = $revenue_account->lockForUpdate()->first();
            $revenue_mobile->balance += $this->exclusive_revenue_portion;
            $revenue_mobile->save();

            $agent_mobile = $agent_account->lockForUpdate()->first();
            $agent_mobile->balance += $this->transaction_amount;
            $agent_mobile->commissions += $this->exclusive_agent_portion;
            $agent_mobile->save();


            $reference = $this->reference;
            $transaction = new WalletTransactions();
            $transaction->txn_type_id        = CASH_OUT;
            $transaction->tax                = '0.00';
            $transaction->revenue_fees       = $this->exclusive_revenue_portion;
            $transaction->zimswitch_fee      = '0.00';
            $transaction->transaction_amount = $this->transaction_amount;
            $transaction->total_debited      = $total_deductions;
            $transaction->total_credited     = '0.00';
            $transaction->switch_reference   = $reference;
            $transaction->batch_id           = $reference;
            $transaction->transaction_status = 1;
            $transaction->account_debited    = $this->source_mobile;
            $transaction->account_credited   = $this->agent_mobile;
            $transaction->balance_after_txn  = $source_mobile->balance;
            $transaction->description        = 'Transaction successfully processed.';
            $transaction->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_OUT;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = '';
            $transaction->total_credited    = $this->transaction_amount;
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $this->source_mobile;
            $transaction->account_credited  = $this->agent_mobile;
            $transaction->balance_after_txn = $agent_mobile->balance;
            $transaction->description = 'Transaction successfully processed.';
            $transaction->save();

            DB::commit();



            $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
            $sender_balance = money_format(CURRENCY.'%i', $source_mobile->balance);
            $receiver_balance =money_format(CURRENCY.'%i', $agent_mobile->balance);
            $sender_name  =  $source_mobile->first_name.' '.$source_mobile->last_name;
            $commission = money_format(CURRENCY.'%i',  $agent_mobile->commissions);

            SmsNotificationService::send(
                '2',
                $source_mobile->mobile,
                "Cash-out from Agent:$agent_mobile->first_name of $amount was successful. Your new balance is $sender_balance. Thank you for using".' '.env('SMS_SENDER').'.',
                $agent_mobile->mobile,
                "$sender_name  with mobile: $source_mobile->mobile, successfully cashed out $amount. Your new balance:$receiver_balance, Commission$commission",
                '',
                ''


            );

        }catch (\Exception $e){
            DB::rollBack();
            WalletTransactions::create([
                'txn_type_id'       => CASH_OUT,
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
                'description'       => 'Transaction was reversed for mobile:' . $this->source_mobile,
            ]);

        }
    }


}
