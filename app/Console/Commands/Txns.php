<?php

namespace App\Console\Commands;

use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\SaveTransaction;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

class Txns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance_enquiry:run';

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

        return 0;
        $items = PendingTxn::where('transaction_type_id', BALANCE_ENQUIRY_OFF_US)->get();

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

    }

    public function balance_deduction($id,$merchant,$card_number,$imei)
    {
        $merchant_id = Devices::where('imei', $imei)->first();
        $fees_result = FeesCalculatorService::calculateFees(
            '0.00',
            '0.00',
            BALANCE_ENQUIRY_BANK_X,
            $merchant //Zimswitch Merchant to be created.
        );


        $debit = array(
            'serial_no'          => '472100',
            'our_branch_id'       => '001',
            'account_id'         => ZIMSWITCH,
            'trx_description_id'  => '007',
            'TrxDescription'    => 'Balance enquiry acquire RRN:'.$id,
            'TrxAmount'         => '-' . $fees_result['zimswitch_fee']);

        $credit = array(
            'serial_no'          => '472100',
            'our_branch_id'       => '001',
            'account_id'         => REVENUE,
            'trx_description_id'  => '008',
            'TrxDescription'    => "'Balance enquiry acquired: RRN:".$id,
            'TrxAmount'         => $fees_result['zimswitch_fee']);


        $auth = TokenService::getToken();
        $client = new Client();

        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit,
                        $credit,
                    ),
                ],
            ]);



            $response = json_decode($result->getBody()->getContents());

            if($response->code == '00'){
                Transactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                    'revenue_fees'        => $fees_result['zimswitch_fee'],
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '-'.$fees_result['zimswitch_fee'],
                    'transaction_amount'  => $fees_result['zimswitch_fee'],
                    'total_debited'       => $fees_result['zimswitch_fee'],
                    'total_credited'      => $fees_result['zimswitch_fee'],
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $response->transaction_batch_id,
                    'merchant_id'         => $merchant_id->merchant_id,
                    'transaction_status'  => 1,
                    'account_debited'     => ZIMSWITCH,
                    'account_credited'     => REVENUE,
                    'pan'                 => $card_number,
                    'description'         => 'Transaction successfully processed.',

                ]);
            }

            return array(
                'code' => $response->code
            );


        }catch (ClientException $exception){

            //  return $exception;
            Transactions::create([
                'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => $merchant,
                'transaction_status'  => 0,
                'account_debited'     => '',
                'pan'                 =>  str_limit($card_number, 16, ''),
                'description'         => 'Failed to process the transaction',

            ]);


            return array(
                'code' => '01'
            );

        }

    }









}