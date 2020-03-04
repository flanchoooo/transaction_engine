<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRAccountID;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Jobs\NotifyBills;
use App\LuhnCards;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PenaltyDeduction;
use App\PendingTxn;
use App\Services\AccountInformationService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PurchaseIssued extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase_issued:run';

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



    public function handle (){

        return;

        $process_txn = BRJob::where('txn_status', 'PENDING')
            ->where('txn_type',PURCHASE_OFF_US)
            ->sharedLock()
            ->limit(5)
            ->get();

        if (!isset($process_txn)) {

            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }

        Log::debug($process_txn);
        foreach ($process_txn as $item){
             $response = $this->process_purchase($item->amount,$item->source_account,$item->rrn);
            Log::debug('Txn: Account Number:'. $response["description"]);

            if($response["code"] != '00'){
                $item->txn_status = 'PENDING';
                $item->response =  $response["description"];
                $item->save();
                continue;
            }
            $item->br_reference = $response["description"];
            $item->txn_status = 'COMPLETED';
            $item->response =  $response["description"];
            $item->save();
        }


    }

    public function process_purchase ($amount,$account,$rrn){
        $fees_charged = FeesCalculatorService::calculateFees(
            $amount,
            '0.00',
            PURCHASE_OFF_US,
            HQMERCHANT // Configure Default Merchant
        );
        $fees_total = $fees_charged['fees_charged'];
        $branch_id = substr($account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => $account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase RRN: '. $rrn,
            'TrxAmount'             => '-' . $amount);


        $debit_client_fees = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => $account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase fees RRN: '. $rrn,
            'TrxAmount'             => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => TAX,
            'trx_description_id'      => '008',
            'TrxDescription'        => "Transaction Tax RRN:$rrn",
            'TrxAmount'             => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => 'POS Purchase Acc:'.$account.'  RRN:'. $rrn,
            'TrxAmount'             =>  $amount);


        $acquirer_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Acquirer fees RRN:$rrn   Acc:$account",
            'TrxAmount'             =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Switch fee RRN:$rrn  Acc:$account",
            'TrxAmount'             =>  $fees_charged['zimswitch_fee']);


        try {
            $token = TokenService::getToken();

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






}
