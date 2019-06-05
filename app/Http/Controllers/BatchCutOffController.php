<?php
namespace App\Http\Controllers;


use App\Batch;
use App\Batch_Transaction;
use App\License;
use App\Services\BalanceEnquiryService;
use App\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class BatchCutOffController extends Controller
{

    public function batch(Request $request){


        //API INPUT VALIDATION
        $validator = $this->balance_enquiry($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $currency = License::find(1);


        $null = Batch::where('imei', $request->imei)->count();

        if ($null == '0') {

            Batch::create([
                'imei' => $request->imei,
            ]);
        };


        $batch = Batch::where('imei', $request->imei)->get()->last();
        $start = Carbon::parse($batch->created_at);
        $end = Carbon::now();

        /*
        $balance_transactions = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '1')
            ->whereBetween('created_at', [$start, $end])->get();

        $purchase_cash_back_transactions = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '14')
            ->whereBetween('created_at', [$start, $end])->get();

        $purchase_cash_back_amount_transactions = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '21')
            ->whereBetween('created_at', [$start, $end])->get();

        $purchase_transactions = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '6')
            ->whereBetween('created_at', [$start, $end])->get();

        */


        //purchase amount
        $purchase_cash_back_sum = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '14')
            ->whereBetween('created_at', [$start, $end])
            ->sum('credit');


        $balance = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '1')
            ->whereBetween('created_at', [$start, $end])
            ->sum('credit');


        //Cashback amount
        $cashback_amount = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '21')
            ->whereBetween('created_at', [$start, $end])
            ->sum('credit');


        //Cashback amount
        $purchase = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '6')
            ->whereBetween('created_at', [$start, $end])
            ->sum('credit');


        $purchase_cash_back_sum_count = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '14')
            ->whereBetween('created_at', [$start, $end])
            ->count();
//
        $balance_count = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '1')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $cashback_amount_count = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '21')
            ->whereBetween('created_at', [$start, $end])->get()
            ->count();


        //Cashback amount
        $purchase_count = Batch_Transaction::where('merchant', $request->imei)
            ->where('transaction_type', '6')
            ->whereBetween('created_at', [$start, $end])
            ->count();


        $total_credits = $purchase_cash_back_sum + $balance + $cashback_amount + $purchase;
        $total_count = $purchase_cash_back_sum_count + $balance_count + $cashback_amount_count + $purchase_count;


        Batch::create([

            'imei' => $request->imei,
        ]);


        Transaction::create([

            'transaction_type' => Batch_Cut_Off,
            'status'           => 'COMPLETED',
            'account'          => '',
            'pan'              => '',
            'credit'           => '0.00',
            'debit'            => '0.00',
            'description'      => 'Batch Cut Off',
            'fee'              => '0.00',
            'batch_id'         => '',
            'merchant'         => $request->imei,
        ]);

        return response([

            'code'                                => '00',
            'description'                         => 'Success',
            'currency'                            => $currency->currency ,
            'total_credits'                       =>  $total_credits,
            'total_number_of_txns'                => "$total_count"


        ]);



    }


    public function last_transaction(Request $request){

        $validator = $this->last_txn($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        $null = Batch::where('imei', $request->imei)->count();

        if($null == '0'){

            return response([

                'code' => '01',
                'description' => 'No last transaction',
            ]);
        }

      $last_transaction =   Batch_Transaction::where('merchant', $request->imei)->get()->last();


        return response([

            'code' => '00',
            'description' => 'Success',
            'transaction' =>  $last_transaction,
        ]);

    }


    protected function balance_enquiry(Array $data){
        return Validator::make($data, [
            'imei' => 'required',
        ]);
    }


    protected function last_txn(Array $data){
        return Validator::make($data, [
            'imei' => 'required',
        ]);
    }




}