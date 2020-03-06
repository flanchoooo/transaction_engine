<?php /** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
use App\Configuration;
use App\Deduct;
use App\Devices;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\BalanceIssuedService;
use App\Services\CheckBalanceService;
use App\Services\DuplicateTxnCheckerService;
use App\Services\EcocashService;
use App\Services\ElectricityService;
use App\Services\FeesCalculatorService;
use App\Services\HotRechargeService;
use App\Services\LoggingService;
use App\Services\MerchantServiceFee;
use App\Services\PurchaseAquiredService;
use App\Services\PurchaseIssuedService;
use App\Services\PurchaseOnUsService;
use App\Services\TokenService;
use App\Services\ZipitReceiveService;
use App\Services\ZipitSendService;
use App\Transactions;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use Composer\DependencyResolver\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class BRBalanceController extends Controller
{


    public function br_balance(Request $request)
    {

        $validator = $this->br_balance_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $balance_res = CheckBalanceService::checkBalance($request->account_number);
        if($balance_res["code"] != '000'){
            return response([
                'code'          => $balance_res["code"],
                'description'   => $balance_res["description"],
            ]);
        }

         $amounts = BRJob::where('source_account',$request->account_number)
             ->whereIn('txn_status',['FAILED_','FAILED','PROCESSING','PENDING'])
             ->where('txn_type', '!=',  '156579070528551244')
             ->get()->sum(['amount_due']);

        $balance = $balance_res["available_balance"] - $amounts;
        return response([
            'code' => '00',
            'available_balance'     => "$balance",
            'ledger_balance'        => "$balance",
        ]);

    }


    public function post_transaction(Request $request)
    {

        $validator = $this->post_transaction_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        DB::beginTransaction();
        try {

            $amount_due = $request->amount + $request->fees;
            $now = Carbon::now();
            $reference = $now->format('mdHisu');
            $br_job                 = new BRJob();
            $br_job->txn_status     = 'PENDING';
            $br_job->status         = 'DRAFT';
            $br_job->version        = 0;
            $br_job->amount         = $request->amount;
            $br_job->amount_due     = $amount_due;
            $br_job->tms_batch      = $reference;
            $br_job->source_account = $request->account_number;
            $br_job->rrn            = $reference;
            $br_job->txn_type       = $request->transaction_id;
            $br_job->narration      = $request->narration;
            $br_job->mobile         = $request->mobile;
            $br_job->save();
            DB::commit();

            LoggingService::message("$request->amount | $request->fees | $request->account_number  | $request->transaction_id |$request->mobile " );

            return response([
                'code'              => '00',
                'description'       => 'Transaction successfully posted',
                'batch_id'          => $reference
            ]);


        } catch (QueryException $queryException) {
            DB::rollBack();
            return response([
                'code'          => '100',
                'description'   => $queryException,
            ]);
        }

    }



    public function  post_pending_transaction (){

        $configuration = Configuration::where('name', 'PENDING_TRANSACTIONS_CRON')->first();
        $running=$configuration->executing;

        $startTime =  Carbon::now();
        $endTime = $configuration->updated_at;
        $totalDuration = $endTime->diffInSeconds($startTime);
        if($totalDuration>60){
            $running=0;
        }

        if($running!= 0) {
            LoggingService::message("Another Job is still running");
            return array(

            );
        }

        $configuration->executing=1;
        $configuration->save();
        $this->transact();
        $configuration->executing=0;
        $configuration->save();
        LoggingService::message("Job finished");

    }

    private function transact()
    {
        LoggingService::message('Cron started');
        $result = BRJob::whereIn('txn_status',['FAILED','PENDING','PROCESSING'])
            ->orderBy('updated_at','asc')->lockForUpdate()->first();


        if(!isset($result)){
            LoggingService::message('No txn to process');
            return array(
                'code'           => '01',
                'description '   => 'No transaction to process'
            );
        }
        LoggingService::message("Processing id | $result->id");

        /* if($result->version > 0){
               $result->txn_status = "RETRY";
               $result->save();
               return array(
                   'code'           => '01',
                   'description '   => 'Re-attempt later.'
               );
           }
        */


        $response =  DuplicateTxnCheckerService::check($result->id);
        if($response["code"] != "00"){
            $result->txn_status = "COMPLETED";
            $result->status = "COMPLETED";
            $result->response = "Transaction already processed.";
            $result->save();
            LoggingService::message('Duplicate has finished executing');
            Config::set('cron_executing',false);
            return array(
                'code'           => '01',
                'description '   => 'Duplicate transaction'
            );
        }



        if($result->txn_type == PURCHASE_OFF_US){
            $purchase_response = PurchaseIssuedService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->rrn,$result->tms_batch);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '01',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == ZIPIT_RECEIVE){
            $purchase_response = ZipitReceiveService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->rrn,$result->tms_batch);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == BR_ECOCASH){
            $purchase_response = EcocashService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->mobile);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '01',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == BALANCE_ENQUIRY_OFF_US){
            $purchase_response = BalanceIssuedService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->rrn);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == ZIPIT_SEND){
            $purchase_response = ZipitSendService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->rrn);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == BR_AIRTIME){
            $purchase_response = HotRechargeService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->mobile);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == BR_ELECTRICITY){
            $purchase_response = ElectricityService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->mobile);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == BALANCE_ON_US){
            $purchase_response = BalanceIssuedService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->rrn);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '00',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == PURCHASE_ON_US){
            $purchase_response = PurchaseOnUsService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '01',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == MDR_DEDUCTION){
            $mdr_response = MerchantServiceFee::sendTransaction($result->id,$result->amount,$result->source_account,$result->tms_batch);
            if($mdr_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $mdr_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $mdr_response["description"];
                $result->save();
                return array(
                    'code'                  => '01',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        if($result->txn_type == PURCHASE_BANK_X){
            $purchase_response = PurchaseAquiredService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->tms_batch);
            if($purchase_response["code"] == "00"){
                $result->txn_status = "COMPLETED";
                $result->br_reference = $purchase_response["description"];
                $result->save();
                return array(
                    'code'           => '00',
                    'description'   => 'Successfully processed the transaction'
                );
            }else{
                $result->updated_at = Carbon::now();
                $result->response = $purchase_response["description"];
                $result->save();
                return array(
                    'code'                  => '01',
                    'description'           => 'Transaction successfully processed',

                );
            }

        }

        /*
             if($result->txn_type == PURCHASE_CASH_BACK_ON_US){
                 $purchase_response = PurchaseCashService::sendTransaction($result->id,$result->amount,$result->cash,$result->source_account,$result->narration,$result->tms_batch);
                 if($purchase_response["code"] == "00"){
                     $result->txn_status = "COMPLETED";
                     $result->br_reference = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'           => '00',
                         'description'   => 'Successfully processed the transaction'
                     );
                 }else{
                     $result->updated_at = Carbon::now();
                     $result->response = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'                  => '01',
                         'description'           => 'Transaction successfully processed',

                     );
                 }

             }

             if($result->txn_type == PURCHASE_BANK_X){
                 $purchase_response = PurchaseAquiredService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,$result->tms_batch);
                 if($purchase_response["code"] == "00"){
                     $result->txn_status = "COMPLETED";
                     $result->br_reference = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'           => '00',
                         'description'   => 'Successfully processed the transaction'
                     );
                 }else{
                     $result->updated_at = Carbon::now();
                     $result->response = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'                  => '01',
                         'description'           => 'Transaction successfully processed',

                     );
                 }

             }

             if($result->txn_type == PURCHASE_CASH_BACK_BANK_X){
                 $purchase_response = CashAquiredService::sendTransaction($result->id,$result->amount,$result->cash,$result->source_account,$result->narration,$result->tms_batch);
                 if($purchase_response["code"] == "00"){
                     $result->txn_status = "COMPLETED";
                     $result->br_reference = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'           => '00',
                         'description'   => 'Successfully processed the transaction'
                     );
                 }else{
                     $result->updated_at = Carbon::now();
                     $result->response = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'                  => '01',
                         'description'           => 'Transaction successfully processed',

                     );
                 }

             }

             if($result->txn_type == WALLET_SETTLEMENT){
                 $purchase_response = WalletSettlementService::sendTransaction($result->id,$result->amount,$result->source_account,$result->destination_account,$result->narration);
                 if($purchase_response["code"] == "00"){
                     $result->txn_status = "COMPLETED";
                     $result->br_reference = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'           => '00',
                         'description'   => 'Successfully processed the transaction'
                     );
                 }else{
                     $result->updated_at = Carbon::now();
                     $result->response = $purchase_response["description"];
                     $result->save();
                     return array(
                         'code'                  => '01',
                         'description'           => 'Transaction successfully processed',

                     );
                 }

             }

        */
    }






    protected function br_balance_validation(Array $data){
        return Validator::make($data, [
            'account_number'    => 'required',
        ]);
    }

    protected function post_transaction_validation(Array $data){
        return Validator::make($data, [
            'amount'                => 'required',
            'account_number'        => 'required',
            'transaction_id'        => 'required',
            'mobile'                => 'required',
            'narration'             => 'required',
            'fees'                  => 'required',
        ]);
    }





}



























