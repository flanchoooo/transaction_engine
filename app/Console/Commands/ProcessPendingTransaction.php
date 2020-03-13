<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRJob;
use App\Configuration;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\PurchaseJob;
use App\Jobs\SaveTransaction;
use App\MDR;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\BalanceIssuedService;
use App\Services\CashAquiredService;
use App\Services\DuplicateTxnCheckerService;
use App\Services\EcocashService;
use App\Services\ElectricityService;
use App\Services\FeesCalculatorService;
use App\Services\HotRechargeService;
use App\Services\LoggingService;
use App\Services\MerchantServiceFee;
use App\Services\PurchaseAquiredService;
use App\Services\PurchaseCashService;
use App\Services\PurchaseIssuedService;
use App\Services\PurchaseOnUsService;
use App\Services\ReversalService;
use App\Services\TokenService;
use App\Services\WalletSettlementService;
use App\Services\ZipitReceiveService;
use App\Services\ZipitSendService;
use App\Transaction;
use App\Transactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class ProcessPendingTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process_pending_transaction:run';



    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deduct mdr fees';

    /**
     * @var bool
     */
     protected $executing =false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
 }

    /**
     * Execute the console command.
     *
     * @return mixed
     */


    public function  handle (){

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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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

            if($result->txn_type == BR_AIRTIME){
                $purchase_response = HotRechargeService::sendTransaction($result->id,$result->amount,$result->source_account,$result->narration,);
                if($purchase_response["code"] == "00"){
                    $result->txn_status = "COMPLETED";
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                $result->updated_at = Carbon::now();
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

            if($result->txn_type == PURCHASE_CASH_BACK_ON_US){
                $purchase_response = PurchaseCashService::sendTransaction($result->id,$result->amount,$result->cash,$result->source_account,$result->narration,$result->tms_batch);
                if($purchase_response["code"] == "00"){
                    $result->txn_status = "COMPLETED";
                    $result->br_reference = $purchase_response["description"];
                    $result->save();
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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
                    $result->updated_at = Carbon::now();
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


}

}