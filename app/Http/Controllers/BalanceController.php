<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\PostWalleBalanceEnqJob;
use App\Jobs\SaveTransaction;
use App\License;
use App\LuhnCards;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
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
use Illuminate\Support\Facades\Validator;
use App\WalletTransaction;




class BalanceController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */
    public function balance(Request $request){

        $validator = $this->balance_enquiry($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

       /*
        * Declarations
        */
        $currency = License::find(1);
        $card_number = substr($request->card_number, 0, 16);
        $branch_id = substr($request->account_number, 0, 3);
        $merchant_id = Devices::where('imei', $request->imei)->first();
        $employee_id = Employee::where('imei', $request->imei)->first();

       /*
        * Check employees if the parameter is set.
        */

        if(!isset($merchant_id)){

            return response([
                'code'        => '01',
                'description' => 'Invalid device imei',

            ]);
        }

        if(isset($employee_id)){

            $user_id = $employee_id->id;
        }

        //Wallet Code
        /*
        if(isset($card_details->wallet_id)){

            //Declaration
             $source = Wallet::where('id', $card_details->wallet_id)->get()->first();
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

                '0.00',
                '0.00',
                BALANCE_ON_US,
                $merchant_id->merchant_id

            );


             $total_deductions = $fees_charged['fees_charged'];
            // Check if client has enough funds.

            $source->lockForUpdate()->first();
            if($total_deductions > '0.08'){

                WalletTransactions::create([

                    'txn_type_id'         => 1,
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
                    'account_debited'     => $source->mobile,
                    'pan'                 => $request->card_number,
                    'description'         => 'Insufficient funds',


                ]);

                return response([

                    'code' => '116',
                    'description' => 'Insufficient funds',

                ]) ;


            }

            $revenue = Wallet::where('mobile','263700000001')->first();

            try {



                //Deduct funds from source account / wallet
                $source->lockForUpdate()->first();
                $source_new_balance = $source->balance - $total_deductions;
                $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                $source->save();

                $revenue->lockForUpdate()->first();
                $revenue_new_balance = $revenue->balance + $total_deductions;
                $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
                $revenue->save();




                DB::commit();



            } catch (\Exception $e){

                DB::rollback();

                WalletTransactions::create([

                    'txn_type_id'         => 1,
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
                    'account_debited'     => $source->mobile,
                    'pan'                 => $request->card_number,
                    'description'         => 'Transaction was reversed',


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]) ;

            }


            $mobi = substr_replace($source->mobile, '', -10, 3);
            $time_stamp = Carbon::now()->format('ymdhis');
            $reference = '18'. $time_stamp . $mobi;


            WalletTransactions::create([

                'txn_type_id'         => BALANCE_ON_US,
                'tax'                 => '0.00',
                'revenue_fees'        => $fees_charged['fees_charged'],
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => $fees_charged['fees_charged'],
                'total_credited'      => $fees_charged['fees_charged'],
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => $merchant_id->merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $source->mobile,
                'pan'                 => $request->card_number,
                'employee_id'         => $user_id,


            ]);


            return response([

                'code'              => '000',
                'currency'          => $currency->currency,
                'available_balance' =>  $source->balance,
                'ledger_balance'    =>  $source->balance,
                'batch_id'          => "  $reference",

            ]);


        }

        */

        /*
         * Peform Balance enquiry & return valid responses.
         */

        if (isset($request->imei)) {

            try {


                $authentication = TokenService::getToken();
                $client = new Client();
                $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                    'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                    'json'    => [
                        'account_number' => $request->account_number,
                    ],
                ]);

                $balance_response = json_decode($result->getBody()->getContents());

                // BALANCE ENQUIRY LOGIC
                if ($balance_response->available_balance < 0.08) {

                    Transactions::create([

                        'txn_type_id'         => 1,
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
                        'description'         => 'Insufficient funds',


                    ]);

                    return response([
                        'code'        => '116',
                        'description' => 'Insufficient Funds',

                    ]);

                }  else

                    {

                    //Balance Enquiry On Us Debit Fees
                    $fees_result = FeesCalculatorService::calculateFees(
                        '0.00',
                        '0.00',
                        BALANCE_ON_US,
                        $merchant_id->merchant_id

                    );


                    $revenue = Accounts::find(2);
                    $account_debit = array('SerialNo'         => '472100',
                                           'OurBranchID'      => $branch_id,
                                           'AccountID'        => $request->account_number,
                                           'TrxDescriptionID' => '007',
                                           'TrxDescription'   => 'Balance enquiry on us,debit fees',
                                           'TrxAmount'        => '-' . $fees_result['fees_charged']);

                    $bank_revenue_credit = array('SerialNo'         => '472100',
                                                 'OurBranchID'      => $branch_id,
                                                 'AccountID'        => $revenue->account_number,
                                                 'TrxDescriptionID' => '008',
                                                 'TrxDescription'   => "Balance enquiry on us,credit revenue with fees",
                                                 'TrxAmount'        => $fees_result['acquirer_fee']);



                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                            'json'    => [
                                'bulk_trx_postings' => array(
                                    $account_debit,
                                    $bank_revenue_credit,
                                ),
                            ],
                        ]);

                        //return $response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());


                       if($response->code != '00'){

                           Transactions::create([

                               'txn_type_id'         => 1,
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
                               'code'        => '100',
                               'description' => 'Failed to process transaction',

                           ]);

                       }

                        Transactions::create([

                              'txn_type_id'         => BALANCE_ON_US,
                              'tax'                 => '0.00',
                              'revenue_fees'        => $fees_result['fees_charged'],
                              'interchange_fees'    => '0.00',
                              'zimswitch_fee'       => '0.00',
                              'transaction_amount'  => '0.00',
                              'total_debited'       => $fees_result['fees_charged'],
                              'total_credited'      => $fees_result['fees_charged'],
                              'batch_id'            => $response->transaction_batch_id,
                              'switch_reference'    => $response->transaction_batch_id,
                              'merchant_id'         => $merchant_id->merchant_id,
                              'transaction_status'  => 1,
                              'account_debited'     => $request->account_number,
                              'pan'                 => $card_number,
                              'employee_id'         => $user_id,


                          ]);


                       $available_balance = round($balance_response->available_balance,2,PHP_ROUND_HALF_EVEN) * 100;
                       $ledger_balance = round($balance_response->ledger_balance,2,PHP_ROUND_HALF_EVEN) * 100;

                            return response([

                                'code'              => '000',
                                'currency'          => $currency->currency,
                                'available_balance' => "$available_balance",
                                'ledger_balance'    => "$ledger_balance",
                                'batch_id'          => "$response->transaction_batch_id",

                            ]);




                    } catch (ClientException $exception) {

                        Transactions::create([

                            'txn_type_id'         => BALANCE_ON_US,
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
                            'description'          => 'Failed to process transaction,error 91'.$exception,


                        ]);


                        return response([
                            'code'        => '100',
                            'description' =>  $exception,

                        ]);



                    }

                }


            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();
                    $exception = json_decode($exception);
                    ;


                    Transactions::create([

                        'txn_type_id'         => BALANCE_ON_US,
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
                        'description'          => $exception->message,


                    ]);


                    return response([
                        'code'        => '100',
                        'description' =>  $exception->message,

                    ]);



                    //return new JsonResponse($exception, $e->getCode());
                } else {

                    Transactions::create([

                        'txn_type_id'         => BALANCE_ON_US,
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
                        'description'          => 'Failed to process transaction'.$e->getMessage(),


                    ]);


                    return response([
                        'code'        => '100',
                        'description' =>  $e->getMessage(),

                    ]);

                    //return new JsonResponse($e->getMessage(), 503);
                }
            }

        }



    }


    public function balance_off_us(Request $request){

        $validator = $this->balance_enquiry_off_us($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        //$card_number = str_limit($request->card_number, 16, '');
        //$card_details = LuhnCards::where('track_1', $request->card_number)->get()->first();
        $branch_id = substr($request->account_number, 0, 3);
        $currency = License::find(1);


        /*
        if(isset($card_details->wallet_id)){

            //Declaration
            $source = Wallet::where('id', $card_details->wallet_id)->get()->first();
            $mobi = substr_replace($source->mobile, '', -10, 3);
            $time_stamp = Carbon::now()->format('ymdhis');
            $reference = '18'. $time_stamp . $mobi;

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

                '0.00',
                '0.00',
                BALANCE_ENQUIRY_OFF_US,
                '28'

            );


            $total_deductions = $fees_charged['fees_charged'];
            // Check if client has enough funds.

            $source->lockForUpdate()->first();
            if($total_deductions > '0.08'){

                WalletTransactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                    'tax'                 => '0.00',
                    'revenue_fees'        => $fees_charged['fees_charged'],
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => $fees_charged['zimswitch_fee'],
                    'transaction_amount'  => '0.00',
                    'total_debited'       => $fees_charged['fees_charged'],
                    'total_credited'      => $fees_charged['fees_charged'],
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $source->mobile,
                    'pan'                 => $request->card_number,
                    'description'         => 'BALANCE ENQUIRY OFF US',


                ]);





                return response([

                    'code' => '116',
                    'description' => 'Insufficient funds',

                ]) ;


            }



            $revenue = Wallet::where('mobile','263700000001')->first();
            $zimswitch_account = Wallet::where('mobile','263700000004')->first();



            try {



                //Deduct funds from source account / wallet
                $source->lockForUpdate()->first();
                $source_new_balance = $source->balance - $total_deductions;
                $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                $source->save();

                $zimswitch_account->lockForUpdate()->first();
                $zimswitch_new_balance = $revenue->balance + $total_deductions;
                $zimswitch_account->balance = number_format((float)$zimswitch_new_balance, 4, '.', '');
                $zimswitch_account->save();




                DB::commit();



            } catch (\Exception $e){

                DB::rollback();


                WalletTransactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $source->mobile,
                    'pan'                 => str_limit($request->card_number,16,''),
                    'description'         => 'Transaction was reversed, error : 400',


                ]);

                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]) ;

            }

            //run job and post to BR
            WalletPostPurchaseTxns::create([

                'purchase_amount' => '0.00',
                'zimswitch_fees' =>  $fees_charged['zimswitch_fee'],
                'tax' =>  '0.00',
                'acquirer_fee' =>  '0.00',
                'interchange_fee' =>  $fees_charged['interchange_fee'],
                'status' =>  0,
                'batch_id' => $reference,
                'card_number' => $request->card_number,
                'mobile' => $source->mobile,

            ]);



          dispatch(new PostWalleBalanceEnqJob(

              $reference
          ));


            return response([

                'code'              => '000',
                'currency'          => $currency->currency,
                'available_balance' =>  $source->balance,
                'ledger_balance'    =>  $source->balance,
                'batch_id'          => "$reference",
                'description'       => "SUCCESS",

            ]);


        }
        */


        try {


            $authentication = TokenService::getToken();
            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json'    => [
                    'account_number' => $request->account_number,
                ],
            ]);

            $balance_response = json_decode($result->getBody()->getContents());

            // BALANCE ENQUIRY LOGIC
            if ($balance_response->available_balance < 0.08) {


                //Balance Enquiry off Us Debit Fees
                $fees_result = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_OFF_US,
                    '28' // configure Zimswitch default merchant

                );


                $zimswitch_account = Accounts::find(1);
                $account_debit = array('SerialNo'         => '472100',
                                       'OurBranchID'      => $branch_id,
                                       'AccountID'        => $request->account_number,
                                       'TrxDescriptionID' => '007',
                                       'TrxDescription'   => 'Balance enquiry attempt,Debit penalty fees',
                                       'TrxAmount'        => '-' . $fees_result['fees_charged']);

                $credit_zimswitch = array('SerialNo'         => '472100',
                                          'OurBranchID'      => $branch_id,
                                          'AccountID'        => $zimswitch_account->account_number,
                                          'TrxDescriptionID' => '008',
                                          'TrxDescription'   => "Balance enquiry attempt,Zimswitch Account Credit ",
                                          'TrxAmount'        => $fees_result['zimswitch_fee']);



                $client = new Client();
                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json'    => [
                            'bulk_trx_postings' => array(
                                $account_debit,
                                $credit_zimswitch,
                            ),
                        ],
                    ]);

                      //$response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());


                    if($response->code != '00'){


                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => '',
                            'switch_reference'    => '',
                            'merchant_id'         => '',
                            'transaction_status'  => 0,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'description'         => 'BALANCE ENQUIRY OFF US',


                        ]);

                        return response([
                            'code'        => '100',
                            'description' => 'BR:  '.$response->description,


                        ]);


                    }


                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 0,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'description'         => 'Insufficient Funds',


                        ]);



                        return response([
                            'code'        => '116',
                            'description' => 'Insufficient Funds',
                            'batch_id'    => "$response->transaction_batch_id",

                        ]);



                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                        'description'          => $exception,


                    ]);


                    return response([
                        'code'        => '116',
                        'description' => $exception,
                    ]);





                }


            } else {

                //$merchant_id = Devices::where('imei', $request->imei)->first();
                // return $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();

                //Balance Enquiry off Us Debit Fees
                $fees_result = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_OFF_US,
                    '28' //Zimswitch Merchant to be created.

                );


                $zimswitch_account = Accounts::find(1);
                $account_debit = array('SerialNo'         => '472100',
                                       'OurBranchID'      => $branch_id,
                                       'AccountID'        => $request->account_number,
                                       'TrxDescriptionID' => '007',
                                       'TrxDescription'   => 'Balance Fees Debit',
                                       'TrxAmount'        => '-' . $fees_result['fees_charged']);

                $credit_zimswitch = array('SerialNo'         => '472100',
                                          'OurBranchID'      => $branch_id,
                                          'AccountID'        => $zimswitch_account->account_number,
                                          'TrxDescriptionID' => '008',
                                          'TrxDescription'   => "Zimswitch Revenue Account Credit",
                                          'TrxAmount'        => $fees_result['zimswitch_fee']);



                $client = new Client();

                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json'    => [
                            'bulk_trx_postings' => array(
                                $account_debit,
                                $credit_zimswitch,
                            ),
                        ],
                    ]);

                    //$response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());


                   if($response->code != '00'){

                       Transactions::create([

                           'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                           'tax'                 => '0.00',
                           'revenue_fees'        => $fees_result['fees_charged'],
                           'interchange_fees'    => '0.00',
                           'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                           'transaction_amount'  => '0.00',
                           'total_debited'       => $fees_result['fees_charged'],
                           'total_credited'      => $fees_result['fees_charged'],
                           'batch_id'            => $response->transaction_batch_id,
                           'switch_reference'    => $response->transaction_batch_id,
                           'merchant_id'         => '',
                           'transaction_status'  => 0,
                           'account_debited'     => $request->account_number,
                           'pan'                 => $request->card_number,
                           'description'         => 'Failed to process transaction.',



                       ]);


                   }

                        Transactions::create([

                            'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,



                        ]);


                        $available_balance = round($balance_response->available_balance,2,PHP_ROUND_HALF_EVEN) * 100;
                        $ledger_balance = round($balance_response->ledger_balance,2,PHP_ROUND_HALF_EVEN) * 100;


                        return response([

                            'code'              => '000',
                            'fees_charged'      => $fees_result['fees_charged'] * 100,
                            'currency'          => $currency->currency,
                            'available_balance' => "$available_balance",
                            'ledger_balance'    => "$ledger_balance",
                            'batch_id'          => "$response->transaction_batch_id",
                            'description'       => "SUCCESS",

                        ]);




                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                        'description'          => $exception,


                    ]);


                    return response([
                        'code'        => '100',
                        'description' => $exception,
                    ]);





                }

            }


        } catch (RequestException $e) {

            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);



                Transactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                    'pan'                 => "BR. Net Error:".$request->card_number,
                    'description'          => $exception->message,


                ]);

                return response([
                    'code'        => '100',
                    'description' => $request->card_number,
                ]);


                //return new JsonResponse($exception, $e->getCode());
            } else {

                Transactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                    'description'          => $e->getMessage(),


                ]);

                return response([
                    'code'        => '100',
                    'description' => $e->getMessage(),
                ]);

                //return new JsonResponse($e->getMessage(), 503);
            }
        }



    }


    protected function balance_enquiry(Array $data){
        return Validator::make($data, [
            'card_number' => 'required',
            'imei'        => 'required',

        ]);
    }

    protected function balance_enquiry_off_us(Array $data){
        return Validator::make($data, [
            'card_number'    => 'required',

        ]);
    }


}