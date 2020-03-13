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
use App\Services\BRBalanceService;
use App\Services\CheckBalanceService;
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

        $reference = $request->rrn;
        $card_number = substr($request->card_number, 0, 16);
        $source_account_number  = substr($request->account_number, 0, 3);
        if ($source_account_number == '263') {
            if(WALLET_STATUS != 'ACTIVE'){
                return response([
                    'code' => '100',
                    'description' => 'Wallet service is temporarily unavailable',
                ]);
            }

            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereMobile($request->account_number);
                $fees_charged = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_OFF_US,
                    HQMERCHANT,$request->account_number

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
                $transaction->pan               = $card_number;
                $transaction->balance_after_txn = $source_new_balance;
                $transaction->description       = 'Transaction successfully processed.';
                $transaction->save();


                $br_job = new BRJob();
                $br_job->txn_status = 'PENDING';
                $br_job->amount = $amount;
                $br_job->amount_due = $amount;
                $br_job->source_account = TRUST_ACCOUNT;
                $br_job->destination_account = ZIMSWITCH;
                $br_job->status = 'DRAFT';
                $br_job->version = 0;
                $br_job->tms_batch = $reference;
                $br_job->narration = $request->narration;
                $br_job->rrn =$reference;
                $br_job->txn_type = BALANCE_ENQUIRY_OFF_US;
                $br_job->save();


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
                DB::rollBack();
                WalletTransactions::create([
                    'txn_type_id'       => BALANCE_ENQUIRY_OFF_US,
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


        $fees_result = FeesCalculatorService::calculateFees(
            '0.00',
            '0.00',
            BALANCE_ENQUIRY_OFF_US,
            HQMERCHANT, $request->account_number

        );


        $balance_res = BRBalanceService::br_balance($request->account_number);
        if($balance_res["code"] != '000'){
            return response([
                'code' => $balance_res["code"],
                'description' => $balance_res["description"],
            ]);
        }

        $available_balance =  round($balance_res["available_balance"], 2) * 100;
        if($available_balance == 0){
            return response([
                'code'              => '000',
                'currency'          => CURRENCY,
                'available_balance' => $available_balance,
                'ledger_balance'    => $available_balance,
            ]);

        }

        if($fees_result['fees_charged'] > $available_balance ){
            PenaltyDeduction::create([
                'amount'                => ZIMSWITCH_PENALTY_FEE,
                'imei'                  => '000',
                'merchant'              => HQMERCHANT,
                'source_account'        => $request->account_number,
                'destination_account'   => ZIMSWITCH,
                'txn_status'            => 'PENDING',
                'description'           => 'Insufficient funds'.$request->rrn

            ]);

            LoggingService::message('Insufficient funds'.$request->account_number);
            return response([
                'code'              => '000',
                'available_balance' => "$available_balance",
                'ledger_balance'    => "$available_balance",
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
        $br_job->narration = $request->narration;
        $br_job->rrn =$reference;
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

        return response([
            'code'              => '000',
            'available_balance' => "$available_balance",
            'ledger_balance'    => "$available_balance",
            'batch_id'          => "$reference",
            'description'       => "SUCCESS",
        ]);


    }

    protected function balance_enquiry_off_us(Array $data){
        return Validator::make($data, [
            'card_number'    => 'required',

        ]);
    }




}
