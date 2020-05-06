<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\Merchant;
use App\MerchantAccount;
use App\PenaltyDeduction;
use App\Services\BalanceEnquiryService;
use App\Services\BRBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
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


class WithdrawalOnUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */



    public function cash_withdrawal(Request $request)
    {
        $validator = $this->cash_withdrawal_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $card_number = substr($request->card_number, 0, 16);

        $fees_charged = FeesCalculatorService::calculateFees(
            $request->amount /100,
            '0.00',
            CASH_WITHDRAWAL,
            HQMERCHANT,$request->source_account
        );

        $balance_res = BRBalanceService::br_balance($request->source_account);
        if($balance_res["code"] != '000'){
            return response([
                'code' => $balance_res["code"],
                'description' => $balance_res["description"],
            ]);
        }

       $response =  $this->switchLimitChecks($request->source_account, $request->amount/100 , $fees_charged['maximum_daily'], $card_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
        if($response["code"] != '000'){
            LoggingService::message('Limits error'.$response["code"].'  '. $response["description"]);
            return response([
                'code' => $response["code"],
                'description' => $response["description"],
            ]);
        }



        $deductable_amount =  $fees_charged['fees_charged'] + $request->amount /100;
        if($deductable_amount > $balance_res["available_balance"] ){
            LoggingService::message('Insufficient funds'.$request->account_number);
            return response([
                'code' => '116',
                'description' => 'Insufficient funds',
            ]);
        }




        $reference =UniqueTxnId::transaction_id();
        $br_job = new BRJob();
        $br_job->txn_status = 'PROCESSING';
        $br_job->status = 'DRAFT';
        $br_job->version = 0;
        $br_job->amount_due = $deductable_amount;
        $br_job->amount = $request->amount /100;
        $br_job->tms_batch = $reference;
        $br_job->narration = 'POS cash withdrawal';
        $br_job->source_account =$request->source_account;
        $br_job->destination_account =$request->destination_account;
        $br_job->rrn = $request->rrn;
        $br_job->txn_type = PURCHASE_OFF_US;
        $br_job->save();


        Transactions::create([
            'txn_type_id'         => CASH_WITHDRAWAL,
            'revenue_fees'        => $fees_charged['acquirer_fee'],
            'transaction_amount'  => $request->amount /100,
            'total_debited'       => $deductable_amount,
            'total_credited'      => $deductable_amount,
            'switch_reference'    => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $request->account_number,
            'pan'                 => $request->card_number,
            'Description'         => 'Transaction successfully processed.'

        ]);


        return response([
            'code'              => '000',
            'fees_charged'      => $fees_charged['fees_charged'],
            'batch_id'          => (string)$reference,
            'description'       => 'Success'
        ]);
    }

    public function switchLimitChecks($account_number,$amount,$maximum_daily,$card_number,$transaction_count,$max_daily_limit){
        $account = substr($account_number, 0,3);
        if($account == '263'){
            $total_count  = WalletTransactions::where('account_debited',$account_number)
                ->whereIn('txn_type_id',[CASH_WITHDRAWAL])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $daily_spent =  WalletTransactions::where('account_debited', $account_number)
                ->where('created_at', '>', Carbon::now()->subDays(1))
                ->sum('transaction_amount');


            if($amount > $maximum_daily){
                WalletTransactions::create([
                    'txn_type_id'         => CASH_WITHDRAWAL,
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
                    'txn_type_id'         => CASH_WITHDRAWAL,
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
                    'txn_type_id'         => CASH_WITHDRAWAL,
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
                    'description' => 'Transaction limit reached for the day',

                );
            }



            return array(
                'code' => '000',
                'description' => 'Success',

            );

        }


        $total_count  = Transactions::where('account_debited',$account_number)
            ->whereIn('txn_type_id',[CASH_WITHDRAWAL])
            ->where('description','Transaction successfully processed.')
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

        $daily_spent =  Transactions::where('account_debited', $account_number)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');


        if($amount > $maximum_daily){
            Transactions::create([
                'txn_type_id'         => CASH_WITHDRAWAL,
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
                'txn_type_id'         => CASH_WITHDRAWAL,
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
                'txn_type_id'         => CASH_WITHDRAWAL,
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
                'description' => 'Transaction limit reached for the day',

            );
        }


        return array(
            'code' => '000',
            'description' => 'Success',

        );


    }

        public function cash_withdrawal_(Request $request)
    {

        $validator = $this->cash_withdrawal_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        try {

            //Balance Enquiry On Us Debit Fees
            $fees_charged = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                CASH_WITHDRAWAL,
                HQMERCHANT,$request->source_account

            );



            $total_funds = $fees_charged['acquirer_fee'] + ($request->amount / 100);
            // Check if client has enough funds.


            $branch_id = substr($request->source_account, 0, 3);

            $revenue = REVENUE;


            $credit_merchant_account = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->destination_account,
                'trx_description_id' => '008',
                'TrxDescription' => 'Credit GL, Withdrawal via POS',
                'TrxAmount' => $request->amount / 100);

            $debit_client_amount = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->source_account,
                'trx_description_id' => '007',
                'TrxDescription' => 'Withdrawal via POS',
                'TrxAmount' => '-' . $request->amount / 100);


            $debit_client_fees = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->source_account,
                'trx_description_id' => '007',
                'TrxDescription' => 'Withdrawal fees charged',
                'TrxAmount' => '-' . $fees_charged['acquirer_fee']);


            $credit_revenue_fees = array(
                'serial_no' => '472100',
                'our_branch_id' => '001',
                'account_id' => $revenue,
                'trx_description_id' => '008',
                'TrxDescription' => "Withdrawal fees earned",
                'TrxAmount' => $fees_charged['acquirer_fee']);



            $client = new Client();

                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                    'headers' => ['Authorization' => 'Cash', 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(

                            $credit_merchant_account,
                            $debit_client_amount,
                            $debit_client_fees,
                            $credit_revenue_fees,


                        ),
                    ]
                ]);


                return $response_ = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());

                if ($response->code != '00') {

                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => $fees_charged['tax'],
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => $request->amount / 100,
                        'total_debited' => $total_funds,
                        'total_credited' => $total_funds,
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => $response->description,


                    ]);


                    return response([

                        'code' => '100',
                        'description' => $response->description


                    ]);


                }


                $revenue = $fees_charged['acquirer_fee'];
                $merchant_amount = -$fees_charged['mdr'] + ($request->amount / 100);

                Transactions::create([

                    'txn_type_id' => CASH_WITHDRAWAL,
                    'tax' => $fees_charged['tax'],
                    'revenue_fees' => $revenue,
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $request->amount / 100,
                    'total_debited' => $total_funds,
                    'total_credited' => $total_funds,
                    'batch_id' => $response->transaction_batch_id,
                    'switch_reference' => $response->transaction_batch_id,
                    'merchant_id' => '',
                    'transaction_status' => 1,
                    'account_debited' => $request->account_number,
                    'pan' => $request->card_number,
                    'merchant_account' => $merchant_amount,
                    'description' => 'Transaction successfully processed.',

                ]);


                return response([

                    'code' => '000',
                    'batch_id' => (string)$response->transaction_batch_id,
                    'description' => 'Success'


                ]);


            } catch (RequestException $e) {

            return $e;

                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();
                    Log::debug('Account Number:' . $request->account_number . ' ' . $exception);

                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => '0.00',
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => '0.00',
                        'total_debited' => '0.00',
                        'total_credited' => '0.00',
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => 'Failed to process BR transaction',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process BR transaction'


                    ]);

                    //return new JsonResponse($exception, $e->getCode());
                } else {
                    Log::debug('Account Number:' . $request->account_number . ' ' . $e->getMessage());
                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => '0.00',
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => '0.00',
                        'total_debited' => '0.00',
                        'total_credited' => '0.00',
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => 'Failed to process transactions',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process BR transaction',


                    ]);

                }
            }

        }




    protected function cash_withdrawal_validator(Array $data)
    {
        return Validator::make($data, [
            'source_account'        => 'required',
            'destination_account'   => 'required',
            'amount'                => 'required',

        ]);

    }

}














