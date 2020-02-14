<?php

namespace App\Console\Commands;

use App\Accounts;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\SaveTransaction;
use App\MDR;
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

class PenaltyDeduction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'penalty_deduction:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deduct mdr fees';

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
    public function handle()
    {
        $mdr = Deduct::where('txn_status', 'PENDING')
                                    ->sharedLock()
                                    ->get();
        if ($mdr->isEmpty()) {
                LoggingService::message('Flanchooo');
                return;
            }

            foreach ($mdr as $item) {
                $response = $this->deduct_mdr($item->amount, $item->source_account, $item->merchant, $item->destination_account, $item->description);
                if ($response["code"] == '00') {
                    LoggingService::message('Transaction successfully processed.');
                    $item->batch_id = $response["batch_id"];
                    $item->txn_status = 'COMPLETED';
                    $item->save();
                }
            }

        }


    public function deduct_mdr ($amount,$account,$merchant,$destination,$description){


        $account_debit = array(
            'serial_no'          => '472100',
            'our_branch_id'       =>  substr($account, 0, 3),
            'account_id'         => $account,
            'trx_description_id'  => '007',
            'trx_description'    => $description,
            'trx_amount'         => '-' . $amount);

        $bank_revenue_credit    = array(
            'serial_no'          => '472100',
            'our_branch_id'       => substr($account, 0, 3),
            'account_id'         => $destination,
            'trx_description_id'  => '008',
            'trx_description'    => $description,
            'trx_amount'         =>  $amount);


        $client = new Client();
        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Test', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $bank_revenue_credit,
                    ),
                ],
            ]);

            $response = json_decode($result->getBody()->getContents());
             if($response->code == '00'){
                 return array(
                     'code' => $response->code,
                     'batch_id' => $response->transaction_batch_id
                 );
              }

        }catch (RequestException $e) {
            LoggingService::message('Failed to process transactions.');
            return array(
                'code' => '01'
            );
        }


    }













}