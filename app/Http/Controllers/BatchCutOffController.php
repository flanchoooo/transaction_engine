<?php
namespace App\Http\Controllers;


use App\Batch;
use App\Batch_Transaction;
use App\Devices;
use App\License;
use App\Merchant;
use App\Services\BalanceEnquiryService;
use App\Transaction;
use App\Transactions;
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

        $merchant =  Devices::where('imei',$request->imei)->first();
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
       // return  Batch_Transaction::all();

        //Purchase Amount on us total
        $purchase_on_us_sum = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '4')
            ->whereBetween('created_at', [$start, $end])
            ->sum('transaction_amount');

         $purchase_on_off_us_sum = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '7')
            ->whereBetween('created_at', [$start, $end])
            ->sum('transaction_amount');

         $purchase_on_us_count = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '4')
            ->whereBetween('created_at', [$start, $end])->get()
            ->count();

         $purchase_on_off_count = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '7')
            ->whereBetween('created_at', [$start, $end])->get()
            ->count();


        $balance_on_us_count= Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '1')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $balance_off_us_count= Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '3')
            ->whereBetween('created_at', [$start, $end])
            ->count();


         $purchase_cb_on_us_txn_amount = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '8')
            ->whereBetween('created_at', [$start, $end])
            ->sum('transaction_amount');

         $purchase_cb_on_us_cb_amount = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '8')
            ->whereBetween('created_at', [$start, $end])
            ->sum('cash_back_amount');


        $purchase_cb_on_us_cb_count = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '8')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $purchase_cb_on_off_txn_amount = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '10')
            ->whereBetween('created_at', [$start, $end])
            ->sum('transaction_amount');

        $purchase_cb_on_off_cb_amount = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '10')
            ->whereBetween('created_at', [$start, $end])
            ->sum('cash_back_amount');


        $purchase_cb__us_txn_count = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '10')
            ->whereBetween('created_at', [$start, $end])
            ->count();

         $purchase_cb_on_us_txn_count = Batch_Transaction::where('merchant_id', $merchant->merchant_id)
            ->where('txn_type_id', '10')
            ->whereBetween('created_at', [$start, $end])
            ->count();





           $total_credits = $purchase_on_us_sum +
                            $purchase_on_off_us_sum +
                            $purchase_cb_on_off_txn_amount +
                            $purchase_cb_on_off_cb_amount +
                            $purchase_cb_on_us_txn_amount +
                            $purchase_cb_on_us_cb_amount;

            $total_count =   $purchase_on_us_count +
                            $purchase_on_off_count +
                            $balance_on_us_count +
                            $balance_off_us_count +
                            $purchase_cb_on_us_txn_count +
                            $purchase_cb_on_us_cb_count +
                            $purchase_cb__us_txn_count;


           Batch::create([

               'imei' => $request->imei,
           ]);


        Transactions::create([

            'txn_type_id'         => BATCH_CUT_OFF,
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
            'transaction_status'  => 1,
            'account_debited'     => '',
            'pan'                 => '',
            'description'         => 'BALANCE ENQUIRY OFF US',


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