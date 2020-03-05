<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\SaveTransaction;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class Purchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Pending Transactions';

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
    public function handle(){

        $items = PendingTxn::where('transaction_type_id', PURCHASE_BANK_X)
            ->where('status', 'DRAFT')
            ->get();


        if ($items->isEmpty()) {
            LoggingService::message('No merchant to settle');
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }



        foreach ($items as $item){

            try{
            LoggingService::message('Successfully dispatched merchant settlement request');
            $merchant_id = Devices::where('imei', $item->imei)->first();
            $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
            if(!isset($merchant_account)){
                $item->status= 'FAILED';
                $item->save();
            }

            $fees_result = FeesCalculatorService::calculateFees(
                $item->amount,
                '0.00',
                PURCHASE_BANK_X,
                $merchant_id->merchant_id,$merchant_account->account_number
            );

            $br_job = new BRJob();
            $br_job->txn_status = 'PENDING';
            $br_job->amount = $item->amount;
            $br_job->source_account = $merchant_account->account_number;
            $br_job->status = 'DRAFT';
            $br_job->version = 0;
            $br_job->tms_batch = $item->transaction_id;
            $br_job->narration = $item->imei;
            $br_job->rrn = $item->transaction_id;
            $br_job->txn_type = PURCHASE_BANK_X;
            $br_job->save();

            $br_jobs = new BRJob();
            $br_jobs->txn_status = 'PENDING';
            $br_jobs->amount = $fees_result['mdr'];
            $br_jobs->source_account = $merchant_account->account_number;
            $br_jobs->status = 'DRAFT';
            $br_jobs->version = 0;
            $br_jobs->tms_batch = $item->transaction_id;
            $br_jobs->narration = $item->imei;
            $br_jobs->rrn = $item->transaction_id;
            $br_jobs->txn_type = MDR_DEDUCTION;
            $br_jobs->save();
                    $item->status= 'COMPLETED';
                }catch(QueryException $queryException){
                    LoggingService::message($queryException->getMessage());
                    $item->status= 'FAILED';
                }

                $item->save();


        }

    }








}