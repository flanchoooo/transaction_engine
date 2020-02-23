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

class FAILED extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'failed_zipit:run';

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
            ->where('txn_type', ZIPIT_RECEIVE)
            ->sharedLock()
            ->get();


        if ($result->isEmpty()) {
           return 'OK';
        }

        foreach ($result as $item) {
            $this->process_zipit($item->id,$item->amount, $item->source_account, $item->rrn);

        }


    }
    public function process_zipit($id,$amount, $account, $rrn){
        $result = BRJob::where('id',$id);
        $update = $result->lockForUpdate()->first();
        $res = $update->br_reference;
        if(!empty($res)){
            $update->txn_status = 'COMPLETED';
            $update->response = 'Transaction already processed';
            $update->save();

            return 'OK';
        }

            $branch_id = substr($account, 0, 3);
            $destination_account_credit = array(
                'serial_no'             => '472100',
                'our_branch_id'         => $branch_id,
                'account_id'            => $account,
                'trx_description_id'    => '007',
                'trx_description'       =>    "SP | ZIPIT RECEIVE | $rrn",
                'trx_amount'            => $amount);


            $zimswitch_debit = array(
                'serial_no'             => '472100',
                'our_branch_id'         => $branch_id,
                'account_id'            => ZIMSWITCH,
                'trx_description_id'    => '008',
                'trx_description'       => "SP | ZIPIT RECEIVE | $account | $rrn",
                'trx_amount'            => -$amount);

            $client = new Client();
            $token = 'TEST';
            try {
                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $token, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(
                            $destination_account_credit,
                            $zimswitch_debit,

                        )
                    ]

                ]);

                $response = json_decode($result->getBody()->getContents());
                if ($response->code != '00') {
                    $update->txn_status = 'FAILED';
                    $update->response = $response->description;
                    $update->save();
                    return array(
                        'code'          => $response->code,
                        'description'   =>$response->description

                    );
                }

                $update->br_reference = $response->description;
                $update->txn_status = 'COMPLETED';
                $update->response = 'Transaction successfully processed';
                $update->save();
                LoggingService::message('Zipit reattempt successfully processed.' .$account);

                return array(
                    'code'          => $response->code,
                    'description'   => $response->transaction_batch_id,
                );

            } catch (RequestException $e) {


                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();
                    LoggingService::message('Txn: ZIPIT SEND' . "$account     " . $exception);
                    $update->txn_status = 'FAILED';
                    $update->response = $exception;
                    $update->save();
                    return array(
                        'code' => '100',
                        'description' => $exception
                    );

                } else {
                    $update->txn_status = 'FAILED';
                    $update->response = $e->getMessage();
                    $update->save();
                    LoggingService::message('ZIPIT SEND:' . $account . '  ' . $account . ' ' . $e->getMessage());
                    return array(
                        'code' => '100',
                        'description' => $e->getMessage()
                    );

                }
            }


        }


}
