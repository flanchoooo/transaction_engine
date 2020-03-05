<?php

namespace App\Http\Controllers;



use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Jobs\NotifyBills;
use App\LuhnCards;
use App\ManageValue;
use App\Merchant;
use App\PenaltyDeduction;
use App\Services\BRBalanceService;
use App\Services\DeductBalanceFeesOnUs;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Services\UniqueTxnId;
use App\Transactions;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;





class BalanceOnUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */
    public function balance(Request $request){
            $validator = $this->balance_enquiry($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }

            $card_number            = substr($request->card_number, 0, 16);
            $source_account_number  = substr($request->account_number, 0, 3);

            //Wallet Code
            if ($source_account_number == '263') {
                $reference = UniqueTxnId::transaction_id();
                if(WALLET_STATUS != 'ACTIVE'){
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
                        '0.00', '0.00', BALANCE_ON_US,
                        $merchant_id->merchant_id,$request->account_number
                    );

                    $fromAccount = $fromQuery->lockForUpdate()->first();
                    if ($fees_charged['minimum_balance'] > $fromAccount->balance) {
                        WalletTransactions::create([
                            'txn_type_id'       => BALANCE_ON_US,
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

                    //Fee Deductions.
                    $amount = $fees_charged['acquirer_fee'];
                    $fromAccount->balance -= $amount;
                    $fromAccount->save();

                    $source_new_balance             = $fromAccount->balance;
                    $transaction                    = new WalletTransactions();
                    $transaction->txn_type_id       = BALANCE_ON_US;
                    $transaction->tax               = '0.00';
                    $transaction->revenue_fees      = $fees_charged['fees_charged'];
                    $transaction->zimswitch_fee     = '0.00';
                    $transaction->transaction_amount= $fees_charged['fees_charged'];
                    $transaction->total_debited     = $fees_charged['fees_charged'];
                    $transaction->total_credited    = '0.00';
                    $transaction->switch_reference  = $reference;
                    $transaction->batch_id          = $reference;
                    $transaction->merchant_id       =  $merchant_id->merchant_id;
                    $transaction->transaction_status= 1;
                    $transaction->account_debited   = $request->account_number;
                    $transaction->account_credited  = WALLET_REVENUE;
                    $transaction->pan               = $card_number;
                    $transaction->balance_after_txn = $source_new_balance;
                    $transaction->description       = 'Transaction successfully processed.';
                    $transaction->save();

                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $amount;
                    $br_job->source_account = TRUST_ACCOUNT;
                    $br_job->destination_account = REVENUE;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration = "WALLET | Balance enquiry  on us | '.$request->account_number";
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();


                    DB::commit();
                    $available_balance = number_format((float)$source_new_balance, 2, '', '');
                    /*$new_balance = money_format('$%i', $source_new_balance);

                    $merchant = Merchant::find($merchant_id->merchant_id)->name;

                   dispatch(new NotifyBills(
                            $fromAccount->mobile,
                            "Balance enquiry via Getbucks m-POS was successful, your balance is ZWL $new_balance. Merchant : $merchant",
                            'eBucks',
                            '',
                            '',
                            '1'
                        )
                    );

                 */

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


            $reference = UniqueTxnId::transaction_id();
            $fees_result = FeesCalculatorService::calculateFees(
            '0.00',
            '0.00',
            BALANCE_ON_US,
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
        $br_job->amount_due = $fees_result['fees_charged'];
        $br_job->version = 0;
        $br_job->tms_batch = $reference;
        $br_job->narration = $request->narration;
        $br_job->rrn =$reference;
        $br_job->txn_type = BALANCE_ON_US;
        $br_job->save();

        Transactions::create([
            'txn_type_id'         => BALANCE_ON_US,
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


        return response([
            'code'              => '000',
            'available_balance' => "$available_balance",
            'ledger_balance'    => "$available_balance",
            'batch_id'          => "$reference",
            'description'       => "SUCCESS",
        ]);

    }

    public static function checkBalance($account_number)
    {

        try
        {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => 'BALANCE', 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $account_number,
                ]
            ]);


            $balance_response = json_decode($result->getBody()->getContents());
            return array(
                'code'              => '00',
                'available_balance' => $balance_response->available_balance,
                'ledger_balance'    => $balance_response->available_balance,
            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Account Number:'.$account_number.' '.$exception);
                return array(
                    'code'          => '01',
                    'description'   => 'BR could not process your request.');

            }
            else {
                Log::debug('Account Number:'.$account_number.' '.$e->getMessage());
                return array(
                    'code'          => '01',
                    'description'   => 'BR could not process your request.');

            }
        }


    }



    protected function balance_enquiry(Array $data){
        return Validator::make($data, [
            'card_number' => 'required',
            'imei'        => 'required',

        ]);
    }




}