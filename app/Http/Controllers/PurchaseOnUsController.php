<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
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
use App\Services\BalanceIssuedService;
use App\Services\BRBalanceService;
use App\Services\CashAquiredService;
use App\Services\DuplicateTxnCheckerService;
use App\Services\EcocashService;
use App\Services\ElectricityService;
use App\Services\FeesCalculatorService;
use App\Services\HotRechargeService;
use App\Services\LoggingService;
use App\Services\MerchantServiceFee;
use App\Services\PurchaseAquiredService;
use App\Services\PurchaseCashService;
use App\Services\PurchaseIssuedService;
use App\Services\PurchaseOnUsService;
use App\Services\TokenService;
use App\Services\UniqueTxnId;
use App\Services\WalletSettlementService;
use App\Services\ZipitReceiveService;
use App\Services\ZipitSendService;
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


         $merchant_id = Devices::where('imei', $request->imei)->first();
         $merchant_account = MerchantAccount::where('merchant_id', $merchant_id->merchant_id)->first();
        $card_number = substr($request->card_number, 0, 16);
        $source_account_number = substr($request->account_number, 0, 3);
        if ($source_account_number == '263') {
            if (WALLET_STATUS != 'ACTIVE') {
                return response([
                    'code' => '100',
                    'description' => 'Wallet service is temporarily unavailable',
                ]);
            }

            $merchant_id = Devices::where('imei', $request->imei)->first();
            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereMobile($request->account_number);
                 $fees_charged = FeesCalculatorService::calculateFees(
                    $request->amount / 100, '0.00', PURCHASE_ON_US,
                    $merchant_id->merchant_id, $request->account_number
                );

                $response = $this->switchLimitChecks($request->account_number, $request->amount / 100, $fees_charged['maximum_daily'], $card_number, $fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
                if ($response["code"] != '000') {
                    return response([
                        'code' => $response["code"],
                        'description' => $response["description"],
                    ]);
                }


                $source_deductions = $fees_charged['fees_charged'] + ($request->amount / 100);
                $fromAccount = $fromQuery->lockForUpdate()->first();
                if ($source_deductions > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id' => PURCHASE_ON_US,
                        'merchant_id' => $merchant_id->merchant_id,
                        'transaction_status' => 0,
                        'pan' => $card_number,
                        'description' => 'Insufficient funds for mobile:' . $request->account_number,

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


                $source_deductions = $fees_charged['tax'] + $fees_charged['acquirer_fee'] + $request->amount / 100;
                $fromAccount->balance -= $source_deductions;
                $fromAccount->save();


                $source_new_balance = $fromAccount->balance;
                $reference = UniqueTxnId::transaction_id();
                $transaction = new WalletTransactions();
                $transaction->txn_type_id = PURCHASE_ON_US;
                $transaction->tax = $fees_charged['tax'];
                $transaction->revenue_fees = $fees_charged['acquirer_fee'];
                $transaction->zimswitch_fee = '0.00';
                $transaction->transaction_amount = $request->amount / 100;
                $transaction->total_debited = $fees_charged['fees_charged'] + $request->amount / 100;
                $transaction->total_credited = '0.00';
                $transaction->switch_reference = $reference;
                $transaction->batch_id = $reference;
                $transaction->merchant_id = $merchant_id->merchant_id;
                $transaction->transaction_status = 1;
                $transaction->account_debited = $request->account_number;
                $transaction->pan = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description = 'Transaction successfully processed.';
                $transaction->save();



                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['acquirer_fee'];
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = REVENUE;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = UniqueTxnId::transaction_id();
                $br_job->narration ='WALLET| Fees settlement on purchase on us | ' . $request->account_number . ' ' . $reference;
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();



                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['tax'];
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = TAX;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch =UniqueTxnId::transaction_id();
                $br_job->narration ='WALLET| Tax settlement on purchase on us | ' . $request->account_number . ' ' . $reference;
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $request->amount / 100;;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account =  $merchant_account->account_number;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = UniqueTxnId::transaction_id();
                $br_job->narration ='WALLET| Merchant settlement on purchase on us | ' . $request->account_number . ' ' . $reference;
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();

                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $fees_charged['mdr'];
                $br_job->source_account =  $merchant_account->account_number;
                $br_job->destination_account =  REVENUE;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = UniqueTxnId::transaction_id();
                $br_job->narration ='WALLET | Merchant service fees | ' . $request->account_number . ' ' . $reference;
                $br_job->rrn =$reference;
                $br_job->txn_type = WALLET_SETTLEMENT;
                $br_job->save();



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
                    'code' => '000',
                    'batch_id' => "$reference",
                    'description' => 'Success'

                ]);


            } catch (\Exception $e) {


                return $e;
                DB::rollBack();
                WalletTransactions::create([

                    'txn_type_id' => PURCHASE_ON_US,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => '0.00',
                    'total_debited' => '0.00',
                    'total_credited' => '0.00',
                    'batch_id' => '',
                    'switch_reference' => '',
                    'merchant_id' => $merchant_id->merchant_id,
                    'transaction_status' => 0,
                    'pan' => $card_number,
                    'description' => 'Transaction was reversed for mobbile:' . $request->account_number,


                ]);


                return response([

                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }



        }


            $reference = UniqueTxnId::transaction_id();
            $fees_charged = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                PURCHASE_ON_US,
                $merchant_id->merchant_id, $request->account_number
            );


            $response = $this->switchLimitChecks($request->account_number, $request->amount / 100, $fees_charged['maximum_daily'], $card_number, $fees_charged['transaction_count'], $fees_charged['max_daily_limit']);
            if ($response["code"] != '000') {
                return response([
                    'code' => $response["code"],
                    'description' => $response["description"],
                ]);
            }

            $balance_res = BRBalanceService::br_balance($request->account_number);
            if ($balance_res["code"] != '000') {
                return response([
                    'code'          => $balance_res["code"],
                    'description'   => $balance_res["description"],
                ]);
            }

            $available_balance = round($balance_res["available_balance"], 2) * 100;
            $total_funds = $fees_charged['fees_charged'] + ($request->amount / 100);
            if ($total_funds > $available_balance) {
                LoggingService::message('Insufficient funds' . $request->account_number);
                return response([
                    'code' => '116',
                    'description' => "Insufficient funds",
                ]);
            }


            $revenue = $fees_charged['mdr'] + $fees_charged['acquirer_fee'];
            $merchant_amount = -$fees_charged['mdr'] + ($request->amount / 100);
            Transactions::create([
                'txn_type_id'       => PURCHASE_ON_US,
                'tax'               => $fees_charged['tax'],
                'revenue_fees'      => $revenue,
                'interchange_fees'  => '0.00',
                'zimswitch_fee'     => '0.00',
                'transaction_amount'=> $request->amount / 100,
                'total_debited'     => $total_funds,
                'total_credited'    => $total_funds,
                'batch_id'          =>  $reference,
                'switch_reference'  => $reference,
                'merchant_id'       => $merchant_id->merchant_id,
                'transaction_status'=> 1,
                'account_debited'   => $request->account_number,
                'pan'               => $request->card_number,
                'merchant_account' => $merchant_amount,
                'description'      => 'Transaction successfully processed.',

            ]);


            $br_job = new BRJob();
            $br_job->txn_status = 'PENDING';
            $br_job->amount = $request->amount / 100;
            $br_job->source_account = $request->account_number;
            $br_job->status = 'DRAFT';
            $br_job->version = 0;
            $br_job->amount_due = $total_funds;
            $br_job->tms_batch = $reference;
            $br_job->narration = $request->imei;
            $br_job->rrn = $reference;
            $br_job->txn_type = PURCHASE_ON_US;
            $br_job->save();

            $br_jobs = new BRJob();
            $br_jobs->txn_status = 'PENDING';
            $br_jobs->amount = $fees_charged['mdr'];
            $br_jobs->source_account = $merchant_account->account_number;
            $br_jobs->status = 'DRAFT';
            $br_jobs->version = 0;
            $br_jobs->tms_batch =  UniqueTxnId::transaction_id();
            $br_jobs->narration = $request->imei;
            $br_jobs->rrn = $reference;
            $br_jobs->txn_type = MDR_DEDUCTION;
            $br_jobs->save();



            return response([
                'code' => '000',
                'batch_id' => (string)$reference,
                'description' => 'Success'

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