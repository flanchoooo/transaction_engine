<?php

namespace App\Http\Controllers;




use App\Services\LoggingService;
use App\TransactionType;
use App\Wallet;
use App\WalletHistory;
use App\WalletInfo;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;




class WalletSupportController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $history_credit =  WalletHistory::where('account_credited',$request->source_mobile)
            ->where('total_credited','>',0)
            -> orderBy('id', 'desc')->take(10)
            ->get();

        //return $history_credit;

        $history_debit =  WalletHistory::where('account_debited',$request->source_mobile)
            ->where('total_debited','>',0)
            -> orderBy('id', 'desc')->take(10)
            ->get();





        $result =[];
        $total_history = $history_credit->merge($history_debit)->sortByDesc('id');

        foreach ($total_history as $item){

           $account = WalletHistory::find($item->id);
            //if($item->total_debited == 0) continue;
            $txn_type =  TransactionType::find($item['txn_type_id']);
            $tax = $item['tax'];
            $fees = $item['revenue_fees'];
            $temp = array(
                'trx_date'      =>\Carbon\Carbon::parse($item->created_at)->format('d M Y'),
                'value_date'    =>\Carbon\Carbon::parse($item->created_at)->format('d M Y'),
                'particulars'   => "$txn_type->name | Fees:$fees | ". $item['reversed'],
                'debit'         => $item['total_debited'],
                'credit'        => $item['total_credited'],
                'closing'       => $item['balance_after_txn'],
                'operator_id'   =>"",
                'supervisor_id' =>"",
            );

            array_push($result,$temp);
        }



        return response([
            'code'                       => '00',
            'description'                => 'success',
            'time'                       => Carbon::now()->format('ymdhis'),
            'error_list'                 => [],
            'account_balance_list'       => array_reverse($result),
        ]);


    }

    public function history_web(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $history_credit =  WalletHistory::where('account_credited',$request->source_mobile)
            ->where('total_credited','>',0)
            -> orderBy('id', 'desc')
            ->get();

        //return $history_credit;

        $history_debit =  WalletHistory::where('account_debited',$request->source_mobile)
            ->where('total_debited','>',0)
            -> orderBy('id', 'desc')
            ->get();





        $result =[];
        $total_history = $history_credit->merge($history_debit)->sortByDesc('id');

        foreach ($total_history as $item){

           $account = WalletHistory::find($item->id);
            //if($item->total_debited == 0) continue;
            $txn_type =  TransactionType::find($item['txn_type_id']);
            $temp = array(
                'trx_date'      =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'value_date'    =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'particulars'   => $txn_type->name,
                'debit'         => $item['total_debited'],
                'credit'        => $item['total_credited'],
                'a_credit'      => $item['account_credited'],
                'a_debit'       => $item['account_debited'],
                'closing'       => $item['balance_after_txn'],
                'batch_id'      => $item['batch_id'],
                'id'            => $item['id'],
                'operator_id'   =>"",
                'supervisor_id' =>"",
            );

            array_push($result,$temp);
        }



        return response([
            'code'                       => '00',
            'description'                => 'success',
            'time'                       => Carbon::now()->format('ymdhis'),
            'error_list'                 => [],
            'account_balance_list'       => array_reverse($result),
        ]);


    }

    public function agent_history(Request $request){

        $validator = $this->agent_history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }




        $end = Carbon::parse($request->end_date)
            ->endOfDay()          // 2018-09-29 23:59:59.000000
            ->toDateTimeString();

        $start = Carbon::parse($request->start_date)
            ->endOfDay()          // 2018-09-29 23:59:59.000000
            ->toDateTimeString();


        $history_credit =  WalletHistory::where('account_credited',$request->source_mobile)
            ->where('total_credited','>',0)
            ->whereIn('txn_type_id',[CASH_OUT,CASH_IN,COMMISSION_SETTLEMENT])
            ->whereBetween('created_at', [$start,$end])
            -> orderBy('id', 'desc')
            ->get();

        //return $history_credit;

        $history_debit =  WalletHistory::where('account_debited',$request->source_mobile)
            ->where('total_debited','>',0)
            ->whereIn('txn_type_id',[CASH_OUT,CASH_IN,COMMISSION_SETTLEMENT])
            ->whereBetween('created_at', [$start,$end])
            -> orderBy('id', 'desc')
            ->get();



        $result =[];
        $total_history = $history_credit->merge($history_debit)->sortByDesc('id');

        foreach ($total_history as $item){

            //if($item->total_debited == 0) continue;
            $txn_type =  TransactionType::find($item['txn_type_id']);
            $temp = array(
                'trx_date'      =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'value_date'    =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'particulars'   => $txn_type->name,
                'debit'         => $item['transaction_amount'],
                'credit'        => $item['transaction_amount'],
                'closing'       => $item['balance_after_txn'],
                'commissions'   => $item['commissions'],
                'account_credited'   => $item['account_credited'],
                'account_debited'   => $item['account_debited'],
                'operator_id'   =>"",
                'supervisor_id' =>"",
            );

            array_push($result,$temp);
        }





        return response([
            'code'                       => '00',
            'description'                => 'success',
            'time'                       => Carbon::now()->format('ymdhis'),
            'error_list'                 => [],
            'account_balance_list'       => array_reverse($result),
        ]);


    }

    public function customer_info(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $customer_info = WalletInfo::where('mobile', $request->source_mobile)->get()->first();
        if(!isset($customer_info)){
            return response([
                'code'                       => '00',
                'description'                => 'Mobile not found'
            ]);
        }

        $time = 'T00:00:00+02:00';
        $date = substr($customer_info->created_at->toDateTimeString(),0,10);
        $date_ = $date.$time;

        $account_name = $customer_info->first_name.' '. $customer_info->last_name;
        return response([
            'code'                       => '00',
            'description'                => 'success',
            'time'                       => Carbon::now()->format('ymdhis'),
            'ds_account_customer'                 => [
                'phone2'        => $customer_info->mobile,
                'branch_name'   => 'FINANCE HOUSE',
                'product_name'  => 'WALLET',
                'account_name'  => $account_name,
                'address1'      => "",
                'address2'      => "",
                'city_id'       => '1',
                'country_id'    => 'ZM',
                'country_name'  => 'ZAMBIA',
                'email_id'      =>  "",
                'operating_mode_id'=> 'S',
                'operating_instructions' => "",
                'account_class_id'=> 'OSPSPCC',
                'acstatus'      => "",
                'created_on'    => "",
                'modified_by'   => "",
                'modified_on'   => "",
                'supervised_by' => 'WALLET SYS',
                'supervised_on' => $date_,
                'client_name'   => $account_name,
                'update_count'  => '2',
                'created_by'    => $account_name,
                'account_id'    => $customer_info->mobile,
                'mobile'        => $customer_info->mobile,
                'our_branch_id' => '001',
                'client_id'     => $customer_info->id,
                'product_id'    => 'SVP01',
                'currency_id'   => CURRENCY,

            ],

        ]);


    }

    public function agent(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $customer_info = WalletInfo::where('mobile', $request->source_mobile)->get()->first();
        if(!isset($customer_info)){
            return response([
                'code'                       => '01',
                'description'                => 'Mobile not found'
            ]);
        }

        if(!isset($customer_info->business_code)){
            return response([
                'code'                       => '02',
                'description'                => 'Mobile account is not an agent.'
            ]);
        }


        return response([
            'code'                       => '00',
            'agent_code'                 => $customer_info->business_code,
            'agent_name'                 => $customer_info->first_name,
            'float'                      => $customer_info->balance,
            'commissions'                => $customer_info->commissions
        ]);


    }

    public function settle_agent(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $customer = Wallet::where('mobile', $request->source_mobile)->get()->first();
        if(!isset($customer)){
            return response([
                'code'                       => '00',
                'description'                => 'Mobile not found'
            ]);
        }

        $customer_info = Wallet::whereMobile($request->source_mobile);
        $reference = $this->genRandomNumber();

        try {

            DB::beginTransaction();

            $agent_commission = $customer_info->lockForUpdate()->first();
            $agent_commission->balance += $agent_commission->commissions;
            $agent_commission->commissions -= $agent_commission->commissions;
            $agent_commission->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = COMMISSION_SETTLEMENT;
            $transaction->tax               = '0.00';
            $transaction->revenue_fees      = '0.00';
            $transaction->zimswitch_fee     = '0.00';
            $transaction->transaction_amount= $agent_commission->commissions;
            $transaction->total_debited     = '0.00';
            $transaction->total_credited    = $agent_commission->commissions;
            $transaction->switch_reference  = $reference;
            $transaction->batch_id          = $reference;
            $transaction->transaction_status= 1;
            $transaction->account_debited   = '';
            $transaction->account_credited  = $request->source_mobile;
            $transaction->balance_after_txn = $agent_commission->balance;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->save();



            DB::commit();

            return response([

                'code' => '000',
                'description' => ' Commission settlement successful',

            ]) ;

        } catch (\Exception $e){

            DB::rollback();

            return response([

                'code' => '01',
                'description' => 'Transaction was reversed',

            ]) ;

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

    protected function history_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',


        ]);


    }

    protected function agent_history_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'start_date' => 'required',
            'end_date' => 'required',


        ]);


    }


}

