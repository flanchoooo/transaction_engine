<?php

namespace App\Http\Controllers;



use App\Wallet;
use App\WalletDisbursements;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;



class WalletDisbursementsController  extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */


    public function bulk_upload(Request $request){

        $validator = $this->bulk_upload_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $destination = Wallet::where('mobile',$request->destination_account)->get()->first();
        $source = Wallet::where('mobile', $request->source_account)->get()->first();


        if(!isset($source)){
            $source_state = 'Source account number not found';
            $transaction_status = 0;
        }else{
            $source_state = 'OK';

        }

        if(!isset($destination)){
            $destination_state = 'Destination account number not found';
            $transaction_status = 0;
        }else{
            $destination_state = 'OK';

        }



        DB::beginTransaction();
        try{

            $bulk_upload = new WalletDisbursements;
            $bulk_upload->source_account        =  $request->source_account;
            $bulk_upload->destination_account   =  $request->destination_account;
            $bulk_upload->amount                =  $request->amount;
            $bulk_upload->transaction_status    =  1;
            $bulk_upload->initiator             =  $request->initiator;
            $bulk_upload->transaction_reference =  $request->transaction_reference;
            $bulk_upload->description           =  "Source Account: $source_state, Destination Account: $destination_state";
            $bulk_upload->save();

            DB::commit();

            return response([
                'code'        => '00',
                'description' => 'Upload Successful'
            ]);


        }catch (\Exception $e){

            return $e;
            DB::rollBack();
            return response([
                'code' => '01',
                'description' => 'System malfunction'
            ]);
        }






    }

    public function disburse (Request $request){
        $validator = $this->disbursement_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

          $disburse = WalletDisbursements::where('description','Source Account: OK, Destination Account: OK')->get();

        if(!count($disburse)){
            return response([
                'code' => '01',
                'description' => 'No disbursement to process',
            ]);
        }

        foreach ($disburse as $item) {



            DB::beginTransaction();
            try {

                $source_account = Wallet::whereMobile($item->source_account);
                $destination_mobile = Wallet::whereMobile($item->destination_account);

                $agent_mobile = $source_account->lockForUpdate()->first();
                if ($item->amount > $agent_mobile->balance) {
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
                        'description'       => 'Agent: Insufficient funds for mobile:' . $request->source_mobile,


                    ]);


                    $item->description = 'Agent:Insufficient funds';
                    $item->save();

                    return response([
                        'code'        => '116',
                        'description' => 'Agent:Insufficient funds',
                    ]);
                }


                //Fee Deductions.
                $agent_mobile->balance -= $item->amount;
                $agent_mobile->save();

                $receiving_wallet = $destination_mobile->lockForUpdate()->first();
                $receiving_wallet->balance += $item->amount;
                $receiving_wallet->save();


                $time_stamp = Carbon::now()->format('ymdhis');
                $reference = '88' . $time_stamp;
                $transaction = new WalletTransactions();
                $transaction->txn_type_id = CASH_IN;
                $transaction->tax = '0.00';
                $transaction->revenue_fees = '0.00';
                $transaction->zimswitch_fee = '0.00';
                $transaction->transaction_amount = $item->amount;
                $transaction->total_debited = $item->amount;
                $transaction->total_credited = '0.00';
                $transaction->switch_reference = $reference;
                $transaction->batch_id = $reference;
                $transaction->transaction_status = 1;
                $transaction->account_debited = $agent_mobile->mobile;
                $transaction->account_credited = $receiving_wallet->mobile;
                $transaction->balance_after_txn = $agent_mobile->balance;
                $transaction->description = 'Transaction successfully processed.';
                $transaction->save();


                //Credit Recipient with amount.
                $transaction = new WalletTransactions();
                $transaction->txn_type_id = CASH_IN;
                $transaction->tax = '0.00';
                $transaction->revenue_fees = '0.00';
                $transaction->zimswitch_fee = '0.00';
                $transaction->transaction_amount = $item->amount;
                $transaction->total_debited = '0.00';
                $transaction->total_credited = $item->amount;
                $transaction->switch_reference = $reference;
                $transaction->batch_id = $reference;
                $transaction->transaction_status = 1;
                $transaction->account_debited = $agent_mobile->mobile;
                $transaction->account_credited = $receiving_wallet->mobile;
                $transaction->balance_after_txn = $receiving_wallet->balance;
                $transaction->description = 'Transaction successfully processed.';
                $transaction->save();


                $item->batch = $reference;
                $item->transaction_status = 3;
                $item->description = 'Disbursement successfully processed';
                $item->validator = $request->validator;
                $item->save();

                DB::commit();






            } catch (\Exception $e) {

                DB::rollBack();
                Log::debug('Account Number:' . $item->source_account . ' ' . $e);

                WalletTransactions::create([

                    'txn_type_id' => CASH_IN,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => '0.00',
                    'total_debited' => '0.00',
                    'total_credited' => '0.00',
                    'batch_id' => '',
                    'switch_reference' => '',
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'pan' => '',
                    'description' => 'Transaction was reversed for mobbile:' . $request->account_number,


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction request could not be processed now.',

                ]);
            }

           continue;


        }

        return response([

            'code' => '000',
            'description' => 'Disbursements successfully processed.',

        ]);

    }

    public function all (){
        $disburse = WalletDisbursements::all();
        return response($disburse);
    }

    protected function disbursement_validator(Array $data){
        return Validator::make($data, [
            'validator'             => 'required',
        ]);
    }
    protected function bulk_upload_validator(Array $data){
        return Validator::make($data, [
            'source_account'        => 'required',
            'destination_account'  => 'required',
            'amount'                => 'required',
            'initiator'             => 'required',
            'transaction_reference' => 'required',
        ]);
    }




}