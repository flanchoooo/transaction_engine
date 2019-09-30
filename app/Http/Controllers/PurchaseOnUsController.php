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


class PurchaseOnUsController extends Controller
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

        $card_details = LuhnCards::where('track_2', $request->card_number)->get()->first();
        $merchant_id        = Devices::where('imei', $request->imei)->first();
        $merchant_account   = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
        $card_number = substr($request->card_number, 0, 16);



        /*
         * Wallet Code
         */

        /*
        if(isset($card_details->wallet_id)){

            //Declaration
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

            $total_count  = WalletTransactions::where('account_debited',$request->account_number)
                ->whereIn('txn_type_id',[PURCHASE_ON_US,PURCHASE_OFF_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();



            if($total_count  >= $fees_charged['transaction_count'] ){

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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',


                ]);


                return response([
                    'code' => '121',
                    'description' => 'Transaction limit reached for the day.',

                ]);
            }

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
            $revenue = Wallet::where('mobile', Accounts::find(8)->account_number)->get()->first();
            $tax = Wallet::where('mobile', Accounts::find(10)->account_number)->get()->first();
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
                    'transaction_amount'  => $request->amount /100,
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
                'transaction_amount'  => $request->amount /100,
                'total_debited'       => $fees_charged['fees_charged'],
                'total_credited'      => '0.00',
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => $merchant_id->merchant_id,
                'transaction_status'  => 1,
                'account_debited'     => $source->mobile,
                'pan'                 => $request->card_number,
                'description'         => 'Transaction successfully processed.',
                'balance_after_txn'   => $source_new_balance,


            ]);



            // add jobs to update records
            return response([
                'code' => '000',
                'batch_id' => (string)$reference,
                'description' => 'Success'


            ]);


        }

        */

        if (isset($card_details->wallet_id)) {

            $merchant_id = Devices::where('imei', $request->imei)->first();
            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereId($card_details->wallet_id);
                $toQuery     = Wallet::whereMobile(WALLET_REVENUE);
                $tax_account = Wallet::whereMobile(WALLET_TAX);
                $merchant_account_wallet = Wallet::whereMerchantId($merchant_id->merchant_id);



                $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount /100, '0.00', PURCHASE_ON_US,
                    $merchant_id->merchant_id
                );

                $source_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($source_deductions > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => PURCHASE_ON_US,
                        'tax'               => '0.00',
                        'revenue_fees'      => '0.00',
                        'interchange_fees'  => '0.00',
                        'zimswitch_fee'     => '0.00',
                        'transaction_amount'=> '0.00',
                        'total_debited'     => '0.00',
                        'total_credited'    => '0.00',
                        'batch_id'          => '',
                        'switch_reference'  => '',
                        'merchant_id'       => $merchant_id->merchant_id,
                        'transaction_status'=> 0,
                        'pan'               => $card_number,
                        'description'       => 'Insufficient funds for mobile:' . $request->account_number,


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }
                //Check Daily Spent
                $daily_spent =  WalletTransactions::where('account_debited', $request->account_number)
                    ->where('created_at', '>', Carbon::now()->subDays(1))
                    ->sum('transaction_amount');

                //Check Monthly Spent
                $monthly_spent =  WalletTransactions::where('account_debited', $request->account_number)
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

                $total_count  = WalletTransactions::where('account_debited',$request->account_number)
                    ->whereIn('txn_type_id',[PURCHASE_OFF_US,PURCHASE_ON_US])
                    ->where('description','Transaction successfully processed.')
                    ->whereDate('created_at', Carbon::today())
                    ->get()->count();


                if( $total_count >= $fees_charged['transaction_count'] ){

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
                        'account_debited'     => $request->br_account,
                        'pan'                 => '',
                        'description'         => 'Transaction limit reached for the day.',


                    ]);


                    return response([
                        'code' => '121',
                        'description' => 'Transaction limit reached for the day.',

                    ]);
                }


                $merchant_amount_mobile = - $fees_charged['mdr'] + $request->amount /100;
                $amount = $fees_charged['acquirer_fee'] + $fees_charged['mdr'];
                $source_deductions = $fees_charged['tax'] + $fees_charged['acquirer_fee'] + $request->amount /100;


                //Credit Tax
                $tax = $tax_account->lockForUpdate()->first();
                $tax->balance += $fees_charged['tax'];
                $tax->save();

               //Credit Revenue
                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance += $amount;
                $toAccount->save();

                //Debit Purchaser
                $fromAccount->balance -= $source_deductions;
                $fromAccount->save();

                //Credit Merchant
                $merchant_acc = $merchant_account_wallet->lockForUpdate()->first();
                $merchant_acc->balance += $merchant_amount_mobile;
                $merchant_acc->save();


                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = PURCHASE_ON_US;
                $transaction->tax               =  $fees_charged['tax'];
                $transaction->revenue_fees      =  $amount;
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $request->amount /100;
                $transaction->total_debited     = $fees_charged['fees_charged'] +  $request->amount /100;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = $merchant_id->merchant_id;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->account_number;
                $transaction->account_credited  = $merchant_acc->mobile;
                $transaction->pan               = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                DB::commit();


                return response([

                    'code'          => '000',
                    'batch_id'      => "$reference",
                    'description'   => 'Success'


                ]);


            } catch (\Exception $e) {
                return $e;
                DB::rollBack();
                Log::debug('Account Number:'.$request->account_number.' '. $e);

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
                    'merchant_id'       => $merchant_id->merchant_id,
                    'transaction_status'=> 0,
                    'pan'               => $card_number,
                    'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }


            // return 'Success';
        }

        //On Us Purchase Txn Getbucks Card on Getbucks POS

            try {

                //Balance Enquiry On Us Debit Fees
                  $fees_charged = FeesCalculatorService::calculateFees(

                    $request->amount /100,
                    '0.00',
                      PURCHASE_ON_US,
                    $merchant_id->merchant_id

                );

                $transactions  = Transactions::where('account_debited',$request->account_number)
                    ->where('txn_type_id',PURCHASE_ON_US)
                    ->where('description','Transaction successfully processed.')
                    ->whereDate('created_at', Carbon::today())
                    ->get()->count();

                $transactions_  = Transactions::where('account_debited',$request->account_number)
                    ->where('txn_type_id',PURCHASE_ON_US)
                    ->where('description','Transaction successfully processed.')
                    ->whereDate('created_at', Carbon::today())
                    ->get()->count();

                $total_count = $transactions_ + $transactions;

                if($total_count  >= $fees_charged['transaction_count'] ){

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
                        'account_debited'     => $request->br_account,
                        'pan'                 => '',
                        'description'         => 'Transaction limit reached for the day.',


                    ]);


                    return response([
                        'code' => '121',
                        'description' => 'Transaction limit reached for the day.',

                    ]);
                }


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
                    $authentication = TokenService::getToken();

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


                      //return $response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());

                if ($response->description == 'API : Validation Failed: Customer TrxAmount cannot be Greater Than the AvailableBalance'){

                    Transactions::create([

                        'txn_type_id'         => PURCHASE_ON_US,
                        'tax'                 =>  $fees_charged['tax'],
                        'revenue_fees'        => '',
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '0.00',
                        'transaction_amount'  => $request->amount /100,
                        'total_debited'       => '0.00',
                        'total_credited'      => '0.00',
                        'batch_id'            => '',
                        'switch_reference'    => '',
                        'merchant_id'         => $merchant_id->merchant_id,
                        'transaction_status'  => 0,
                        'account_debited'     => $request->account_number,
                        'pan'                 => $request->card_number,
                        'description'         => 'Insufficient funds',


                    ]);


                    return response([

                        'code' => '116',
                        'description' => 'Insufficient funds'


                    ]);


                }


                if ($response->code != '00'){

                            Transactions::create([

                                'txn_type_id'         => PURCHASE_ON_US,
                                'tax'                 =>  $fees_charged['tax'],
                                'revenue_fees'        => '',
                                'interchange_fees'    => '0.00',
                                'zimswitch_fee'       => '0.00',
                                'transaction_amount'  => $request->amount /100,
                                'total_debited'       => $total_funds,
                                'total_credited'      => $total_funds,
                                'batch_id'            => '',
                                'switch_reference'    => '',
                                'merchant_id'         => $merchant_id->merchant_id,
                                'transaction_status'  => 0,
                                'account_debited'     => $request->account_number,
                                'pan'                 => $request->card_number,
                                'description'    => 'Failed to process transaction',


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
                                'description'         => 'Transaction successfully processed.',

                            ]);



                            return response([

                                'code'          => '000',
                                'batch_id'      => (string)$response->transaction_batch_id,
                                'description'   => 'Success'


                            ]);



            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();

                    Log::debug('Account Number:'.$request->account_number.' '. $exception);
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
                        'description'         => 'Failed to process BR Transaction',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process BR transaction'


                    ]);

                    //return new JsonResponse($exception, $e->getCode());
                } else {


                    Log::debug('Account Number:'.$request->account_number.' '. $e->getMessage());
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

                        'code'          => '100',
                        'description'   => 'Failed to process BR transaction'


                    ]);

                }
            }

        }



    protected function purchase_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'card_number' => 'required',

        ]);
    }






}