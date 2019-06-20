<?php

namespace App\Http\Controllers;



use App\Jobs\SaveWalletTransaction;

use App\Logs;
use App\ManageValue;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;



class WalletEValueController extends Controller
{

    public function all_e_value_management(){


        return response(

            ManageValue::where('state','0')
                ->where('txn_type','18')->get()

        );
    }


    public function all_destroy_value(){


        return response(

            ManageValue::where('state','0')
                ->where('txn_type','6')->get()

        );
    }

    public function e_value_management(Request $request){

        $validator = $this->e_value_management_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        ManageValue::create([

            'account_number'    => $request->account_number,
            'amount'            => $request->amount,
            'txn_type'          => $request->txn_type,
            'state'             => $request->state,
            'initiated_by'      => $request->initiated_by,
            'narration'         => $request->narration

        ]);

        Logs::create([
            'description' => "Initiated a $request->txn_type transaction.",
            'user' => $request->initiated_by,

        ]);


        return response([

            'code' => '00',
            'description' => 'Transaction successfully initiated.'

        ]) ;




    }

    public function e_value__destroy(Request $request){


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

                'code' => '01',
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


        Logs::create([
            'description' => "Destroy e-value worth:$total_deductions for mobile:  $source->mobile",
            'user' => $request->created_by,

        ]);


        return response([

            'code' => '00',
            'description' => 'E-Value was successfully destroyed.'
        ]) ;




    }

    public function create_value(Request $request){



        //return $request->all();


        $validator = $this->create_value_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        $id = ManageValue::where('id',$request->id)->first();
        $destination_mobile = Wallet::where('mobile',$id->account_number)->get()->first();
        $mobi = substr_replace($id->account_number, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = $request->bill_payment_id . $time_stamp . $mobi;


        if($request->state == '2'){

            $id->state = 2;
            $id->validated_by = $request->created_by;
            $id->save();

            return response([

                'code' => '01',
                'description' => 'E-Value creation request has been successfully cancelled.',

            ]) ;

        }


        if($id->state == '1'){

            return response([

                'code' => '01',
                'description' => 'E-Value creation request already processed.',

            ]) ;
        }


        if(!isset($destination_mobile)){

            return response([

                'code' => '01',
                'description' => 'Invalid destination account.',

            ]) ;


        }


        $total_deductions = $id->amount;




        try {

            DB::beginTransaction();


            $new_balance = $destination_mobile->balance + $total_deductions;

            $destination_mobile->lockForUpdate()->first();
            $destination_mobile->balance =number_format((float)$new_balance, 4, '.', '');;
            $destination_mobile->save();

            //Deduct funds from source account
            DB::commit();



        } catch (\Exception $e){

            DB::rollback();

            WalletTransactions::create([

                'txn_type_id'         => CREATE_VALUE,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => $total_deductions,
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $destination_mobile->mobile,
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

            'txn_type_id'         => CREATE_VALUE,
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
            'account_debited'     => '',
            'pan'                 => '',
            'merchant_account'    => '',
            'account_credited'    => $destination_mobile->mobile,


        ]);





        Logs::create([
            'description' => "Created e-value worth:$total_deductions for mobile:$destination_mobile->mobile",
            'user' => $request->created_by,

        ]);


        $id->state = 1;
        $id->validated_by = $request->created_by;
        $id->save();

        return response([

            'code' => '00',
            'description' => 'E-Value was successfully created.'
        ]) ;




    }

    public function e_value_destroy(Request $request){



        //return $request->all();


        $validator = $this->create_value_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        $id = ManageValue::where('id',$request->id)->first();
        $destination_mobile = Wallet::where('mobile',$id->account_number)->get()->first();
        $mobi = substr_replace($id->account_number, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = $request->bill_payment_id . $time_stamp . $mobi;


        if($request->state == '2'){

            $id->state = 2;
            $id->validated_by = $request->created_by;
            $id->save();

            return response([

                'code' => '01',
                'description' => 'Destroy E-Value request has been successfully cancelled.',

            ]) ;


        }


        if($id->state == '1'){

            return response([

                'code' => '01',
                'description' => 'Destroy E-Value request already processed.',

            ]) ;
        }


        if(!isset($destination_mobile)){

            return response([

                'code' => '01',
                'description' => 'Invalid destination account.',

            ]) ;


        }




        $total_deductions = $id->amount;

        $destination_mobile->lockForUpdate()->first();
        $new_balance = $destination_mobile->balance - $total_deductions;

        if($new_balance <= '0'){

            return response([

                'code' => '06',
                'description' => 'Balance after destroying e-value must be greater or equal to zero'
            ]) ;


        }




        try {

            DB::beginTransaction();


            $new_balance = $destination_mobile->balance - $total_deductions;

            $destination_mobile->lockForUpdate()->first();
            $destination_mobile->balance =number_format((float)$new_balance, 4, '.', '');;
            $destination_mobile->save();

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
                'total_debited'       => '0.00',
                'total_credited'      => $total_deductions,
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $destination_mobile->mobile,
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
            'account_debited'     => '',
            'pan'                 => '',
            'merchant_account'    => '',
            'account_credited'    => $destination_mobile->mobile,


        ]);





        Logs::create([
            'description' => "Destroyed e-value worth:$total_deductions for mobile:$destination_mobile->mobile",
            'user' => $request->created_by,

        ]);


        $id->state = 1;
        $id->validated_by = $request->created_by;
        $id->save();

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
            'description'         => $request->narration


        ]);



        Logs::create([
            'description' => "Created e-value worth:$total_deductions for mobile:$destination->mobile",
            'user' => $request->created_by,

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
            'narration' => 'required',
            'amount' => 'required',



        ]);


    }


    protected function e_value_destroy_validator(Array $data)
    {
        return Validator::make($data, [
            'id' => 'required',
            'state' => 'required',
            'created_by' => 'required',

        ]);


    }

    protected function create_value_validator(Array $data)
    {
        return Validator::make($data, [
            'id' => 'required',
            'state' => 'required',
            'created_by' => 'required',

        ]);


    }

    protected function e_value_management_validator(Array $data)
    {
        return Validator::make($data, [

            'account_number' => 'required',
            'narration' => 'required',
            'amount' => 'required|integer|min:0',
            'initiated_by' => 'required',
            'txn_type' => 'required',
            'state' => 'required',

        ]);


    }

}










