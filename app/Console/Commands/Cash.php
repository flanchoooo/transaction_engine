<?php

namespace App\Console\Commands;

use App\Accounts;
use App\Devices;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

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
    public function handle()
    {


        $type = PendingTxn::where('transaction_type_id', PURCHASE_CASH_BACK_OFF_US)->get()->last();
        if (!isset($type)) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }

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

            $total_funds = $amount + $cash_back_amount + $fees_result['interchange_fee'] + $fees_result['acquirer_fee'];


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
                'AccountID' => $revenue->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription' => 'Purchase + Cash back bank x, credit merchant purchase amount',
                'TrxAmount' => $fees_result['mdr']);






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
                        ),
                    ]

                ]);


                // return  $response = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());
                $revenue_fees = $fees_result['interchange_fee'] + $fees_result['acquirer_fee'] + $fees_result['mdr'];
                $merchant_account_amount = - $fees_result['mdr'] + $amount + $cash_back_amount;



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
                        'pan'                 => '',
                        'merchant_account'    => '',
                        'description'         => $response->description,

                    ]);

                    return;

                }


                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_BANK_X,
                    'revenue_fees'        => $revenue_fees,
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '-'.$total_funds,
                    'tax'                 => '0.00',
                    'transaction_amount'  => $type->amount,
                    'total_debited'       => $total_funds,
                    'total_credited'      => $total_funds,
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $response->transaction_batch_id,
                    'merchant_id'         => $merchant_id->merchant_id,
                    'transaction_status'  => 1,
                    'account_debited'     => $zimswitch->account_number,
                    'pan'                 => $card_number,
                    'merchant_account'    => $merchant_account_amount,
                    'description'         => 'Transaction successfully processed.',
                    'debit_mdr_from_merchant' => '-'. $fees_result['mdr'],
                    'cash_back_amount'    => $cash_back_amount,

                ]);


                PendingTxn::destroy($type->id);

            } catch (ClientException $exception) {



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
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'         => 'Failed to process transaction',

                ]);

            }

        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);



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
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'         => 'Failed to process transaction',

                ]);

                return array('code' => '91',
                    'error' => $exception);

                PendingTxn::destroy($type->id);

            } else {

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
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'         => 'Failed to process transaction',

                ]);

                PendingTxn::destroy($type->id);
            }
        }



    }








}