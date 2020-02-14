<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\CheckBalanceService;
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
            $merchant_id = Devices::where('imei', $request->imei)->first();
            $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
            $merchant_account->account_number;
            $branch_id = substr($merchant_account->account_number, 0, 3);


            try {
                     $fees_result = FeesCalculatorService::calculateFees(
                    $request->amount /100,
                    $request->cashback_amount/100,
                    PURCHASE_CASH_BACK_ON_US,
                    $merchant_id->merchant_id,$request->account_number
                );


               $this->switchLimitChecks($request->account_number, $request->amount/100 , $fees_result['maximum_daily'], $card_number,$fees_result['transaction_count'], $fees_result['max_daily_limit']);

                $transamount = $request->amount/100;
                $total_deduction = $transamount + $fees_result['fees_charged'];
                $balance = CheckBalanceService::checkBalance($request->account_number);
                if( $balance['code'] != '00'){
                    return response([

                        'code'=> $balance['code'],
                        'description'=> $balance['description']

                    ]);

                }


                if($balance['available_balance'] < $total_deduction){
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

                   $amount = $request->amount /100;
                   $cash  = $request->cashback_amount/100;
                   $goods_services = $amount - $cash;

                $debit_client_amount_cashback_amount = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $request->account_number,
                    'trx_description_id'  => '007',
                    'trx_description'    => 'PURCHASE + CASH @ '.$request->merchant_name,
                    'trx_amount'         => '-' . $amount);


                $debit_client_fees = array(
                    'serial_no'        => '472100',
                    'our_branch_id'     => $branch_id,
                    'account_id'       => $request->account_number,
                    'trx_description_id'=> '007',
                    'trx_description'  =>  'PURCHASE + CASH FEES',
                    'trx_amount'       => '-' . $fees_result['fees_charged']);

                $credit_revenue_fees = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $revenue,
                    'trx_description_id'  => '008',
                    'trx_description'    => 'PURCHASE + CASH REVENUE',
                    'trx_amount'         => $fees_result['acquirer_fee']);

                $tax_account_credit = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $tax,
                    'trx_description_id'  => '008',
                    'trx_description'    => 'PURCHASE + CASH TAX',
                    'trx_amount'         => $fees_result['tax']);

                $credit_revenue_cashback_fee = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $revenue,
                    'trx_description_id'  => '008',
                    'trx_description'    => 'PURCHASE + CASH FEES',
                    'trx_amount'         => $fees_result['cash_back_fee']);

                $credit_merchant_account = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $merchant_account->account_number,
                    'trx_description_id'  => '008',
                    'trx_description'    => 'PURCHASE + CASH',
                    'trx_amount'         => $goods_services);

                $credit_merchant_cashback_amount = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $merchant_account->account_number,
                    'trx_description_id'  => '008',
                    'trx_description'    => 'PURCHASE + CASH',
                    'trx_amount'         =>   $cash);

                    $authentication = 'CASH';
                    $client = new Client();



                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array(

                                    $credit_merchant_account,
                                    $debit_client_fees,
                                    $credit_revenue_fees,
                                    $tax_account_credit,
                                    $credit_revenue_cashback_fee,
                                    $credit_merchant_cashback_amount,
                                    $debit_client_amount_cashback_amount
                                ),
                            ]

                        ]);



                        //return $response_ = $result->getBody()->getContents();
                        $response = json_decode($result->getBody()->getContents());
                        if ($response->description == 'API : Validation Failed: Customer trx_amount cannot be Greater Than the AvailableBalance'){
                            Transactions::create([
                                'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
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
                                'description' => $response


                            ]);



                        }


                            $total_txn_amount = $cash + $goods_services;
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
                                'cash_back_amount'    => $request->cashback_amount /100,
                                'description'         => 'Transaction successfully processed.',

                            ]);

                        MDR::create([
                            'amount'            => $fees_result['mdr'],
                            'imei'              => $request->imei,
                            'merchant'          => $merchant_id->merchant_id,
                            'source_account'    => $merchant_account->account_number,
                            'txn_status'        => 'PENDING',
                            'batch_id'          => $response->transaction_batch_id,

                        ]);


                       /*  if(isset($request->mobile)) {
                             $merchant = Merchant::find($merchant_id->merchant_id);
                             $merchant_name = $merchant->name;
                             $merchant_mobile = $merchant->mobile;
                             $new_balance = money_format('$%i', $request->amount / 100);
                             $cash_back = money_format('$%i', $request->cashback_amount / 100);
                             $sender_mobile = COUNTRY_CODE . substr($request->mobile, 1, 10);

                             dispatch(new NotifyBills(
                                     $sender_mobile,
                                     "Purchase of ZWL $new_balance and cash back of  $cash_back was successful. Merchant:$merchant_name reference:$response->transaction_batch_id",
                                     'GetBucks',
                                     COUNTRY_CODE . substr($merchant_mobile, 1, 10),
                                     "Your merchant account has been credited with ZWL $new_balance and cash  back amount worth $cash_back via m-POS card swipe  from client with mobile: $sender_mobile, reference:$response->transaction_batch_id",
                                     '2'
                                 )
                             );

                         }

                       */

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
                            'description'


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

                   return  $e->getMessage();

                    Log::debug('Account Number:'.$request->account_number.' '.  $e->getMessage());
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
                WalletTransactions::create([
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