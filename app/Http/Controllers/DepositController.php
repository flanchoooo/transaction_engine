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


class DepositController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */



    public function info(Request $request){


       // return LimitCheckerService::checkBalance('3','6058721611383182=221');

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){

            //API INPUT VALIDATION
            $validator = $this->customer_info($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }


            //Check Device Existence
            $device_existence = Devices::where('imei', $request->imei)->count();
            if ($device_existence === 0){

                return response(['code' => '58', 'Description' => 'Devices does not exists']);

            }
            //Check Device State
            $status = Devices::all()->where('imei', $request->imei);
            foreach ($status as $state) {
                if ($state->state == '0') {

                    return response(['code' => '58', 'Description' => 'Devices is not active']);
                }
            }



            $auth = TokenService::getToken();
            $client = new Client();

            try {
                $result = $client->post(env('BASE_URL') . '/api/customers', [

                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                    'json' => [
                        'account_number' => $request->destination_account,
                    ]
                ]);

              //return $response = $result->getBody()->getContents();
              $response = json_decode($result->getBody()->getContents());

               return response([

                   'code' => $response->code,
                   'name' => $response->ds_account_customer_info->account_name,
                   'mobile' => $response->ds_account_customer_info->mobile,
                   'email' => $response->ds_account_customer_info->email_id,
                   'account_id' => $response->ds_account_customer_info->account_id,

               ]);





            }catch (ClientException $exception){

                return response(['code' => '91']);
            }





        }else{

            return response()->json(['error' => 'Unauthorized'], 401);
        }


    }


    public function deposit(Request $request){


        // return LimitCheckerService::checkBalance('3','6058721611383182=221');

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){





            //API INPUT VALIDATION
            $validator = $this->deposit_validation($request->all());
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
                if(isset($request->imei)){


                    //Check Device Existence
                    $device_existence = Devices::where('imei', $request->imei)->count();
                    if ($device_existence === 0){

                        return response(['code' => '58', 'Description' => 'Devices does not exists']);

                    }
                    //Check Device State
                    $status = Devices::all()->where('imei', $request->imei);
                    foreach ($status as $state) {
                        if ($state->state == '0') {

                            return response(['code' => '58', 'Description' => 'Devices is not active']);
                        }
                    }


                    $deposit_account = Accounts::find(4);


                    $destination_account_credit = array('SerialNo'         => '472100',
                        'OurBranchID'      => substr($account_number, 0, 3),
                        'AccountID'        => $account_number,
                        'TrxDescriptionID' => '007',
                        'TrxDescription'   => 'ZIPIT RECEIVE',
                        'TrxAmount'        => $request->amount);




                    $deposit_account_debit = array('SerialNo'         => '472100',
                        'OurBranchID'      => '001',
                        'AccountID'        => $deposit_account->account_number,
                        'TrxDescriptionID' => '008',
                        'TrxDescription'   => 'ZIPIT CREDIT SUSPENSE ACCOUNT',
                        'TrxAmount'        => -$request->amount);

                    $auth = TokenService::getToken();
                    $client = new Client();

                    try {
                        $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                            'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                            'json' => [
                                'bulk_trx_postings' => array($destination_account_credit, $deposit_account_debit),
                            ]
                        ]);

                        $response = json_decode($result->getBody()->getContents());


                    }catch (ClientException $exception){

                        return response(['code' => '91']);
                    }

                    if($response->code == '00'){

                        //Credit Suspense
                        TransactionRecorder::recordTxn(
                            'CASH DEPOSIT',
                            $request->amount,
                            'API',
                            $account_number,
                            'CASH DEPOSIT',
                            'COMPLETED',
                            'CARD',
                            '0.00',
                            $response->transaction_batch_id,
                            $account_number,
                            $request->amount,
                            '0.00'

                        );


                        return response([

                            'code' =>'00',
                        ]);


                    }else{

                        TransactionRecorder::recordTxn(
                            'CASH DEPOSIT',
                            '',
                            'API',
                            $account_number,
                            "Error $response->description",
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




    protected function customer_info(Array $data)
    {
        return Validator::make($data, [
            'destination_account' => 'required',
            'imei' => 'required',

        ]);
    }

    protected function deposit_validation(Array $data)
    {
        return Validator::make($data, [
            'token' => 'required',
            'destination_account' => 'required',
            'amount' => 'required',

        ]);
    }









}