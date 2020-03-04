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

class IssuedTxns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'issued:run';

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


    public function handle (Request $request){

        $process_txn = BRJob::where('txn_status', 'PENDING')
                              ->where('txn_type', )

            ->get();

        if (!isset($items)) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }

        foreach ($items as $item){
            $merchant_id = Devices::where('imei', $item->imei)->first();
            $response = $this->balance_deduction($item->transaction_id,$merchant_id->merchant_id,$item->card_number,$item->imei);
            if($response["code"] == '00'){
                PendingTxn::destroy($item->id);
            }
        }




        $process_txn = BRJob::where('txn_status', 'PENDING')->first();

        if(!isset($process_txn)){
            return '01';
        }

        $fees_charged = FeesCalculatorService::calculateFees(
            $process_txn->amount,
            '0.00',
           ZIP,
            HQMERCHANT // Configure Default Merchant
        );

        $fees_total = $fees_charged['interchange_fee'] + $fees_charged['fees_charged'];
        $results =  AccountInformationService::getUserDetails($process_txn->source_account);
        if($results["code"] != '00'){
            return response([
                'code' => '100',
                'description' => 'Failed to fetch customer information',
            ]);
        }

        $branch_id = substr($process_txn->source_account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'account_id'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase RRN: '. $request->rrn,
            'TrxAmount'             => '-' . $process_txn->amount);


        $debit_client_fees = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase fees RRN: '. $process_txn->rrn,
            'TrxAmount'             => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => TAX,
            'trx_description_id'      => '008',
            'TrxDescription'        => "Transaction Tax RRN:$process_txn->rrn",
            'TrxAmount'             => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => 'POS Purchase Acc:'.$process_txn->source_account.'  RRN:'. $process_txn->rrn,
            'TrxAmount'             =>  $process_txn->amount);


        $acquirer_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Acquirer fees RRN:$process_txn->rrn   Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Switch fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['zimswitch_fee']);



        $credit_revenue = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Interchange fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             => $fees_charged['interchange_fee']);


        try {
            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $results["token"], 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_revenue,
                        $credit_zimswitch_amount,
                    ),
                ]
            ]);

            //return  $response = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if ($response->code == '00'){
                $process_txn->br_reference = $response->transaction_batch_id;
                $process_txn->txn_status = 'COMPLETED';
                $process_txn->save();

                echo "Processed Issued transaction" .'<br>';
            }

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $exception);
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);


            } else {


                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $e->getMessage());
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'
                ]);

            }
        }

    }



    public function process_zipit_job (Request $request){

        $process_txn = BRJob::where('txn_status', 'PENDING')->first();

        if(!isset($process_txn)){
            return '01';
        }

        $fees_charged = FeesCalculatorService::calculateFees(
            $process_txn->amount,
            '0.00',
            $process_txn->txn_type,
            HQMERCHANT // Configure Default Merchant
        );

        $fees_total = $fees_charged['interchange_fee'] + $fees_charged['fees_charged'];
        $results =  AccountInformationService::getUserDetails($process_txn->source_account);
        if($results["code"] != '00'){
            return response([
                'code' => '100',
                'description' => 'Failed to fetch customer information',
            ]);
        }

        $branch_id = substr($process_txn->source_account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase RRN: '. $request->rrn,
            'TrxAmount'             => '-' . $process_txn->amount);


        $debit_client_fees = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase fees RRN: '. $process_txn->rrn,
            'TrxAmount'             => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => TAX,
            'trx_description_id'      => '008',
            'TrxDescription'        => "Transaction Tax RRN:$process_txn->rrn",
            'TrxAmount'             => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => 'POS Purchase Acc:'.$process_txn->source_account.'  RRN:'. $process_txn->rrn,
            'TrxAmount'             =>  $process_txn->amount);


        $acquirer_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Acquirer fees RRN:$process_txn->rrn   Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Switch fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['zimswitch_fee']);



        $credit_revenue = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Interchange fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             => $fees_charged['interchange_fee']);


        try {
            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $results["token"], 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_revenue,
                        $credit_zimswitch_amount,
                    ),
                ]
            ]);

            //return  $response = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if ($response->code == '00'){
                $process_txn->br_reference = $response->transaction_batch_id;
                $process_txn->txn_status = 'COMPLETED';
                $process_txn->save();

                echo "Processed Issued transaction" .'<br>';
            }

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $exception);
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);


            } else {


                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $e->getMessage());
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'
                ]);

            }
        }

    }

    public function handles (Request $request){

        $process_txn = BRJob::where('txn_status', 'PENDING')->first();

        if(!isset($process_txn)){
            return '01';
        }

        $fees_charged = FeesCalculatorService::calculateFees(
            $process_txn->amount,
            '0.00',
            $process_txn->txn_type,
            HQMERCHANT // Configure Default Merchant
        );

        $fees_total = $fees_charged['interchange_fee'] + $fees_charged['fees_charged'];
        $results =  AccountInformationService::getUserDetails($process_txn->source_account);
        if($results["code"] != '00'){
            return response([
                'code' => '100',
                'description' => 'Failed to fetch customer information',
            ]);
        }

        $branch_id = substr($process_txn->source_account, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase RRN: '. $request->rrn,
            'TrxAmount'             => '-' . $process_txn->amount);


        $debit_client_fees = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => $process_txn->source_account,
            'trx_description_id'      => '007',
            'TrxDescription'        => 'POS Purchase fees RRN: '. $process_txn->rrn,
            'TrxAmount'             => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => TAX,
            'trx_description_id'      => '008',
            'TrxDescription'        => "Transaction Tax RRN:$process_txn->rrn",
            'TrxAmount'             => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => 'POS Purchase Acc:'.$process_txn->source_account.'  RRN:'. $process_txn->rrn,
            'TrxAmount'             =>  $process_txn->amount);


        $acquirer_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Acquirer fees RRN:$process_txn->rrn   Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'             => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => ZIMSWITCH,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Switch fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             =>  $fees_charged['zimswitch_fee']);



        $credit_revenue = array(
            'serial_no'              => '472100',
            'our_branch_id'           => $branch_id,
            'AccountID'             => REVENUE,
            'trx_description_id'      => '008',
            'TrxDescription'        => "POS Purchase Interchange fee RRN:$process_txn->rrn  Acc:$process_txn->source_account",
            'TrxAmount'             => $fees_charged['interchange_fee']);


        try {
            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $results["token"], 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_revenue,
                        $credit_zimswitch_amount,
                    ),
                ]
            ]);

            //return  $response = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if ($response->code == '00'){
                $process_txn->br_reference = $response->transaction_batch_id;
                $process_txn->txn_status = 'COMPLETED';
                $process_txn->save();

                echo "Processed Issued transaction" .'<br>';
            }

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $exception);
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);


            } else {


                Log::debug('Txn: Account Number:' .$process_txn->txn_type.'  '. $process_txn->source_account.' '. $e->getMessage());
                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'
                ]);

            }
        }

    }






}