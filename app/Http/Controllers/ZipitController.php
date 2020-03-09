<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\BRAccountID;
use App\BRClientInfo;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Jobs\PurchaseJob;
use App\Jobs\ZipitReceive;
use App\ManageValue;
use App\PenaltyDeduction;
use App\PendingTxn;
use App\Services\AccountInformationService;
use App\Services\BalanceEnquiryService;
use App\Services\BRBalanceService;
use App\Services\EcocashService;
use App\Services\FeesCalculatorService;
use App\Services\HotRechargeService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Services\UniqueTxnId;
use App\Services\ZipitSendService;
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
use Illuminate\Database\QueryException;
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
        $validator = $this->zipit_send_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $account_checker = substr($request->br_account,0, 3);
        if ($account_checker == '263') {
            if(WALLET_STATUS != 'ACTIVE'){
                return response([
                    'code' => '100',
                    'description' => 'Wallet service is temporarily unavailable',
                ]);
            }

            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereMobile($request->br_account);
                $reference = UniqueTxnId::transaction_id();

                $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount/100,
                    '0.00',
                    ZIPIT_SEND,
                    HQMERCHANT,$request->br_account
                );


                $response =   $this->switchLimitChecks($request->br_account, $request->amount/100 , $fees_charged['maximum_daily'], $request->account_number,$fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
                if($response["code"] != '000'){
                    return response([
                        'code' => $response["code"],
                        'description' => $response["description"],
                    ]);
                }

                $fromAccount = $fromQuery->lockForUpdate()->first();
                if($fromAccount->state != 1){
                    return response([
                        'code' => '114',
                        'description' => 'Account closed',
                    ]);
                }

                if ($fees_charged['minimum_balance'] > $fromAccount->balance) {
                    WalletTransactions::create([
                        'txn_type_id'       => BALANCE_ON_US,
                        'merchant_id'       => HQMERCHANT,
                        'transaction_status'=> 0,
                        'description'       => 'Insufficient funds for mobile:' . $request->account_number,


                    ]);

                    return response([
                        'code' => '116',
                        'description' => 'Insufficient funds',
                    ]);
                }


                $transaction_amount = $request->amount/100;
                $total_deduction = $transaction_amount + $fees_charged['fees_charged'];
                $fromAccount->balance -=$total_deduction ;
                $fromAccount->save();


                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['tax'];;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = TAX;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch =$reference;
                $br_job->narration ="WALLET | Zipit send tax | $reference | RRN:$request->rrn" ;
                $br_job->rrn =$request->rrn;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['zimswitch_fee'];
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = REVENUE;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch =$reference;
                $br_job->narration ="WALLET | Zipit send revenue | $reference | RRN:$request->rrn" ;
                $br_job->rrn =$request->rrn;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $request->amount /100 ;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = ZIMSWITCH;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch =$reference;
                $br_job->narration = "WALLET | Transaction amount , Zipit Send | $reference  RRN:$request->rrn"  ;
                $br_job->rrn =$request->rrn;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


                $source_new_balance             = $fromAccount->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = ZIPIT_SEND;
                $transaction->tax               = $fees_charged['tax'];
                $transaction->revenue_fees      = $fees_charged['zimswitch_fee'];
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $request->amount/100;
                $transaction->total_debited     = $transaction_amount + $fees_charged['fees_charged'];
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $request->transaction_id;
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
                    'code'                              => '000',
                    'batch_id'                          => "$reference",
                    'transaction_batch_id'              => "$reference",
                    'description'                       => 'Success'

                ]);


            } catch (\Exception $e) {
                DB::rollBack();
                WalletTransactions::create([
                    'merchant_id'       => HQMERCHANT,
                    'transaction_status'=> 0,
                    'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,
                ]);


                return response([
                    'code' => '100',
                    'description' => 'Transaction failed',
                ]);
            }


            // return 'Success';
        }


            $reference = UniqueTxnId::transaction_id();
            $fees_result = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                ZIPIT_SEND,
                HQMERCHANT, $request->br_account

            );


             $response = $this->switchLimitChecks($request->br_account, $request->amount / 100, $fees_result['maximum_daily'], $request->account_number, $fees_result['transaction_count'], $fees_result['max_daily_limit']);
            if ($response["code"] != '000') {
                return response([
                    'code' => $response["code"],
                    'description' => $response["description"],
                ]);
            }

        $balance_res = BRBalanceService::br_balance($request->br_account);
        if ($balance_res["code"] != '000') {
            return response([
                'code'          => $balance_res["code"],
                'description'   => $balance_res["description"],
            ]);
        }

        $available_balance = $balance_res["available_balance"];
        $total_funds = $fees_result['fees_charged'] + ($request->amount / 100);
        if ($total_funds > $available_balance) {
            return response([
                'code' => '116',
                'description' => "Insufficient funds",
            ]);
        }


            $total_debit = $request->amount / 100 + $fees_result['fees_charged'];
            Transactions::create([

                'txn_type_id'           => ZIPIT_SEND,
                'tax'                   => $fees_result['tax'],
                'revenue_fees'          => $fees_result['acquirer_fee'],
                'interchange_fees'      => '0.00',
                'zimswitch_fee'         => $fees_result['zimswitch_fee'],
                'transaction_amount'    => $request->amount / 100,
                'total_debited'         => $total_debit,
                'total_credited'        => $total_debit,
                'batch_id'              => $reference,
                'switch_reference'      => $request->transaction_id,
                'merchant_id'           => '',
                'transaction_status'    => 1,
                'account_debited'       => $request->br_account,
                'account_credited'      => ZIMSWITCH,
                'pan'                   => '',
                'merchant_account'      => '',
                'description'           => 'Transaction successfully processed.',

            ]);

            $br_job = new BRJob();
            $br_job->txn_status = 'PENDING';
            $br_job->amount = $request->amount /100;
            $br_job->amount_due =$total_funds;
            $br_job->source_account = $request->br_account;
            $br_job->status = 'DRAFT';
            $br_job->version = 0;
            $br_job->tms_batch =$reference;
            $br_job->narration ="Zipit send | RRN:$request->rrn" ;
            $br_job->rrn =$request->rrn;
            $br_job->txn_type = ZIPIT_SEND;
            $br_job->save();

            Zipit::create([
                'source_bank'       => 'GETBUCKS',
                'destination_bank'  => $request->destination_bank,
                'source'            => $request->br_account,
                'destination'       => $request->destination_account,
                'amount'            => $request->amount / 100,
                'type'              => 'ZIPIT SEND',
            ]);


            return response([
                'code' => '000',
                'transaction_batch_id' => (string)$reference,
                'batch_id' => (string)$reference,
                'description' => 'Success'
            ]);



    }

    public function switchLimitChecks($account_number,$amount,$maximum_daily,$card_number,$transaction_count,$max_daily_limit){

        $account = substr($account_number, 0,3);
        if($account == '263'){
            $total_count  = WalletTransactions::where('account_debited',$account_number)
                ->whereIn('txn_type_id',[ZIPIT_SEND])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $daily_spent =  Transactions::where('account_debited', $account_number)
                ->where('txn_type_id',ZIPIT_SEND)
                ->where('description','Transaction successfully processed.')
                ->where('reversed', '=', null)
                ->whereDate('created_at', Carbon::today())
                ->sum('transaction_amount');


            if($amount > $maximum_daily){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => $card_number,
                    'description'         => 'Exceeds maximum zipit limit',

                ]);

                return array(
                    'code' => '110',
                    'description' => 'Invalid amount',

                );

            }


            if($total_count  >= $transaction_count ){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Exceeds zipit frequency limit.',
                ]);

                return array(
                    'code' => '123',
                    'description' => 'Exceeds wallet zipit frequency limit.',

                );

            }

            if($daily_spent  >= $max_daily_limit ){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',
                ]);

                return array(
                    'code' => '121',
                    'description' => 'Exceeds wallet zipit frequency limit.',

                );
            }



            return array(
                'code' => '000',
                'description' => 'Success',

            );

        }


        $total_count  = Transactions::where('account_debited',$account_number)
            ->whereIn('txn_type_id',[ZIPIT_SEND])
            ->where('description','Transaction successfully processed.')
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

         $daily_spent =  Transactions::where('account_debited', $account_number)
            ->where('txn_type_id',ZIPIT_SEND)
            ->where('reversed', '!=', 1)
            ->whereDate('created_at', Carbon::today())
            ->sum('transaction_amount');


        if($amount > $maximum_daily){
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
                'account_debited'     => $account_number,
                'pan'                 => $card_number,
                'description'         => 'Exceeds maximum zipit limit',

            ]);

            return array(
                'code' => '110',
                'description' => 'Invalid amount',

            );

        }


        if($total_count  >= $transaction_count ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Exceeds zipit frequency limit.',
            ]);

            return array(
                'code' => '123',
                'description' => 'Exceeds zipit frequency limit.',

            );

        }


        if($daily_spent  >= $max_daily_limit ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Transaction limit reached for the day.',
            ]);

            return array(
                'code' => '121',
                'description' => 'Exceeds Zipit amount limit ',

            );
        }


        return array(
            'code' => '000',
            'description' => 'Success',

        );
    }

    public function receive(Request $request){

        $account_checker = substr($request->br_account,0, 3);
        $reference                      = $request->rrn;
        $rrn_result = BRJob::where('rrn', $request->rrn)->get()->count();
        if($rrn_result > 0) {
            return response([

                'code' => '100',
                'description' => 'Do not honor'

            ]);
        }

        if ($account_checker == '263') {
            if(WALLET_STATUS != 'ACTIVE'){
                return response([
                    'code' => '100',
                    'description' => 'Wallet service is temporarily unavailable',
                ]);
            }

            DB::beginTransaction();
            try {

                $toQuery = Wallet::whereMobile($request->br_account);
                $zipit_amount = $request->amount/100;
                //Fee Deductions.
                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance += $zipit_amount;
                $toAccount->save();

                $source_new_balance_             = $toAccount->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = ZIPIT_RECEIVE;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = '0.00';
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $zipit_amount;
                $transaction->total_debited     = '0.00';
                $transaction->total_credited    = $zipit_amount;
                $transaction->switch_reference  = $request->transaction_id;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = '';
                $transaction->transaction_status= 1;
                $transaction->account_debited   = ZIMSWITCH_WALLET_MOBILE;
                $transaction->account_credited  = $request->br_account;
                $transaction->pan               = '';
                $transaction->balance_after_txn = $source_new_balance_;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();


                //BR Settlement

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $zipit_amount;
                $br_job->source_account = ZIMSWITCH;
                $br_job->destination_account = TRUST_ACCOUNT;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch =$reference;
                $br_job->narration ="WALLET | Credit wallet via wallet zipit receive | $reference | RRN:$request->rrn";
                $br_job->updated_at = '2000-01-01 00:00:00';
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();


                DB::commit();

                return response([
                    'code'              => '000',
                    'currency'          => CURRENCY,
                    'mobile'            => $toAccount->mobile,
                    'national_id_number' => $toAccount->national_id,
                    'email'             => null,
                    'name'              => $toAccount->first_name .' '. $toAccount->last_name,
                    'batch_id'          => "$reference",
                    'descripition'      => "Success"

                ]);



            } catch (\Exception $e) {
                DB::rollBack();
                WalletTransactions::create([
                    'txn_type_id'       => ZIPIT_RECEIVE,
                    'transaction_status'=> 0,
                    'description'       => 'Transaction was reversed for mobbile:' . $request->br_account,
                ]);


                return response([

                    'code' => '100',
                    'description' => 'Transaction failed',

                ]);
            }

        }

        try {
            $account = BRAccountID::where('AccountID', $request->br_account)->first();
            if ($account == null) {
                LoggingService::message('Zipit:Invalid Account:'.$request->br_account);
                return response([
                    'code' => '114',
                    'description' => 'Invalid Account',
                ]);
            }

            if ($account->IsBlocked == 1) {
                LoggingService::message('Zipit:Account is closed:'.$request->br_account);
                return response([
                    'code' => '114',
                    'description' => 'Account is closed',
                ]);
            }

            $account =  BRAccountID::where('AccountID', $request->br_account)->first();
            if(!isset($account)){
                $mobile = '';
                $name = '';
                $mail = '';
            }else{
                $mobile = $account->Mobile;
                $name =   $account->Name;
                $mail = $account->EmailID;

            }

            $responses = BRClientInfo::where('ClientID',$account->ClientID)->first();
            if(!isset($responses)){
                $id = '';
            }else{
                $id = $responses->PassportNo;

            }
        }catch (QueryException $queryException){
            LoggingService::message('Zipit receive Failed to access CBS on validations:'.$request->br_account);
            return response([
                'code' => '100',
                'description' => 'Failed to access CBS',
            ]);
        }


        $br_job = new BRJob();
        $br_job->txn_status = 'PENDING';
        $br_job->amount = $request->amount /100;
        $br_job->source_account =$request->br_account;
        $br_job->status = 'DRAFT';
        $br_job->version = 0;
        $br_job->tms_batch = $reference;
        $br_job->updated_at = '2014-01-01 00:00:01';
        $br_job->rrn = $request->rrn;
        $br_job->txn_type = ZIPIT_RECEIVE;
        $br_job->save();





        Transactions::create([
            'txn_type_id'         => ZIPIT_RECEIVE,
            'tax'                 => '0.00',
            'revenue_fees'        => '0.00',
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => '0.00',
            'transaction_amount'  => $request->amount/100,
            'total_debited'       => $request->amount/100,
            'total_credited'      => $request->amount/100,
            'batch_id'            => $reference,
            'switch_reference'    => $request->transaction_id,
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

        if(isset($request->narration)){
            $narration = $request->narration;
        }else{
            $narration = 'Zimswitch Transaction';
        }

        return response([
            'code'              => '000',
            'mobile'            => $mobile,
            'name'              => $name,
            'national_id_number'=> $id,
            'email'             => $mail,
            'batch_id'          => (string)$reference,
            'description'       => 'Success'
        ]);

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
