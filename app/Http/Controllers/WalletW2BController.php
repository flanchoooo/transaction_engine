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


        //Check Status of the account
        if($source->state == '0') {

            return response([

                'code' => '02',
                'description' => 'Source mobile wallet is blocked',

            ]);


        }

        $amount = $request->amount/100 + 0;


       /*
        if($amount > $destination_gl_wallet->balance ){

            return response([

                'code' => '05',
                'description' => 'Unable to process your transaction please contact bank',

            ]);

        }

       */





        if($amount >   $source->balance){

            return response([

                'code' => '116',
                'description' => 'Insufficient funds',

            ]);

        }




            //Calculate Fees
        $fees_charged = WalletFeesCalculatorService::calculateFees(
            $amount ,
            WALLET_TO_BANK

        );



         $total_deductions = $amount +  $fees_charged['fee'] +  $fees_charged['tax'];


        try {

            Log::info( ":::::Request:::::   amount: $amount, mobile: $source->mobile, account_number: $request->account_number, reference: $reference");


            DB::beginTransaction();

            //Deduct funds from source account
            $source->lockForUpdate()->first();
            $source_new_balance = $source->balance - $total_deductions;
            $source->balance = number_format((float)$source_new_balance, 4, '.', '');
            $source->save();

            $destination_gl_wallet->lockForUpdate()->first();
            $gl_new_balance = - $amount + $destination_gl_wallet->balance + $amount;
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

            Log::info( ":::::Response:::::  Message:Transaction was reversed, reference: $reference");
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


            //$response_ = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());

            if ($response->code != '00') {


                WalletTransactions::create([

                    'txn_type_id'         => WALLET_TO_BANK,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      =>'0.00',
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $source->mobile,
                    'pan'                 => '',
                    'description'         => 'Failed to process transaction please contact bank.',



                ]);


                return response([

                    'code' => '92',
                    'description' => 'Failed to process transaction please contact bank.',

                ]);


            }



            Transactions::create([

                'txn_type_id'         => WALLET_TO_BANK,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => $amount,
                'total_debited'       => $amount,
                'total_credited'      => $amount,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $trust_account->account_number,
                'account_credited'    => $request->account_number,
                'pan'                 => '',
                'description'         => 'Failed to process transaction please contact bank.',



            ]);


            WalletTransactions::create([

                'txn_type_id'         => WALLET_TO_BANK,
                'tax'                 => $fees_charged['tax'],
                'revenue_fees'        => $fees_charged['fee'],
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => $amount,
                'total_debited'       => $total_deductions,
                'total_credited'      => $total_deductions,
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $request->source_mobile,
                'account_credited'    => $destination_gl_wallet->mobile,
                'pan'                 => '',
                'description'         => 'Failed to process transaction please contact bank.',
                'merchant_account'    => $amount,



            ]);


            return response([

                'code' => '00',
                'description' => 'Success',

            ]);






        }catch (ClientException $exception){

            Log::info( ":::::Response:::::  Message:Transaction was reversed, reference: $reference");
            return array('code' => '91', 'description' => 'Failed to process transaction please contact bank.');

        }


        //Record Transactions













        /*

        try {


            // deduct Funds from BR
            $tax = Accounts::find(3);
            $revenue = Accounts::find(2);
            $bank_to_wallet_gl_acc = Accounts::find(7);

            $debit_client = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $request->account_number,
                'TrxDescriptionID' => '007',
                'TrxDescription' => 'Bank to wallet debit client',
                'TrxAmount' => '-' . $total_deductions);

            $credit_revenue = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $revenue->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription' => "Bank to wallet credit revenue",
                'TrxAmount' => $fees_charged['acquirer_fee']);

            $credit_tax = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $tax->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription' => "Bank to wallet credit tax",
                'TrxAmount' => $fees_charged['tax']);

            $credit_destionation_gl = array('SerialNo' => '472100',
                'OurBranchID' => substr($request->account_number, 0, 3),
                'AccountID' => $bank_to_wallet_gl_acc->account_number,
                'TrxDescriptionID' => '008',
                'TrxDescription' => "Bank to wallet credit destination gl",
                'TrxAmount' => $request->amount / 100);


            $auth = TokenService::getToken();
            $client = new Client();

            try {
                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(

                            $debit_client,
                            $credit_revenue,
                            $credit_tax,
                            $credit_destionation_gl,

                        ),
                    ]
                ]);


                // $response_ = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());

                if ($response->code != '00') {

                    return array('code' => '92', 'description' => 'Failed to process transaction please contact bank.');

                }


                //Check source_account

                //Wallet Transaction
                try {

                    DB::beginTransaction();

                    //Deduct funds from source account
                    $source->lockForUpdate()->first();
                    $source_new_balance = $source->balance - $request->amount / 100;
                    $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                    $source->save();

                    $number_exists->lockForUpdate()->first();
                    $number_exists_balance = $source->balance + $request->amount / 100;
                    $number_exists->balance = number_format((float)$number_exists_balance, 4, '.', '');
                    $number_exists->save();

                    DB::commit();


                } catch (\Exception $e) {

                    DB::rollback();

                    return response([

                        'code' => '01',
                        'description' => 'Transaction was reversed',

                    ]);

                }


                $mobi = substr_replace($request->destination_mobile, '', -10, 3);
                $time_stamp = Carbon::now()->format('ymdhis');
                $reference = '18' . $time_stamp . $mobi;


                // Record Txns
                dispatch(new SaveTransaction(

                        Bank_to_wallet_debit_source_account,
                        'COMPLETED',
                        $request->account_number,
                        '',
                        '0.00',
                        $total_deductions,
                        '0.00',
                        $response->transaction_batch_id,
                        'MOBILE-WALLET'

                    )
                );


                dispatch(new SaveTransaction(

                        Bank_to_wallet_credit_GL_with_transfer_amount,
                        'COMPLETED',
                        $bank_to_wallet_gl_acc->account_number,
                        '',
                        $request->amount / 100,
                        '0.00',
                        '0.00',
                        $response->transaction_batch_id,
                        'MOBILE-WALLET'

                    )
                );


                dispatch(new SaveTransaction(

                        Bank_to_wallet_credit_revenue,
                        'COMPLETED',
                        $revenue->account_number,
                        '',
                        $fees_charged['acquirer_fee'],
                        '0.00',
                        '0.00',
                        $response->transaction_batch_id,
                        'MOBILE-WALLET'

                    )
                );

                dispatch(new SaveTransaction(

                        Bank_to_wallet_credit_tax,
                        'COMPLETED',
                        $revenue->account_number,
                        '',
                        $fees_charged['tax'],
                        '0.00',
                        '0.00',
                        $response->transaction_batch_id,
                        'MOBILE-WALLET'

                    )
                );


                WalletTransaction::create([

                    'transaction_type' => Bank_to_wallet_credit_wallet,
                    'status' => 'COMPLETED',
                    'account' => $number_exists->mobile,
                    'pan' => '',
                    'credit' => $request->amount / 100,
                    'debit' => '0.00',
                    'description' => 'Bank to wallet credit wallet',
                    'fee' => '0.00',
                    'batch_id' => $reference,
                    'merchant' => 'MOBILE-WALLET',

                ]);

                WalletTransaction::create([

                    'transaction_type' => Bank_to_wallet_debit_source_wallet,
                    'status' => 'COMPLETED',
                    'account' => $source->mobile,
                    'pan' => '',
                    'credit' => $request->amount / 100,
                    'debit' => '0.00',
                    'description' => 'Bank to wallet debit source wallet',
                    'fee' => '0.00',
                    'batch_id' => $reference,
                    'merchant' => 'MOBILE-WALLET',

                ]);


                return response([

                    "code" => '00',
                    "description" => 'success',
                    'batch_id' => $reference

                ]);


            } catch (ClientException $exception) {


                return array('code' => '91', 'description' => 'Failed to process transaction please contact bank.');


            }


        }

        */


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

