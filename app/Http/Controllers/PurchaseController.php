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


class PurchaseController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */

    public function purchase(Request $request)
    {


        $validator = $this->purchase_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        //$card_details = LuhnCards::where('track_1', $request->card_number)->get()->first();
        $employee_id = Employee::where('imei', $request->imei)->get()->first();
        $merchant_id = Devices::where('imei', $request->imei)->first();
        $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();

        /*
         * Employee Management
         */

        if(!isset($merchant_id)){

            return response([
                'code'        => '01',
                'description' => 'Invalid device imei',

            ]);
        }

        if(!isset($merchant_account)){

            return response([
                'code'        => '01',
                'description' => 'Merchant account not configured.',

            ]);

        }

        if(isset($employee_id)){

            $user_id = $employee_id->id;
        }

        /*
         * Wallet Code
         */

        /*
        if(isset($card_details->wallet_id)){

            //Declaration
            $card_details = LuhnCards::where('track_1', $request->card_number)->get()->first();
            $source = Wallet::where('id', $card_details->wallet_id)->get()->first();
            $merchant_id = Devices::where('imei', $request->imei)->first();


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
               PURCHASE_ON_US,
                $merchant_id->merchant_id

            );

             $total_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
            // Check if client has enough funds.

            $source->lockForUpdate()->first();
            if($total_deductions > $source->balance){

                WalletTransactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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


            //Relevant Destination Accounts
            $revenue = Wallet::where('mobile', '263700000001')->get()->first();
            $tax = Wallet::where('mobile', '263700000000')->get()->first();
            $merchant = Wallet::where('merchant_id', $merchant_id->merchant_id)->get()->first();

            try {

                DB::beginTransaction();

                //Deduct funds from source account
                $source->lockForUpdate()->first();
                $source_new_balance = $source->balance - $total_deductions;
                $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                $source->save();


                $revenue->lockForUpdate()->first();
                $revenue_new_balance = $revenue->balance + $fees_charged['mdr'] + $fees_charged['fees_charged'];
                $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
                $revenue->save();


                $tax->lockForUpdate()->first();
                $tax_new_balance = $tax->balance + $fees_charged['tax'];
                $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
                $tax->save();

                $merchant->lockForUpdate()->first();
                $merchant_new_balance = $tax->balance - $fees_charged['mdr']  + ($request->amount /100) ;
                $merchant->balance = number_format((float)$merchant_new_balance, 4, '.', '');
                $merchant->save();


                DB::commit();



            } catch (\Exception $e){

                DB::rollback();

                WalletTransactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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
            $reference = '95'. $time_stamp . $mobi;


            WalletTransactions::create([

                'txn_type_id'         => PURCHASE_ON_US,
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
                'employee_id'         =>$user_id,


            ]);



            // add jobs to update records
            return response([

                'code' => '000',
                'batch_id' => (string)$reference,
                'description' => 'Success'


            ]);


        }
        */


        //On Us Purchase Txn Getbucks Card on Getbucks POS
        if(isset($request->imei)) {


            try {



                $authentication = TokenService::getToken();

                $client = new Client();
                $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                    'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                    'json' => [
                        'account_number' => $request->account_number,
                    ]
                ]);

                $balance_response = json_decode($result->getBody()->getContents());

                //Balance Enquiry On Us Debit Fees
                  $fees_charged = FeesCalculatorService::calculateFees(

                    $request->amount /100,
                    '0.00',
                    PURCHASE_ON_US,
                    $merchant_id->merchant_id

                );


                if($request->amount /100 > $fees_charged['maximum_daily']){

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
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



                $total_funds = $fees_charged['fees_charged'] + ($request->amount /100);
                // Check if client has enough funds.
                if ($balance_response->available_balance < $total_funds) {

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
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
                        'description'         => 'Insufficient Funds',


                    ]);

                    return response([
                        'code' => '116 ',
                        'description' => 'Insufficient Funds',

                    ]);

                }




                    $revenue = REVENUE;
                    $tax = TAX;


                    $credit_merchant_account = array('SerialNo' => '472100',
                        'OurBranchID' => substr($merchant_account->account_number, 0, 3),
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase on us credit merchant account',
                        'TrxAmount' => $request->amount /100);

                    $debit_client_amount = array('SerialNo' => '472100',
                        'OurBranchID' => substr($request->account_number, 0, 3),
                        'AccountID' => $request->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase on us debit client with purchase amount',
                        'TrxAmount' => '-' . $request->amount /100);


                    $debit_client_fees = array('SerialNo' => '472100',
                        'OurBranchID' => substr($request->account_number, 0, 3),
                        'AccountID' => $request->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase on us debit client with fees',
                        'TrxAmount' => '-' . $fees_charged['fees_charged']);



                    $credit_revenue_fees = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase on us  credit revenue account with fees",
                        'TrxAmount' => $fees_charged['acquirer_fee']);

                    $tax_account_credit = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $tax,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase on us tax account credit",
                        'TrxAmount' => "". $fees_charged['tax']);

                    $debit_merchant_account_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => substr($merchant_account->account_number, 0, 3),
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase on us, debit merchant account with mdr fees',
                        'TrxAmount' => '-' . $fees_charged['mdr']);

                    $credit_revenue_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase on us,credit revenue with fees",
                        'TrxAmount' => $fees_charged['mdr']);




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

                                ),
                            ]
                        ]);


                        //$response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());

                        if ($response->code != '00'){

                            Transactions::create([

                                'txn_type_id'         => PURCHASE_ON_US,
                                'tax'                 =>  $fees_charged['tax'],
                                'revenue_fees'        => $revenue,
                                'interchange_fees'    => '0.00',
                                'zimswitch_fee'       => '0.00',
                                'transaction_amount'  => $request->amount /100,
                                'total_debited'       => $total_funds,
                                'total_credited'      => $total_funds,
                                'batch_id'            => $response->transaction_batch_id,
                                'switch_reference'    => $response->transaction_batch_id,
                                'merchant_id'         => $merchant_id->merchant_id,
                                'transaction_status'  => 0,
                                'account_debited'     => $request->account_number,
                                'pan'                 => $request->card_number,
                                'merchant_account'    => 'Failed to process transaction',
                                'employee_id'         => $user_id,

                            ]);


                            return response([

                                'code' => '100',
                                'description' => 'Failed to process transaction'


                            ]);


                        }


                            $revenue = $fees_charged['mdr']  +  $fees_charged['acquirer_fee'];
                            $merchant_amount =  - $fees_charged['mdr'] + ($request->amount /100);

                            Transactions::create([

                                'txn_type_id'         => PURCHASE_ON_US,
                                'tax'                 =>  $fees_charged['tax'],
                                'revenue_fees'        => $revenue,
                                'interchange_fees'    => '0.00',
                                'zimswitch_fee'       => '0.00',
                                'transaction_amount'  => $request->amount /100,
                                'total_debited'       => $total_funds,
                                'total_credited'      => $total_funds,
                                'batch_id'            => $response->transaction_batch_id,
                                'switch_reference'    => $response->transaction_batch_id,
                                'merchant_id'         => $merchant_id->merchant_id,
                                'transaction_status'  => 1,
                                'account_debited'     => $request->account_number,
                                'pan'                 => $request->card_number,
                                'merchant_account'    => $merchant_amount,
                                'employee_id'         => $user_id,

                            ]);



                            return response([

                                'code'          => '000',
                                'batch_id'      => (string)$response->transaction_batch_id,
                                'description'   => 'Success'


                            ]);




                    } catch (ClientException $exception) {

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_ON_US,
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
                            'pan'                 => $request->card_number,
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
                    $exception = json_decode($exception);

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
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
                        'pan'                 => $request->card_number,
                        'description'         => $exception->message,


                    ]);

                    return response([

                        'code' => '100',
                        'description' => $exception->message


                    ]);

                    //return new JsonResponse($exception, $e->getCode());
                } else {

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
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
                        'pan'                 => $request->card_number,
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

    }

    public function purchase_off_us(Request $request)
    {
        //return  $transaction_type = TransactionType::find(65);
        $validator = $this->purchase_off_us_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $branch_id = substr($request->account_number, 0, 3);
        //$card_details = LuhnCards::where('track_1', $request->card_number)->get()->first();

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

                $request->amount /100,
                '0.00',
                PURCHASE_OFF_US,
                '28'

            );


              $total_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
            // Check if client has enough funds.

            $source->lockForUpdate()->first();
            if($total_deductions > $source->balance){
                WalletTransactions::create([

                    'txn_type_id'         => PURCHASE_OFF_US,
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
                    'pan'                 => $request->card_number,
                    'description'         => 'Insufficient funds',


                ]);

                return response([

                    'code' => '116',
                    'description' => 'Insufficient funds',

                ]) ;


            }

            $credit_zimswitch_account = $fees_charged['zimswitch_fee']
                                   +  $fees_charged['acquirer_fee']
                                   +  $request->amount /100;

            $revenue = Wallet::where('mobile','263700000001')->first();
            $tax = Wallet::where('mobile','263700000000')->first();
            $zimswitch_wallet = Wallet::where('mobile','263700000004')->first();


            try {



                //Deduct funds from source account / wallet
                $source->lockForUpdate()->first();
                $source_new_balance = $source->balance - $total_deductions;
                $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                $source->save();

                $tax->lockForUpdate()->first();
                $tax_new_balance = $tax->balance + $fees_charged['tax'];
                $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
                $tax->save();

                $zimswitch_wallet->lockForUpdate()->first();
                $zimswitch_wallet_new_balance = - $fees_charged['interchange_fee'] + $zimswitch_wallet->balance + $credit_zimswitch_account;
                $zimswitch_wallet->balance = number_format((float)$zimswitch_wallet_new_balance, 4, '.', '');
                $zimswitch_wallet->save();


                $revenue->lockForUpdate()->first();
                $revenue_new_balance = $revenue->balance + $fees_charged['interchange_fee'] ;
                $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
                $revenue->save();




                DB::commit();



            } catch (\Exception $e){

                DB::rollback();

                WalletTransactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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
            $reference = '16'. $time_stamp . $mobi;


            //run job and post to BR
            WalletPostPurchaseTxns::create([

                'purchase_amount'   => $request->amount/100,
                'zimswitch_fees'    =>  $fees_charged['zimswitch_fee'],
                'tax'               =>  $fees_charged['tax'],
                'acquirer_fee'      =>  $fees_charged['acquirer_fee'],
                'interchange_fee'   =>  $fees_charged['interchange_fee'],
                'status'            =>  0,
                'batch_id'          => $reference,
                'card_number'       => $request->card_number,
                'mobile'            => $source->mobile,

            ]);


         dispatch(new PostWalletPurchaseJob(

                $reference
            ));








            // add jobs to update records
            return response([

                'code' => '000',
                'batch_id' => (string)$reference,
                'description' => 'Success'


            ]);


        }
        */

        try {


               $fees_charged = FeesCalculatorService::calculateFees(

                $request->amount /100,
                '0.00',
                PURCHASE_OFF_US,
                '28' // Configure Default Merchant

            );


            if($request->amount /100 > $fees_charged['maximum_daily']){

                Transactions::create([

                    'txn_type_id'         => PURCHASE_OFF_US,
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
                    'description' => 'Amount exceeds limit per transaction.',

                ]);
            }



            $authentication = TokenService::getToken();

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $request->account_number,
                ]
            ]);


            $balance_response = json_decode($result->getBody()->getContents());
            $total_funds = $fees_charged['fees_charged'] + ($request->amount /100);


            if ($balance_response->available_balance < $total_funds) {

                Transactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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
                    'description'         => 'Insufficient Funds',


                ]);

                return response([
                    'code' => '116 ',
                    'description' => 'Insufficient Funds',

                ]);

            }


                 $zimswitch = Accounts::find(1);
                $revenue = Accounts::find(2);
                $tax = Accounts::find(3);

                $credit_zimswitch_account = $fees_charged['zimswitch_fee']
                                          + $fees_charged['acquirer_fee']
                                          +   $request->amount /100;


                $debit_client_purchase_amount = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $request->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase off us, debit purchase amount',
                    'TrxAmount' => '-' . $request->amount /100);


                $debit_client_fees = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $request->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase off us, debit fees from client',
                    'TrxAmount' => '-' . $fees_charged['fees_charged']);

                $credit_tax = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $tax->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase off us, credit tax',
                    'TrxAmount' => $fees_charged['tax']);


                 $credit_zimswitch = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $zimswitch->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase off us, credit Zimswitch',
                    'TrxAmount' =>  $credit_zimswitch_account);

                $debit_zimswitch_inter_fee = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $zimswitch->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase off us, debit inter change fee',
                    'TrxAmount' => '-' . $fees_charged['interchange_fee']);


                $credit_revenue = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $revenue->account_number,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase off us, credit revenue',
                    'TrxAmount' => $fees_charged['interchange_fee']);




                $client = new Client();

                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' => array(
                                $debit_client_purchase_amount,
                                $debit_client_fees,
                                $credit_tax,
                                $credit_zimswitch,
                                $debit_zimswitch_inter_fee,
                                $credit_revenue,
                            ),
                        ]
                    ]);


                    //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());

                    if ($response->code != '00')
                    {

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_OFF_US,
                            'tax'                 =>  $fees_charged['tax'],
                            'revenue_fees'        => $revenue,
                            'interchange_fees'    => $fees_charged['interchange_fee'],
                            'zimswitch_fee'       => $credit_zimswitch_account,
                            'transaction_amount'  => $request->amount /100,
                            'total_debited'       => $total_funds,
                            'total_credited'      => $total_funds,
                            'batch_id'            => '',
                            'switch_reference'    => '',
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'description'         => 'Failed to process transaction.'

                        ]);


                        return response([

                            'code' => '100',
                            'description' => 'Failed to process transaction.'


                        ]);


                    }



                        $revenue = $fees_charged['mdr']  +  $fees_charged['acquirer_fee'] + $fees_charged['interchange_fee'];
                        $merchant_amount =  - $fees_charged['mdr'] + ($request->amount /100);

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_OFF_US,
                            'tax'                 =>  $fees_charged['tax'],
                            'revenue_fees'        => $revenue,
                            'interchange_fees'    => $fees_charged['interchange_fee'],
                            'zimswitch_fee'       => $credit_zimswitch_account,
                            'transaction_amount'  => $request->amount /100,
                            'total_debited'       => $total_funds,
                            'total_credited'      => $total_funds,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'merchant_account'    => $merchant_amount,

                        ]);


                        return response([

                            'code'              => '000',
                            'fees_charged'      => $fees_charged['fees_charged'],
                            'batch_id'          => (string)$response->transaction_batch_id,
                            'description'       => 'Success'


                        ]);




                } catch (ClientException $exception) {

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
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
                $exception = json_decode($exception);





                Transactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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
                    'description'         =>$exception->message,


                ]);

                return response([

                    'code' => '100',
                    'description' => $exception


                ]);



                //return new JsonResponse($exception, $e->getCode());
            } else {




                Transactions::create([

                    'txn_type_id'         => PURCHASE_ON_US,
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
                    'description'         => $e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => $e->getMessage()


                ]);

                //return new JsonResponse($e->getMessage(), 503);
            }
        }



    }

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
            //$amount =  $request->amount / 100;
            $cash_back_amount =  $request->cashback_amount / 100;
            $merchant_id = Devices::where('imei', $request->imei)->first();
            $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
            $merchant_account->account_number;
            $employee_id = Employee::where('imei', $request->imei)->first();

            if(!isset($merchant_id)){

            return response([
                'code'        => '01',
                'description' => 'Invalid device imei',

            ]);


            }

             if(isset($employee_id)){

            $user_id = $employee_id->id;

             }

            try {


                $authentication = TokenService::getToken();

                $client = new Client();
                $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                    'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                    'json' => [
                        'account_number' => $request->account_number,
                    ]
                ]);

                $balance_response = json_decode($result->getBody()->getContents());

                $fees_result = FeesCalculatorService::calculateFees(
                    $request->amount / 100,
                    $request->cashback_amount / 100,
                    PURCHASE_CASH_BACK_ON_US,
                    $merchant_id->merchant_id

                );


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



                 $total_funds = $request->amount / 100 + $request->cashback_amount / 100 +  $fees_result['fees_charged'];

                // Check if client has enough funds.
                if ($balance_response->available_balance < $total_funds) {

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
                        'description'         => 'Insufficient Funds',


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient Funds',

                    ]);

                }


                    $revenue = REVENUE;
                    $tax = TAX;


                    $credit_merchant_account = array('SerialNo' => '472100',
                        'OurBranchID' => substr($merchant_account->account_number, 0, 3),
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash on us credit merchant account',
                        'TrxAmount' => $request->amount / 100);

                    $debit_client_amount = array('SerialNo' => '472100',
                        'OurBranchID' => substr($request->account_number, 0, 3),
                        'AccountID' => $request->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash on us debit client with purchase amount',
                        'TrxAmount' => '-' . $request->amount / 100);

                    $credit_merchant_cashback_amount = array('SerialNo' => '472100',
                        'OurBranchID' => substr($merchant_account->account_number, 0, 3),
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => 'Purchase + Cash on us credit merchant account with cash back amount',
                        'TrxAmount' =>  $cash_back_amount);

                    $debit_client_amount_cashback_amount = array('SerialNo' => '472100',
                        'OurBranchID' => substr($request->account_number, 0, 3),
                        'AccountID' => $request->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash on us debit client with cash back amount',
                        'TrxAmount' => '-' .  $request->cashback_amount / 100);


                    $debit_client_fees = array('SerialNo' => '472100',
                        'OurBranchID' => substr($request->account_number, 0, 3),
                        'AccountID' => $request->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash on us debit client with fees',
                        'TrxAmount' => '-' . $fees_result['fees_charged']);



                    $credit_revenue_fees = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase + Cash on us credit revenue account with fees",
                        'TrxAmount' => $fees_result['acquirer_fee']);

                    $tax_account_credit = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $tax,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase + Cash  on us tax account credit",
                        'TrxAmount' => "". $fees_result['tax']);

                    $debit_merchant_account_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => substr($merchant_account->account_number, 0, 3),
                        'AccountID' => $merchant_account->account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription' => 'Purchase + Cash on us, debit merchant account with mdr fees',
                        'TrxAmount' => '-' . $fees_result['mdr']);

                    $credit_revenue_mdr = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase + Cash  on us tax account credit",
                        'TrxAmount' => $fees_result['mdr']);



                    $credit_revenue_cashback_fee = array('SerialNo' => '472100',
                        'OurBranchID' => '001',
                        'AccountID' => $revenue,
                        'TrxDescriptionID' => '008',
                        'TrxDescription' => "Purchase + Cash credit revenue with cashback fees",
                        'TrxAmount' => $fees_result['cash_back_fee']);







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


                        //$response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());

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
                                'description'         => 'Failed to process transaction.',



                            ]);

                            return response([

                                'code' => '000',
                                'description' => 'Failed to process transaction.'


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
                                'account_debited'     => $request->account_number,
                                'pan'                 => $card_number,
                                'merchant_account'    => $merchant_account_amount,
                                'employee_id'         => $user_id,
                                'cash_back_amount'    => $cash_back_amount,



                            ]);



                            return response([

                                'code' => '000',
                                'batch_id' => (string)$response->transaction_batch_id,
                                'description' => 'Success'


                            ]);




                    } catch (ClientException $exception) {

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
                    $exception = json_decode($exception);


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
                        'description'         => $exception->message,


                    ]);

                    return response([

                        'code' => '100',
                        'description' => $exception->message


                    ]);

                    //return new JsonResponse($exception, $e->getCode());
                } else {
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

    public function purchase_cash_back_off_us(Request $request)
    {

        $validator = $this->purchase_cashback_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $card_number = str_limit($request->card_number,16,'');
        $branch_id = substr($request->account_number, 0, 3);

        try {



            $authentication = TokenService::getToken();

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $request->account_number,
                ]
            ]);

            $balance_response = json_decode($result->getBody()->getContents());

            //Balance Enquiry On Us Debit Fees
             $fees_charged = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                $request->cashback_amount / 100,
                PURCHASE_CASH_BACK_OFF_US,
                '28' // configure a default merchant for the HQ,

            );


            if($request->amount /100 > $fees_charged['maximum_daily']){

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
                    'pan'                 => $request->card_number,
                    'description'         => 'Invalid amount, error 902',


                ]);

                return response([
                    'code' => '902',
                    'description' => 'Invalid mount',

                ]);
            }


            // deductable amt = amount = variable????
            $deductable_funds = $request->amount / 100 +
                                $request->cashback_amount / 100 +
                                $fees_charged['fees_charged'];

            // Check if client has enough funds.
            if ($balance_response->available_balance < $deductable_funds) {

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

            }

                $revenue = REVENUE;
                $tax = TAX;
                $zimswitch =ZIMSWITCH;


                $zimswitch_amount = $request->amount/100 +
                                    $request->cashback_amount/100 +
                                    $fees_charged['zimswitch_fee'] +
                                    $fees_charged['acquirer_fee']  +
                                    $fees_charged['cash_back_fee'];


                $debit_client_amount = array('SerialNo' => '472100',
                    'OurBranchID' => substr($request->account_number, 0, 3),
                    'AccountID' => $request->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase + Cash off us, debit purchase amount',
                    'TrxAmount' => '-'. $request->amount/100);

                $debit_client_cash_back = array('SerialNo' => '472100',
                    'OurBranchID' => substr($request->account_number, 0, 3),
                    'AccountID' => $request->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase + Cash off us, debit cash amount',
                    'TrxAmount' => '-'. $request->cashback_amount/100);


                $debit_client_fees = array('SerialNo' => '472100',
                    'OurBranchID' => substr($request->account_number, 0, 3),
                    'AccountID' => $request->account_number,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase + Cash off us, debit fees',
                    'TrxAmount' => '-'. $fees_charged['fees_charged']);


                $credit_tax = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $tax,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase + Cash off us,credit tax',
                    'TrxAmount' => $fees_charged['tax']);

                $credit_zimswitch = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $zimswitch,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase + Cash off us,credit zimswitch ',
                    'TrxAmount' => $zimswitch_amount);

                $debit_zimswitch_interchange = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $zimswitch,
                    'TrxDescriptionID' => '007',
                    'TrxDescription' => 'Purchase + Cash off us,debit zimswitch inter change fee',
                    'TrxAmount' => '-'.$fees_charged['interchange_fee']);


                $credit_revenue = array('SerialNo' => '472100',
                    'OurBranchID' => $branch_id,
                    'AccountID' => $revenue,
                    'TrxDescriptionID' => '008',
                    'TrxDescription' => 'Purchase + Cash off us,credit revenue',
                    'TrxAmount' => $fees_charged['interchange_fee']);

                $auth = TokenService::getToken();
                $client = new Client();

                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' => array(
                                $debit_client_amount,
                                $debit_client_cash_back,
                                $debit_client_fees,
                                $credit_tax,
                                $credit_zimswitch,
                                $debit_zimswitch_interchange,
                                $credit_revenue,

                            ),
                        ]

                    ]);


                    //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());

                    if($response->code != '00'){

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
                            'account_debited'     => '',
                            'pan'                 => $card_number,
                            'merchant_account'    => '',
                            'description'         => 'Failed to process transaction',
                        ]);


                        return response([

                            'code' => '100',
                            'description' => 'Failed to process transaction',


                        ]);

                    }



                        $transaction_amount = $request->amount /100 + $request->cashback_amount/100;
                        $revenue = $fees_charged['mdr']  +  $fees_charged['acquirer_fee'] + $fees_charged['interchange_fee'];
                        $merchant_amount = $transaction_amount - $fees_charged['mdr'];
                        $total_ = $transaction_amount + $fees_charged['fees_charged'];

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
                            'tax'                 => $fees_charged['mdr'],
                            'revenue_fees'        => $revenue,
                            'interchange_fees'    => $fees_charged['interchange_fee'],
                            'zimswitch_fee'       => $fees_charged['zimswitch_fee'],
                            'transaction_amount'  => $transaction_amount,
                            'total_debited'       => $total_,
                            'total_credited'      => $total_,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $card_number,
                            'merchant_account'    => $merchant_amount,

                        ]);


                        return response([

                            'code' => '000',
                            'fees_charged' => $fees_charged['fees_charged'],
                            'batch_id' => (string)$response->transaction_batch_id,
                            'description' => 'Success'


                        ]);




                } catch (ClientException $exception) {

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
                        'pan'                 => $request->card_number,
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
                $exception = json_decode($exception);


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
                    'pan'                 => $request->card_number,
                    'description'         =>$exception->message,


                ]);

                return response([

                    'code' => '100',
                    'description' => $exception


                ]);


                //return new JsonResponse($exception, $e->getCode());
            } else {

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
                    'pan'                 => $request->card_number,
                    'description'         => $e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => $e->getMessage()


                ]);

                //return new JsonResponse($e->getMessage(), 503);
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

    protected function purchase_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'card_number' => 'required',

        ]);
    }

    protected function purchase_off_us_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'card_number' => 'required',

        ]);
    }




}