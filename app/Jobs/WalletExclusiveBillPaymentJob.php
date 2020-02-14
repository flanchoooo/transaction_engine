<?php

namespace App\Jobs;
use App\Deduct;
use App\ManageValue;
use App\Services\SmsNotificationService;
use App\TransactionType;
use App\Wallet;
use App\WalletTransactions;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletExclusiveBillPaymentJob extends Job
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

            $source_account = $source->lockForUpdate()->first();
            if($source_account->wallet_type == 'BILLER'){
                $tax_account = $tax->lockForUpdate()->first();
                $tax_account->balance += $this->tax;
                $tax_account->save();

                $revenue_account = $revenue->lockForUpdate()->first();
                $revenue_account->balance += $this->fee;
                $revenue_account->save();

                $source_account = $source->lockForUpdate()->first();
                $source_account->balance-= $this->transaction_amount + $this->fee;
                $source_account->save();

                $biller_account =  $biller->lockForUpdate()->first();
                $biller_account->balance += $this->transaction_amount;
                $biller_account->save();

                $source_      = $source->lockForUpdate()->first();
                $source_->balance += $this->transaction_amount;
                $source_->save();


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
                $transaction->total_credited    = $this->transaction_amount;
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $this->source_mobile;
                $transaction->account_credited  = $this->biller_mobile;
                $transaction->balance_after_txn = $biller_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                $value_management                   = new ManageValue();
                $value_management->account_number   = $this->biller_mobile;
                $value_management->amount           = $this->transaction_amount;
                $value_management->txn_type         = CREATE_VALUE;
                $value_management->state            = 1;
                $value_management->initiated_by     = 3;
                $value_management->validated_by     = 3;
                $value_management->narration        = 'Create Value';
                $value_management->description      = 'Create Value on trust account funding'. $reference ;
                $value_management->save();


                DB::commit();

               /* $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
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

               */

            }

            if($source_account->wallet_type != 'BILLER'){

                $tax_account = $tax->lockForUpdate()->first();
                $tax_account->balance += $this->tax;
                $tax_account->save();

                $revenue_account = $revenue->lockForUpdate()->first();
                $revenue_account->balance += $this->fee;
                $revenue_account->save();

                $source_account = $source->lockForUpdate()->first();
                $source_account->balance-= $this->transaction_amount + $this->fee;
                $source_account->save();

                $biller_account =  $biller->lockForUpdate()->first();
                $biller_account->balance += $this->transaction_amount;
                $biller_account->save();

                $biller_account =  $biller->lockForUpdate()->first();
                $biller_account->balance -= $this->transaction_amount;
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
                $transaction->total_credited    = $this->transaction_amount;
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $this->source_mobile;
                $transaction->account_credited  = $this->biller_mobile;
                $transaction->balance_after_txn = $biller_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                $value_management                   = new ManageValue();
                $value_management->account_number   = $this->biller_mobile;
                $value_management->amount           = $this->transaction_amount;
                $value_management->txn_type         = DESTROY_E_VALUE;
                $value_management->state            = 1;
                $value_management->initiated_by     = 3;
                $value_management->validated_by     = 3;
                $value_management->narration        = 'Destroy E-Value';
                $value_management->description      = 'Destroy E-Value on bill payment settlment'. $reference ;
                $value_management->save();

                $value_management                   = new ManageValue();
                $value_management->account_number   = $this->biller_mobile;
                $value_management->amount           = $this->transaction_amount;
                $value_management->txn_type         = DESTROY_E_VALUE;
                $value_management->state            = 1;
                $value_management->initiated_by     = 3;
                $value_management->validated_by     = 3;
                $value_management->narration        = 'Destroy E-Value';
                $value_management->description      = 'Destroy E-Value on bill payment settlment'. $reference ;
                $value_management->save();

                //BR Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $this->tax;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = TAX;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->description = 'Tax settlement via wallet:'.$reference;
                $auto_deduction->save();

                //BR Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $this->fee;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = REVENUE;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->description = 'Revenue settlement via wallet:'.$reference;
                $auto_deduction->save();



                DB::commit();

               /* $amount = money_format(CURRENCY.'%i', $this->transaction_amount);
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
               */


            }



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
                'description'       => 'Transaction was reversed for mobile:' . $this->agent_mobile,
            ]);

           /* SmsNotificationService::send(
                '1',
                '',
                '',
                '',
                '',
                $this->source_mobile,
                "Transaction with reference: $this->reference failed and the reversal was successfully processed, please try again later. Thank you for using ". env('SMS_SENDER'). ' .'

            );
           */


        }





    }


}
