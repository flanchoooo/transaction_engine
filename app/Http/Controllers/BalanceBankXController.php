<?php /** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\License;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;




class BalanceBankXController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */


    public function balance(Request $request)
    {


        $item = PendingTxn::where('state', 0)->get();

        if (!isset($item)) {

            return response([

                'code' => '01',
                'description' => 'No transaction to process',

            ]);
        }

        foreach ($item as $type) {

            if ($type->transaction_type == '1') {


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

                    if ($response->code == '00') {


                        //Record Txn
                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_BANK_X,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       =>  '-'.$fees_result['fees_charged'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => $merchant_id->merchant_id,
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $type->card_number,


                        ]);



                        dispatch(new SaveTransaction(
                                Balance_enquiry_off_us_debit_fees,
                                'COMPLETED',
                                $zimswitch_account->account_number,
                                $card_number,
                                0,
                                $fees_result['fees_charged'],
                                $fees_result['fees_charged'],
                                $response->transaction_batch_id,
                                $type->imei)
                        );

                        dispatch(new SaveTransaction(
                                Balance_enquiry_bank_x_card_credit_fees,
                                'COMPLETED',
                                $revenue->account_number,
                                $card_number,
                                $fees_result['zimswitch_fee'],
                                0,
                                $fees_result['zimswitch_fee'],
                                $response->transaction_batch_id,
                                $type->imei)
                        );



                 PendingTxn::destroy($type->id);

                    }

                } catch (ClientException $exception) {

                    Transaction::create([

                        'transaction_type' => Balance_enquiry_off_us_debit_fees,
                        'status' => 'FAILED',
                        'account' => $request->account_number,
                        'pan' => $card_number,
                        'credit' => '0.00',
                        'debit' => '0.00',
                        'description' => 'Failed to process the transaction contact admin.',
                        'fee' => '0.00',
                        'batch_id' => '',
                        'merchant' => $type->imei,
                    ]);


                    return array(

                        'code' => '91',
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
                    '37',
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

                    if ($response->code == '00') {


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_debit_Zimswitch_with_purchase_amount,
                                'COMPLETED',
                                $zimswitch->account_number,
                                $card_number,
                                '0.00',
                                $type->amount,
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_debit_Zimswitch_with_acquirer_fees,
                                'COMPLETED',
                                $zimswitch->account_number,
                                $card_number,
                                '0.00',
                                $fees_result['acquirer_fee'],
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_credit_merchant_account_with_purchase_amount,
                                'COMPLETED',
                                $merchant_account->account_number,
                                $card_number,
                                $type->amount,
                                '0.00',
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_credit_revenue_account_with_acquirer_fees,
                                'COMPLETED',
                                $revenue->account_number,
                                $card_number,
                                $fees_result['acquirer_fee'],
                                '0.00',
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_debit_merchant_account_with_mdr_fees,
                                'COMPLETED',
                                $merchant_account->account_number,
                                $card_number,
                                '0.00',
                                $fees_result['mdr'],
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        dispatch(new SaveTransaction(
                                Purchase_bank_x_credit_revenue_with_mdr_fees,
                                'COMPLETED',
                                $revenue->account_number,
                                $card_number,
                                $fees_result['mdr'],
                                '0.00',
                                '0.00',
                                $response->transaction_batch_id,
                                $type->imei)
                        );


                        PendingTxn::destroy($type->id);


                    }

                } catch (ClientException $exception) {

                    Transaction::create([

                        'transaction_type' => '37',
                        'status' => 'FAILED',
                        'account' => $request->account_number,
                        'pan' => $card_number,
                        'credit' => '0.00',
                        'debit' => '0.00',
                        'description' => 'Failed to process the transaction contact admin.',
                        'fee' => '0.00',
                        'batch_id' => '',
                        'merchant' => $request->imei,
                    ]);


                    return array('code' => '91',
                        'error' => $exception);


                }


            }

            if ($type->transaction_type == '3'){


                    $card_number = str_limit($request->card_number, 16, '');
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
                            Purchase_Cash_back_bank_x_debit_zimswitch,
                            $merchant_id->merchant_id

                        );

                        $total_funds = $amount + $cash_back_amount +
                            $fees_result['interchange_fee'] + $fees_result['acquirer_fee'];
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


                            //return $response_ = $result->getBody()->getContents();
                            $response = json_decode($result->getBody()->getContents());

                            if ($response->code == '00') {

                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_debit_zimswitch,
                                        'COMPLETED',
                                        $zimswitch->account_number,
                                        $card_number,
                                       '0.00',
                                        $total_funds,
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );

                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_credit_merchant_purchase_amount,
                                        'COMPLETED',
                                        $merchant_account->account_number,
                                        $card_number,
                                        $amount,
                                       '0.00',
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );


                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_credit_merchant_cash_amount,
                                        'COMPLETED',
                                        $merchant_account->account_number,
                                        $card_number,
                                        $cash_back_amount,
                                        '0.00',
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );


                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_credit_revenue_account_with_fees,
                                        'COMPLETED',
                                        $merchant_account->account_number,
                                        $card_number,
                                        $fees_result['interchange_fee'] + $fees_result['acquirer_fee'],
                                        '0.00',
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );



                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_debit_merchant_mdr_fees,
                                        'COMPLETED',
                                        $merchant_account->account_number,
                                        $card_number,
                                        '0.00',
                                        $fees_result['mdr'],
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );


                                dispatch(new SaveTransaction(
                                        Purchase_Cash_back_bank_x_credit_revenue_mdr_fees,
                                        'COMPLETED',
                                        $merchant_account->account_number,
                                        $card_number,
                                        $fees_result['mdr'],
                                        '0.00',
                                        '0.00',
                                        $response->transaction_batch_id,
                                        $type->imei)
                                );



                                PendingTxn::destroy($type->id);



                            }

                        } catch (ClientException $exception) {

                            Transaction::create([

                                'transaction_type' => '14',
                                'status' => 'FAILED',
                                'account' => $request->account_number,
                                'pan' => $card_number,
                                'credit' => '0.00',
                                'debit' => '0.00',
                                'description' => 'Failed to process the transaction contact admin.',
                                'fee' => '0.00',
                                'batch_id' => '',
                                'merchant' => $request->imei,
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
                                'account' => $request->account_number,
                                'pan' => $card_number,
                                'credit' => '0.00',
                                'debit' => '0.00',
                                'description' => "BR. Net Error: $exception->message",
                                'fee' => '0.00',
                                'batch_id' => '',
                                'merchant' => $request->imei,
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










