<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PendingTxn;
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


        $merchant_id        = Devices::where('imei', $request->imei)->first();
        if(!isset($merchant_id)){
            return response([
                'code' => '100',
                'description' => 'Unknown device.',
            ]);
        }

        if($merchant_id->status != 'ACTIVE'){
            return response([
                'code' => '100',
                'description' => 'Device not active.',
            ]);
        }
        $merchant_account   = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
        $card_number = substr($request->card_number, 0, 16);
        $source_account_number  = substr($request->account_number, 0, 3);





        if ($source_account_number == '263') {
             $merchant_id = Devices::where('imei', $request->imei)->first();


            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereMobile($request->account_number);
                 $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount /100, '0.00', PURCHASE_ON_US,
                    $merchant_id->merchant_id,$request->account_number
                );

                 $response =   $this->switchLimitChecks($request->account_number, $request->amount/100 , $fees_charged['maximum_daily'], $card_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
                if($response["code"] != '000'){
                    return response([
                        'code' => $response["code"],
                        'description' => $response["description"],
                    ]);
                }


                $source_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($source_deductions > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => PURCHASE_ON_US,
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

                /*$daily_spent =  WalletTransactions::where('account_debited', $request->account_number)
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

                */


                $source_deductions = $fees_charged['tax'] + $fees_charged['acquirer_fee'] + $request->amount /100;
                $fromAccount->balance -= $source_deductions;
                $fromAccount->save();


                $source_new_balance             = $fromAccount->balance;
                $reference                      = $this->genRandomNumber();
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = PURCHASE_ON_US;
                $transaction->tax               =  $fees_charged['tax'];
                $transaction->revenue_fees      =  $fees_charged['acquirer_fee'];
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $request->amount /100;
                $transaction->total_debited     = $fees_charged['fees_charged'] +  $request->amount /100;
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = $merchant_id->merchant_id;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->account_number;
                $transaction->pan               = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

               //Revenue Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->amount = $fees_charged['acquirer_fee'];
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = REVENUE;
                $auto_deduction->txn_status = 'WALLET PENDING';
                $auto_deduction->description = 'WALLET| Fees settlement on purchase on us | '. $request->account_number.' '.$reference;
                $auto_deduction->save();

                //Tax Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $fees_charged['tax'];
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = TAX;
                $auto_deduction->txn_status = 'WALLET PENDING';
                $auto_deduction->description =  'WALLET| Tax settlement on purchase on us | '. $request->account_number.' '.$reference;
                $auto_deduction->save();

                //Merchant Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $request->amount /100;
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = $merchant_account->account_number;
                $auto_deduction->txn_status = 'WALLET PENDING';
                $auto_deduction->description = 'WALLET| Merchant settlement on purchase on us | '. $request->account_number.' '.$reference;
                $auto_deduction->save();


                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $fees_charged['mdr'];
                $auto_deduction->wallet_batch_id = $reference;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = $merchant_account->account_number;
                $auto_deduction->destination_account = REVENUE;
                $auto_deduction->txn_status = 'WALLET PENDING';
                $auto_deduction->description = 'WALLET | Merchant service fees | '. $request->account_number.' '.$reference;
                $auto_deduction->save();



               /* $merchant_name = $merchant->name;
                $new_balance = money_format('$%i', $request->amount /100);
                $new_wallet_balance = money_format('$%i', $source_new_balance);
                $sender_mobile =  COUNTRY_CODE.substr($request->mobile, 1, 10);

                $merchant_wallet = $merchant_acc->mobile;

                dispatch(new NotifyBills(
                        $sender_mobile,
                        "Purchase of goods and service worth ZWL $new_balance was successful. Merchant:$merchant_name reference:$reference, your new balance is ZWL $new_wallet_balance" ,
                        'eBucks',
                    $merchant_wallet,
                        "Your merchant wallet has been credited with ZWL $new_balance via m-POS card swipe  from client with mobile: $sender_mobile, reference:$reference" ,
                        '2'
                    )
                );
               */


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

                //Balance Enquiry On Us Debit Fees
                   $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount /100,
                    '0.00',
                      PURCHASE_ON_US,
                    $merchant_id->merchant_id,$request->account_number
                );


                  $response =   $this->switchLimitChecks(
                    $request->account_number,
                    $request->amount/100 ,
                    $fees_charged['maximum_daily'],
                    $card_number,$fees_charged['transaction_count'],
                    $fees_charged['max_daily_limit']);

                  if($response["code"] != '000'){
                      return response([
                          'code' => $response["code"],
                          'description' => $response["description"],
                      ]);
                  }


                $total_funds = $fees_charged['fees_charged'] + ($request->amount /100);
                // Check if client has enough funds.

                    $revenue = REVENUE;
                    $tax = TAX;


                $debit_client_amount        = array('serial_no' => '472100',
                    'our_branch_id'           => substr($request->account_number, 0, 3),
                    'account_id'             => $request->account_number,
                    'trx_description_id'      => '007',
                    'trx_description'        => 'POS PURCHASE @'. $request->merchant_name,
                    'trx_amount'             => '-' . $request->amount /100);

                $debit_client_fees          = array('serial_no' => '472100',
                    'our_branch_id'           => substr($request->account_number, 0, 3),
                    'account_id'             => $request->account_number,
                    'trx_description_id'      => '007',
                    'trx_description'        => 'POS PURCHASE FEES',
                    'trx_amount'             => '-' . $fees_charged['fees_charged']);

                $credit_revenue_fees        = array('serial_no' => '472100',
                    'our_branch_id'           => '001',
                    'account_id'             => $revenue,
                    'trx_description_id'      => '008',
                    'trx_description'        => "POS PURCHASE REVENUE",
                    'trx_amount'             => $fees_charged['acquirer_fee']);


                $tax_account_credit         = array('serial_no' => '472100',
                    'our_branch_id'           => '001',
                    'account_id'             => $tax,
                    'trx_description_id'      => '008',
                    'trx_description'        => "POS PURCHASE TAZ",
                    'trx_amount'             =>  $fees_charged['tax']);

                $credit_merchant_account    = array('serial_no' => '472100',
                    'our_branch_id'           => substr($merchant_account->account_number, 0, 3),
                    'account_id'             => $merchant_account->account_number,
                    'trx_description_id'      => '008',
                    'trx_description'        => 'POS PURCHASE',
                    'trx_amount'             => $request->amount /100);




                    $client = new Client();
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => 'PURCHASE', 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array(

                                    $debit_client_amount,
                                    $debit_client_fees,
                                    $credit_revenue_fees,
                                    $tax_account_credit,
                                    $credit_merchant_account
                                ),
                            ]
                        ]);


                        $response = json_decode($result->getBody()->getContents());

                if ($response->description == 'API : Validation Failed: Customer trx_amount cannot be Greater Than the AvailableBalance'){
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
                                'description'          => 'Failed to process transaction',


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

                            MDR::create([
                                'amount'            => $fees_charged['mdr'],
                                'imei'              => $request->imei,
                                'merchant'          => $merchant_id->merchant_id,
                                'source_account'    => $merchant_account->account_number,
                                'txn_status'        => 'PENDING',
                                'batch_id'          => $response->transaction_batch_id,

                            ]);

                           /* if(isset($request->mobile)) {
                                $merchant = Merchant::find($merchant_id->merchant_id);
                                $merchant_name = $merchant->name;
                                $merchant_mobile = $merchant->mobile;
                                $new_balance = money_format('$%i', $request->amount / 100);
                                $sender_mobile = COUNTRY_CODE . substr($request->mobile, 1, 10);

                                dispatch(new NotifyBills(
                                        $sender_mobile,
                                        "Purchase of ZWL $new_balance was successful. Merchant:$merchant_name reference:$response->transaction_batch_id",
                                        'GetBucks',
                                        COUNTRY_CODE . substr($merchant_mobile, 1, 10),
                                        "Your merchant account has been credited with ZWL $new_balance via m-POS card swipe  from client with mobile: $sender_mobile, reference:$response->transaction_batch_id",
                                        '2'
                                    )
                                );

                            }

                           */

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
                        'code'          => '100',
                        'description'   => 'Failed to process BR transaction'
                    ]);

                }
            }

        }

    public function genRandomNumber($length = 10, $formatted = false){
        $nums = '0123456789';

        // First number shouldn't be zero
        $out = $nums[ mt_rand(1, strlen($nums) - 1) ];

        // Add random numbers to your string
        for ($p = 0; $p < $length - 1; $p++)
            $out .= $nums[ mt_rand(0, strlen($nums) - 1) ];

        // Format the output with commas if needed, otherwise plain output
        if ($formatted)
            return number_format($out);

        return $out;
    }

    public function switchLimitChecks($account_number,$amount,$maximum_daily,$card_number,$transaction_count,$max_daily_limit){


        $account = substr($account_number, 0,3);
        if($account == '263'){
            $total_count  = WalletTransactions::where('account_debited',$account_number)
                ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $daily_spent =  WalletTransactions::where('account_debited', $account_number)
                ->where('created_at', '>', Carbon::now()->subDays(1))
                ->sum('transaction_amount');


            if($amount > $maximum_daily){
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
                    'account_debited'     => $account_number,
                    'pan'                 => $card_number,
                    'description'         => 'Exceeds maximum purchase limit',

                ]);

                return array(
                    'code' => '121',
                    'description' => "Exceeds maximum purchase ". "<br>"."limit",

                );

            }


            if($total_count  >= $transaction_count ){
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Exceeds purchase frequency limit.',
                ]);

                return array(
                    'code' => '123',
                    'description' => 'Exceeds purchase frequency limit.',

                );

            }

            if($daily_spent  >= $max_daily_limit ){
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',
                ]);

                return array(
                    'code' => '121',
                    'description' => 'Exceeds purchase frequency limit.',

                );
            }



            return array(
                'code' => '000',
                'description' => 'Success',

            );

        }


        $total_count  = Transactions::where('account_debited',$account_number)
            ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
            ->where('description','Transaction successfully processed.')
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

        $daily_spent =  Transactions::where('account_debited', $account_number)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');


        if($amount > $maximum_daily){
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
                'account_debited'     => $account_number,
                'pan'                 => $card_number,
                'description'         => 'Exceeds maximum purchase limit',

            ]);

            return array(
                'code' => '121',
                'description' => 'Exceeds maximum purchase limit',

            );

        }


        if($total_count  >= $transaction_count ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Exceeds purchase frequency limit.',
            ]);

            return array(
                'code' => '123',
                'description' => 'Exceeds purchase frequency limit.',

            );

        }

        if($daily_spent  >= $max_daily_limit ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Transaction limit reached for the day.',
            ]);

            return array(
                'code' => '121',
                'description' => 'Exceeds purchase frequency limit.',

            );
        }



        return array(
            'code' => '000',
            'description' => 'Success',

        );






    }


    protected function purchase_validation(Array $data)
    {
        return Validator::make($data, [
            'amount' => 'required',
            'card_number' => 'required',

        ]);
    }






}