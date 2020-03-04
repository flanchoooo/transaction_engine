<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRJob;
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

class WalletSettlement extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet_settlement:run';

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
        
        $mdr = Deduct::where('txn_status', 'WALLET PENDING')
                                    ->sharedLock()
                                    ->get();
        if ($mdr->isEmpty()) {
                return 'No walllet to settle';
            }

            foreach ($mdr as $item) {
                $this->deduct_mdr($item->id,$item->amount, $item->source_account, $item->merchant, $item->destination_account, $item->description);
            }

        }


    public function deduct_mdr ($id,$amount,$account,$merchant,$destination,$description){

        $result = Deduct::where('id',$id);
        $update = $result->lockForUpdate()->first();
        $res = $update->batch_id;
        if(!empty($res)){
            $update->txn_status = 'COMPLETED';
            $update->response = 'Transaction already processed';
            $update->save();
            return 'OK';
        }

        $accounts  = strlen($account);
        if($accounts <= 8){
             $branch_id = substr($destination,0,3);
        }else{
             $branch_id = substr($account,0,3);
        }


        $account_debit = array(
            'serial_no'             => '472100',
            'our_branch_id'         =>  $branch_id,
            'account_id'            => $account,
            'trx_description_id'    => '007',
            'trx_description'       => $description,
            'trx_amount'            => '-' . $amount);

        $bank_revenue_credit    = array(
            'serial_no'             => '472100',
            'our_branch_id'         => $branch_id,
            'account_id'            => $destination,
            'trx_description_id'    => '008',
            'trx_description'       => $description,
            'trx_amount'            =>  $amount);


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
                     $update->batch_id = $response->transaction_batch_id;
                     $update->txn_status = 'COMPLETED';
                     $update->save();

                     return array(
                     'code' => $response->code,
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
