<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\BalanceEnquiryOnUsJob;
use App\License;
use App\LuhnCards;
use App\Services\CheckBalanceService;
use App\Services\DeductBalanceFeesOnUs;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
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

            /*
             * Declarations
             */
            $card_number = substr($request->card_number, 0, 16);
            $card_details = LuhnCards::where('track_2', $request->card_number)->get()->first();

            /*
             * Check employees if the parameter is set.
             */


            //Wallet Code
            if (isset($card_details->wallet_id)) {

                $merchant_id = Devices::where('imei', $request->imei)->first();
                DB::beginTransaction();
                try {

                    $fromQuery = Wallet::whereId($card_details->wallet_id);
                    $toQuery = Wallet::whereMobile(WALLET_REVENUE);


                    $fees_charged = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ON_US,
                        $merchant_id->merchant_id
                    );

                    $fromAccount = $fromQuery->lockForUpdate()->first();
                    if ($fees_charged['minimum_balance'] > $fromAccount->balance) {
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
                            'description'       => 'Insufficient funds for mobile:' . $request->account_number,


                        ]);

                        return response([
                            'code' => '116',
                            'description' => 'Insufficient funds',
                        ]);
                    }

                    //Fee Deductions.
                    $amount = $fees_charged['acquirer_fee'];
                    $toAccount = $toQuery->lockForUpdate()->first();
                    $toAccount->balance += $amount;
                    $toAccount->save();
                    $fromAccount->balance -= $amount;
                    $fromAccount->save();


                    $source_new_balance             = $fromAccount->balance;
                    $time_stamp                     = Carbon::now()->format('ymdhis');
                    $reference                      = '18' . $time_stamp;
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




            /*
             * Peform Balance enquiry & return valid responses.
             */

            if (isset($request->imei)) {

                    $merchant_id    = Devices::where('imei', $request->imei)->first();
                    $balance_result = CheckBalanceService::checkBalance($request->account_number);

                    if (isset($balance_result)) {
                        $fees_result = FeesCalculatorService::calculateFees(
                            '0.00',
                            '0.00',
                            BALANCE_ON_US,
                            $merchant_id->merchant_id
                        );


                        if ($balance_result['available_balance'] < $fees_result['minimum_balance']) {
                            Transactions::create([
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
                                'account_debited'   => $request->account_number,
                                'pan'               => $card_number,
                                'description'       => 'Insufficient funds',

                            ]);

                            return response([
                                'code' => '116',
                                'description' => 'Insufficient Funds',

                            ]);

                        }

                    }


                    $batch =  DeductBalanceFeesOnUs::deduct($request->account_number, $fees_result['fees_charged'], $merchant_id->merchant_id, $card_number);

                    $batch_id = $batch['batch'];

                    $available_balance_  =   $balance_result['available_balance'] - $fees_result['fees_charged'];
                    $ledger_balance_     =  $balance_result['ledger_balance'] - $fees_result['fees_charged'];

                    $available_balance  = round($available_balance_ , 2, PHP_ROUND_HALF_EVEN) * 100;
                    $ledger_balance     = round($ledger_balance_ , 2, PHP_ROUND_HALF_EVEN) * 100;

                    return response([

                        'code'                  => '000',
                        'currency'              => CURRENCY,
                        'available_balance'     => "$available_balance",
                        'ledger_balance'        => "$ledger_balance",
                        'batch_id'              => "$batch_id",

                    ]);


            }


    }


    protected function balance_enquiry(Array $data){
        return Validator::make($data, [
            'card_number' => 'required',
            'imei'        => 'required',

        ]);
    }




}