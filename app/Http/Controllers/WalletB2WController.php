<?php

namespace App\Http\Controllers;



use App\Accounts;
use App\Transactions;
use App\WalletTransaction;
use App\Jobs\SaveTransaction;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use Composer\DependencyResolver\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;




class WalletB2WController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bank_to_wallet(Request $request){

       //API Validation
        $validator = $this->bank_to_wallet_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }



        //Wallet Checks
        //Declarations
        $number_exists =  Wallet::where('mobile',$request->destination_mobile)->first();
        $source =  Wallet::where('mobile','263700000005')->first();




        if(!isset($number_exists)){

            return response([

                'code' => '01',
                'description' => 'Failed',
                'message'=>'Destination mobile account is invalid'
            ]);
        }


        //Check Status of the account
        if($number_exists->state == '0') {

            return response([

                'code' => '02',
                'description' => 'Destination mobile wallet is blocked',

            ]);


        }

        $amount = $request->amount/100 + 0;

        if($amount > $source->balance ){

            return response([

                'code' => '05',
                'description' => 'Unable to process your transaction please contact bank',

            ]);

        }



        try {
            $user = new Client();
            $res = $user->post(env('BASE_URL') . '/api/authenticate', [
                'json' => [
                    'username' => env('TOKEN_USERNAME'),
                    'password' => env('TOKEN_PASSWORD'),
                ]
            ]);
            $tok = $res->getBody()->getContents();
            $bearer = json_decode($tok, true);
            $authentication = 'Bearer ' . $bearer['id_token'];

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $request->account_number,
                ]
            ]);

          // return  $balance_response = $result->getBody()->getContents();
            $balance_response = json_decode($result->getBody()->getContents());

            //Fees Calculator
            $fees_charged =  FeesCalculatorService::calculateFees(

                $request->amount/100,
                '0.00',
                BANK_TO_WALLET,
                '28'
            );

            if($request->amount /100 > $fees_charged['maximum_daily']){

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
                    'account_debited'     => $request->account_number,
                    'pan'                 => $request->card_number,
                    'description'         => 'Invalid amount, error 902',


                ]);

                return response([
                    'code' => '902',
                    'description' => 'Invalid mount',

                ]);
            }


            $total_deductions = $fees_charged['fees_charged'] +  $request->amount/100;
            if($total_deductions > $balance_response->available_balance){

                return response([

                    'code' => '116',
                    'description' => 'Insufficient funds',

                ]) ;


            }



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
                'TrxAmount' => $request->amount/100 );





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

                if($response->code != '00'){


                    return response([

                        'code' => '92',
                        'description' => 'Failed to process transaction please contact bank.',

                    ]) ;


                }






                //Check source_account

                //Wallet Transaction
                try {

                    DB::beginTransaction();


                    $am =  $request->amount /100;

                    //Deduct funds from source account
                    $source->lockForUpdate()->first();
                    $source_new_balance = $source->balance - $am;
                    $source->balance = number_format((float)$source_new_balance, 4, '.', '');
                    $source->save();

                    $number_exists->lockForUpdate()->first();
                    $number_exists_balance = $number_exists->balance + $am;
                    $number_exists->balance = number_format((float)$number_exists_balance, 4, '.', '');
                    $number_exists->save();

                    DB::commit();



                } catch (\Exception $e){

                    DB::rollback();

                    return response([

                        'code' => '01',
                        'description' => 'Transaction was reversed',

                    ]) ;

                }




                $mobi = substr_replace($request->destination_mobile, '', -10, 3);
                $time_stamp = Carbon::now()->format('ymdhis');
                $reference = '18' . $time_stamp . $mobi;


                Transactions::create([

                    'txn_type_id'         => BANK_TO_WALLET,
                    'tax'                 => $fees_charged['tax'],
                    'revenue_fees'        => $fees_charged['acquirer_fee'],
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => $am,
                    'total_debited'       => $total_deductions,
                    'total_credited'      => $total_deductions,
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 1,
                    'account_debited'     => $request->account_number,
                    'pan'                 => '',
                    'merchant_account'    => '',


                ]);

                WalletTransactions::create([

                    'txn_type_id'         => BANK_TO_WALLET,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => $am,
                    'total_debited'       => $am,
                    'total_credited'      => $am,
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 1,
                    'account_debited'     => $request->account_number,
                    'account_credited'    => $request->destination_mobile,
                    'pan'                 => '',
                    'merchant_account'    => '',


                ]);



                return response([

                    "code" => '000',
                    "description" => 'success',
                    'batch_id' =>$reference

                ]);




            } catch (ClientException $exception) {


                WalletTransactions::create([

                    'txn_type_id'         => BANK_TO_WALLET,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $request->account_number,
                    'account_credited'    => $request->destination_mobile,
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'         => 'Failed to process transaction please contact bank.',


                ]);



                return response([

                    "code" => '91',
                    "description" => 'Failed to process transaction please contact bank.',


                ]);




            }



        } catch (ClientException $exception) {


            WalletTransactions::create([

                'txn_type_id'         => BANK_TO_WALLET,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => $response->transaction_batch_id,
                'switch_reference'    => $reference,
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => $request->account_number,
                'account_credited'    => $request->destination_mobile,
                'pan'                 => '',
                'merchant_account'    => '',
                'description'         => 'Invalid Account',


            ]);



            return response([

                "code" => '91',
                "description" => 'Invalid Account',


            ]);




        }


























        }






    protected function bank_to_wallet_validator(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile' => 'required',
            'account_number' => 'required',
            'amount' => 'required',


        ]);


    }


}

