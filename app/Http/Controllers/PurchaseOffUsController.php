<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\BRAccountID;
use App\BRClientInfo;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\PostWalletPurchaseJob;
use App\Jobs\PurchaseJob;
use App\Jobs\WalletSendMoneyJob;
use App\LuhnCards;
use App\MerchantAccount;
use App\PenaltyDeduction;
use App\Services\AccountInformationService;
use App\Services\BalanceEnquiryService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\SessionToken;
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
use Illuminate\Database\QueryException;
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


        $card_number = substr($request->card_number, 0, 16);

        $card_details = LuhnCards::where('track_1', $card_number)->get()->first();
        if (isset($card_details->wallet_id)) {

            // $merchant_id = Devices::where('imei', $request->imei)->first();
            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereId($card_details->wallet_id);
                //  $toQuery     = Wallet::whereMobile(WALLET_REVENUE);
                // $tax_account = Wallet::whereMobile(WALLET_TAX);
                // $zimswitch_wallet_mobile = Wallet::whereMobile(ZIMSWITCH_WALLET_MOBILE);




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
                        'merchant_id'       => HQMERCHANT,
                        'transaction_status'=> 0,
                        'pan'               => $card_number,
                        'description'       => 'Insufficient funds for mobile:' . $request->account_number,


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }

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





                $amount = $fees_charged['interchange_fee'];
                $merchant_amount_mobile = $fees_charged['zimswitch_fee']  + $fees_charged['acquirer_fee'] + $request->amount /100;
                $total = $request->amount /100 +  $fees_charged['acquirer_fee'] +  $fees_charged['zimswitch_fee']  + $fees_charged['tax'] + $fees_charged['interchange_fee'];



                $fromAccount->balance -= $total;
                $fromAccount->save();

                $source_new_balance             = $fromAccount->balance;
                $reference                      = $this->genRandomNumber();
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
                $transaction->merchant_id       = HQMERCHANT;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $request->account_number;
                $transaction->account_credited  = ZIMSWITCH_WALLET_MOBILE;
                $transaction->pan               = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                //Tax Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $fees_charged['tax'];
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = TAX;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->description = 'Wallet tax settlement on purchase (off us):'. $request->account_number.' '.$reference;
                $auto_deduction->save();

                //Revenue Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $amount;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = REVENUE;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->description = 'Wallet revenue settlement on purchase:(off us)'. $request->account_number.' '.$reference;
                $auto_deduction->save();

                //Revenue Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $merchant_amount_mobile;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = ZIMSWITCH;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->description = 'Wallet  settlement on purchase:(off us)'. $request->account_number.' '.$reference;
                $auto_deduction->save();

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
                    'merchant_id'       => HQMERCHANT,
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
            $account = BRAccountID::where('AccountID', $request->account_number)->first();
            if ($account == null) {
                LoggingService::message('Purchase:Invalid Account:'.$request->account_number);
                return $response = response([
                    'code' => '114',
                    'description' => 'Invalid Account',
                ]);

            }

            if ($account->IsBlocked == 1) {
                LoggingService::message('Purchase:Account closed:'.$request->account_number);
                return response([

                    'code' => '114',
                    'description' => 'Account is closed',
                ]);
            }
        }catch (QueryException $queryException){
            LoggingService::message('Error accessing CBS'.$request->account_number);
            return response([
                'code' => '100',
                'description' => 'Error accessing CBS',
            ]);
        }

        $fees_charged = FeesCalculatorService::calculateFees(
            $request->amount /100,
            '0.00',
            PURCHASE_OFF_US,
            HQMERCHANT,$request->account_number
        );



        $response =  $this->switchLimitChecks($request->account_number, $request->amount/100 , $fees_charged['maximum_daily'], $card_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
        if($response["code"] != '000'){
            LoggingService::message('Limits error'.$response["code"].'  '. $response["description"]);
            return response([
                'code' => $response["code"],
                'description' => $response["description"],
            ]);
        }


        $amounts_to = BRJob::where('source_account',$request->account_number)
            ->where('txn_status','PROCESSING')->get()->sum(['amount']);

        $change = $fees_charged['fees_charged'];
        $deductable_amount = $amounts_to + $fees_charged['fees_charged'] + $request->amount /100;

        $available = $account->ClearBalance - $fees_charged['minimum_balance'];
        if($deductable_amount > $available ){
            PenaltyDeduction::create([
                'amount'                => ZIMSWITCH_PENALTY_FEE,
                'imei'                  => '000',
                'merchant'              => HQMERCHANT,
                'source_account'        => $request->account_number,
                'destination_account'   => ZIMSWITCH,
                'txn_status'            => 'PENDING',
                'description'           => 'Swipe fee'

            ]);
            Log::info('Insufficient funds'.$request->account_number);
            return response([
                'code' => '116',
                'description' => 'Insufficient funds',
            ]);
        }


        $reference = $this->genRandomNumber(6,false);
        $br_job = new BRJob();
        $br_job->txn_status = 'PROCESSING';
        $br_job->status = 'DRAFT';
        $br_job->version = 0;
        $br_job->amount = $request->amount /100;
        $br_job->source_account = $request->account_number;
        $br_job->tms_batch = $reference;
        $br_job->rrn = $request->rrn;
        $br_job->txn_type = PURCHASE_OFF_US;
        $br_job->save();

        $credit_zimswitch_account = $fees_charged['zimswitch_fee'] + $request->amount /100;
        Transactions::create([
            'txn_type_id'         => PURCHASE_OFF_US,
            'tax'                 =>  $fees_charged['tax'],
            'revenue_fees'        => $fees_charged['interchange_fee'],
            'interchange_fees'    => $fees_charged['interchange_fee'],
            'zimswitch_fee'       => $credit_zimswitch_account,
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

        if(isset($request->narration)){
            $narration = $request->narration;
        }else{
            $narration = 'Zimswitch Transaction';
        }

        LoggingService::message('Job dispatched'.$request->account_number);
        dispatch(new PurchaseJob($request->account_number,$request->amount /100,$reference,$request->rrn,$narration));

        return response([
            'code'              => '000',
            'fees_charged'      => $change,
            'batch_id'          => (string)$reference,
            'description'       => 'Success'
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
            ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
            ->where('description','Transaction successfully processed.')
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

        $daily_spent =  Transactions::where('account_debited', $account_number)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');


        if($amount > $maximum_daily){
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


    protected function purchase_off_us_validation(Array $data)
    {
        return Validator::make($data, [
            'amount'        => 'required',
            'card_number'   => 'required',

        ]);
    }




}