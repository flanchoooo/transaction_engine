<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Jobs\Notify;
use App\Jobs\SaveWalletTransaction;
use App\License;
use App\Services\FeesCalculatorService;
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

    public function send_money(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }




            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereMobile($request->source_mobile);
                $toQuery     = Wallet::whereMobile(WALLET_REVENUE);
                $tax_account = Wallet::whereMobile(WALLET_TAX);
                $destion_mobile = Wallet::whereMobile($request->destination_mobile);



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
                    ->sum('transaction_amount');

                //Check Monthly Spent
                $monthly_spent =  WalletTransactions::where('account_debited', $request->source_mobile)
                    ->where('created_at', '>', Carbon::now()->subDays(30))
                    ->sum('transaction_amount');




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





                //Fee Deductions.

                $tax = $tax_account->lockForUpdate()->first();
                $tax->balance += $wallet_fees['tax'];
                $tax->save();
                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance +=  $wallet_fees['fee'];
                $toAccount->save();
                $fromAccount->balance -= $total_deductions;
                $fromAccount->save();

                $receiving_wallet = $destion_mobile->lockForUpdate()->first();
                $receiving_wallet->balance += $amount_in_cents;
                $receiving_wallet->save();


                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '56' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = SEND_MONEY;
                $transaction->tax               =  $wallet_fees['tax'];
                $transaction->revenue_fees      = $wallet_fees['fee'];
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

                $source_new_balance_             = $receiving_wallet->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '56' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = SEND_MONEY;
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




                DB::commit();


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


            // return 'Success';


        //Declarations
        $destination    = Wallet::where('mobile',$request->destination_mobile)->get()->first();
        $source         = Wallet::where('mobile', $request->source_mobile)->get()->first();
        $revenue        = Wallet::where('mobile', Accounts::find(8)->account_number)->get()->first();
        $tax            = Wallet::where('mobile', Accounts::find(10)->account_number)->get()->first();



        // Check if source is registered.
        if(!isset($source)){
            return response([
                'code'          => '01',
                'description'   => 'Source mobile not registered.',
            ]);

        }

        // Check if source is active.
        if($source->state == '0') {
            return response([
                'code'          => '02',
                'description'   => 'Source account is blocked',
            ]);

        }

        if($source->mobile == $destination->mobile){
            return response([
                'code'        => '07',
                'description' => 'Invalid transaction',
            ]);

        }


        /*//Check PIN
        $hasher = app()->make('hash');
        if (!$hasher->check($request->pin, $source->pin)){



            $number_of_attempts =  $source->auth_attempts + 1;
            $source->auth_attempts = $number_of_attempts;
            $source->save();

            if($number_of_attempts  > '2'){

                $source->state = '0';
                $source->save();

            }


            return response([

                'code' => '01',
                'description' => 'Authentication Failed',

            ]);

        }

        */


        //Balance integrity check.
        $last_txn =   WalletTransactions::where('account_debited',$source->mobile)
                                            ->orWhere('account_credited',$source)->get()->last();
        $source->lockForUpdate()->first();
        if($last_txn->balance_after_txn != $source->balance){
            WalletTransactions::create([

                'txn_type_id'         => SEND_MONEY,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Integrity breach, contact support: mobile'.$source->mobile,

            ]);

            return response([
                'code'          => '09',
                'description'   => 'Transaction request cannot be processed please contact support'
            ]);
        }






        //Check Daily Spent
        $daily_spent =  WalletTransactions::where('account_debited', $source->mobile)
                                            ->where('created_at', '>', Carbon::now()->subDays(1))
                                            ->sum('transaction_amount');

        //Check Monthly Spent
        $monthly_spent =  WalletTransactions::where('account_debited', $source->mobile)
                                            ->where('created_at', '>', Carbon::now()->subDays(30))
                                            ->sum('transaction_amount');


        //Check COS in USE
        $wallet_cos = WalletCOS::find($source->wallet_cos_id);

        if($wallet_cos->maximum_daily <  $daily_spent){
            return response([
                'code'          => '03',
                'description'   => 'Daily limit reached'
            ]);
        }


        if($wallet_cos->maximum_monthly <  $monthly_spent){
            return response([
                'code'          => '04',
                'description'   => 'Monthly limit reached'
            ]);
        }


        if(!isset($destination)){
            return response([
                'code'          => '05',
                'description'   => 'Destination mobile not registered.',
            ]) ;


        }


        //Calculate Fees
        $amount_in_cents =  $request->amount / 100;
         $wallet_fees = WalletFeesCalculatorService::calculateFees(
                         $amount_in_cents,
            SEND_MONEY

       );

        if($amount_in_cents > $wallet_fees['maximum_daily']   ){
            return response([
                'code'          => '08',
                'description'   => 'Amount exceed transactional limits.'
            ]);
        }


        $total_deductions = $amount_in_cents + $wallet_fees['fee'] + $wallet_fees['tax'];
        $source->lockForUpdate()->first();
        if($total_deductions > $source->balance){
            return response([
                'code'          => '116',
                'description'   => 'Insufficient funds',
            ]) ;


        }

        $mobi = substr_replace($source->mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = '56' . $time_stamp . $mobi;


      try {

          DB::beginTransaction();

          //Deduct funds from source account
          $source->lockForUpdate()->first();
          $source_new_balance = $source->balance - $total_deductions;
          $source->balance = number_format((float)$source_new_balance, 4, '.', '');
          $source->save();

          //Create Recipient
          $destination->lockForUpdate()->first();
          $destination_new_balance = $destination->balance + $amount_in_cents;
          $destination->balance = number_format((float)$destination_new_balance, 4, '.', '');
          $destination->save();

          //Credit Tax Account
          $tax->lockForUpdate()->first();
          $tax_new_balance = $tax->balance + $wallet_fees['tax'];
          $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
          $tax->save();

          //Credit Revenue
          $revenue->lockForUpdate()->first();
          $revenue_new_balance = $revenue->balance + $wallet_fees['fee'];
          $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
          $revenue->save();

          DB::commit();



      } catch (\Exception $e){

          DB::rollback();

          WalletTransactions::create([

              'txn_type_id'         => SEND_MONEY,
              'tax'                 => $wallet_fees['tax'],
              'revenue_fees'        => $wallet_fees['fee'],
              'interchange_fees'    => '0.00',
              'zimswitch_fee'       => '0.00',
              'transaction_amount'  => $amount_in_cents,
              'total_debited'       => $total_deductions,
              'total_credited'      => $total_deductions,
              'batch_id'            => $reference,
              'switch_reference'    => $reference,
              'merchant_id'         => '',
              'transaction_status'  => 0,
              'account_debited'     => $source->mobile,
              'pan'                 => '',
              'description'         => 'Transaction was reversed',

          ]);

          return response([
              'code' => '01',
              'description' => 'Transaction was reversed',
          ]) ;

      }

        WalletTransactions::create([

            'txn_type_id'         => SEND_MONEY,
            'tax'                 => $wallet_fees['tax'],
            'revenue_fees'        => $wallet_fees['fee'],
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => $amount_in_cents,
            'total_debited'       => $total_deductions,
            'total_credited'      => '0.00',
            'batch_id'            => $reference,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $source->mobile,
            'pan'                 => '',
            'description'         => 'Transaction successfully processed.',
            'balance_after_txn'   => $source_new_balance,

        ]);


        WalletTransactions::create([

            'txn_type_id'         => SEND_MONEY,
            'tax'                 => '0.00',
            'revenue_fees'        => '0.00',
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => $amount_in_cents,
            'total_debited'       => '0.00',
            'total_credited'      => $amount_in_cents,
            'batch_id'            => $reference,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => '',
            'account_credited'    => $request->destination_mobile,
            'pan'                 => '',
            'description'         => 'Transaction successfully processed.',
            'balance_after_txn'   => $destination_new_balance,

        ]);



        dispatch(new Notify(

            $source->mobile,
            $destination->mobile,
            $amount_in_cents,
            $source_new_balance,
            $destination_new_balance,
            $reference

        ));

        return response([

            'code' => '000',
            'description' => "Success",

        ]);

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
            'destination_mobile' => 'required',
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'pin' => 'required',

        ]);


    }


}

