<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\CardCheckerService;
use App\Services\CheckBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\LimitCheckerService;
use App\Services\TokenService;
use App\Services\ApiTokenValidity;
use App\Services\TransactionRecorder;
use App\Transactions;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use App\Zipit;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class ZipitController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */



    public function send(Request $request){

        //Validations
        $validator = $this->zipit_send_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        /**
         *
         *
         * Check if its a wallet transaction or not.
         */


         $account_checker = substr($request->br_account,0, 3);

         if($account_checker == '263'){

             $source = Wallet::where('mobile', $request->br_account)->get()->first();


             //Check Daily Spent
             $daily_spent =  WalletTransactions::where('account_debited', $source->mobile)
                 ->where('created_at', '>', Carbon::now()->subDays(1))
                 ->sum('transaction_amount');

             //Check Monthly Spent
             $monthly_spent =  WalletTransactions::where('account_debited', $source->mobile)
                 ->where('created_at', '>', Carbon::now()->subDays(30))
                 ->sum('transaction_amount');



             $wallet_cos = WalletCOS::find($source->wallet_cos_id);


             if($wallet_cos->maximum_daily <  $daily_spent){

                 return response([

                     'code' => '902',
                     'description' => 'Daily limit reached'

                 ]);
             }


             if($wallet_cos->maximum_monthly <  $monthly_spent){

                 return response([

                     'code' => '902',
                     'description' => 'Monthly limit reached'

                 ]);
             }



             //Balance Enquiry On Us Debit Fees
              $fees_charged = FeesCalculatorService::calculateFees(

                 $request->amount /100,
                 '0.00',
                 ZIPIT_SEND,
                 '28'

             );

              $total_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
             // Check if client has enough funds.

             $source->lockForUpdate()->first();
             if($total_deductions > $source->balance){

                 WalletTransactions::create([

                     'txn_type_id'         => ZIPIT_SEND,
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
                     'account_debited'     => $source->mobile,
                     'pan'                 => '',
                     'description'         => 'Insufficient funds',


                 ]);

                 return response([

                     'code' => '116',
                     'description' => 'Insufficient funds',

                 ]) ;


             }


             //Relevant Destination Accounts
             $revenue = Wallet::where('mobile', '263700000001')->get()->first();
             $tax = Wallet::where('mobile', '263700000000')->get()->first();
             $zimswitch_mobile = Wallet::where('mobile', '263700000004')->get()->first();

             try {

                 DB::beginTransaction();

                 //Deduct funds from source account
                 $source->lockForUpdate()->first();
                 $source_new_balance = $source->balance - $total_deductions;
                 $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                 $source->save();


                 $revenue->lockForUpdate()->first();
                 $revenue_new_balance = $revenue->balance + $fees_charged['acquirer_fee'];
                 $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
                 $revenue->save();


                 $tax->lockForUpdate()->first();
                 $tax_new_balance = $tax->balance + $fees_charged['tax'];
                 $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
                 $tax->save();

                 $zimswitch_mobile->lockForUpdate()->first();
                 $zimswitch_new_balance = $zimswitch_mobile->balance + $fees_charged['zimswitch_fee']  + ($request->amount /100) ;
                 $zimswitch_mobile->balance = number_format((float)$zimswitch_new_balance, 4, '.', '');
                 $zimswitch_mobile->save();


                 DB::commit();



             } catch (\Exception $e){

                 DB::rollback();

                 WalletTransactions::create([

                     'txn_type_id'         => ZIPIT_SEND,
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
                     'account_debited'     => $source->mobile,
                     'pan'                 => '',
                     'description'         => 'Transaction was reversed',


                 ]);



                 return response([

                     'code' => '400',
                     'description' => 'Transaction was reversed',

                 ]) ;

             }

             $mobi = substr_replace($source->mobile, '', -10, 3);
             $time_stamp = Carbon::now()->format('ymdhis');
             $reference = '95'. $time_stamp . $mobi;


             WalletTransactions::create([

                 'txn_type_id'         => ZIPIT_SEND,
                 'tax'                 => $fees_charged['tax'],
                 'revenue_fees'        => $fees_charged['acquirer_fee'],
                 'interchange_fees'    => '0.00',
                 'zimswitch_fee'       => $fees_charged['zimswitch_fee'],
                 'transaction_amount'  => '0.00',
                 'total_debited'       => $total_deductions,
                 'total_credited'      => $total_deductions,
                 'batch_id'            => $reference,
                 'switch_reference'    => $reference,
                 'merchant_id'         => '',
                 'transaction_status'  => 1,
                 'account_debited'     => $source->mobile,
                 'pan'                 => $request->card_number,


             ]);



             Zipit::create([

                 'source_bank'           =>'GETBUCKS',
                 'destination_bank'      =>$request->destination_bank,
                 'source'                =>$request->br_account,
                 'destination'           =>$request->destination_account,
                 'amount'                => $request->amount/100,
                 'type'                  =>'ZIPIT SEND',

             ]);

             // add jobs to update records
             return response([

                 'code' => '000',
                 'batch_id' => (string)$reference,
                 'description' => 'Success'


             ]);






         }






        /**
         *
         *
         * BR Transaction.
         */

        try {


            $account_number = $request->br_account;
            $user = new Client();
            $res = $user->post(env('BASE_URL') . '/api/authenticate', [
                'json' => [
                    'username' => env('TOKEN_USERNAME'),
                    'password' => env('TOKEN_PASSWORD'),
                ]
            ]);
            $tok = $res->getBody()->getContents();
            $bearer = json_decode($tok, true);
            $authentication = 'Bearer ' . $bearer['id_token'];

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $request->br_account,
                ]
            ]);

           // return $balance_response = $result->getBody()->getContents();
            $balance_response = json_decode($result->getBody()->getContents());

             $fees_result = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                ZIPIT_SEND,
               '28'

            );


            if($request->amount /100 > $fees_result['maximum_daily']){

                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Invalid amount, error 902',


                ]);

                return response([
                    'code' => '902',
                    'description' => 'Invalid mount',

                ]);
            }



            $total_funds = $request->amount / 100 + $request->cashback_amount / 100 +  $fees_result['fees_charged'];

            // Check if client has enough funds.
            if ($balance_response->available_balance < $total_funds) {

                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
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
                    'account_debited'     => $account_number,
                    'pan'                 => $request->card_number,
                    'description'         => 'Insufficient Funds',


                ]);

                return response([
                    'code' => '116',
                    'description' => 'Insufficient Funds',

                ]);

            } else {



                $zimswitch = Accounts::find(1);
                $revenue = Accounts::find(2);
                $tax =  Accounts::find(3);

                $account_debit = array('SerialNo'         => '472100',
                    'OurBranchID'      => substr($account_number, 0, 3),
                    'AccountID'        => $account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription'   => 'ZIPIT SEND',
                    'TrxAmount'        => -$request->amount/100);

                $account_debit_fees = array('SerialNo'         => '472100',
                    'OurBranchID'      => substr($account_number, 0, 3),
                    'AccountID'        => $account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription'   => "ZIPIT Transfer Fees Debit",
                    'TrxAmount'        => '-' . $fees_result['fees_charged']);

                $destination_credit_zimswitch = array('SerialNo'         => '472100',
                    'OurBranchID'      => '001',
                    'AccountID'        => $zimswitch->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                    'TrxAmount'        => $request->amount/100);

                $bank_revenue_credit = array('SerialNo'         => '472100',
                    'OurBranchID'      => '001',
                    'AccountID'        => $revenue->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Revenue Account Credit",
                    'TrxAmount'        => $fees_result['acquirer_fee']);

                $tax_credit = array('SerialNo'         => '472100',
                    'OurBranchID'      => '001',
                    'AccountID'        => $tax->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Tax Account Credit",
                    'TrxAmount'        => $fees_result['tax']);

                $zimswitch_fees = array('SerialNo'         => '472100',
                    'OurBranchID'      => '001',
                    'AccountID'        => $tax->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Tax Account Credit",
                    'TrxAmount'        => $fees_result['zimswitch_fee']);





                $auth = TokenService::getToken();
                $client = new Client();

                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' =>  array(
                        $account_debit,
                        $account_debit_fees,
                        $destination_credit_zimswitch,
                        $bank_revenue_credit,
                        $tax_credit,
                        $zimswitch_fees
                    )
                        ]

                    ]);





                     //$response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());



                    if ($response->code == '00') {


                        $total_debit  = $request->amount/100 + $fees_result['fees_charged'];

                        Transactions::create([

                            'txn_type_id'         => ZIPIT_SEND,
                            'tax'                 => $fees_result['tax'],
                            'revenue_fees'        => $fees_result['acquirer_fee'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '0.00',
                            'transaction_amount'  => $request->amount/100,
                            'total_debited'       => $total_debit,
                            'total_credited'      => $total_debit,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => '',
                            'merchant_account'    => '',



                        ]);




                        Zipit::create([

                            'source_bank'           =>'GETBUCKS',
                            'destination_bank'      =>$request->destination_bank,
                            'source'                =>$account_number,
                            'destination'           =>$request->destination_account,
                            'amount'                => $request->amount/100,
                            'type'                  =>'ZIPIT SEND',

                        ]);



                        return response([

                            'code' => '000',
                            'batch_id' => (string)$response->transaction_batch_id,
                            'description' => 'Success'


                        ]);


                    }

                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => ZIPIT_SEND,
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
                        'pan'                 => '',
                        'description'         =>$exception,


                    ]);

                    return response([

                        'code' => '100',
                        'description' => $exception


                    ]);


                }

            }


        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);


                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => $exception->message,


                ]);

                return response([

                    'code' => '100',
                    'description' => $exception->message


                ]);

                //return new JsonResponse($exception, $e->getCode());
            } else {
                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => '',
                    'switch_reference'    => '',
                    'merchant_id'         =>'',
                    'transaction_status'  => 0,
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Failed to process transactions,error 01'.$e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => $e->getMessage()


                ]);
                //return new JsonResponse($e->getMessage(), 503);
            }
        }


    }



    public function receive(Request $request){



        $account_checker = substr($request->br_account,0, 3);


        if($account_checker == '263'){

            $destination = Wallet::where('mobile', $request->br_account)->get()->first();
            $zimswitch_mobile = Wallet::where('mobile', '263700000004')->get()->first();





            if(!isset($destination)){


                WalletTransactions::create([

                    'txn_type_id'         => ZIPIT_RECEIVE,
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
                    'account_debited'     => $destination->mobile,
                    'pan'                 => '',
                    'description'         => 'Insufficient funds',


                ]);

                return response([

                    'code' => '116',
                    'description' => 'Invalid mobile number',

                ]) ;


           }




            $total_deductions =  $request->amount /100;
            // Check if client has enough funds.

            $destination->lockForUpdate()->first();
            if($total_deductions > $destination->balance){

                WalletTransactions::create([

                    'txn_type_id'         => ZIPIT_RECEIVE,
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
                    'account_debited'     => $destination->mobile,
                    'pan'                 => '',
                    'description'         => 'Zimswitch Wallet not funded',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Zimswitch Wallet not funded',

                ]) ;


            }





            try {

                DB::beginTransaction();

                //Deduct funds from source account
                $destination->lockForUpdate()->first();
                $destination_new_balance = $destination->balance + $total_deductions;
                $destination->balance = number_format((float)$destination_new_balance, 4, '.', '');
                $destination->save();



                $zimswitch_mobile->lockForUpdate()->first();
                $zimswitch_new_balance = $zimswitch_mobile->balance - $total_deductions;
                $zimswitch_mobile->balance = number_format((float)$zimswitch_new_balance, 4, '.', '');
                $zimswitch_mobile->save();


                DB::commit();



            } catch (\Exception $e){

                DB::rollback();

                WalletTransactions::create([

                    'txn_type_id'         => ZIPIT_RECEIVE,
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
                    'account_debited'     => $destination->mobile,
                    'pan'                 => '',
                    'description'         => 'Transaction was reversed',


                ]);



                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]) ;

            }

            $mobi = substr_replace($destination->mobile, '', -10, 3);
            $time_stamp = Carbon::now()->format('ymdhis');
            $reference = '95'. $time_stamp . $mobi;


            WalletTransactions::create([

                'txn_type_id'         => ZIPIT_SEND,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => $total_deductions,
                'total_debited'       => $total_deductions,
                'total_credited'      => $total_deductions,
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => $destination->mobile,
                'pan'                 => '',


            ]);



            Zipit::create([

                'source_bank'           =>$request->source_bank,
                'destination_bank'      =>'GETBUCKS',
                'source'                =>$request->source_account,
                'destination'           =>$request->br_account,
                'amount'                => $request->amount/100,
                'type'                  =>'RECEIVE',

            ]);

            // add jobs to update records
            return response([

                'code' => '000',
                'batch_id' => (string)$reference,
                'description' => 'Success'


            ]);






        }


        try {

            $account_number = $request->br_account;

            $zimswitch = Accounts::find(1);
            $destination_account_credit = array('SerialNo'         => '472100',
                'OurBranchID'      => substr($account_number, 0, 3),
                'AccountID'        => $account_number,
                'TrxDescriptionID' => '007',
                'TrxDescription'   => 'ZIPIT  CREDIT RECEIVE',
                'TrxAmount'        => $request->amount);


            $zimswitch_debit = array('SerialNo'         => '472100',
                'OurBranchID'      => '001',
                'AccountID'        => $zimswitch->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                'TrxAmount'        => -$request->amount);



            $auth = TokenService::getToken();
            $client = new Client();





                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' =>  array(
                                $destination_account_credit,
                                $zimswitch_debit,

                            )
                        ]

                    ]);





                    //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());




                    if ($response->code != '00') {


                        return response([

                            'code' => '100',
                            'description' => $response->description


                        ]);



                    }




                        Transactions::create([

                            'txn_type_id'         => ZIPIT_RECEIVE,
                            'tax'                 => '0.00',
                            'revenue_fees'        => '0.00',
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => '0.00',
                            'transaction_amount'  => $request->amount/100,
                            'total_debited'       => $request->amount/100,
                            'total_credited'      => $request->amount/100,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $zimswitch->account_number,
                            'pan'                 => '',
                            'merchant_account'    => '',
                            'account_credited'    => $zimswitch->account_number,



                        ]);




                        Zipit::create([

                            'source_bank'           =>$request->source_bank,
                            'destination_bank'      =>'GETBUCKS',
                            'source'                =>$request->source_account,
                            'destination'           =>$request->br_account,
                            'amount'                => $request->amount/100,
                            'type'                  =>'RECEIVE',

                        ]);



                    return response([

                        'code' => '000',
                        'batch_id' => (string)$response->transaction_batch_id,
                        'description' => 'Success'


                    ]);




                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => ZIPIT_RECEIVE,
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
                        'pan'                 => '',
                        'description'         => 'Failed to process transactions. BR. Error',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process transactions. BR. Error'


                    ]);


                }



        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);

                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => $exception->message,


                ]);

                return response([

                    'code' => '100',
                    'description' => $exception->message


                ]);

                //return new JsonResponse($exception, $e->getCode());
            } else {
                Transactions::create([

                    'txn_type_id'         => ZIPIT_SEND,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => '',
                    'switch_reference'    => '',
                    'merchant_id'         =>'',
                    'transaction_status'  => 0,
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Failed to process transactions,error 01'.$e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => $e->getMessage()


                ]);
                //return new JsonResponse($e->getMessage(), 503);
            }
        }





    }

    protected function zipit_send_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'br_account' => 'required',
            'destination_bank' => 'required',
            'destination_account' => 'required',
        ]);
    }


    protected function zipit_rec_validation(Array $data)
    {
        return Validator::make($data, [
            'destination_account' => 'required',
            'token' => 'required',
            'amount' => 'required',
            'sender_bank' => 'required',
            'sender_account' => 'required',

        ]);
    }









}