<?php

namespace App\Http\Controllers;


use App\Jobs\NotifyBills;
use App\Jobs\WalletsCashOutJob;
use App\Services\WalletFeesCalculatorService;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;



class WalletCashOutController extends Controller
{


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


    public function cash_out_(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        DB::beginTransaction();
        try {


            $source = Wallet::whereMobile($request->source_mobile);
            $agent = Wallet::whereMobile($request->agent_mobile);
            $revenue = Wallet::whereMobile(WALLET_REVENUE);
            $tax = Wallet::whereMobile(WALLET_TAX);


            $biller_mobile = $agent->lockForUpdate()->first();
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

            if ($source_mobile->state == '0') {
                return response([

                    'code' => '02',
                    'description' => 'Source account is blocked',

                ]);


            }


            //Check Daily Spent
            $daily_spent = WalletTransactions::where('account_debited', $source_mobile->mobile)
                ->where('created_at', '>', Carbon::now()->subDays(1))
                ->sum('transaction_amount');

            //Check Monthly Spent
            $monthly_spent = WalletTransactions::where('account_debited', $source_mobile->mobile)
                ->where('created_at', '>', Carbon::now()->subDays(30))
                ->sum('transaction_amount');


            $wallet_cos = WalletCOS::find($source_mobile->wallet_cos_id);
            if ($wallet_cos->maximum_daily < $daily_spent) {
                return response([

                    'code' => '03',
                    'description' => 'Daily limit reached'

                ]);
            }


            if ($wallet_cos->maximum_monthly < $monthly_spent) {
                return response([

                    'code' => '04',
                    'description' => 'Monthly limit reached'

                ]);
            }

            $amount_in_cents = $request->amount / 100;

            //Calculate Fees
             $wallet_fees = WalletFeesCalculatorService::calculateFees(
                $amount_in_cents, CASH_OUT
            );



           /*
            * Bill Payment using Cash
            */
                $total_deductions = $amount_in_cents + $wallet_fees['fee'];
                if ($total_deductions > $source_mobile->balance) {
                    return response([

                        'code' => '116',
                        'description' => 'insufficient funds',

                    ]);

                }

                    //debit source with cash + fees

                    $source_mobile->balance -= $total_deductions;
                    $source_mobile->save();

                    //credit revenue
                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance += $wallet_fees['exclusive_revenue_portion'];
                    $revenue_mobile->save();


                    //credit agent with cash amount + fees
                    $agent_mobile = $agent->lockForUpdate()->first();
                    $agent_mobile->balance += $amount_in_cents;
                    $agent_mobile->commissions += $wallet_fees['exclusive_agent_portion'];
                    $agent_mobile->save();


                    $time_stamp = Carbon::now()->format('ymdhis');
                    $reference = '24' . $time_stamp;

                    $transaction = new WalletTransactions();
                    $transaction->txn_type_id = CASH_OUT;
                    $transaction->tax = '-' . $wallet_fees['tax'];
                    $transaction->revenue_fees = '-' . $wallet_fees['exclusive_revenue_portion'];
                    $transaction->zimswitch_fee = '0.00';
                    $transaction->transaction_amount = $amount_in_cents;
                    $transaction->total_debited = '-' . $amount_in_cents + $wallet_fees['fee'];
                    $transaction->total_credited = '0.00';
                    $transaction->switch_reference = $reference;
                    $transaction->batch_id = $reference;
                    $transaction->transaction_status = 1;
                    $transaction->account_debited = $request->agent_mobile;
                    $transaction->account_credited = $request->biller_account;
                    $transaction->balance_after_txn = $source_mobile->balance;
                    $transaction->description = 'Transaction successfully processed.';
                    $transaction->save();

                    $transaction = new WalletTransactions();
                    $transaction->txn_type_id = CASH_OUT;
                    $transaction->tax = '0.00';
                    $transaction->revenue_fees = '0.00';
                    $transaction->zimswitch_fee = '0.00';
                    $transaction->transaction_amount = $amount_in_cents;
                    $transaction->total_debited = '';
                    $transaction->total_credited = '0.00';
                    $transaction->switch_reference = $reference;
                    $transaction->batch_id = $reference;
                    $transaction->transaction_status = 1;
                    $transaction->account_debited =  $request->agent_mobile;
                    $transaction->account_credited = $request->biller_account;
                    $transaction->balance_after_txn = $biller_mobile->balance;
                    $transaction->description = 'Transaction successfully processed.';
                    $transaction->save();
                    DB::commit();


            $amount = money_format('$%i',  $request->amount / 100);
            $commission = money_format('$%i',  $biller_mobile->commissions);

            dispatch(new NotifyBills(
                    $agent_mobile->mobile,
                    "Cash-out of ZWL $amount was successful your new balance is  $source_mobile->balance. Reference $reference",
                    'eBucks',
                    $agent_mobile->mobile,
                    "Cash-out of ZWL $amount into mobile $source_mobile->mobile was successful. New Float balance:  ZWL $agent_mobile->balance Commissions balance: ZWL  $commission",
                    '2'
                )
            );


            return response([
                        'code' => '000',
                        'batch_id' => "$reference",
                        'description' => 'Success'

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

    public function cash_out(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        $source = Wallet::whereMobile($request->source_mobile);
        $agent = Wallet::whereBusinessCode($request->agent_mobile);

        $biller_mobile = $agent->lockForUpdate()->first();
        if (!isset($biller_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Agent account is not registered.',

            ]);
        }

        $source_mobile = $source->lockForUpdate()->first();
        if (!isset($source_mobile)) {
            return response([
                'code' => '01',
                'description' => 'Source mobile not registered.',
            ]);
        }

        if ($source_mobile->state == '0') {
            return response([
                'code' => '02',
                'description' => 'Source account is blocked',
            ]);
        }

        if($biller_mobile->id ==  $source_mobile->id){
            return response([
                'code' => '01',
                'description' => 'You cannot perform a cash-out from your own account',
            ]);
        }

        $this->limit_checker($source_mobile->mobile,$source_mobile->wallet_cos_id);
        $transaction_amount = $request->amount/100;
        $wallet_fees = WalletFeesCalculatorService::calculateFees(
            $transaction_amount, CASH_OUT
        );


        /*
         * Bill Payment using Cash
         */
        $total_deductions = $transaction_amount + $wallet_fees['fee'];
        if ($total_deductions > $source_mobile->balance) {
            return response([
                'code' => '116',
                'description' => 'Insufficient funds',
            ]);
        }

        $reference = '30'. $this->genRandomNumber();
        dispatch(new WalletsCashOutJob(
            $request->source_mobile,
            $wallet_fees['exclusive_agent_portion'],
            $wallet_fees['exclusive_revenue_portion'],
            $request->agent_mobile,
            WALLET_REVENUE,
            $transaction_amount,
            $reference

        ));




        return response([
            'code' => '000',
            'batch_id' => "$reference",
            'description' => 'Success'

        ]);






    }




    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'agent_mobile' => 'required',


        ]);


    }


}

