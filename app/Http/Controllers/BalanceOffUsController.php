<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\BRAccountID;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\BalanceJob;
use App\Jobs\NotifyBills;
use App\Jobs\PurchaseJob;
use App\License;
use App\LuhnCards;
use App\ManageValue;
use App\MDR;
use App\Merchant;
use App\PenaltyDeduction;
use App\PendingTxn;
use App\Services\AccountInformationService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
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





class BalanceOffUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */




    public function balance_off_us(Request $request){
        $validator = $this->balance_enquiry_off_us($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $card_number = substr($request->card_number, 0, 16);
        $card_details = LuhnCards::where('track_1', $card_number)->get()->first();
        $currency = CURRENCY;

        if (isset($card_details->wallet_id)) {
            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereId($card_details->wallet_id);
                $reference                      = $this->genRandomNumber();
                $fees_charged = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_OFF_US,
                    HQMERCHANT

                );

                $fromAccount = $fromQuery->lockForUpdate()->first();
                if($fromAccount->balance == 0){
                    $available_balance = number_format((float)$fromAccount->balance, 2, '', '');
                    return response([
                        'code'              => '000',
                        'currency'          => CURRENCY,
                        'available_balance' => $available_balance,
                        'ledger_balance'    => $available_balance,
                        'batch_id'          => "$reference",

                    ]);

                }

                if($fromAccount->state == '0') {
                    return response([
                        'code' => '114',
                        'description' => 'Source account is closed',

                    ]);
                }


                if ($fees_charged['minimum_balance'] > $fromAccount->balance) {
                    WalletTransactions::create([

                        'txn_type_id'       => BALANCE_ENQUIRY_OFF_US,
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

                //Fee Deductions.
                $amount = $fees_charged['zimswitch_fee'];
                $fromAccount->balance -= $amount;
                $fromAccount->save();


                $source_new_balance             = $fromAccount->balance;
                $transaction                    = new WalletTransactions();
                $transaction->txn_type_id       = BALANCE_ENQUIRY_OFF_US;
                $transaction->tax               = '0.00';
                $transaction->revenue_fees      = $fees_charged['fees_charged'];
                $transaction->zimswitch_fee     = '0.00';
                $transaction->transaction_amount= $fees_charged['fees_charged'];
                $transaction->total_debited     = $fees_charged['fees_charged'];
                $transaction->total_credited    = '0.00';
                $transaction->switch_reference  = $reference;
                $transaction->batch_id          = $reference;
                $transaction->merchant_id       = HQMERCHANT;
                $transaction->transaction_status= 1;
                $transaction->account_debited   = $fromAccount->mobile;
                $transaction->account_credited  = ZIMSWITCH_WALLET_MOBILE;
                $transaction->pan               = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();

                $value_management                   = new ManageValue();
                $value_management->account_number   = WALLET_REVENUE;
                $value_management->amount           = $amount;
                $value_management->txn_type         = DESTROY_E_VALUE;
                $value_management->state            = 1;
                $value_management->initiated_by     = 3;
                $value_management->validated_by     = 3;
                $value_management->narration        = 'Destroy E-Value';
                $value_management->description      = 'Destroy E-Value on balance fee'. $request->account_number. 'reference:'.$reference ;
                $value_management->save();

                //BR Settlement
                $auto_deduction = new Deduct();
                $auto_deduction->imei = '000';
                $auto_deduction->amount = $amount;
                $auto_deduction->merchant = HQMERCHANT;
                $auto_deduction->source_account = TRUST_ACCOUNT;
                $auto_deduction->destination_account = ZIMSWITCH;
                $auto_deduction->txn_status = 'PENDING';
                $auto_deduction->description = 'Balance enquiry via wallet RRN:'. $request->rrn;
                $auto_deduction->save();



                DB::commit();



                $available_balance = number_format((float)$source_new_balance, 2, '', '');

                return response([
                    'code'              => '000',
                    'currency'          => CURRENCY,
                    'available_balance' => $available_balance,
                    'ledger_balance'    => $available_balance,
                    'batch_id'          => "$reference",

                ]);


            } catch (\Exception $e) {

                //return  $e;
                DB::rollBack();

                Log::debug('Account Number:'. $request->card_number.' '. $e);

                WalletTransactions::create([

                    'txn_type_id'       => BALANCE_ENQUIRY_OFF_US,
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

        try{


        $account =  BRAccountID::where('AccountID', $request->account_number)->first();

            if ($account == null){
            return response([
                'code' => '114',
                'description' => 'Invalid Account',
            ]);
        }

        if($account->IsBlocked == 1){
            return response([
                'code' => '114',
                'description' => 'Account is closed',
            ]);
        }

        if($account->ClearBalance == 0){
            $available_balance =  round($account->ClearBalance, 2) * 100;
            return response([
                'code'              => '000',
                'currency'          => CURRENCY,
                'available_balance' => $available_balance,
                'ledger_balance'    => $available_balance,
            ]);

        }
        }catch (QueryException $queryException){
            return response([
                'code' => '100',
                'description' => 'Failed to access BR',
            ]);

        }
        $reference = $this->genRandomNumber(6,false);
         $fees_result = FeesCalculatorService::calculateFees(
            '0.00',
            '0.00',
            BALANCE_ENQUIRY_OFF_US,
            HQMERCHANT, $request->account_number

        );

        $balance = round($account->ClearBalance, 2) * 100;
        $available = $account->ClearBalance - $fees_result['minimum_balance'];
        if ($available < $fees_result['minimum_balance']) {

            PenaltyDeduction::create([
                'amount'                => $fees_result['fees_charged'],
                'imei'                  => '000',
                'merchant'              => HQMERCHANT,
                'source_account'        => $request->account_number,
                'destination_account'   => ZIMSWITCH,
                'txn_status'            => 'PENDING',
                'description'           => 'Insufficient funds'

            ]);

            return response([
                'code'              => '000',
                'available_balance' => "$balance",
                'ledger_balance'    => "$balance",
                'batch_id'          => "$reference",
                'description'       => "SUCCESS",
            ]);

        }



        $br_job = new BRJob();
        $br_job->txn_status = 'PENDING';
        $br_job->amount = $fees_result['fees_charged'];
        $br_job->source_account = $request->account_number;
        $br_job->status = 'DRAFT';
        $br_job->version = 0;
        $br_job->tms_batch = $reference;
        $br_job->rrn = $request->rrn;
        $br_job->txn_type = BALANCE_ENQUIRY_OFF_US;
        $br_job->save();

        Transactions::create([
            'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
            'tax'                 => '0.00',
            'revenue_fees'        => $fees_result['fees_charged'],
            'interchange_fees'    => '0.00',
            'zimswitch_fee'       => $fees_result['zimswitch_fee'],
            'transaction_amount'  => '0.00',
            'total_debited'       => $fees_result['fees_charged'],
            'total_credited'      => $fees_result['fees_charged'],
            'batch_id'            => $reference,
            'merchant_id'         => '',
            'transaction_status'  => 1,
            'account_debited'     => $request->account_number,
            'pan'                 => $request->card_number,
            'description'         => 'Transaction successfully processed.'
        ]);


        if(isset($request->narration)){
            $narration = $request->narration;
        }else{
            $narration = 'Zimswitch Transaction';
        }

        LoggingService::message('Job dispatched Balance enquiry: '.$request->account_number);
        dispatch(new BalanceJob($request->account_number,$fees_result['fees_charged'],$reference,$request->rrn,$narration));


        return response([
            'code'              => '000',
            'available_balance' => "$balance",
            'ledger_balance'    => "$balance",
            'batch_id'          => "$reference",
            'description'       => "SUCCESS",
        ]);


    }

    protected function balance_enquiry_off_us(Array $data){
        return Validator::make($data, [
            'card_number'    => 'required',

        ]);
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


}