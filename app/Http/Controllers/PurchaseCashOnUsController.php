<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\BRBalanceService;
use App\Services\CheckBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\PurchaseCashService;
use App\Services\TokenService;
use App\Services\UniqueTxnId;
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



            $reference = UniqueTxnId::transaction_id();
            $card_number = str_limit($request->card_number,16,'');
            $merchant_id = Devices::where('imei', $request->imei)->first();
            $merchant_account = MerchantAccount::where('merchant_id',$merchant_id->merchant_id)->first();
            $merchant_account->account_number;

              $fees_charged = FeesCalculatorService::calculateFees($request->amount /100, $request->cashback_amount/100, PURCHASE_CASH_BACK_ON_US, $merchant_id->merchant_id,$request->account_number);

                    $response_transaction =  $this->switchLimitChecks(
                    $request->account_number,
                    $request->amount/100 ,
                    $fees_charged['maximum_daily'],
                    $card_number,$fees_charged['transaction_count'],
                    $fees_charged['max_daily_limit']);


                if ($response_transaction["code"] != '000') {
                    return response([
                        'code' => $response_transaction["code"],
                        'description' => $response_transaction["description"],
                    ]);
                }

                $balance_res = BRBalanceService::br_balance($request->account_number);
                if ($balance_res["code"] != '000') {
                    return response([
                        'code'          => $balance_res["code"],
                        'description'   => $balance_res["description"],
                    ]);
                }


                $total_funds = $fees_charged['fees_charged'] + ($request->amount / 100);
                if ($total_funds > $balance_res["available_balance"]) {
                    LoggingService::message('Insufficient funds' . $request->account_number);
                    return response([
                        'code' => '116',
                        'description' => "Insufficient funds",
                    ]);
                }

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $request->amount / 100;
                $br_job->amount_due = $total_funds;
                $br_job->source_account = $request->account_number;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration = $request->imei;
                $br_job->rrn = $reference;
                $br_job->txn_type = PURCHASE_CASH_BACK_ON_US;
                $br_job->save();

                $br_jobs = new BRJob();
                $br_jobs->txn_status = 'PENDING';
                $br_jobs->amount = $fees_charged['mdr'];
                $br_jobs->amount_due = $fees_charged['mdr'];
                $br_jobs->source_account = $merchant_account->account_number;
                $br_jobs->status = 'DRAFT';
                $br_jobs->version = 0;
                $br_jobs->tms_batch = $reference;
                $br_jobs->narration = $request->imei;
                $br_jobs->rrn = $reference;
                $br_jobs->txn_type = MDR_DEDUCTION;
                $br_jobs->save();

                $amount = $request->amount /100;
                $cash  = $request->cashback_amount/100;
                $goods_services = $amount - $cash;
                $total_txn_amount = $cash + $goods_services;
                $merchant_account_amount  = $total_txn_amount - $fees_charged['mdr'];
                $total_debit  = $total_txn_amount + $fees_charged['fees_charged'];
                $rev =  $fees_charged['acquirer_fee'] +  $fees_charged['cash_back_fee'] +$fees_charged['mdr'];

                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_ON_US,
                    'tax'                 => $fees_charged['tax'],
                    'revenue_fees'        =>  $rev,
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => $total_txn_amount,
                    'total_debited'       => $total_debit,
                    'total_credited'      => $total_debit,
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => $merchant_id->merchant_id,
                    'transaction_status'  => 1,
                    'account_debited'       => $request->account_number,
                    'pan'                 => $card_number,
                    'merchant_account'    => $merchant_account_amount,
                    'cash_back_amount'    => $request->cashback_amount /100,
                    'description'         => 'Transaction successfully processed.',

                ]);

                return response([
                    'code'          => '000',
                    'batch_id'      => (string)$reference,
                    'description'   => 'Success'
                ]);

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