<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Services\BalanceEnquiryService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use App\TransactionType;
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
use Illuminate\Support\Facades\Log;
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



       $account_checker = substr($request->br_account,0, 3);



        if ($account_checker == '263') {


            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereMobile($request->br_account);
                $toQuery = Wallet::whereMobile(WALLET_REVENUE);
                $zimswitch_mobile_acc = Wallet::whereMobile(ZIMSWITCH_WALLET_MOBILE);
                $tax_mobile = Wallet::whereMobile(WALLET_TAX);


                $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount/100,
                    '0.00',
                    ZIPIT_SEND,
                    HQMERCHANT
                );

                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($fees_charged['minimum_balance'] > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => BALANCE_ON_US,
                        'tax'               => '0.00',
                        'revenue_fees'      => '0.00',
                        'interchange_fees'  => '0.00',
                        'zimswitch_fee'     => '0.00',
                        'transaction_amount'=> '0.00',
                        'total_debited'     => '0.00',
                        'total_credited'    => '0.00',
                        'batch_id'          => '',
                        'switch_reference'  => '',
                        'merchant_id'       => HQMERCHANT,
                        'transaction_status'=> 0,
                        'description'       => 'Insufficient funds for mobile:' . $request->account_number,


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }

               $total_count  = WalletTransactions::where('account_debited',$request->account_number)
                    ->whereIn('txn_type_id',[ZIPIT_SEND])
                    ->where('description','Transaction successfully processed.')
                    ->whereDate('created_at', Carbon::today())
                    ->get()->count();





                if($total_count  >= $fees_charged['transaction_count'] ){

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
                        'account_debited'     => $request->br_account,
                        'pan'                 => '',
                        'description'         => 'Transaction limit reached for the day.',


                    ]);


                    return response([
                        'code' => '121',
                        'description' => 'Transaction limit reached for the day.',

                    ]);
                }




                $daily_spent =  WalletTransactions::where('account_debited', $fromAccount->mobile)
                    ->where('created_at', '>', Carbon::now()->subDays(1))
                    ->sum('transaction_amount');

                //Check Monthly Spent
                $monthly_spent =  WalletTransactions::where('account_debited', $fromAccount->mobile)
                    ->where('created_at', '>', Carbon::now()->subDays(30))
                    ->sum('transaction_amount');


                $wallet_cos = WalletCOS::find($fromAccount->wallet_cos_id);


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


                 $zimswitch_amount = $fees_charged['zimswitch_fee'] + $request->amount/100;
                 $tax = $fees_charged['tax'];
                 $revenue=  $fees_charged['acquirer_fee'];

                 $total_deduction = $zimswitch_amount + $tax;
                 $toAccount = $toQuery->lockForUpdate()->first();
                 $toAccount->balance += $revenue;
                 $toAccount->save();
                 $fromAccount->balance -=$total_deduction ;
                 $fromAccount->save();

                 $due_tax = $tax_mobile->lockForUpdate()->first();
                 $due_tax->balance += $tax;
                 $due_tax->save();

                 $due_zimswitch  = $zimswitch_mobile_acc->lockForUpdate()->first();
                 $due_zimswitch->balance += $zimswitch_amount;
                 $due_zimswitch->save();




                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = ZIPIT_SEND;
                $transaction->tax               = $fees_charged['tax'];
                $transaction->revenue_fees      = $fees_charged['fees_charged'];
                $transaction->zimswitch_fee     = $fees_charged['zimswitch_fee'];
                $transaction->transaction_amount= $request->amount/100;
                $transaction->total_debited     = $zimswitch_amount + $revenue + $tax;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = HQMERCHANT;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->br_account;
                $transaction->account_credited  = ZIMSWITCH_WALLET_MOBILE;
                $transaction->pan               = HQMERCHANT;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                DB::commit();

                return response([
                    'code'              => '000',
                    'batch_id'          => "$reference",
                    'description'       => 'Success'

                ]);


            } catch (\Exception $e) {
                DB::rollBack();

                WalletTransactions::create([

                    'txn_type_id'       => ZIPIT_SEND,
                    'tax'               => '0.00',
                    'revenue_fees'      => '0.00',
                    'interchange_fees'  => '0.00',
                    'zimswitch_fee'     => '0.00',
                    'transaction_amount'=> '0.00',
                    'total_debited'     => '0.00',
                    'total_credited'    => '0.00',
                    'batch_id'          => '',
                    'switch_reference'  => '',
                    'merchant_id'       => HQMERCHANT,
                    'transaction_status'=> 0,
                    'pan'               => '',
                    'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }


            // return 'Success';
        }







        try {

            $authentication = TokenService::getToken();
             $fees_result = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                 ZIPIT_SEND,
               '28'

            );


            $transactions  = Transactions::where('account_debited',$request->br_account)
                                                    ->where('txn_type_id',ZIPIT_SEND)
                                                    ->where('description','Transaction successfully processed.')
                                                    ->whereDate('created_at', Carbon::today())
                                                    ->get()->count();

            if($transactions  >= $fees_result['transaction_count'] ){

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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Invalid amount, error 902',


                ]);

                return response([
                    'code' => '902',
                    'description' => 'Invalid mount',

                ]);
            }




            // Check if client has enough funds.

                $zimswitch = ZIMSWITCH;
                $revenue =REVENUE;
                $tax =  TAX;
                $branch_id = substr($request->br_account, 0, 3);

                $account_debit = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => $branch_id,
                    'AccountID'        => $request->br_account,
                    'TrxDescriptionID' => '007',
                    'TrxDescription'   => 'ZIPIT SEND',
                    'TrxAmount'        => - $request->amount/100);

                $account_debit_fees = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => $branch_id,
                    'AccountID'        => $request->br_account,
                    'TrxDescriptionID' => '007',
                    'TrxDescription'   => "ZIPIT Transfer Fees Debit",
                    'TrxAmount'        => '-' . $fees_result['fees_charged']);

                $destination_credit_zimswitch = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => $branch_id,
                    'AccountID'        => $zimswitch,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                    'TrxAmount'        => $request->amount/100);

                $bank_revenue_credit = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => $branch_id,
                    'AccountID'        => $revenue,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Revenue Account Credit",
                    'TrxAmount'        => $fees_result['acquirer_fee']);

                $tax_credit = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => $branch_id,
                    'AccountID'        => $tax,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Tax Account Credit",
                    'TrxAmount'        => $fees_result['tax']);

                $zimswitch_fees = array(
                    'SerialNo'         => '472100',
                    'OurBranchID'      => '001',
                    'AccountID'        => $zimswitch,
                    'TrxDescriptionID' => '008',
                    'TrxDescription'   => "ZIPIT Credit Zimswitch fees",
                    'TrxAmount'        => $fees_result['zimswitch_fee']);


                $client = new Client();


                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
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





                     //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());


                    if($response->description == 'API : Validation Failed: Customer TrxAmount cannot be Greater Than the AvailableBalance'){

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
                            'transaction_status'  => 1,
                            'account_debited'     => $request->br_account,
                            'pan'                 => '',
                            'merchant_account'    => '',
                            'description'         => 'Insufficient funds',



                        ]);


                        return response([

                            'code' => '116',
                            'description' => 'Insufficient funds'


                        ]);

                    }
                    if($response->code != '00'){

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
                            'transaction_status'  => 1,
                            'account_debited'     => $request->br_account,
                            'pan'                 => '',
                            'merchant_account'    => '',
                            'description'         => 'Invalid BR account',



                        ]);


                        return response([

                            'code' => '100',
                            'description' => 'Invalid BR account'


                        ]);

                    }





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
                            'account_debited'     => $request->br_account,
                            'pan'                 => '',
                            'merchant_account'    => '',
                            'description'         => 'Transaction successfully processed.',



                        ]);




                        Zipit::create([

                            'source_bank'           =>'GETBUCKS',
                            'destination_bank'      =>$request->destination_bank,
                            'source'                =>$request->br_account,
                            'destination'           =>$request->destination_account,
                            'amount'                => $request->amount/100,
                            'type'                  =>'ZIPIT SEND',

                        ]);



                        return response([

                            'code' => '000',
                            'batch_id' => (string)$response->transaction_batch_id,
                            'description' => 'Success'


                        ]);




        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Account Number:'.$request->br_account.' '. $exception);

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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Failed to process transaction',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process transaction'


                ]);

                //return new JsonResponse($exception, $e->getCode());
            } else {

                Log::debug('Account Number:'.$request->br_account.' '. $e->getMessage());
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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Failed to process transaction'.$e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process transaction'


                ]);
                //return new JsonResponse($e->getMessage(), 503);
            }
        }


    }

    public function receive(Request $request){

        $account_checker = substr($request->br_account,0, 3);

        if ($account_checker == '263') {

            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereMobile(ZIMSWITCH_WALLET_MOBILE);
                $toQuery = Wallet::whereMobile($request->br_account);
                $zipit_amount = $request->amount/100;

                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ( $zipit_amount > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => ZIPIT_RECEIVE,
                        'tax'               => '0.00',
                        'revenue_fees'      => '0.00',
                        'interchange_fees'  => '0.00',
                        'zimswitch_fee'     => '0.00',
                        'transaction_amount'=> '0.00',
                        'total_debited'     => '0.00',
                        'total_credited'    => '0.00',
                        'batch_id'          => '',
                        'switch_reference'  => '',
                        'merchant_id'       => '',
                        'transaction_status'=> 0,
                        'pan'               => '',
                        'description'       => 'Insufficient funds for mobile:' . $request->br_account,


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds in the Zimswitch pull account.',
                    ]);
                }

                //Fee Deductions.

                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance += $zipit_amount;
                $toAccount->save();
                $fromAccount->balance -= $zipit_amount;
                $fromAccount->save();


                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = ZIPIT_RECEIVE;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = '0.00';
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $zipit_amount;
                $transaction->total_debited     = $zipit_amount;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = '';
                $transaction->transaction_status= 1;
                $transaction->account_debited   = ZIMSWITCH_WALLET_MOBILE;
                $transaction->account_credited  = $request->br_account;
                $transaction->pan               = '';
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                $source_new_balance_             = $toAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = ZIPIT_RECEIVE;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = '0.00';
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $zipit_amount;
                $transaction->total_debited     = $zipit_amount;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = '';
                $transaction->transaction_status= 1;
                $transaction->account_debited   = ZIMSWITCH_WALLET_MOBILE;
                $transaction->account_credited  = $request->br_account;
                $transaction->pan               = '';
                $transaction->balance_after_txn = $source_new_balance_;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                DB::commit();

                return response([
                    'code'              => '000',
                    'currency'          => CURRENCY,
                    'batch_id'          => "$reference",
                    'descripition'      => "Success",

                ]);


            } catch (\Exception $e) {
                DB::rollBack();
                Log::debug('Account Number:'.$request->br_account.' '. $e);

                WalletTransactions::create([

                    'txn_type_id'       => ZIPIT_RECEIVE,
                    'tax'               => '0.00',
                    'revenue_fees'      => '0.00',
                    'interchange_fees'  => '0.00',
                    'zimswitch_fee'     => '0.00',
                    'transaction_amount'=> '0.00',
                    'total_debited'     => '0.00',
                    'total_credited'    => '0.00',
                    'batch_id'          => '',
                    'switch_reference'  => '',
                    'merchant_id'       => '',
                    'transaction_status'=> 0,
                    'pan'               => '',
                    'description'       => 'Transaction was reversed for mobbile:' . $request->br_account,


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }

        }



        try {

            $zimswitch = ZIMSWITCH;
            $destination_account_credit = array(
                'SerialNo'         => '472100',
                'OurBranchID'      => substr($request->br_account, 0, 3),
                'AccountID'        => $request->br_account,
                'TrxDescriptionID' => '007',
                'TrxDescription'   => 'ZIPIT  CREDIT RECEIVE',
                'TrxAmount'        => $request->amount /100);


            $zimswitch_debit = array(
                'SerialNo'         => '472100',
                'OurBranchID'      => substr($request->br_account, 0, 3),
                'AccountID'        => $zimswitch,
                'TrxDescriptionID' => '008',
                'TrxDescription'   => 'ZIPIT DEBIT SUSPENSE ACCOUNT',
                'TrxAmount'        => - $request->amount /100);



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
                            'account_debited'     => $request->br_account,
                            'pan'                 => '',
                            'merchant_account'    => '',
                            'account_credited'    => $request->br_account,
                            'description'         => 'Transaction successfully processed.',
                        ]);




                        Zipit::create([

                            'source_bank'           =>$request->source_bank,
                            'destination_bank'      =>'GETBUCKS',
                            'source'                =>$request->source_account,
                            'destination'           =>$request->br_account,
                            'amount'                => $request->amount/100,
                            'type'                  =>'RECEIVE',

                        ]);


                    $result = $client->post(env('BASE_URL') . '/api/customers', [

                        'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                        'json' => [
                            'account_number' => $request->br_account,
                        ]
                    ]);


                      $responses = $result->getBody()->getContents();
                      $zimswitch_response = json_decode($responses);

                      return response([

                        'code'              => '000',
                        'mobile'            => $zimswitch_response->ds_account_customer_info->mobile,
                        'name'              => $zimswitch_response->ds_account_customer_info->account_name,
                        'national_id'       => '22242274J26',
                        'email'             => $zimswitch_response->ds_account_customer_info->email_id,
                        'batch_id'          => (string)$response->transaction_batch_id,
                        'description'       => 'Success'


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
                        'account_debited'     => '',
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
                    'account_debited'     => $request->br_account,
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
                    'merchant_id'         =>'',
                    'transaction_status'  => 0,
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Failed to process transactions,error 01'.$e->getMessage(),


                ]);

                return response([

                    'code' => '100',
                    'description' => $e->getMessage()


                ]);

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
            'amount' => 'required',
            'sender_bank' => 'required',
            'sender_account' => 'required',

        ]);
    }









}