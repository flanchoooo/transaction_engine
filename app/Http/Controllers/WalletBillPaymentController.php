<?php

namespace App\Http\Controllers;


use App\BRAccountID;
use App\BRClientInfo;
use App\Deduct;
use App\Jobs\NotifyBills;
use App\Jobs\WalletAgentBillPaymentJob;
use App\Jobs\WalletExclusiveBillPaymentJob;
use App\Jobs\WalletInclusiveBillPaymentJob;
use App\ManageValue;
use App\Services\SmsNotificationService;
use App\Services\WalletFeesCalculatorService;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use http\Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;



class WalletBillPaymentController extends Controller
{
    public function paybill_copy(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        //Declarations
        $biller_account = Wallet::whereMobile($request->biller_account);
        $source = Wallet::whereMobile($request->source_mobile);
        $transaction_amount = $request->amount / 100;


        //Wallet Checks
        $biller_mobile = $biller_account->lockForUpdate()->first();
        if (!isset($biller_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Biller account mobile not registered.',
            ]);
        }

        $source_mobile = $source->lockForUpdate()->first();
        if (!isset($source_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Source mobile not registered.',
            ]);
        }

        if ($source_mobile->state == '0'){
            return response([
                'code' => '02',
                'description' => 'Source account is blocked',
            ]);
        }


        //Class of Service Checks
        $this->limit_checker($request->source_mobile,$source_mobile->wallet_cos_id);


        if(!isset($request->agent_mobile)){
            $wallet_fees = WalletFeesCalculatorService::calculateFees(
                $transaction_amount,
                $request->bill_payment_id

            );

            if($wallet_fees['fee_type'] == 'INCLUSIVE'){
                return response([
                    'code' => '01',
                    'description' => 'Transaction is currently not supported. Configure your fees to EXCLUSIVE profile.',
                ]);

                /*
                if($source_mobile->wallet_type != 'BILLER'){
                    if ($transaction_amount > $source_mobile->balance) {
                        return response([
                            'code' => '116',
                            'description' => 'Insufficient Funds',
                        ]);
                    }

                }



                $reference = '22'. $this->genRandomNumber();
                dispatch(new WalletInclusiveBillPaymentJob(
                    $request->source_mobile,
                    $request->biller_account,
                    $transaction_amount,
                    $wallet_fees['individual_fee'],
                    $wallet_fees['tax'],
                    $reference,
                    $request->bill_payment_id
                ));

                return response([
                    'code'                  => '000',
                    'transaction_batch_id'  => "$reference",
                    'description'           => 'Success'
                ]);

                */
            }



            if($wallet_fees['fee_type'] == 'EXCLUSIVE'){

                if($source_mobile->wallet_type != 'BILLER'){
                    if ($transaction_amount > $source_mobile->balance) {
                        return response([
                            'code' => '116',
                            'description' => 'Insufficient Funds',
                        ]);
                    }

                }


                $reference = '23'. $this->genRandomNumber();
                dispatch(new WalletExclusiveBillPaymentJob(
                    $request->source_mobile,
                    $request->biller_account,
                    $transaction_amount,
                    $wallet_fees['fee'],
                    $wallet_fees['tax'],
                    $reference,
                    $request->bill_payment_id
                ));


                return response([
                    'code'          => '000',
                    'batch_id'      => "$reference",
                    'tax'           =>  $wallet_fees['tax'],
                    'revenue'       =>  $wallet_fees['fee'],
                    'transaction_batch_id' => "$reference",
                    'description'   => 'Success'
                ]);



            }

        }





    }

    public function paybill(Request $request){
       // return $request->all();
        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        //Declarations
        $biller_account = Wallet::whereMobile($request->biller_account);
        $source = Wallet::whereMobile($request->source_mobile);
        $transaction_amount = $request->amount / 100;


        //Wallet Checks
        $biller_mobile = $biller_account->lockForUpdate()->first();
        if (!isset($biller_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Biller account mobile not registered.',
            ]);
        }

        $source_mobile = $source->lockForUpdate()->first();
        if (!isset($source_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Source mobile not registered.',
            ]);
        }

        if ($source_mobile->state == '0'){
            return response([
                'code' => '02',
                'description' => 'Source account is blocked',
            ]);
        }


        //Class of Service Checks
        $this->limit_checker($request->source_mobile,$source_mobile->wallet_cos_id);
        if(!isset($request->agent_mobile)){
             $wallet_fees = WalletFeesCalculatorService::calculateFees(
                $transaction_amount,
                $request->bill_payment_id

            );

             $account =  BRAccountID::where('Mobile', $request->source_mobile)->first();
           if(isset($account)) {
               if($request->bill_reference ==  $account->AccountID){
                   $tax = 0;
               }else{
                   $tax = $wallet_fees['tax'];
               }
           }else{
               $tax = $wallet_fees['tax'];
           }

            if($wallet_fees['fee_type'] == 'EXCLUSIVE'){

                $total_deduction = $transaction_amount + $wallet_fees['tax']  + $wallet_fees['fee'] ;
                if($source_mobile->wallet_type != 'BILLER'){
                    if ($total_deduction > $source_mobile->balance) {
                        return response([
                            'code' => '116',
                            'description' => 'Insufficient Funds',
                        ]);
                    }

                }

                $reference = '23'. $this->genRandomNumber();
                $result = $this->processBillPayment($request->source_mobile,$request->biller_account,$transaction_amount,$wallet_fees['fee'],$tax,$reference, $request->bill_payment_id,$request->bill_reference);

                if($result['code'] != '00'){
                    return response([
                        'code'          => '01',
                        'description'      => "Failed to process transaction",
                       ]);

                }

                return response([
                    'code'          => '000',
                    'batch_id'      => "$reference",
                    'tax'           =>  $tax,
                    'revenue'       =>  $wallet_fees['fee'],
                    'transaction_batch_id' => "$reference",
                    'description'   => 'Success'
                ]);

            }
        }

    }


    public function processBillPayment($source_mobile,$biller_mobile,$transaction_amount,$fee,$tax_fee,$reference,$bill_payment_id,$bill_reference){
       // return $fee;
        DB::beginTransaction();
        try {

            $source      = Wallet::whereMobile($source_mobile);
            $biller     = Wallet::whereMobile($biller_mobile);

            $source_account = $source->lockForUpdate()->first();
            if($bill_payment_id == BANK_TO_WALLET){

                $biller_account =  $biller->lockForUpdate()->first();
                $biller_account->balance += $transaction_amount;
                $biller_account->save();

                $biller_balance                 = $biller_account->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = $bill_payment_id;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = '0.00';
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $transaction_amount;
                $transaction->total_debited     = '0.00';
                $transaction->total_credited    = $transaction_amount;
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $source_mobile;
                $transaction->account_credited  = $biller_mobile;
                $transaction->balance_after_txn = $biller_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                DB::commit();
                return array(
                    'code' => '00'
                );


            }


            if($source_account->wallet_type != 'BILLER'){
                $source_account = $source->lockForUpdate()->first();
                $source_account->balance-= $transaction_amount + $fee + $tax_fee;
                $source_account->save();


                $agent_new_balance              = $source_account->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = $bill_payment_id;
                $transaction->tax               =  $tax_fee;
                $transaction->revenue_fees      = $fee;
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $transaction_amount;
                $transaction->total_debited     = $transaction_amount + $fee + $tax_fee;
                $transaction->account_debited   = $source_mobile;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $bill_reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_credited  = $biller_mobile;
                $transaction->balance_after_txn = $agent_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();



                //BR Settlement
               /* $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $tax_fee;
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
                $auto_deduction->amount = $fee;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = REVENUE;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->description = 'Revenue settlement via wallet:'.$reference;
                $auto_deduction->save();

               */


                DB::commit();

                return array(
                    'code' => '00'
                );

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
                'txn_type_id'       => $bill_payment_id,
                'description'       => 'Transaction was reversed for mobile:' . $source_mobile,
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

            return array(
                'code' => '01'
            );


        }





    }




    public function genRandomNumber($length = 10, $formatted = false){
        $nums = '0123456789';
        // First number shouldn't be zero
        $out = $nums[ mt_rand(1, strlen($nums) - 1) ];
        // Add random numbers to your string
        for ($p = 0; $p < $length - 1; $p++)
            $out .= $nums[ mt_rand(0, strlen($nums) - 1) ];
        // Format the output with commas if needed, otherwise plain output
        if ($formatted)
            return number_format($out);
        return $out;
    }

    public function limit_checker($source_mobile,$wallet_cos_id){

        //Check Daily Spent
        $daily_spent =  WalletTransactions::where('account_debited',$source_mobile)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');

        //Check Monthly Spent
        $monthly_spent =  WalletTransactions::where('account_debited',$source_mobile)
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->sum('transaction_amount');


        $wallet_cos = WalletCOS::find($wallet_cos_id);
        if($wallet_cos->maximum_daily <  $daily_spent){
            return response([
                'code' => '902',
                'description' => 'Daily limit reached'
            ]);
        }

        if($wallet_cos->maximum_monthly <  $monthly_spent){
            return response([
                'code' => '902',
                'description' => 'Monthly limit reached'
            ]);
        }

    }

    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'bill_payment_id' => 'required',
            'bill_reference' => 'required',
            'biller_account' => 'required',


        ]);


    }


}

