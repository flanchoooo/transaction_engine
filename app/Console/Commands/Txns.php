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
    public function handle()
    {




        $item = PendingTxn::where('transaction_type_id', BALANCE_ENQUIRY_OFF_US)->get()->last();
        $merchant_id = Devices::where('imei', $item->imei)->first();

        if (!isset($item)) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }



        $fees_result = FeesCalculatorService::calculateFees(
            '0.00',
            '0.00',
            BALANCE_ENQUIRY_BANK_X,
            $merchant_id->merchant_id //Zimswitch Merchant to be created.
        );


        $zimswitch_account = Accounts::find(1);
        $revenue = Accounts::find(2);

        $account_debit = array('SerialNo' => '472100',
            'OurBranchID' => '001',
            'AccountID' => $zimswitch_account->account_number,
            'TrxDescriptionID' => '007',
            'TrxDescription' => 'Balance enquiry bank x card, debit  zimswitch fees',
            'TrxAmount' => '-' . $fees_result['fees_charged']);

        $credit_zimswitch = array('SerialNo' => '472100',
            'OurBranchID' => '001',
            'AccountID' => $revenue->account_number,
            'TrxDescriptionID' => '008',
            'TrxDescription' => "Balance enquiry bank x card, credit revenue with fees",
            'TrxAmount' => $fees_result['zimswitch_fee']);


        $auth = TokenService::getToken();
        $client = new Client();

        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $credit_zimswitch,
                    ),
                ],
            ]);

            // $response_ = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());



            //Record Txn
            Transactions::create([

                'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                'tax'                 => '0.00',
                'revenue_fees'        => $fees_result['fees_charged'],
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       =>  '-'.$fees_result['zimswitch_fee'],
                'transaction_amount'  => '0.00',
                'total_debited'       => $fees_result['fees_charged'],
                'total_credited'      => $fees_result['fees_charged'],
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $response->transaction_batch_id,
                'merchant_id'         => $merchant_id->merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $zimswitch_account->account_number,
                'pan'                 =>  str_limit($item->card_number, 16, ''),
                'description'         => 'Transaction successfully processed.',


            ]);

            PendingTxn::destroy($item->id);


        }catch (ClientException $exception){

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
                'merchant_id'         => $merchant_id->merchant_id,
                'transaction_status'  => 0,
                'account_debited'     => '',
                'pan'                 =>  str_limit($item->card_number, 16, ''),
                'description'         => 'Failed to process the transaction',

            ]);

            PendingTxn::destroy($item->id);

        }


    }








}