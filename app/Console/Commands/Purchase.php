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

class Purchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase:run';

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


        $type = PendingTxn::where('transaction_type_id', PURCHASE_OFF_US)->get()->last();

        if (!isset($type)) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }



        $card_number = str_limit($type->card_number, 16, '');
        $merchant_id = Devices::where('imei', $type->imei)->first();
        $merchant_account = MerchantAccount::where('merchant_id', $merchant_id->merchant_id)->first();
        $branch_id = substr($merchant_account->account_number, 0, 3);


        //Balance Enquiry On Us Debit Fees
        $fees_result = FeesCalculatorService::calculateFees(

            $type->amount,
            '0.00',
            PURCHASE_BANK_X,
            $merchant_id->merchant_id

        );


        $zimswitch = Accounts::find(1);
        $revenue = Accounts::find(2);

        $debit_zimswitch_with_purchase_amnt = array('SerialNo' => '472100',
            'OurBranchID' => $branch_id,
            'AccountID' => $zimswitch->account_number,
            'TrxDescriptionID' => '007',
            'TrxDescription' => 'Purchase bank x,debit zimswitch purchase amount',
            'TrxAmount' => '-' . $type->amount);

        $debit_zimswitch_with_fees = array('SerialNo' => '472100',
            'OurBranchID' => $branch_id,
            'AccountID' => $zimswitch->account_number,
            'TrxDescriptionID' => '007',
            'TrxDescription' => 'Purchase bank x,debit acquirer fees',
            'TrxAmount' => '-' . $fees_result['acquirer_fee']);


        $credit_merchant_account = array('SerialNo' => '472100',
            'OurBranchID' => substr($merchant_account->account_number, 0, 3),
            'AccountID' => $merchant_account->account_number,
            'TrxDescriptionID' => '008',
            'TrxDescription' => 'Purchase bank x, credit merchant account with purchase amount',
            'TrxAmount' => $type->amount);


        $credit_revenue_fees = array('SerialNo' => '472100',
            'OurBranchID' => '001',
            'AccountID' => $revenue->account_number,
            'TrxDescriptionID' => '008',
            'TrxDescription' => "Purchase bank x,credit revenue account with fees",
            'TrxAmount' => $fees_result['acquirer_fee']);


        $debit_merchant_account_mdr = array('SerialNo' => '472100',
            'OurBranchID' => substr($merchant_account->account_number, 0, 3),
            'AccountID' => $merchant_account->account_number,
            'TrxDescriptionID' => '007',
            'TrxDescription' => 'Purchase bank x, debit merchant account with mdr fees',
            'TrxAmount' => '-' . $fees_result['mdr']);

        $credit_revenue_mdr = array('SerialNo' => '472100',
            'OurBranchID' => '001',
            'AccountID' => $revenue->account_number,
            'TrxDescriptionID' => '008',
            'TrxDescription' => "Purchase bank x credit revenue with mdr",
            'TrxAmount' => $fees_result['mdr']);


        $auth = TokenService::getToken();
        $client = new Client();


        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_zimswitch_with_purchase_amnt,
                        $debit_zimswitch_with_fees,
                        $credit_merchant_account,
                        $credit_revenue_fees,
                        $debit_merchant_account_mdr,
                        $credit_revenue_mdr,

                    ),
                ]
            ]);



            //return  $result->getBody()->getContents();

            $response = json_decode($result->getBody()->getContents());

            $rev =  $fees_result['mdr'] + $fees_result['acquirer_fee'];
            $zimswitch_amount = $type->amount + $fees_result['acquirer_fee'];
            $merchant_account_amount = $type->amount  - $fees_result['mdr'];

            Transactions::create([

                'txn_type_id'         => PURCHASE_BANK_X,
                'revenue_fees'        => $rev,
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '-'.$zimswitch_amount,
                'transaction_amount'  => $type->amount,
                'total_debited'       => $zimswitch_amount,
                'total_credited'      => $zimswitch_amount,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $response->transaction_batch_id,
                'merchant_id'         => $merchant_id->merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $zimswitch->account_number,
                'pan'                 => $card_number,
                'merchant_account'    => $merchant_account_amount,
                'description'         => 'Transaction successfully processed.',

            ]);

            PendingTxn::destroy($type->id);


        } catch (ClientException $exception) {

            Transactions::create([

                'txn_type_id'         => PURCHASE_BANK_X,
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
                'transaction_status'  => 1,
                'account_debited'     => '',
                'pan'                 => '',
                'merchant_account'    => '',
                'description'         => 'Failed to process transaction',

            ]);

            PendingTxn::destroy($type->id);



        }




    }








}