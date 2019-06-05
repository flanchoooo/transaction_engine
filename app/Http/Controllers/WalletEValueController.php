<?php

namespace App\Http\Controllers;



use App\Jobs\SaveWalletTransaction;

use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;



class WalletEValueController extends Controller
{




    public function e_value_destroy(Request $request){


        $validator = $this->e_value_destroy_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }




        $source = Wallet::where('mobile',$request->source_mobile)->get()->first();
        $mobi = substr_replace($source->mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = $request->bill_payment_id . $time_stamp . $mobi;


        if(!isset($source)){

            return response([

                'code' => '01',
                'description' => 'Invalid Source Account',

            ]) ;


        }


        $total_deductions = $request->amount / 100;

        $source->lockForUpdate()->first();
        $new_balance = $source->balance - $total_deductions;

        if($new_balance <= '0'){

            return response([

                'code' => '06',
                'description' => 'Balance after destroying e-value must be greater or equal to zero'
            ]) ;


        }


        try {

            DB::beginTransaction();


            $new_balance = $source->balance - $total_deductions;

            $source->lockForUpdate()->first();
            $source->balance =number_format((float)$new_balance, 4, '.', '');;
            $source->save();

            //Deduct funds from source account
            DB::commit();



        } catch (\Exception $e){

            DB::rollback();

            WalletTransactions::create([

                'txn_type_id'         => DESTROY_E_VALUE,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => $total_deductions,
                'total_credited'      => '',
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $source->mobile,
                'pan'                 => '',
                'merchant_account'    => '',
                'descripition'        => 'Transaction was reversed',


            ]);


            return response([

                'code' => '01',
                'description' => 'Transaction was reversed',

            ]) ;

        }


        WalletTransactions::create([

            'txn_type_id'         => DESTROY_E_VALUE,
            'tax'                 => '0.00',
            'revenue_fees'        => '0.00',
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => '0.00',
            'total_debited'       => $total_deductions,
            'total_credited'      => '',
            'batch_id'            => $reference,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $source->mobile,
            'pan'                 => '',
            'merchant_account'    => '',


        ]);


        return response([

            'code' => '00',
            'description' => 'E-Value was successfully destroyed.'
        ]) ;




    }

    public function adjustment(Request $request){


        $validator = $this->ajustment_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        $source = Wallet::where('mobile',$request->source_mobile)->get()->first();
        $destination = Wallet::where('mobile', $request->destination_mobile)->get()->first();
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = '57'.$time_stamp;

        if(!isset($source)){

            return response([

                'code' => '01',
                'description' => 'Invalid Source Account',

            ]) ;


        }

        if(!isset($destination)){

            return response([

                'code' => '02',
                'description' => 'Invalid Destination Account',

            ]) ;


        }

        $total_deductions = $request->amount / 100;

        if($total_deductions > $source->balance){

            return response([

                'code' => '06',
                'description' => 'Source account does not have sufficient balance'
            ]) ;


        }



        try {

            DB::beginTransaction();

            $source->lockForUpdate()->first();
            $source_new_balance = $source->balance - $total_deductions;
            $source->balance = number_format((float)$source_new_balance, 4, '.', '');;
            $source->save();

            $destination->lockForUpdate()->first();
            $destination_new_balance = $destination->balance + $total_deductions;
            $destination->balance = number_format((float)$destination_new_balance, 4, '.', '');
            $destination->save();

            DB::commit();

        }catch (\Exception $e){



            DB::rollback();

            WalletTransactions::create([

                'txn_type_id'         => ADJUSTMENT,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => $total_deductions,
                'total_credited'      => '',
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $source->mobile,
                'pan'                 => '',
                'merchant_account'    => '',
                'description'        => 'Transaction was reversed',


            ]);

            return response([

                'code' => '01',
                'description' => 'Transaction was reversed',

            ]) ;

        }




        WalletTransactions::create([

            'txn_type_id'         => ADJUSTMENT,
            'tax'                 => '0.00',
            'revenue_fees'        => '0.00',
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
            'account_credited'    => $destination->mobile,
            'pan'                 => '',
            'merchant_account'    => '',


        ]);





        return response([

            'code' => '00',
            'description' => 'Adjustment successful',

        ]) ;



    }






    protected function ajustment_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'destination_mobile' => 'required',



        ]);


    }

    protected function e_value_destroy_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'amount' => 'required|integer|min:0',



        ]);


    }


}










