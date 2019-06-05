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


class WithdrawalController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */


    public function withdrawal(Request $request){


        // return LimitCheckerService::checkBalance('3','6058721611383182=221');

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){

            //API INPUT VALIDATION
            $validator = $this->withdrawal_validation($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }

            //$result = json_decode(CheckBalanceService::checkBalance($request->account_number));
            $result = CheckBalanceService::checkBalance($request->account_number);


            if($result['code'] != '00'){

                return response([

                    'code' => '42',

                ]);

            }



            if ($result['code'] == '00'){

                $account_number  = $request->account_number;
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
                        'WITHDRAWAL',
                        $request->amount,
                        'API',
                        $request->card_number,
                        'INSUFFICIENT FUNDS',
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





                //POS CHANNEL
                if(isset($request->imei)){


                    //Devices Checker
                    $device_existence = Devices::where('imei', $request->imei)->count();
                    if ($device_existence === 0){

                        return response(['code' => '58', 'Description' => 'Devices does not exists']);

                    }

                    $status = Devices::all()->where('imei', $request->imei);

                    foreach ($status as $state){
                        //Check Device State
                        if($state->state == '0'){

                            return response(['code' => '58', 'Description' => 'Devices is not active']);

                        }


                        //Check Account Status
                        $account =  MerchantAccount::where('merchant_id',  $state->merchant_id)->count();

                        if ($account == '0'){

                            return response(['code' => '58', 'Description' => 'Merchant Account number not configured']);
                        }



                            $withdrawals =  Accounts::find(5);
                            $revenue =Accounts::find(2);
                            $tax = Accounts::find(3);

                            $account_debit = array('SerialNo'         => '472100',
                                'OurBranchID'      => substr($account_number, 0, 3),
                                'AccountID'        => $account_number,
                                'TrxDescriptionID' => '007',
                                'TrxDescription'   => 'CARD-SWIPE',
                                'TrxAmount'        => -$request->amount);

                            $account_debit_fees = array('SerialNo'         => '472100',
                                'OurBranchID'      => substr($account_number, 0, 3),
                                'AccountID'        => $account_number,
                                'TrxDescriptionID' => '007',
                                'TrxDescription'   => "Transfer Fees Debit",
                                'TrxAmount'        => '-' . $fees_result['fees_charged']);

                            $destination_credit_zimswitch = array('SerialNo'         => '472100',
                                'OurBranchID'      => '001',
                                'AccountID'        => $withdrawals->account_number,
                                'TrxDescriptionID' => '008',
                                'TrxDescription'   => 'POS PURCHASE',
                                'TrxAmount'        => $request->amount);

                            $bank_revenue_credit = array('SerialNo'         => '472100',
                                'OurBranchID'      => '001',
                                'AccountID'        => $revenue->account_number,
                                'TrxDescriptionID' => '008',
                                'TrxDescription'   => "Revenue Account Credit",
                                'TrxAmount'        => $fees_result['fee']);




                            $auth = TokenService::getToken();
                            $client = new Client();


                            try {
                                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                                    'json' => [
                                        'bulk_trx_postings' => array($account_debit, $account_debit_fees,
                                            $destination_credit_zimswitch, $bank_revenue_credit,),
                                    ]
                                ]);

                                $response = json_decode($result->getBody()->getContents());

                            }catch (ClientException $exception){

                                return response(['code' => '91']);
                            }

                            if($response->code == '00'){

                                //Credit Suspense
                                TransactionRecorder::recordTxn(
                                    'WITHDRAWAL',
                                    $request->amount,
                                    $state->merchant_id,
                                    $account_number,
                                    'CREDIT WITHDRAWAL ACCOUNT',
                                    'COMPLETED',
                                    'POS',
                                    '0.00',
                                    $response->transaction_batch_id,
                                    $withdrawals->account_number,
                                    $request->amount,
                                    '0.00'
                                );

                                //Debit Source Account
                                TransactionRecorder::recordTxn(
                                    'WITHDRAWAL',
                                    $request->amount,
                                    $state->merchant_id,
                                    $account_number,
                                    'DEBIT ACCOUNT VIA POS WITHDRAWAL',
                                    'COMPLETED',
                                    'POS',
                                    '0.00',
                                    $response->transaction_batch_id,
                                    $withdrawals->account_number,
                                    '0.00',
                                    $request->amount
                                );

                                //CREDIT REVENUE ACCOUNT
                                TransactionRecorder::recordTxn(
                                    'WITHDRAWAL',
                                   '0.00',
                                    $state->merchant_id,
                                    $account_number,
                                    'CREDIT REVENUE ACCOUNT',
                                    'COMPLETED',
                                    'POS',
                                    '0.00',
                                    $response->transaction_batch_id,
                                    $revenue->account_number,
                                    $fees_result['fee'],
                                    '0.00'
                                );



                                return response([

                                    'code' =>'00',
                                ]);


                            }else{

                                TransactionRecorder::recordTxn(
                                    'WITHDRAWAL',
                                    $request->amount,
                                    'API',
                                    $request->card_number,
                                    "Error: $response->description ",
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










                }





            }else{


                $error_code = CardCheckerService::checkCard($request->card_number);

                return response(['code' => $error_code ]);
            }


        }else{

            return response()->json(['error' => 'Unauthorized'], 401);
        }


    }

    protected function withdrawal_validation(Array $data)
    {
        return Validator::make($data, [
            'token' => 'required',
            'account_number' => 'required',
            'amount' => 'required',
            'product_id' => 'required',
            'imei' => 'required',
        ]);
    }







}