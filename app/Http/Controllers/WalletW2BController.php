<?php

namespace App\Http\Controllers;



use App\Accounts;
use App\Services\WalletFeesCalculatorService;
use App\Transactions;
use App\WalletTransaction;
use App\Jobs\SaveTransaction;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;




class WalletW2BController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function wallet_to_bank(Request $request){

       //API Validation
        $validator = $this->wallet_to_bank_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        //Declarations
        $source =  Wallet::where('mobile',$request->source_mobile)->first();
        $destination_gl_wallet =  Wallet::where('mobile','263700000006')->first();
        $revenue =  Wallet::where('mobile','263700000001')->first();
        $tax =  Wallet::where('mobile','263700000000')->first();

        //Reference
        $mobi = substr_replace($request->source_mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = '18' . $time_stamp . $mobi;




        if(!isset($source)){
            return response([

                'code' => '01',
                'description' => 'Source mobile account is invalid',

            ]);
        }

        $amount = $request->amount/100 + 0;



            //Check Status of the account
            if ($source->state != '1') {
                return response([
                    'code' => '02',
                    'description' => 'Source mobile wallet is blocked',

                ]);
            }


            if ($amount > $source->balance) {
                return response([
                    'code' => '116',
                    'description' => 'Insufficient funds',
                ]);

            }


            //Calculate Fees
            $fees_charged = WalletFeesCalculatorService::calculateFees(
                $amount,
                WALLET_TO_BANK

            );

            $total_deductions = $amount + $fees_charged['fee'] + $fees_charged['tax'];

            try {

                DB::beginTransaction();

                //Deduct funds from source account
                $source->lockForUpdate()->first();
                $source_new_balance = $source->balance - $total_deductions;
                $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                $source->save();

                $destination_gl_wallet->lockForUpdate()->first();
                $gl_new_balance = $destination_gl_wallet->balance + $amount;
                $destination_gl_wallet->balance = number_format((float)$gl_new_balance, 4, '.', '');
                $destination_gl_wallet->save();


                $revenue->lockForUpdate()->first();
                $revenue_new_balance = $revenue->balance + $fees_charged['fee'];
                $revenue->balance = number_format((float)$revenue_new_balance, 4, '.', '');
                $revenue->save();

                $tax->lockForUpdate()->first();
                $tax_new_balance = $tax->balance + $fees_charged['tax'];
                $tax->balance = number_format((float)$tax_new_balance, 4, '.', '');
                $tax->save();

                DB::commit();


            } catch (\Exception $e) {

                DB::rollback();

                WalletTransactions::create([

                    'txn_type_id'           => WALLET_TO_BANK,
                    'tax'                   => '0.00',
                    'revenue_fees'          => '0.00',
                    'interchange_fees'      => '0.00',
                    'zimswitch_fee'         => '0.00',
                    'transaction_amount'    => '0.00',
                    'total_debited'         => '0.00',
                    'total_credited'        => '0.00',
                    'batch_id'              => '',
                    'switch_reference'      => $reference,
                    'merchant_id'           => '',
                    'transaction_status'    => 0,
                    'account_debited'       => $request->source_mobile,
                    'account_credited'      => '',
                    'pan'                   => '',
                    'description'           => 'Transaction was reversed',
                    'merchant_account'      => '0.00',

                ]);

                return response([

                    'code' => '01',
                    'description' => 'Transaction was reversed',

                ]);

            }

            //BR Computations

            $trust_account = Accounts::find(6);

            $debit_trust_account = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $trust_account->account_number,
                'TrxDescriptionID' => '007',
                'TrxDescription' => 'Bank to wallet debit trust account',
                'TrxAmount' => '-' . $amount);

            $credit_client = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $request->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription' => "Bank  to wallet credit client wallet",
                'TrxAmount' => $amount);


            $auth = TokenService::getToken();
            $client = new Client();

            try {
                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(

                            $debit_trust_account,
                            $credit_client,

                        ),
                    ]
                ]);


                // return $response_ = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());

                if ($response->code != '00') {


                    WalletTransactions::create([

                        'txn_type_id' => WALLET_TO_BANK,
                        'tax' => '0.00',
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => '0.00',
                        'total_debited' => '0.00',
                        'total_credited' => '0.00',
                        'batch_id' => $reference,
                        'switch_reference' => $reference,
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $source->mobile,
                        'pan' => '',
                        'description' => 'Failed to process transaction please contact bank.',


                    ]);


                    return response([

                        'code' => '92',
                        'description' => 'Failed to process transaction please contact bank.',

                    ]);


                }


                Transactions::create([

                    'txn_type_id' => WALLET_TO_BANK,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $amount,
                    'total_debited' => $amount,
                    'total_credited' => $amount,
                    'batch_id' => $response->transaction_batch_id,
                    'switch_reference' => $reference,
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => $trust_account->account_number,
                    'account_credited' => $request->account_number,
                    'pan' => '',
                    'description' => 'Transaction successfully processed.',


                ]);


                WalletTransactions::create([

                    'txn_type_id' => WALLET_TO_BANK,
                    'tax' => $fees_charged['tax'],
                    'revenue_fees' => $fees_charged['fee'],
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $amount,
                    'total_debited' => $total_deductions,
                    'total_credited' => '0.00',
                    'batch_id' => $response->transaction_batch_id,
                    'switch_reference' => $reference,
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => $request->source_mobile,
                    'account_credited' => '',
                    'pan' => '',
                    'description' => 'Transaction successfully processed.',
                    'balance_after_txn' => $source_new_balance,


                ]);


                WalletTransactions::create([

                    'txn_type_id' => WALLET_TO_BANK,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $amount,
                    'total_debited' => '0.00',
                    'total_credited' => $amount,
                    'batch_id' => $response->transaction_batch_id,
                    'switch_reference' => $reference,
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => '',
                    'account_credited' => $destination_gl_wallet->mobile,
                    'pan' => '',
                    'description' => 'Transaction successfully processed.',
                    'balance_after_txn' => $gl_new_balance,


                ]);


                return response([

                    'code' => '00',
                    'description' => 'Success',

                ]);


            } catch (ClientException $exception) {

                WalletTransactions::create([

                    'txn_type_id' => WALLET_TO_BANK,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $amount,
                    'total_debited' => '0.00',
                    'total_credited' => '0.00',
                    'batch_id' => '',
                    'switch_reference' => $reference,
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => $request->source_mobile,
                    'account_credited' => $destination_gl_wallet->mobile,
                    'pan' => '',
                    'description' => 'BR Error,Failed to process transaction',
                    'merchant_account' => $amount,

                ]);


                return array('code' => '91', 'description' => 'Failed to process transaction please contact bank.');

            }



        }








    protected function wallet_to_bank_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'account_number' => 'required',
            'amount' => 'required',


        ]);


    }


}

