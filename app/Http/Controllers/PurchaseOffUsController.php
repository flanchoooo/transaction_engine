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


class PurchaseOffUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */



    public function purchase_off_us(Request $request)
    {

        $validator = $this->purchase_off_us_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $branch_id = substr($request->account_number, 0, 3);
        $card_details = LuhnCards::where('track_1', $request->card_number)->get()->first();
        $card_number = substr($request->card_number, 0, 16);


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

            $total_count  = WalletTransactions::where('account_debited',$request->account_number)
                ->whereIn('txn_type_id',[PURCHASE_OFF_US,PURCHASE_ON_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();


            if( $total_count >= $fees_charged['transaction_count'] ){

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

            $revenue = Wallet::where('mobile', Accounts::find(8)->account_number)->get()->first();
            $tax = Wallet::where('mobile', Accounts::find(10)->account_number)->get()->first();
            $zimswitch_wallet = Wallet::where('mobile', Accounts::find(9)->account_number)->get()->first();


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

                    'txn_type_id'         => PURCHASE_OFF_US,
                    'tax'                 => $fees_charged['tax'],
                    'revenue_fees'        => $fees_charged['interchange_fee'],
                    'interchange_fees'    => $fees_charged['interchange_fee'],
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
            WalletTransactions::create([

                'txn_type_id'         => PURCHASE_OFF_US,
                'tax'                 =>  $fees_charged['tax'],
                'revenue_fees'        => $fees_charged['interchange_fee'],
                'interchange_fees'    => $fees_charged['interchange_fee'],
                'zimswitch_fee'       => $credit_zimswitch_account,
                'transaction_amount'  => $request->amount /100,
                'total_debited'       => $total_deductions,
                'total_credited'      => '0.00',
                'batch_id'            => $reference,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => $source->mobile,
                'pan'                 => $request->card_number,
                'Description'         => 'Transaction successfully processed.',
                'balance_after_txn'   => $source_new_balance

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
                $zimswitch_wallet_mobile = Wallet::whereMobile(ZIMSWITCH_WALLET_MOBILE);




                  $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount /100,
                    '0.00',
                    PURCHASE_OFF_US,
                   HQMERCHANT
                );

                $source_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($source_deductions > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => PURCHASE_OFF_US,
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
                        'account_debited'     => $request->br_account,
                        'pan'                 => '',
                        'description'         => 'Transaction limit reached for the day.',


                    ]);


                    return response([
                        'code' => '121',
                        'description' => 'Transaction limit reached for the day.',

                    ]);
                }


                $amount = $fees_charged['interchange_fee'];
                $merchant_amount_mobile =  -$amount + $fees_charged['acquirer_fee'] + $request->amount /100;
                $source_deductions = $fees_charged['fees_charged'] + ($request->amount /100);

                //Fee Deductions.

                //Deduct Tax
                $tax = $tax_account->lockForUpdate()->first();
                $tax->balance += $fees_charged['tax'];
                $tax->save();

                //Credit Revenue with interchange fee.
                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance += $amount;
                $toAccount->save();

                //Debit Client with fees.
                $fromAccount->balance -= $source_deductions;
                $fromAccount->save();

                //Credit Zimswitch
                $merchant_acc = $zimswitch_wallet_mobile->lockForUpdate()->first();
                $merchant_acc->balance += $merchant_amount_mobile;
                $merchant_acc->save();


                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = PURCHASE_OFF_US;
                $transaction->tax               =  $fees_charged['tax'];
                $transaction->revenue_fees      = $fees_charged['fees_charged'];
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $request->amount /100;
                $transaction->total_debited     = $fees_charged['fees_charged'] + $request->amount /100;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       =  $merchant_id->merchant_id;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->account_number;
                $transaction->account_credited  = ZIMSWITCH_WALLET_MOBILE;
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


        }




        try {


               $fees_charged = FeesCalculatorService::calculateFees(

                $request->amount /100,
                '0.00',
                   PURCHASE_OFF_US,
                '28' // Configure Default Merchant

            );

             $total_count  = Transactions::where('account_debited',$request->account_number)
                ->whereIn('txn_type_id',[PURCHASE_OFF_US,PURCHASE_ON_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();



            if($total_count  >= $fees_charged['transaction_count'] ){

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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',


                ]);

                

                return response([
                    'code' => '123',
                    'description' => 'Transaction limit reached for the day.',

                ]);
            }



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
                    'description'         => 'Amount exceeds limit per transaction.',


                ]);

                return response([
                    'code' => '121',
                    'description' => 'Amount exceeds limit per transaction.',

                ]);
            }



            $authentication = TokenService::getToken();


            $total_funds = $fees_charged['fees_charged'] + ($request->amount /100);




            $zimswitch = ZIMSWITCH;
            $revenue = REVENUE;
            $tax = TAX;

                $credit_zimswitch_account = $fees_charged['zimswitch_fee']
                                          + $fees_charged['acquirer_fee']
                                          +   $request->amount /100;


                $debit_client_purchase_amount = array(
                    'SerialNo'              => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $request->account_number,
                    'TrxDescriptionID'      => '007',
                    'TrxDescription'        => 'Purchase off us, debit purchase amount',
                    'TrxAmount'             => '-' . $request->amount /100);


                $debit_client_fees = array(
                    'SerialNo'              => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $request->account_number,
                    'TrxDescriptionID'      => '007',
                    'TrxDescription'        => 'Purchase off us, debit fees from client',
                    'TrxAmount'             => '-' . $fees_charged['fees_charged']);

                $credit_tax = array(
                    'SerialNo'              => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $tax,
                    'TrxDescriptionID'      => '008',
                    'TrxDescription'        => 'Purchase off us, credit tax',
                    'TrxAmount'             => $fees_charged['tax']);


                 $credit_zimswitch = array(
                     'SerialNo'             => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $zimswitch,
                    'TrxDescriptionID'      => '008',
                    'TrxDescription'        => 'Purchase off us, credit Zimswitch',
                    'TrxAmount'             =>  $credit_zimswitch_account);

                $debit_zimswitch_inter_fee = array(
                    'SerialNo'              => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $zimswitch,
                    'TrxDescriptionID'      => '007',
                    'TrxDescription'        => 'Purchase off us, debit inter change fee',
                    'TrxAmount'             => '-' . $fees_charged['interchange_fee']);


                $credit_revenue = array(
                    'SerialNo'              => '472100',
                    'OurBranchID'           => $branch_id,
                    'AccountID'             => $revenue,
                    'TrxDescriptionID'      => '008',
                    'TrxDescription'        => 'Purchase off us, credit revenue',
                    'TrxAmount'             => $fees_charged['interchange_fee']);




                     $client = new Client();
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

                    if($response->description == 'API : Validation Failed: Customer TrxAmount cannot be Greater Than the AvailableBalance'){

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
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'description'         => 'Insufficient funds'

                        ]);


                        return response([

                            'code' => '116',
                            'description' => 'Insufficient funds'


                        ]);

                    }

                    if ($response->code != '00')
                    {

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
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,
                            'description'         => 'Invalid BR account number'

                        ]);


                        return response([

                            'code'        => '100',
                            'description' => 'Invalid BR account number'


                        ]);


                    }

                        //$revenue = $fees_charged['mdr']  +  $fees_charged['acquirer_fee'] + $fees_charged['interchange_fee'];

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_OFF_US,
                            'tax'                 =>  $fees_charged['tax'],
                            'revenue_fees'        => $fees_charged['interchange_fee'],
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
                            'Description'         => 'Transaction successfully processed.'

                        ]);


                        return response([

                            'code'              => '000',
                            'fees_charged'      => $fees_charged['fees_charged'],
                            'batch_id'          => (string)$response->transaction_batch_id,
                            'description'       => 'Success'


                        ]);




        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Account Number:'. $request->account_number.' '. $exception);
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
                    'description'         =>'Failed to process BR transaction',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);


            } else {




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
                    'description'         => $e->getMessage(),


                ]);

                Log::debug('Account Number:'. $request->account_number.' '. $e->getMessage());

                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);


            }
        }


    }



    protected function purchase_off_us_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'card_number' => 'required',

        ]);
    }




}