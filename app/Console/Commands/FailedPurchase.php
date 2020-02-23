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

class FailedPurchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'failed_purchase:run';

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
            ->where('txn_type',PURCHASE_OFF_US)
            ->get();


        if ($result->isEmpty()) {
            return 'OK';
        }

        foreach ($result as $item) {
            $this->process_purchase($item->id,$item->amount, $item->source_account, $item->rrn);
            LoggingService::message('Purchase reattempt in progress' . $item->source_account);

        }
    }

    public function process_purchase ($id,$amount,$account,$rrn){

        $result = BRJob::where('id',$id);
        $update = $result->lockForUpdate()->first();
        $res = $update->br_reference;
        if(!empty($res)){
            $update->txn_status = 'COMPLETED';
            $update->response = 'Transaction already processed';
            $update->save();

            return 'OK';
        }



        $fees_charged = FeesCalculatorService::calculateFees(
            $amount,
            '0.00',
            PURCHASE_OFF_US,
            HQMERCHANT,$account
        );
        $fees_total = $fees_charged['fees_charged'] - $fees_charged['tax'];
        $branch_id = substr($account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $account,
            'trx_description_id'        => '007',
            'trx_description'           => "POS Purchase | $rrn",
            'trx_amount'                => '-' . $amount);

        $tax           = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $account,
            'trx_description_id'        => '007',
            'trx_description'           =>  "POS Purchase Tax | $rrn",
            'trx_amount'                => '-' . $fees_charged['tax']);

        $debit_client_fees = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $account,
            'trx_description_id'        => '007',
            'trx_description'           =>  "POS Purchase fees | $rrn",
            'trx_amount'                => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => TAX,
            'trx_description_id'        => '008',
            'trx_description'           => "Transaction Tax RRN:$rrn",
            'trx_amount'                => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => 'POS Purchase Acc:'.$account.'  RRN:'. $rrn,
            'trx_amount'                =>  $amount);

        $acquirer_fee = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "POS Purchase Acquirer fees RRN:$rrn   Acc:$account",
            'trx_amount'                =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => "POS Purchase Switch fee RRN:$rrn  Acc:$account",
            'trx_amount'                =>  $fees_charged['zimswitch_fee']);

        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Test', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_zimswitch_amount,
                        $tax
                    ),
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){

                $update->txn_status = 'FAILED';
                $update->response = $response->description;
                $update->save();
                return array(
                    'code'          => $response->code,
                    'description'   =>  $response->description
                );
            }


            $update->br_reference = $response->description;
            $update->txn_status = 'COMPLETED';
            $update->response = 'Transaction successfully processed';
            $update->save();
            LoggingService::message('Purchase reattempt successfully processed.' .$account);
            return array(
                'code'          => $response->code,
                'description'   => $response->transaction_batch_id
            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                LoggingService::message('Txn: Account Number:' .PURCHASE_OFF_US.'  '. $account.' '. $exception);

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
                LoggingService::message('Txn: Account Number:' .PURCHASE_OFF_US.'  '. $account.' '. $e->getMessage());
                return array(
                    'code' => '100',
                    'description' =>$e->getMessage()
                );
            }
        }

    }

}
