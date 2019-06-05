<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\CardCheckerService;
use App\Services\CheckBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\LimitCheckerService;
use App\Services\TokenService;
use App\Services\ApiTokenValidity;
use App\Services\TransactionRecorder;
use App\Zipit;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class ZipitController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */



    public function send(Request $request){


       // return LimitCheckerService::checkBalance('3','6058721611383182=221');

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){

            //API INPUT VALIDATION
            $validator = $this->zipit_send_validation($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }

            //Card Check Services
            $result =  strlen(CardCheckerService::checkCard($request->card_number));

            if ($result > 2){


                $account_number = CardCheckerService::checkCard($request->card_number);
                $balance = CheckBalanceService::checkBalance($account_number);
                $limit_result  = LimitCheckerService::checkLimit($request->product_id,$request->card_number);
                $fees_result = FeesCalculatorService::calculateFees($request->amount,$request->product_id,$account_number,'0000');



                //checking limits
                if($limit_result['code'] != '00'){
                    return response(
                        array(
                            'code' => $limit_result['code'],
                        ));
                }

                $deductable_amount = $fees_result['fees_charged'] + $request->amount;

                //Checking balance
                if($deductable_amount > $balance['available']){

                    //In sufficient funds
                    TransactionRecorder::recordTxn(
                        'ZIPIT SEND',
                        $request->amount,
                        'API',
                        $request->card_number,
                        "Insufficient Funds",
                        'FAILED',
                        'CARD',
                        '0.00',
                        '',
                        $account_number,
                        '0.00',
                        '0.00'

                    );
                    return response(
                        array(
                            'code' => '51',
                        ));

                }

                //Zipu
               if(!isset($request->imei)){

                    $zimswitch = Accounts::find(1);
                    $revenue = Accounts::find(2);
                    $tax =  Accounts::find(3);

                   $account_debit = array('SerialNo'         => '472100',
                        'OurBranchID'      => substr($account_number, 0, 3),
                        'AccountID'        => $account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription'   => 'ZIPIT SEND',
                        'TrxAmount'        => -$request->amount);

                    $account_debit_fees = array('SerialNo'         => '472100',
                        'OurBranchID'      => substr($account_number, 0, 3),
                        'AccountID'        => $account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription'   => "ZIPIT Transfer Fees Debit",
                        'TrxAmount'        => '-' . $fees_result['fees_charged']);

                    $destination_credit_zimswitch = array('SerialNo'         => '472100',
                        'OurBranchID'      => '001',
                        'AccountID'        => $zimswitch->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                        'TrxAmount'        => $request->amount);

                     $bank_revenue_credit = array('SerialNo'         => '472100',
                        'OurBranchID'      => '001',
                       'AccountID'        => $revenue->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription'   => "ZIPIT Revenue Account Credit",
                        'TrxAmount'        => $fees_result['fee']);

                    $tax_credit = array('SerialNo'         => '472100',
                        'OurBranchID'      => '001',
                        'AccountID'        => $tax->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription'   => "ZIPIT Tax Account Credit",
                        'TrxAmount'        => $fees_result['tax']);




                    $auth = TokenService::getToken();
                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array($account_debit, $account_debit_fees,
                                    $destination_credit_zimswitch, $bank_revenue_credit, $tax_credit),
                            ]
                        ]);

                        $response = json_decode($result->getBody()->getContents());

                    }catch (ClientException $exception){

                        return response(['code' => '91']);
                    }

                    if($response->code == '00'){

                        //Credit Suspense
                        TransactionRecorder::recordTxn(
                            'ZIPIT SEND',
                            $request->amount,
                            'API',
                            $request->card_number,
                            'ZIMSWITCH SUPSENSE ACCOUNT CREDIT',
                            'COMPLETED',
                            'CARD',
                            '0.00',
                            $response->transaction_batch_id,
                            $zimswitch->account_number,
                            $request->amount,
                            '0.00'
                        );


                        //Cardholder Account Debit Fees
                        TransactionRecorder::recordTxn(
                          'ZIPIT SEND',
                          $request->amount,
                          'API',
                          $request->card_number,
                          'DEBIT VIA ZIPIT SEND',
                          'COMPLETED',
                          'CARD',
                          '0.00',
                          $response->transaction_batch_id,
                          $account_number,
                          '0.00',
                            $request->amount
                          );

                        //Fees Debit
                        TransactionRecorder::recordTxn(
                            'ZIPIT SEND',
                            $request->amount,
                            'API',
                            $request->card_number,
                            'ZIPIT FEES DEBIT',
                            'COMPLETED',
                            'CARD',
                            $fees_result['fees_charged'],
                            $response->transaction_batch_id,
                            $account_number,
                            '0.00',
                            $fees_result['fees_charged']
                        );


                        //Credit TAX account
                        TransactionRecorder::recordTxn(
                            'ZIPIT SEND',
                            $request->amount,
                            'API',
                            $request->card_number,
                            'ZIPIT TAX CHARGED',
                            'COMPLETED',
                            'CARD',
                            '0.00',
                            $response->transaction_batch_id,
                            $tax->account_number,
                            $fees_result['tax'],
                            '0.00'
                        );


                        //Credit Revenue account
                        TransactionRecorder::recordTxn(
                            'ZIPIT SEND',
                            $request->amount,
                            'API',
                            $request->card_number,
                            ' ZIPIT CREDIT REVENUE ACCOUNT',
                            'COMPLETED',
                            'CARD',
                            '0.00',
                            $response->transaction_batch_id,
                            $revenue->account_number,
                            $fees_result['fee'],
                            '0.00'
                        );


                        Zipit::create([

                        'source_bank' =>'GETBUCKS',
                        'destination_bank' =>$request->destination_bank,
                        'source' =>$account_number,
                        'destination' =>$request->destination_account,
                        'amount' => $request->amount,
                        'type' =>'ZIPIT SEND',

                        ]);

                        return response([

                            'code' =>'00',
                    ]);


                    }else{

                        //if the transaction Fails
                        TransactionRecorder::recordTxn(
                            'ZIPIT SEND',
                            $request->amount,
                            'API',
                            $request->card_number,
                            "Code: $response->code, Description: $response->description",
                            'FAILED',
                            'CARD',
                            '0.00',
                            '',
                            $account_number,
                            '0.00',
                            '0.00'

                        );


                    }

                }


            }else{


                $error_code = CardCheckerService::checkCard($request->card_number);

                return response(['code' => $error_code ]);
            }


        }else{

            return response()->json(['error' => 'Unauthorized'], 401);
        }


    }



    public function receive(Request $request){


        // return LimitCheckerService::checkBalance('3','6058721611383182=221');

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){





            //API INPUT VALIDATION
            $validator = $this->zipit_rec_validation($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }


            //$result = json_decode(CheckBalanceService::checkBalance($request->account_number));
         $result = CheckBalanceService::checkBalance($request->destination_account);


            if($result['code'] == '01'){

                return response([

                    'code' => '42',

                ]);

            }



            if ($result['code'] == '00'){


                $account_number = $request->destination_account;


                //Zipit
                if(!isset($request->imei)){

                    $zimswitch = Accounts::find(1);


                    $destination_account_credit = array('SerialNo'         => '472100',
                        'OurBranchID'      => substr($account_number, 0, 3),
                        'AccountID'        => $account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription'   => 'ZIPIT RECEIVE',
                        'TrxAmount'        => $request->amount);




                    $zimswitch_debit = array('SerialNo'         => '472100',
                        'OurBranchID'      => '001',
                        'AccountID'        => $zimswitch->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                        'TrxAmount'        => -$request->amount);







                    $auth = TokenService::getToken();
                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array($destination_account_credit,$zimswitch_debit),
                            ]
                        ]);

                        $response = json_decode($result->getBody()->getContents());

                    }catch (ClientException $exception){

                        return response(['code' => '91']);
                    }

                    if($response->code == '00'){

                        //Credit Suspense
                        TransactionRecorder::recordTxn(
                            'ZIPIT RECEIVE',
                            $request->amount,
                            'API',
                            $account_number,
                            'ZIPIT RECEIVE CREDIT DESTIONATON ACCOUNT',
                            'COMPLETED',
                            'CARD',
                           '0.00',
                            $response->transaction_batch_id,
                            $account_number,
                            $request->amount,
                            '0.00'

                        );


                        //Credit Suspense
                        TransactionRecorder::recordTxn(
                            'ZIPIT RECEIVE',
                            $request->amount,
                            'API',
                            $account_number,
                            'DEBIT ZIMSWITCH CREDIT ACCOUNT',
                            'COMPLETED',
                            'CARD',
                            '0.00',
                            $response->transaction_batch_id,
                            $account_number,
                            '0.00',
                            $request->amount

                        );






                        Zipit::create([

                            'source_bank' =>$request->sender_bank,
                            'destination_bank' =>'GETBUCKS',
                            'source' =>$request->sender_account,
                            'destination' => $account_number,
                            'amount' => $request->amount,
                            'type' =>'ZIPIT RECEIVE',

                        ]);

                        return response([

                            'code' =>'00',
                        ]);


                    }else{

                        //if the transaction Fails
                        TransactionRecorder::recordTxn(
                            'ZIPIT RECEIVE',
                            $request->amount,
                            'API',
                            $request->card_number,
                            "Code: $response->code, Description: $response->description",
                            'FAILED',
                            'CARD',
                            '0.00',
                            '',
                            $account_number,
                            '0.00',
                            '0.00'

                        );


                    }

                }


            }else{


                $error_code = CardCheckerService::checkCard($request->card_number);

                return response(['code' => $error_code ]);
            }


        }else{

            return response()->json(['error' => 'Unauthorized'], 401);
        }


    }

    protected function zipit_send_validation(Array $data)
    {
        return Validator::make($data, [
            'card_number' => 'required',
            'token' => 'required',
            'amount' => 'required',
            'product_id' => 'required',
            'destination_bank' => 'required',
            'destination_account' => 'required',
        ]);
    }


    protected function zipit_rec_validation(Array $data)
    {
        return Validator::make($data, [
            'destination_account' => 'required',
            'token' => 'required',
            'amount' => 'required',
            'sender_bank' => 'required',
            'sender_account' => 'required',

        ]);
    }









}