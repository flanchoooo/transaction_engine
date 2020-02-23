<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRJob;
use App\Devices;
use App\Employee;
use App\Jobs\BalanceJob;
use App\Jobs\PurchaseJob;
use App\Jobs\SaveTransaction;
use App\Jobs\ZipitReceive;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\AccountInformationService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FailedBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'failed_balance:run';

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
     * @param PurchaseJob $purchaseJob
     * @return mixed
     */

    public function handle()
    {


        $result = BRJob::where('txn_status', 'FAILED')
            ->where('txn_type', BALANCE_ENQUIRY_OFF_US)
            ->sharedLock()
            ->get();


        if ($result->isEmpty()) {
            return 'OK';
        }

        foreach ($result as $item) {
            $response = $this->process_balance($item->amount, $item->source_account, $item->rrn);
            LoggingService::message('Balance reattempt in progress' . $item->source_account);
            if ($response["code"] == '00') {
                $item->br_reference = $response["description"];
                $item->txn_status = 'COMPLETED';
                $item->response = 'Transaction processed successfully';
                $item->save();
                LoggingService::message('Balance successfully processed done' . $item->source_account);
            } else {
                $item->txn_status = 'FAILED';
                $item->response = $response["description"];
                $item->save();
            }
        }


    }


    public function process_balance ($amount,$account,$rrn){
        $branch_id = substr($account, 0, 3);
        $destination_account_credit = array(
            'serial_no'         => '472100',
            'our_branch_id'      => $branch_id,
            'account_id'        => $account,
            'trx_description_id' => '007',
            'trx_description'   => "SP | Balance fees",
            'trx_amount'        => -$amount);


        $zimswitch_debit = array(
            'serial_no'         => '472100',
            'our_branch_id'      =>$branch_id,
            'account_id'        => ZIMSWITCH,
            'trx_description_id' => '008',
            'trx_description'   => "SP | Balance fees RRN: $rrn",
            'trx_amount'        =>  $amount);


        $client = new Client();
        try{
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Test', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' =>  array(
                        $destination_account_credit,
                        $zimswitch_debit,

                    )
                ]

            ]);

            $response = json_decode($result->getBody()->getContents());
            if($response->code != '00'){
                return array(
                    'code'          =>   $response->code,
                    'description'   =>   $response->description

                );
            }

            return array(
                'code'          =>   $response->code,
                'description'   =>  $response->transaction_batch_id

            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message('Txn:Balance' . "$account     " . $exception);
                return array(
                    'code' => '100',
                    'description' => $exception
                );

            } else {
                LoggingService::message('Balance:' .$account.'  '. $account.' '. $e->getMessage());
                return array(
                    'code' => '100',
                    'description' =>  $e->getMessage()

                );

            }
        }


    }










}
