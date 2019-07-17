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
    protected $signature = 'txns:run';

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


        $item = PendingTxn::where('state', 0)->get();
        $employee_id = Employee::where('imei', $item->imei)->first();

        if(!isset($employee_id)){

            $user_id = '';
        }
        else{

            $user_id = $employee_id->id;
        }


        if (!isset($item)) {

            return response([

                'code' => '01',
                'description' => 'No transaction to process',

            ]);
        }

        foreach ($item as $type) {

            //Balance Bank X
            if ($type->transaction_type == '1') {


                $merchant_id = Devices::where('imei', $type->imei)->first();

                $card_number = str_limit($type->card_number, 16, '');
                //Balance Enquiry off Us Debit Fees
                $fees_result = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_BANK_X,
                    '28' //Zimswitch Merchant to be created.
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

                    //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());

                    if($response->code != '00'){

                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '-'.$fees_result['fees_charged'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 0,
                            'account_debited'     => $zimswitch_account->account_number,
                            'pan'                 => $card_number,
                            'description'         => 'Failed to process transaction',



                        ]);


                    }


                        //Record Txn
                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '-'.$fees_result['fees_charged'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 1,
                            'account_debited'     => $zimswitch_account->account_number,
                            'pan'                 => $card_number,
                            'employee_id'         => $user_id,


                        ]);



                        PendingTxn::destroy($type->id);



                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                        'tax'                 => '0.00',
                        'revenue_fees'        => '0.00',
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '0.00',
                        'transaction_amount'  => '0.00',
                        'total_debited'       => $fees_result['fees_charged'],
                        'total_credited'      => $fees_result['fees_charged'],
                        'batch_id'            => '',
                        'switch_reference'    => '',
                        'merchant_id'         => $merchant_id->merchant_id,
                        'transaction_status'  => 0,
                        'account_debited'     => $zimswitch_account->account_number,
                        'pan'                 => $card_number,
                        'description'         => $exception,


                    ]);


                    return array(

                        'code' => '100',
                        'error' => $exception

                    );


                }

            }

            if ($type->transaction_type == '2') {


                $card_number = str_limit($type->card_number, 16, '');
                $merchant_id = Devices::where('imei', $type->imei)->first();
                $merchant_account = MerchantAccount::where('merchant_id', $merchant_id->merchant_id)->first();

                //Merchant account
                $merchant_account->account_number;

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



                    $response = json_decode($result->getBody()->getContents());

                    if($response->code != '00'){


                        Transactions::create([

                            'txn_type_id'         => PURCHASE_BANK_X,
                            'tax'                 => '',
                            'revenue_fees'        => '0.00',
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '-'.$fees_result['acquirer_fee'],
                            'zimswitch_txn_amount'=> '-'.$type->amount,
                            'transaction_amount'  => $type->amount,
                            'total_debited'       => '',
                            'total_credited'      => '',
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 1,
                            'account_debited'     =>  $zimswitch->account_number,
                            'pan'                 =>  $card_number,
                            'merchant_account'    =>  '',
                            'description'         =>  'Failed to process transaction.',

                        ]);

                    }



                        $merchant_account_amount =   $type->amount -  $fees_result['mdr'];
                        $revenue_amount =    $fees_result['mdr'] +  $fees_result['acquirer_fee'];
                        $total = $revenue_amount + $fees_result['acquirer_fee'];

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_BANK_X,
                            'tax'                 => $revenue_amount,
                            'revenue_fees'        => '0.00',
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '-'.$fees_result['acquirer_fee'],
                            'zimswitch_txn_amount'=> '-'.$type->amount,
                            'transaction_amount'  => $type->amount,
                            'total_debited'       => $total,
                            'total_credited'      => $total,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 1,
                            'account_debited'     =>  $zimswitch->account_number,
                            'pan'                 =>  $card_number,
                            'merchant_account'    =>  $merchant_account_amount,

                        ]);


                        PendingTxn::destroy($type->id);




                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_BANK_X,
                        'tax'                 => $revenue_amount,
                        'revenue_fees'        => '0.00',
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '0.00',
                        'zimswitch_txn_amount'=> '0.00',
                        'transaction_amount'  => '0.00',
                        'total_debited'       => '0.00',
                        'total_credited'      => '0.00',
                        'batch_id'            => '',
                        'switch_reference'    => '',
                        'merchant_id'         => $merchant_id->merchant_id,
                        'transaction_status'  => 0,
                        'account_debited'     =>  $zimswitch->account_number,
                        'pan'                 =>  $card_number,
                        'merchant_account'    =>  '0.00',
                        'description'         =>  'Failed to process transaction',

                    ]);


                    return array('code' => '91',
                        'error' => $exception);


                }


            }

            if ($type->transaction_type == '3'){


                $card_number = str_limit($type->card_number, 16, '');
                $amount = $type->amount;
                $cash_back_amount = $type->cash_back_amount;

                $merchant_id = Devices::where('imei', $type->imei)->first();
                $merchant_account = MerchantAccount::where('merchant_id', $merchant_id->merchant_id)->first();


                $branch_id = substr( $merchant_account->account_number, 0, 3);

                $merchant_account->account_number;

                //Balance Enquiry On Us Debit Fees

                try {


                    $fees_result = FeesCalculatorService::calculateFees(
                        $amount,
                        $cash_back_amount,
                        PURCHASE_CASH_BACK_BANK_X,
                        $merchant_id->merchant_id

                    );

                    $total_funds =  $amount +
                                    $cash_back_amount +
                                    $fees_result['interchange_fee'] +
                                    $fees_result['acquirer_fee'];

                    // Check if client has enough funds.

                    $revenue = Accounts::find(2);
                    $zimswitch = Accounts::find(1);


                    $debit_zimswitch = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $zimswitch->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash back bank x, debit zimswitch',
                        'TrxAmount' => '-' . $total_funds );

                    $credit_merchant_purchase = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash back bank x, credit merchant purchase amount',
                        'TrxAmount' => $amount);

                    $credit_merchant_cash = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash back bank x, credit merchant cash amount',
                        'TrxAmount' => $cash_back_amount);

                    $credit_revenue = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $revenue->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash back bank x, credit revenue',
                        'TrxAmount' => $fees_result['interchange_fee'] + $fees_result['acquirer_fee']);

                    $debit_merchant_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash back bank x, debit merchant  mdr fees',
                        'TrxAmount' => '-' . $fees_result['mdr']);


                    $credit_revenue_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => $branch_id,
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash back bank x, credit merchant purchase amount',
                        'TrxAmount' => $fees_result['mdr']);

                    $credit_revenue_cashback_fee = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase + Cash credit revenue with cashback fees",
                        'TrxAmount' => $fees_result['cash_back_fee']);






                    $auth = TokenService::getToken();
                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array(
                                    $debit_zimswitch,
                                    $credit_merchant_purchase,
                                    $credit_merchant_cash,
                                    $credit_revenue,
                                    $debit_merchant_mdr,
                                    $credit_revenue_mdr,
                                    $credit_revenue_cashback_fee
                                ),
                            ]

                        ]);


                        //return $response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());

                        if($response->code != '00'){

                            Transactions::create([

                                'txn_type_id'         => PURCHASE_CASH_BACK_BANK_X,
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
                                'pan'                 => $card_number,
                                'merchant_account'    => $merchant_account_amount,
                                'employee_id'         => $user_id,
                                'cash_back_amount'    => $cash_back_amount,



                            ]);
                        }


                        $revenue_amount =   $fees_result['interchange_fee'] +
                                            $fees_result['acquirer_fee'] +
                                            $fees_result['cash_back_fee'] +
                                            $fees_result['mdr'];

                        $merchant_total_amount =    - $fees_result['mdr'] +  $amount + $cash_back_amount ;


                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_BANK_X,
                            'tax'                 => $fees_result['tax'],
                            'revenue_fees'        => $revenue_amount,
                            'interchange_fees'    => $fees_result['interchange_fee'],
                            'zimswitch_fee'       => '-'.$total_funds,
                            'transaction_amount'  => '0.00',
                            'total_debited'       => '0.00',
                            'total_credited'      => '0.00',
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 1,
                            'account_debited'     => '',
                            'pan'                 => $card_number,
                            'merchant_account'    =>$merchant_total_amount,
                            'employee_id'         => $user_id,
                            'cash_back_amount'    => $cash_back_amount,




                        ]);









                    } catch (ClientException $exception) {

                        Transaction::create([

                            'transaction_type' => '14',
                            'status' => 'FAILED',
                            'account' => '',
                            'pan' => $card_number,
                            'credit' => '0.00',
                            'debit' => '0.00',
                            'description' => 'Failed to process the transaction contact admin.',
                            'fee' => '0.00',
                            'batch_id' => '',
                            'merchant' => $type->imei,
                        ]);


                        return array('code' => '91',
                            'error' => $exception);


                    }

                } catch (RequestException $e) {
                    if ($e->hasResponse()) {
                        $exception = (string)$e->getResponse()->getBody();
                        $exception = json_decode($exception);


                        Transaction::create([

                            'transaction_type' => '14',
                            'status' => 'FAILED',
                            'account' => '',
                            'pan' => $card_number,
                            'credit' => '0.00',
                            'debit' => '0.00',
                            'description' => "BR. Net Error: $exception->message",
                            'fee' => '0.00',
                            'batch_id' => '',
                            'merchant' => $type->imei,
                        ]);


                        return array('code' => '91',
                            'error' => $exception);

                        //return new JsonResponse($exception, $e->getCode());
                    } else {
                        return array('code' => '01',
                            'error' => $e->getMessage());
                        //return new JsonResponse($e->getMessage(), 503);
                    }
                }

            }

        }
    }








}