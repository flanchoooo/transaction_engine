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
    protected $signature = 'failed:run';

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
            ->sharedLock()
            ->get();


        if ($result->isEmpty()) {
            LoggingService::message('No transaction to process');
        }

        foreach ($result as $item) {
            if ($item->txn_type == ZIPIT_RECEIVE) {
                $response = $this->process_zipit($item->amount, $item->source_account, $item->rrn);
                LoggingService::message('Zipit reattempt in progress' . $item->source_account);
                if ($response["code"] != '00') {
                    $item->txn_status = 'PENDING';
                    $item->response = $response["description"];
                    $item->save();
                    continue;
                }
                $item->br_reference = $response["description"];
                $item->txn_status = 'COMPLETED';
                $item->response = $response["description"];
                $item->save();
                LoggingService::message('Zipit successfully processed' . $item->source_account);
                continue;
            }

            if ($item->txn_type == PURCHASE_OFF_US) {
                    $response = $this->process_purchase($item->amount, $item->source_account, $item->rrn);
                    LoggingService::message('Purchase reattempt in progress' . $item->source_account);
                    if ($response["code"] != '00') {
                        $item->txn_status = 'PENDING';
                        $item->response = $response["description"];
                        $item->save();
                        continue;
                    }

                    $item->br_reference = $response["description"];
                    $item->txn_status = 'COMPLETED';
                    $item->response = $response["description"];
                    $item->save();
                    LoggingService::message('Purchase successfully processed done' . $item->source_account);
                    continue;
            }


            if ($item->txn_type == BALANCE_ENQUIRY_OFF_US) {
                $response = $this->process_balance($item->amount, $item->source_account, $item->rrn);
                LoggingService::message('Balance reattempt in progress' . $item->source_account);
                if ($response["code"] != '00') {
                    $item->txn_status = 'PENDING';
                    $item->response = $response["description"];
                    $item->save();
                    continue;
                }

                $item->br_reference = $response["description"];
                $item->txn_status = 'COMPLETED';
                $item->response = $response["description"];
                $item->save();
                LoggingService::message('Balance successfully processed done' . $item->source_account);
                continue;
            }
        }

    }

    public function process_zipit($amount, $account, $rrn)
        {


            $branch_id = substr($account, 0, 3);
            $destination_account_credit = array(
                'serial_no'             => '472100',
                'our_branch_id'         => $branch_id,
                'account_id'            => $account,
                'trx_description_id'    => '007',
                'trx_description' => "SP | ZIPIT RECEIVE | $rrn",
                'trx_amount' => $amount);


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

                //$response =$result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());
                if ($response->code == '00') {
                    return array(
                        'code' => $response->code,
                        'description' => $response->transaction_batch_id

                    );
                }

                return array(
                    'code' => $response->code,
                    'description' => $response->description,

                );


            } catch (RequestException $e) {

                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();

                    Log::debug('Txn: ZIPIT SEND' . "$account     " . $exception);
                    return array(
                        'code' => '100',
                        'description' => $exception


                    );


                } else {


                    Log::debug('ZIPIT SEND:' . $account . '  ' . $account . ' ' . $e->getMessage());
                    return array(
                        'code' => '100',
                        'description' => $e->getMessage()


                    );

                }
            }


        }

    public function process_purchase ($amount,$account,$rrn){
        $fees_charged = FeesCalculatorService::calculateFees(
            $amount,
            '0.00',
            PURCHASE_OFF_US,
            HQMERCHANT,$account // Configure Default Merchant
        );
        $fees_total = $fees_charged['fees_charged'];
        $branch_id = substr($account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $account,
            'trx_description_id'        => '007',
            'trx_description'           => "POS Purchase | $rrn",
            'trx_amount'                => '-' . $amount);


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
            $token = 'TEST';

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $token, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_zimswitch_amount,
                    ),
                ]
            ]);




            //return  $response = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if ($response->code == '00'){
                return array(
                    'code' => $response->code,
                    'description' =>  $response->transaction_batch_id
                );
            }

            return array(
                'code' => $response->code,
                'description' =>  $response->description
            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Txn: Account Number:' .PURCHASE_OFF_US.'  '. $account.' '. $exception);
                return array(
                    'code' => '100',
                    'description' => $exception
                );
            } else {
                Log::debug('Txn: Account Number:' .PURCHASE_OFF_US.'  '. $account.' '. $e->getMessage());
                return array(
                    'code' => '100',
                    'description' =>$e->getMessage()
                );
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

            //$response =$result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if($response->code == '00'){
                return array(
                    'code' =>   $response->code,
                    'description' =>   $response->transaction_batch_id

                );
            }

            return array(
                'code' =>   $response->code,
                'description' =>   ''

            );



        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Txn: ZIPIT SEND' . "$account     " . $exception);
                return array(
                    'code' => '100',
                    'description' => $exception
                );


            } else {
                Log::debug('ZIPIT SEND:' .$account.'  '. $account.' '. $e->getMessage());
                return array(

                    'code' => '100',
                    'description' =>  $e->getMessage()


                );

            }
        }


    }











}