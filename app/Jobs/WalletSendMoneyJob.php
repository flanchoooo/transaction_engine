<?php

namespace App\Jobs;
use App\License;
use App\ManageValue;
use App\Services\SmsNotificationService;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;

class WalletSendMoneyJob extends Job
{

    protected $source;
    protected $destination;
    protected $transaction_amount;
    protected $tax;
    protected $fee;
    protected $reference;
    protected $deductible_amount;




    public function __construct($source,$destination,$transaction_amount,$tax,$fee,$reference,$deductible_amount){
        //
        $this->source             = $source;
        $this->destination        = $destination;
        $this->transaction_amount = $transaction_amount;
        $this->tax                = $tax;
        $this->fee                = $fee;
        $this->reference          = $reference;
        $this->deductible_amount  = $deductible_amount;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        DB::beginTransaction();
        try {

            $sender     = Wallet::whereMobile($this->source);
            $revenue    = Wallet::whereMobile(WALLET_REVENUE);
            $tax        = Wallet::whereMobile(WALLET_TAX);
            $recipient  = Wallet::whereMobile($this->destination);

            $tax_account = $tax->lockForUpdate()->first();
            $tax_account->balance += $this->tax;
            $tax_account->save();

            $tax_account = $tax->lockForUpdate()->first();
            $tax_account->balance -= $this->tax;
            $tax_account->save();

            $revenue_account = $revenue->lockForUpdate()->first();
            $revenue_account->balance += $this->fee;
            $revenue_account->save();

            $revenue_account = $revenue->lockForUpdate()->first();
            $revenue_account->balance -= $this->fee;
            $revenue_account->save();

            $sender_account = $sender->lockForUpdate()->first();
            $sender_account->balance-=$this->deductible_amount;
            $sender_account->save();


            $recipient_account =  $recipient->lockForUpdate()->first();
            $recipient_account->balance += $this->transaction_amount;
            $recipient_account->save();

            $source_new_balance             = $sender_account->balance;
            $reference                      = $this->reference;
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = SEND_MONEY;
            $transaction->tax               =  $this->tax;
            $transaction->revenue_fees      =  $this->fee;
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = $this->deductible_amount;
            $transaction->total_credited    = '0.00';
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $this->source;
            $transaction->account_credited  = $this->destination;
            $transaction->balance_after_txn = $source_new_balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();

            $source_new_balance_             = $recipient_account->balance;
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = MONEY_RECEIVED;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $this->transaction_amount;
            $transaction->total_debited     = '0.00';
            $transaction->total_credited    = $this->transaction_amount;
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $this->source;
            $transaction->account_credited  = $this->destination;
            $transaction->balance_after_txn = $source_new_balance_;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();

            $value_management                   = new ManageValue();
            $value_management->account_number   = $tax_account->mobile;
            $value_management->amount           = $this->tax;
            $value_management->txn_type         = DESTROY_E_VALUE;
            $value_management->state            = 1;
            $value_management->initiated_by     = 3;
            $value_management->validated_by     = 3;
            $value_management->narration        = 'Destroy E-Value';
            $value_management->description      = 'Destroy E-Value on tax settlement'. $reference ;
            $value_management->save();

            $value_management                   = new ManageValue();
            $value_management->account_number   = $tax_account->mobile;
            $value_management->amount           = $this->tax;
            $value_management->txn_type         = DESTROY_E_VALUE;
            $value_management->state            = 1;
            $value_management->initiated_by     = 3;
            $value_management->validated_by     = 3;
            $value_management->narration        = 'Destroy E-Value';
            $value_management->description      = 'Destroy E-Value on revenue settlement'. $reference ;
            $value_management->save();


            DB::commit();

            /*
            $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
            $sender_balance = money_format(CURRENCY.'%i', $source_new_balance);
            $receiver_balance =money_format(CURRENCY.'%i', $source_new_balance_);
            $sender_name  =  $sender_account->first_name.' '.$sender_account->last_name;
            $receiver_name = $recipient_account->first_name.' '.$recipient_account->last_name;

           SmsNotificationService::send(
                '2',
                $this->source,
                "Transfer to $receiver_name  of $amount was successful. New wallet balance  $sender_balance. Reference:$reference. Thank you for using ".env('SMS_SENDER').'.',
                $this->destination,
                "You have received  $amount from  $sender_name. New wallet balance  $receiver_balance. Reference:$reference. Thank you for using ". env('SMS_SENDER').'.',
                '',
                ''

            );*/

        }catch (\Exception $e){

            DB::rollBack();
            WalletTransactions::create([
                'txn_type_id'       => SEND_MONEY,
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
                'description'       => 'Transaction was reversed for mobile:' . $this->source,
            ]);

           /* SmsNotificationService::send(
                '1',
                '',
                '',
                '',
                '',
                $this->source,
                "Transaction with reference: $this->reference failed and the reversal was successfully processed, please try again later. Thank you for using ". env('SMS_SENDER'). ' .'

            );
           */

        }


    }


}
