<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Jobs\NotifyBills;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\Jobs\WalletCashInJob;
use App\License;
use App\Merchant;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\SmsNotificationService;
use App\Services\TokenService;
use App\Services\WalletFeesCalculatorService;
use App\Transaction;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;




class WalletCashInController extends Controller
{





    public function cash_in_preauth(Request $request){

        $validator = $this->cash_in_preauth_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        //Declarations
        $destination = Wallet::where('mobile',$request->destination_mobile)->get()->first();
        $source = Wallet::where('mobile', $request->source_mobile)->get()->first();


        // Check if source is registered.
        if(!isset($source)){
            return response([

                'code' => '01',
                'description' => 'Source mobile not registered.',

            ]) ;

        }

        // Check if source is active.
        if($source->state == '0') {
            return response([
                'code' => '02',
                'description' => 'Source account is blocked',

            ]);
        }

        //Check destination mobile
        if(!isset($destination)){
            return response([

                'code' => '05',
                'description' => 'Destination mobile not registered.',

            ]) ;


        }

        //Check one is sending his or her self $
        if($source->mobile == $destination->mobile){
            return response([

                'code' => '07',
                'description' => 'Invalid transaction',

            ]);

        }

        //Return response for preauth.
        return response([

            'code' => '00',
            'description' =>'Pre-auth successful',
            'recipient_info' =>$destination,


        ]);

    }


    public function cash_in_(Request $request){

        $validator = $this->cash_in_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        DB::beginTransaction();
        try {

            $agent_account   = Wallet::whereMobile($request->source_mobile);
            $revenue_account     = Wallet::whereMobile(WALLET_REVENUE);
            $destination_mobile = Wallet::whereMobile($request->destination_mobile);



            $amount_in_cents =  $request->amount / 100;
            $wallet_fees = WalletFeesCalculatorService::calculateFees(
                $amount_in_cents, CASH_IN

            );

            $total_deductions = $wallet_fees['fee'];
            $agent_mobile = $agent_account->lockForUpdate()->first();
            if ($amount_in_cents > $agent_mobile->balance) {
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
                    'description'       => 'Agent: Insufficient funds for mobile:' .$request->source_mobile,


                ]);

                return response([
                    'code' => '116',
                    'description' => 'Agent:Insufficient funds',
                ]);
            }


            $revenue_mobile = $revenue_account->lockForUpdate()->first();
            if ($total_deductions > $revenue_mobile->balance) {
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
                    'description'       => 'Revenue:Insufficient funds for mobile:' . $request->account_number,


                ]);

                return response([
                    'code' => '116',
                    'description' => 'Revenue account has Insufficient funds',
                ]);
            }




            //Fee Deductions.
            $revenue_mobile->balance -=  $wallet_fees['fee'];
            $revenue_mobile->save();

            $agent_mobile->commissions += $wallet_fees['fee'];
            $agent_mobile->balance -= $amount_in_cents;
            $agent_mobile->save();

            $receiving_wallet = $destination_mobile->lockForUpdate()->first();
            $receiving_wallet->balance += $amount_in_cents;
            $receiving_wallet->save();



            $time_stamp                     = Carbon::now()->format('ymdhis');
            $reference                      = '88' . $time_stamp;
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_IN;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '-'.$wallet_fees['fee'];
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $amount_in_cents;
            $transaction->total_debited     = $amount_in_cents + $wallet_fees['fee'];
            $transaction->total_credited    = '0.00';
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $agent_mobile->mobile;
            $transaction->account_credited  = $receiving_wallet->mobile;
            $transaction->balance_after_txn = $agent_mobile->balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();


            //Credit Recipient with amount.
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_IN;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $amount_in_cents;
            $transaction->total_debited     = '0.00';
            $transaction->total_credited    = $amount_in_cents;
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = $agent_mobile->mobile;
            $transaction->account_credited  = $receiving_wallet->mobile;
            $transaction->balance_after_txn = $receiving_wallet->balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();


            $amount = money_format('$%i',  $request->amount / 100);
            $commission = money_format('$%i',  $agent_mobile->commissions);

            dispatch(new NotifyBills(
                    $receiving_wallet->mobile,
                    "Cash-in of ZWL $amount was successful your new balance is  $receiving_wallet->balance. Reference $reference",
                    'eBucks',
                    $agent_mobile->mobile,
                    "Cash-in of ZWL $amount into mobile $receiving_wallet->mobile was successful. New Float balance:  ZWL $agent_mobile->balance Commissions balance: ZWL  $commission",
                    '2'
                )
            );




            DB::commit();

            return response([

                'code'          => '000',
                'batch_id'      => "$reference",
                'description'   => 'Success'


            ]);


        } catch (\Exception $e) {

            //  return $e;
            DB::rollBack();
            Log::debug('Account Number:'.$request->account_number.' '. $e);

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
                'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,


            ]);



        }



    }

    public function cash_in(Request $request){

        $validator = $this->cash_in_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }



        DB::beginTransaction();
        try {


            $agent_account   = Wallet::whereMobile($request->source_mobile);
            $revenue_account     = Wallet::whereMobile(WALLET_CASH_IN_REVENUE);

            $amount_in_cents =  $request->amount / 100;
            $wallet_fees = WalletFeesCalculatorService::calculateFees(
                $amount_in_cents, CASH_IN

            );

            $total_deductions = $wallet_fees['fee'];
            $agent_mobile = $agent_account->lockForUpdate()->first();
            if ($amount_in_cents > $agent_mobile->balance) {
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
                    'description'       => 'Agent: Insufficient funds for mobile:' .$request->source_mobile,
                ]);

                return response([
                    'code' => '116',
                    'description' => 'Agent:Insufficient funds',
                ]);
            }


            if($agent_mobile->mobile ==  $request->destination_mobile){
                return response([

                    'code' => '07',
                    'description' => 'Invalid transaction',

                ]);

            }

            $revenue_mobile = $revenue_account->lockForUpdate()->first();
            if ($total_deductions > $revenue_mobile->balance) {
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
                    'description'       => 'Revenue:Insufficient funds for mobile:' . $request->account_number,


                ]);

                return response([
                    'code' => '116',
                    'description' => 'Revenue account has Insufficient funds',
                ]);
            }

            $reference                      = '20'.$this->genRandomNumber();
            dispatch(new WalletCashInJob(
                $request->source_mobile,
                WALLET_CASH_IN_REVENUE,
                $request->destination_mobile,
                $amount_in_cents,
                $wallet_fees['fee'],
                $reference
            ));

            DB::commit();

            return response([

                'code'          => '000',
                'batch_id'      => "$reference",
                'description'   => 'Success'


            ]);


        } catch (\Exception $e) {

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
                'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,


            ]);


            return response([

                'code' => '400',
                'description' => 'Transaction was reversed',

            ]);
        }


        // return 'Success';


        //Declarations

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


    protected function cash_in_preauth_validator(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile' => 'required',
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'pin' => 'required',

        ]);


    }

    protected function cash_in_validator(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile' => 'required',
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'pin' => 'required',

        ]);


    }




}

