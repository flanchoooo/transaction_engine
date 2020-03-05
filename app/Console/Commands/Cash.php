<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Jobs\NotifyBills;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class Cash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:run';

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

        $items = PendingTxn::where('transaction_type_id', PURCHASE_CASH_BACK_BANK_X)
            ->where('status', 'DRAFT')
            ->get();

        if ($items->isEmpty()) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }


        foreach ($items as $item){
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
                PURCHASE_CASH_BACK_OFF_US,
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
            $br_job->cash = $item->cash_back_amount;
            $br_job->rrn = $item->transaction_id;
            $br_job->txn_type = PURCHASE_CASH_BACK_BANK_X;
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
            $item->save();

            try{
                $br_jobs->save();
                $item->status= 'COMPLETED';
            }catch(QueryException $queryException){
                LoggingService::message($queryException->getMessage());
                $item->status= 'FAILED';
            }

            $item->save();

        }


    }

    public function purchase_deduction($id,$card,$merchant,$amount,$cash_amount,$imei)
    {


        $card_number = str_limit($card, 16, '');
        $cash_back_amount = $cash_amount;
        $merchant_account = MerchantAccount::where('merchant_id', $merchant)->first();
        $branch_id = substr( $merchant_account->account_number, 0, 3);

        $purchase_amount = $amount - $cash_amount;


        try {

            $fees_result = FeesCalculatorService::calculateFees(
                $purchase_amount,
                $cash_back_amount,
                PURCHASE_CASH_BACK_BANK_X,
                $merchant,$merchant_account->account_number

            );

            $total_funds = $purchase_amount + $cash_back_amount + $fees_result['interchange_fee'] + $fees_result['acquirer_fee'];


            $debit_zimswitch  = array(
                'serial_no'          => '472100',
                'our_branch_id'       => $branch_id,
                'account_id'         => ZIMSWITCH,
                'trx_description_id'  => '007',
                'trx_description'    => 'POS SALE & CASH RRN'.$id,
                'trx_amount'         => '-' . $amount );

            $credit_merchant_purchase = array(
                'serial_no'         => '472100',
                'our_branch_id'      => $branch_id,
                'account_id'        => $merchant_account->account_number,
                'trx_description_id' => '008',
                'trx_description'   => 'POS SALE & CASH RRN'.$id,
                'trx_amount'        => $amount);



            $debit = array(
                'serial_no'             => '472100',
                'our_branch_id'         => $branch_id,
                'account_id'            => ZIMSWITCH,
                'trx_description_id'    => '007',
                'trx_description'       => 'POS SALE Acquirer Fee:'.$id,
                'trx_amount'            => '-' . $fees_result['acquirer_fee']);


            $credit = array(
                'serial_no'             => '472100',
                'our_branch_id'         => substr($merchant_account->account_number, 0, 3),
                'account_id'             => REVENUE,
                'trx_description_id'    => '008',
                'trx_description'       => 'POS SALE Acquirer Fee:'.$id,
                'trx_amount'            => $fees_result['acquirer_fee']);


            $auth = 'Test';
            $client = new Client();

            try {
                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(
                            $debit_zimswitch,
                            $credit_merchant_purchase,
                            $debit,
                            $credit
                        ),
                    ]

                ]);


                $response = json_decode($result->getBody()->getContents());
                $revenue_fees = $fees_result['interchange_fee'] + $fees_result['acquirer_fee'] + $fees_result['mdr'];
                $merchant_account_amount = - $fees_result['mdr'] + $amount + $cash_back_amount;

                if($response->code == '00'){
                    Transactions::create([

                        'txn_type_id'         => PURCHASE_CASH_BACK_BANK_X,
                        'revenue_fees'        => $revenue_fees,
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '-'.$total_funds,
                        'tax'                 => '0.00',
                        'transaction_amount'  => $amount,
                        'total_debited'       => $total_funds,
                        'total_credited'      => $total_funds,
                        'batch_id'            => $response->transaction_batch_id,
                        'switch_reference'    => $id,
                        'merchant_id'         => $merchant,
                        'transaction_status'  => 1,
                        'account_debited'     => ZIMSWITCH,
                        'pan'                 => $card_number,
                        'merchant_account'    => $merchant_account_amount,
                        'description'         => 'Transaction successfully processed.',
                        'debit_mdr_from_merchant' => '-'. $fees_result['mdr'],
                        'cash_back_amount'    => $cash_back_amount,

                    ]);

                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $fees_result['mdr'];
                    $auto_deduction->source_account = $merchant_account->account_number;
                    $auto_deduction->destination_account = REVENUE;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->wallet_batch_id = $response->transaction_batch_id;
                    $auto_deduction->description = "Merchant service fee RRN: $id";
                    $auto_deduction->save();

                    MDR::create([
                        'amount'            => $fees_result['mdr'],
                        'imei'              => $imei,
                        'merchant'          => $merchant,
                        'source_account'    => $merchant_account->account_number,
                        'txn_status'        => 'PENDING',
                        'batch_id'          => $response->transaction_batch_id,

                    ]);

                    return array(
                        'code' => $response->code
                    );

                }


            } catch (ClientException $exception) {
                return array(
                    'code' => '01'
                );
            }

        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);
                return array(
                    'code' => '01'
                );

            } else {
                return array(
                    'code' => '01'
                );
            }
        }

    }








}