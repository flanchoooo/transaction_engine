<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
use App\Deduct;
use App\Jobs\Notify;
use App\Jobs\NotifyBills;
use App\Jobs\SaveWalletTransaction;
use App\Jobs\SendMoneyJob;
use App\Jobs\WalletSendMoneyJob;
use App\License;
use App\ManageValue;
use App\Services\FeesCalculatorService;
use App\Services\SmsNotificationService;
use App\Services\TsambaService;
use App\Services\UniqueTxnId;
use App\Services\WalletFeesCalculatorService;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransaction;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;




class WalletSendMoneyController extends Controller
{




    public function send_money_preauth(Request $request){

         $validator = $this->wallet_preauth($request->all());
         if ($validator->fails()) {
           return response()->json(['code' => '99', 'description' => $validator->errors()]);

         }

         //Declarations
         $destination = Wallet::where('mobile',$request->destination_mobile)->get()->first();
         $source = Wallet::where('mobile', $request->source_mobile)->get()->first();

         if(!isset($source)){
             return response([
                'code' => '01',
                'description' => 'Source mobile not registered.',
            ]) ;

         }

         if($source->state == '0') {
             return response([
                 'code' => '02',
                 'description' => 'Source account is blocked',
             ]);
         }

        if(!isset($destination)){
            return response([
                'code' => '05',
                'description' => 'Destination mobile not registered.',
            ]) ;


        }

        if($source->mobile == $destination->mobile){
            return response([
                'code' => '07',
                'description' => 'Invalid transaction',
            ]);

        }


        return response([
            'code' => '00',
            'description' =>'Pre-auth successful',
            'recipient_info' =>$destination,
            ]);

    }

    public function send_money(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        if(WALLET_STATUS != 'ACTIVE'){
            return response([
                'code'          => '100',
                'description'   => 'Wallet service is temporarily unavailable',
            ]);
        }

        if($request->source_mobile == $request->destination_mobile ){
            return response([
                'code' => '100',
                'description' => 'Invalid Transaction',
            ]);
        }

            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereMobile($request->source_mobile);
                $destination_mobile = Wallet::whereMobile($request->destination_mobile);

                $amount_in_cents =  $request->amount / 100;
                $wallet_fees = WalletFeesCalculatorService::calculateFees(
                    $amount_in_cents, SEND_MONEY

                );

                if($amount_in_cents > $wallet_fees['maximum_daily']   ){
                    return response([
                        'code'          => '08',
                        'description'   => 'Amount exceed transactional limits.'
                    ]);
                }

                 $total_deductions = $amount_in_cents + $wallet_fees['fee'] + $wallet_fees['tax'];
                 $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($total_deductions > $fromAccount->balance) {
                    WalletTransactions::create([
                        'txn_type_id'       => SEND_MONEY,
                        'description'       => 'Insufficient funds for mobile:' . $request->account_number,
                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }

                //Check Daily Spent
                $daily_spent =  WalletTransactions::where('account_debited', $request->source_mobile)
                    ->where('created_at', '>', Carbon::now()->subDays(1))
                    ->sum('total_debited');

                //Check Monthly Spent
                $monthly_spent =  WalletTransactions::where('account_debited', $request->source_mobile)
                    ->where('created_at', '>', Carbon::now()->subDays(30))
                    ->sum('total_debited');


                $wallet_cos = WalletCOS::find($fromAccount->wallet_cos_id);
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

                if($wallet_cos->maximum_monthly <  $request->amount / 100){
                    return response([
                        'code' => '902',
                        'description' => 'Cannot perform transaction above monthly limit'
                    ]);
                }

                //Fee Deductions.
                $fromAccount->balance -= $total_deductions;
                $fromAccount->save();

                $receiving_wallet = $destination_mobile->lockForUpdate()->first();
                $receiving_wallet->balance += $amount_in_cents;
                $receiving_wallet->save();

                $source_new_balance             = $fromAccount->balance;
                $reference                      = UniqueTxnId::transaction_id();
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = SEND_MONEY;
                $transaction->tax               =  $wallet_fees['tax'];
                $transaction->revenue_fees      =  $wallet_fees['fee'];
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $amount_in_cents;
                $transaction->total_debited     = $total_deductions;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->source_mobile;
                $transaction->account_credited  = $request->destination_mobile;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                $source_new_balance_            = $receiving_wallet->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = MONEY_RECEIVED;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = '0.00';
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $amount_in_cents;
                $transaction->total_debited     = '0.00';
                $transaction->total_credited    = $amount_in_cents;
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->source_mobile;
                $transaction->account_credited  = $request->destination_mobile;
                $transaction->balance_after_txn = $source_new_balance_;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();



                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $wallet_fees['tax'];
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = TAX;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = UniqueTxnId::transaction_id();
                $br_job->narration = "WALLET |Tax settlement |$reference | $request->source_mobile";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $wallet_fees['fee'];
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = REVENUE;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = UniqueTxnId::transaction_id();
                $br_job->narration = "WALLET | Revenue settlement |$reference | $request->source_mobile";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


               DB::commit();

                /*$amount = money_format('$%i', $amount_in_cents);
                $sender_balance = money_format('$%i', $source_new_balance);
                $receiver_balance =money_format('$%i', $source_new_balance_);
                $sender_name  =  $fromAccount->first_name.' '.$fromAccount->last_name;

                $receiver_name = $receiving_wallet->first_name.' '.$receiving_wallet->last_name;
               dispatch(new NotifyBills(
                       $request->source_mobile,
                       "Transfer to $receiver_name  of $amount was successful. New wallet balance ZWL $sender_balance. Reference:$reference",
                       'GetBucks',
                       $request->destination_mobile,
                       "You have received  $amount from  $sender_name. New wallet balance ZWL $receiver_balance. Reference:$reference",
                       '2'
                   )
               );*/


                return response([
                    'code'          => '000',
                    'batch_id'      => "$reference",
                    'description'   => 'Success'
                ]);


            } catch (\Exception $e) {
                return $e;
                DB::rollBack();
                Log::debug('Account Number:'.$request->account_number.' '. $e);

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
                    'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,


                ]);

                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }



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



    protected function wallet_preauth(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile' => 'required',
            'source_mobile' => 'required',

        ]);


    }

    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile'    => 'required',
            'source_mobile'         => 'required',
            'amount'                => 'required|integer|min:0',


        ]);


    }


}

