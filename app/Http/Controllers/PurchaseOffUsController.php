<?php
namespace App\Http\Controllers;


use App\Account;
use App\Accounts;
use App\BRAccountID;
use App\BRClientInfo;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\IBFees;
use App\Jobs\PostWalletPurchaseJob;
use App\Jobs\PurchaseJob;
use App\Jobs\WalletSendMoneyJob;
use App\LuhnCards;
use App\MerchantAccount;
use App\PenaltyDeduction;
use App\Services\AccountInformationService;
use App\Services\BalanceEnquiryService;
use App\Services\BRBalanceService;
use App\Services\CheckBalanceService;
use App\Services\DuplicateTxnCheckerService;
use App\Services\EcocashService;
use App\Services\FeesCalculatorService;
use App\Services\IBFeesCalculatorService;
use App\Services\LoggingService;
use App\Services\PurchaseIssuedService;
use App\Services\SessionToken;
use App\Services\TokenService;
use App\T_Transactions;
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
        $source_account_number  = substr($request->account_number, 0, 3);
        $rrn_result = BRJob::where('rrn', $request->rrn)->get()->count();

        if($rrn_result > 0) {
            return response([
                'code' => '100',
                'description' => 'Do not honor'
            ]);
        }


        if ($source_account_number == '263'){
            if(WALLET_STATUS != 'ACTIVE'){
                return response([
                    'code' => '100',
                    'description' => 'Wallet service is temporarily unavailable',
                ]);
            }

            DB::beginTransaction();
            try {

                $fromQuery   = Wallet::whereMobile($request->account_number);
                $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount /100,
                    '0.00',
                    PURCHASE_OFF_US,
                    HQMERCHANT,$request->account_number
                );

                $source_deductions = $fees_charged['fees_charged'] + ($request->amount /100);
                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($source_deductions > $fromAccount->balance) {
                    WalletTransactions::create([
                        'txn_type_id'       => PURCHASE_OFF_US,
                        'merchant_id'       => HQMERCHANT,
                        'transaction_status'=> 0,
                        'pan'               => $card_number,
                        'description'       => 'Z15 Insufficient funds for mobile:' . $request->account_number,
                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }

                $response =   $this->switchLimitChecks($request->account_number, $request->amount/100 , $fees_charged['maximum_daily'], $card_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
                if($response["code"] != '000'){
                    return response([
                        'code' => $response["code"],
                        'description' => $response["description"],
                    ]);
                }


                $total = $request->amount /100 +  $fees_charged['acquirer_fee'] +  $fees_charged['zimswitch_fee']  + $fees_charged['tax'];
                $fromAccount->balance -= $total;
                $fromAccount->save();

                $source_new_balance             = $fromAccount->balance;
                $reference                      = $request->rrn;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = PURCHASE_OFF_US;
                $transaction->tax               =  $fees_charged['tax'];
                $transaction->revenue_fees      = $fees_charged['acquirer_fee'];
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
                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['tax'];;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = TAX;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration =  "WALLET | Tax settlement | $reference | $request->account_number |  $request->rrn";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['acquirer_fee'];;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = REVENUE;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration =  "WALLET | Revenue settlement | $reference | $request->account_number |  $request->rrn";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['zimswitch_fee'];;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = ZIMSWITCH;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration =  "WALLET | Switch fee settlement | $reference | $request->account_number |  $request->rrn";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $request->amount /100;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = ZIMSWITCH;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration = "WALLET | Pos purchase | $request->rrn |  $request->account_number |  $reference";
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


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

                    'code' => '100',
                    'description' => 'Transaction was reversed',

                ]);
            }


        }



        $fees_charged = FeesCalculatorService::calculateFees(
            $request->amount /100,
            '0.00',
            PURCHASE_OFF_US,
            HQMERCHANT,$request->account_number
        );

        $balance_res = BRBalanceService::br_balance($request->account_number);
        if($balance_res["code"] != '000'){
            return response([
                'code' => $balance_res["code"],
                'description' => $balance_res["description"],
            ]);
        }

        $response =  $this->switchLimitChecks($request->account_number, $request->amount/100 , $fees_charged['maximum_daily'], $card_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
        if($response["code"] != '000'){
            LoggingService::message('Limits error'.$response["code"].'  '. $response["description"]);
            return response([
                'code' => $response["code"],
                'description' => $response["description"],
            ]);
        }




        $deductable_amount =  $fees_charged['fees_charged'] + $request->amount /100;
        ;
        if($deductable_amount > $balance_res["available_balance"] ){
            PenaltyDeduction::create([
                'amount'                => ZIMSWITCH_PENALTY_FEE,
                'imei'                  => '000',
                'merchant'              => HQMERCHANT,
                'source_account'        => $request->account_number,
                'destination_account'   => ZIMSWITCH,
                'txn_status'            => 'PENDING',
                'description'           => 'Z15 Insufficient funds' | $request->rrn

            ]);

            LoggingService::message('Insufficient funds'.$request->account_number);
            return response([
                'code' => '116',
                'description' => 'Insufficient funds',
            ]);
        }

        if(isset($request->narration)){
            $narration = $request->narration;
        }else{
            $narration = 'Zimswitch Transaction';
        }


        $reference = $request->rrn;
        $br_job = new BRJob();
        $br_job->txn_status = 'PROCESSING';
        $br_job->status = 'DRAFT';
        $br_job->version = 0;
        $br_job->amount_due = $deductable_amount;
        $br_job->amount = $request->amount /100;
        $br_job->tms_batch = $reference;
        $br_job->narration = $narration;
        $br_job->source_account = $request->account_number;
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

    protected function purchase_off_us_validation(Array $data)
    {
        return Validator::make($data, [
            'amount'        => 'required',
            'card_number'   => 'required',

        ]);
    }




}
