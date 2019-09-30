<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletPostPurchaseTxns;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\WalletTransaction;


class PurchaseCashOnUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */





    public function purchase_cashback(Request $request)
    {

        $validator = $this->purchase_cashback_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


            /*
             *
             *Declarations
             */

            $card_number = str_limit($request->card_number,16,'');
            $cash_back_amount =  $request->cashback_amount /100;
            $merchant_id = Devices::where('imei', $request->imei)->first();
            $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
            $merchant_account->account_number;
            $branch_id = substr($merchant_account->account_number, 0, 3);


            try {




                 $fees_result = FeesCalculatorService::calculateFees(
                    $request->amount ,
                    $request->cashback_amount,
                    PURCHASE_CASH_BACK_ON_US,
                    $merchant_id->merchant_id

                );



                    $total_count  = Transactions::where('account_debited',$request->account_number)
                     ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
                     ->where('description','Transaction successfully processed.')
                     ->whereDate('created_at', Carbon::today())
                     ->get()->count();





                 if($total_count  >= $fees_result['transaction_count'] ){

                     Transactions::create([

                         'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
                         'tax'                 => '0.00',
                         'revenue_fees'        => '0.00',
                         'interchange_fees'    => '0.00',
                         'zimswitch_fee'       => '0.00',
                         'transaction_amount'  => '0.00',
                         'total_debited'       => '0.00',
                         'total_credited'      => '0.00',
                         'batch_id'            => '',
                         'switch_reference'    => '',
                         'merchant_id'         => '',
                         'transaction_status'  => 0,
                         'account_debited'     => $request->br_account,
                         'pan'                 => '',
                         'description'         => 'Transaction limit reached for the day.',


                     ]);


                     return response([
                         'code' => '121',
                         'description' => 'Transaction limit reached for the day.',

                     ]);
                 }



                if($request->amount /100 > $fees_result['maximum_daily']){

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
                        'tax'                 => '0.00',
                        'revenue_fees'        => '0.00',
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '0.00',
                        'transaction_amount'  => '0.00',
                        'total_debited'       => '0.00',
                        'total_credited'      => '0.00',
                        'batch_id'            => '',
                        'switch_reference'    => '',
                        'merchant_id'         => '',
                        'transaction_status'  => 0,
                        'account_debited'     => $request->account_number,
                        'pan'                 => $request->card_number,
                        'description'         => 'Invalid amount, error 902',


                    ]);

                    return response([
                        'code' => '902',
                        'description' => 'Invalid mount',

                    ]);
                }




                $revenue = REVENUE;
                $tax = TAX;

                    $credit_merchant_account = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $merchant_account->account_number,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => 'Purchase + Cash on us credit merchant account',
                        'TrxAmount'         => $request->amount /100);

                    $debit_client_amount = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $request->account_number,
                        'TrxDescriptionID'  => '007',
                        'TrxDescription'    => 'Purchase + Cash on us debit client with purchase amount',
                        'TrxAmount'         => '-' . $request->amount / 100);

                    $credit_merchant_cashback_amount = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $merchant_account->account_number,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => 'Purchase + Cash on us credit merchant account with cash back amount',
                        'TrxAmount'         =>  $cash_back_amount);

                    $debit_client_amount_cashback_amount = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $request->account_number,
                        'TrxDescriptionID'  => '007',
                        'TrxDescription'    => 'Purchase + Cash on us debit client with cash back amount',
                        'TrxAmount'         => '-' .  $cash_back_amount);


                    $debit_client_fees = array(
                        'SerialNo'        => '472100',
                        'OurBranchID'     => $branch_id,
                        'AccountID'       => $request->account_number,
                        'TrxDescriptionID'=> '007',
                        'TrxDescription'  => 'Purchase + Cash on us debit client with fees',
                        'TrxAmount'       => '-' . $fees_result['fees_charged']);



                    $credit_revenue_fees = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $revenue,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => "Purchase + Cash on us credit revenue account with fees",
                        'TrxAmount'         => $fees_result['acquirer_fee']);

                    $tax_account_credit = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $tax,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => "Purchase + Cash  on us tax account credit",
                        'TrxAmount'         => $fees_result['tax']);

                    $debit_merchant_account_mdr = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $merchant_account->account_number,
                        'TrxDescriptionID'  => '007',
                        'TrxDescription'    => 'Purchase + Cash on us, debit merchant account with mdr fees',
                        'TrxAmount'         => '-' . $fees_result['mdr']);

                    $credit_revenue_mdr = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $revenue,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => "Purchase + Cash  on us tax account credit",
                        'TrxAmount'         => $fees_result['mdr']);



                    $credit_revenue_cashback_fee = array(
                        'SerialNo'          => '472100',
                        'OurBranchID'       => $branch_id,
                        'AccountID'         => $revenue,
                        'TrxDescriptionID'  => '008',
                        'TrxDescription'    => "Purchase + Cash credit revenue with cashback fees",
                        'TrxAmount'         => $fees_result['cash_back_fee']);



                    $authentication = TokenService::getToken();
                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array(

                                    $credit_merchant_account,
                                    $debit_client_amount,
                                    $debit_client_fees,
                                    $credit_revenue_fees,
                                    $tax_account_credit,
                                    $debit_merchant_account_mdr,
                                    $credit_revenue_mdr,
                                    $credit_revenue_cashback_fee,
                                    $credit_merchant_cashback_amount,
                                    $debit_client_amount_cashback_amount

                                ),
                            ]

                        ]);


                       // return $response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());


                         if($response->description == 'API : Validation Failed: Customer TrxAmount cannot be Greater Than the AvailableBalance'){


                             Transactions::create([

                                 'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
                                 'tax'                 => '0.00',
                                 'revenue_fees'        => '0.00',
                                 'interchange_fees'    => '0.00',
                                 'zimswitch_fee'       => '0.00',
                                 'transaction_amount'  => '0.00',
                                 'total_debited'       => '0.00',
                                 'total_credited'      => '0.00',
                                 'batch_id'            => '',
                                 'switch_reference'    => '',
                                 'merchant_id'         => '',
                                 'transaction_status'  => 0,
                                 'account_debited'     => $request->account_number,
                                 'pan'                 => $card_number,
                                 'description'         => 'Insufficient Funds',


                             ]);

                             return response([
                                 'code' => '116 ',
                                 'description' => 'Insufficient Funds',

                             ]);

                         };
                        if($response->code != '00'){

                            Transactions::create([
                                'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
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
                                'account_debited'     => $request->account_number,
                                'pan'                 => $card_number,
                                'merchant_account'    => '',
                                'description'         => 'Invalid BR account',

                            ]);

                            return response([

                                'code' => '100',
                                'description' => 'Invalid BR account'


                            ]);



                        }


                            $total_txn_amount = $request->amount / 100 + $request->cashback_amount / 100;
                            $merchant_account_amount  = $total_txn_amount - $fees_result['mdr'];
                            $total_debit  = $total_txn_amount + $fees_result['fees_charged'];
                            $rev =  $fees_result['acquirer_fee'] +  $fees_result['cash_back_fee'] +$fees_result['mdr'];

                            Transactions::create([

                                'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
                                'tax'                 => $fees_result['tax'],
                                'revenue_fees'        =>  $rev,
                                'interchange_fees'    => '0.00',
                                'zimswitch_fee'       => '0.00',
                                'transaction_amount'  => $total_txn_amount,
                                'total_debited'       => $total_debit,
                                'total_credited'      => $total_debit,
                                'batch_id'            => $response->transaction_batch_id,
                                'switch_reference'    => $response->transaction_batch_id,
                                'merchant_id'         => $merchant_id->merchant_id,
                                'transaction_status'  => 1,
                                'account_debited'       => $request->account_number,
                                'pan'                 => $card_number,
                                'merchant_account'    => $merchant_account_amount,
                                'cash_back_amount'    => $cash_back_amount,
                                'description'         => 'Transaction successfully processed.',



                            ]);



                            return response([

                                'code' => '000',
                                'batch_id' => (string)$response->transaction_batch_id,
                                'description' => 'Success'


                            ]);




                    } catch (ClientException $exception) {

                        return $exception;
                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
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
                            'account_debited'     => $request->account_number,
                            'pan'                 => $card_number,
                            'description'         =>$exception,


                        ]);

                        return response([

                            'code' => '100',
                            'description' => $exception


                        ]);


                    }




            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                     $exception = (string)$e->getResponse()->getBody();
                    Log::debug('Account Number:'.$request->account_number.' '.  $exception);


                    Transactions::create([

                        'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
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
                        'account_debited'     => $request->account_number,
                        'pan'                 => $card_number,


                    ]);

                    return response([

                        'code' => '100',
                        'description' => $exception->message


                    ]);


                } else {

                    Log::debug('Account Number:'.$request->account_number.' '.  $e->getMessage());

                    ;

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
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
                        'account_debited'     => $request->account_number,
                        'pan'                 => $card_number,
                        'description'         => 'Failed to BR process transactions',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to BR process transactions'


                    ]);

                }
            }




    }



    protected function purchase_cashback_validation(Array $data)
    {
        return Validator::make($data, [
            'account_number' => 'required',
            'amount' => 'required',
            'card_number' => 'required',
            'cashback_amount' => 'required',
            'imei' => 'required',

        ]);
    }








}