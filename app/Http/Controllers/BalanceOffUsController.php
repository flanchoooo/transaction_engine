<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\License;
use App\LuhnCards;
use App\Services\FeesCalculatorService;
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


        $card_details = LuhnCards::where('track_2', $request->card_number)->get()->first();
        $card_number = substr($request->card_number, 0, 16);
        $branch_id = substr($request->account_number, 0, 3);
        $currency = CURRENCY;



        if (isset($card_details->wallet_id)) {


            DB::beginTransaction();
            try {

                $fromQuery = Wallet::whereId($card_details->wallet_id);
                $toQuery = Wallet::whereMobile(ZIMSWITCH_WALLET_MOBILE);



                  $fees_charged = FeesCalculatorService::calculateFees(
                    '0.00',
                    '0.00',
                    BALANCE_ENQUIRY_OFF_US,
                    HQMERCHANT

                );

                $fromAccount = $fromQuery->lockForUpdate()->first();
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
                $toAccount = $toQuery->lockForUpdate()->first();
                $toAccount->balance += $amount;
                $toAccount->save();
                $fromAccount->balance -= $amount;
                $fromAccount->save();


                $source_new_balance             = $fromAccount->balance;
                $time_stamp                     = Carbon::now()->format('ymdhis');
                $reference                      = '18' . $time_stamp;
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



        try {


            $authentication = TokenService::getToken();
            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json'    => [
                    'account_number' => $request->account_number,
                ],
            ]);



            $balance_response = json_decode($result->getBody()->getContents());

            $fees_result = FeesCalculatorService::calculateFees(
                '0.00',
                '0.00',
                BALANCE_ENQUIRY_OFF_US,
                HQMERCHANT

            );

            // BALANCE ENQUIRY LOGIC
            if ($balance_response->available_balance <= $fees_result['minimum_balance']) {

                Transactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                    'account_debited'     => $request->account_number,
                    'pan'                 => $request->card_number,
                    'description'         => 'Insufficient Funds',


                ]);


            }


                $zimswitch_account = ZIMSWITCH;
                $account_debit = array('SerialNo'         => '472100',
                                       'OurBranchID'      => $branch_id,
                                       'AccountID'        => $request->account_number,
                                       'TrxDescriptionID' => '007',
                                       'TrxDescription'   => 'Balance Fees Debit',
                                       'TrxAmount'        => '-' . $fees_result['fees_charged']);

                $credit_zimswitch = array('SerialNo'         => '472100',
                                          'OurBranchID'      => $branch_id,
                                          'AccountID'        => $zimswitch_account,
                                          'TrxDescriptionID' => '008',
                                          'TrxDescription'   => "Zimswitch Revenue Account Credit",
                                          'TrxAmount'        => $fees_result['zimswitch_fee']);



                $client = new Client();

                try {
                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json'    => [
                            'bulk_trx_postings' => array(
                                $account_debit,
                                $credit_zimswitch,
                            ),
                        ],
                    ]);

                    //$response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());


                   if($response->code != '00'){

                       Transactions::create([
                           'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                           'tax'                 => '0.00',
                           'revenue_fees'        => $fees_result['fees_charged'],
                           'interchange_fees'    => '0.00',
                           'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                           'transaction_amount'  => '0.00',
                           'total_debited'       => $fees_result['fees_charged'],
                           'total_credited'      => $fees_result['fees_charged'],
                           'batch_id'            => $response->transaction_batch_id,
                           'switch_reference'    => $response->transaction_batch_id,
                           'merchant_id'         => '',
                           'transaction_status'  => 0,
                           'account_debited'     => $request->account_number,
                           'pan'                 => $request->card_number,
                           'description'         => 'Failed to process transaction.',

                       ]);


                       return response([
                           'code'        => '100',
                           'description' => 'Failed to process transaction.',
                       ]);

                   }

                        Transactions::create([
                            'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
                            'tax'                 => '0.00',
                            'revenue_fees'        => $fees_result['fees_charged'],
                            'interchange_fees'    => '0.00',
                            'zimswitch_fee'       => $fees_result['zimswitch_fee'],
                            'transaction_amount'  => '0.00',
                            'total_debited'       => $fees_result['fees_charged'],
                            'total_credited'      => $fees_result['fees_charged'],
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $request->card_number,

                        ]);


                        $available_balance = round($balance_response->available_balance,2,PHP_ROUND_HALF_EVEN) * 100;
                        $ledger_balance = round($balance_response->ledger_balance,2,PHP_ROUND_HALF_EVEN) * 100;


                        return response([

                            'code'              => '000',
                            'fees_charged'      => $fees_result['fees_charged'] * 100,
                            'currency'          => $currency,
                            'available_balance' => "$available_balance",
                            'ledger_balance'    => "$ledger_balance",
                            'batch_id'          => "$response->transaction_batch_id",
                            'description'       => "SUCCESS",

                        ]);




                } catch (ClientException $exception) {

                    Transactions::create([
                        'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                        'account_debited'     => $request->account_number,
                        'pan'                 => $request->card_number,
                        'description'          => $exception,
                    ]);


                    return response([
                        'code'        => '100',
                        'description' => $exception,
                    ]);


                }


        } catch (RequestException $e) {

            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();


                Transactions::create([
                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                    'account_debited'     => $request->account_number,
                    'pan'                 => $request->card_number,
                    'description'          => 'BR api validation error.',


                ]);

                Log::debug('Account Number:'. $request->account_number.' '. $exception);

                return response([
                    'code'        => '100',
                    'description' => 'Invalid BR account number',
                ]);

            }

            else {


                Transactions::create([

                    'txn_type_id'         => BALANCE_ENQUIRY_OFF_US,
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
                    'account_debited'     => $request->account_number,
                    'pan'                 => $request->card_number,
                    'description'          => $e->getMessage(),


                ]);

                Log::debug('Account Number:'. $request->account_number.' '. $e->getMessage());
                return response([
                    'code'        => '100',
                    'description' => $e->getMessage(),
                ]);

            }
        }



    }
    protected function balance_enquiry_off_us(Array $data){
        return Validator::make($data, [
            'card_number'    => 'required',

        ]);
    }


}