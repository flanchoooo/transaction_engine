<?php

namespace App\Http\Controllers;


use App\Jobs\Notify;
use App\Jobs\NotifyBills;
use App\Jobs\SaveWalletTransaction;
use App\License;
use App\Services\FeesCalculatorService;
use App\Services\WalletFeesCalculatorService;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransaction;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;




class WalletBillPaymentController extends Controller
{


    public function paybill(Request $request){

        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }



        //Declarations
        $biller_account = Wallet::where('mobile','263700000002')->get()->first();
        $revenue = Wallet::where('mobile', '263700000001')->get()->first();
        $source = Wallet::where('mobile', $request->source_mobile)->get()->first();
        $tax = Wallet::where('mobile', '263700000000')->get()->first();

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






        //Check PIN
       /* $hasher = app()->make('hash');
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



        //Check Daily Spent
        $daily_spent =  WalletTransactions::where('account_debited', $source->mobile)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');

        //Check Monthly Spent
        $monthly_spent =  WalletTransactions::where('account_debited', $source->mobile)
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->sum('transaction_amount');



        $wallet_cos = WalletCOS::find($source->wallet_cos_id);


        if($wallet_cos->maximum_daily <  $daily_spent){

            return response([

                'code' => '03',
                'description' => 'Daily limit reached'

            ]);
        }


        if($wallet_cos->maximum_monthly <  $monthly_spent){

            return response([

                'code' => '04',
                'description' => 'Monthly limit reached'

            ]);
        }





        $amount_in_cents =  $request->amount / 100;


        //Calculate Fees
         $wallet_fees = WalletFeesCalculatorService::calculateFees(
          $amount_in_cents,$request->bill_payment_id

       );


         $total_deductions = $amount_in_cents + $wallet_fees['fee'] + $wallet_fees['tax'];

         $source->lockForUpdate()->first();
        if($total_deductions > $source->balance){

            return response([

                'code' => '06',
                'description' => 'Insufficient funds',

            ]) ;


        }

       //return Carbon::createFromTimestampMs(Carbon::now())->format('Y-m-d\TH:i:s.uP T');





      try {

          DB::beginTransaction();

          //Deduct funds from source account
          $source->lockForUpdate()->first();
          $source_new_balance = $source->balance - $total_deductions;
          $source->balance = number_format((float)$source_new_balance, 4, '.', '');
          $source->save();




          //Credit Revenue
          $revenue->lockForUpdate()->first();
          $revenue_new_balance = $revenue->balance + $wallet_fees['fee'];
          $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
          $revenue->save();


          //Credit Biller
          $biller_account->lockForUpdate()->first();
          $biller_new_balance = $biller_account->balance + $amount_in_cents;
          $biller_account->balance = number_format((float)$biller_new_balance, 4, '.', '');
          $biller_account->save();

          //Credit Tax
          $tax->lockForUpdate()->first();
          $tax_new_balance = $tax->balance + $wallet_fees['tax'];
          $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
          $tax->save();


          DB::commit();



      } catch (\Exception $e){

          DB::rollback();

          return response([

              'code' => '01',
              'description' => 'Transaction was reversed',

          ]) ;

      }



        $mobi = substr_replace($source->mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = $request->bill_payment_id . $time_stamp . $mobi;




        WalletTransactions::create([

            'txn_type_id'         => $request->bill_payment_id,
            'tax'                 => $wallet_fees['tax'],
            'revenue_fees'        => $wallet_fees['fee'],
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => '0.00',
            'total_debited'       => $total_deductions,
            'total_credited'      => $total_deductions,
            'batch_id'            => $reference,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $source->mobile,
            'pan'                 => '',
            'merchant_account'    => $amount_in_cents,



        ]);





        dispatch(new NotifyBills(
            $source->mobile,
            '',
            $amount_in_cents,
            $source_new_balance,
            '',
            $reference,
            $request->product_name

        ));





        return response([

            'code' => '00',
            'description' => "Success",
            'reference' => "$reference",

        ]);




    }




    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',
            'bill_payment_id' => 'required',
            'product_name' => 'required',
            'bill_reference' => 'required',

        ]);


    }


}

