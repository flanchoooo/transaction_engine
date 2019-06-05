<?php

namespace App\Http\Controllers;




use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;




class WalletLinkBrController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function link_wallet(Request $request)
    {

        $validator = $this->br_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


        $source_mobile = Wallet::where('mobile', $request->source_mobile)->first();
        $account_number = $request->br_account;
        $mobi = substr_replace($source_mobile->mobile, '', -10, 3);
        $time_stamp = Carbon::now()->format('ymdhis');
        $reference = '8' . $time_stamp . $mobi;


        if (!isset($source_mobile)) {

            return response([

                'code' => '01',
                'description' => 'Invalid Mobile Number',

            ]);
        }

        if(isset($source_mobile->account_number)){

            return response([

                'code' => '02',
                'description' => 'Account number is already linked to a BR account',

            ]);

        }




        //Check BR
        try {
            //TOKEN GENERATION
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
            $result = $client->post(env('BASE_URL') . '/api/customers', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'account_number' => $account_number,
                ]
            ]);

           //$response = $result->getBody()->getContents();

            $response = json_decode($result->getBody()->getContents());
           $br_mobile = $response->ds_account_customer_info->mobile;


            if($source_mobile->state == '0') {

                return response([

                    'code' => '02',
                    'description' => 'Mobile account is blocked',

                ]);

            }

           //Check mobiles
           if($br_mobile != $source_mobile->mobile){

               return response([

                   'code' => '01',
                   'description' => 'Account verification failed.',

               ]);

           }

           //update records
            $source_mobile->lockForUpdate()->first();
            $source_mobile->account_number = $account_number;
            $source_mobile->save();




            WalletTransactions::create([

                    'txn_type_id'         => LINK_WALLET_TO_BR,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 1,
                    'account_debited'     => $source_mobile->mobile,
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'        => 'Wallet linked to bank successfully',


                ]);

            return response([

                'code' => '00',
                'description' => 'Wallet linked to bank successfully',

            ]);





        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);


                if($source_mobile->state == '0') {


                    WalletTransactions::create([

                        'txn_type_id'         => LINK_WALLET_TO_BR,
                        'tax'                 => '0.00',
                        'revenue_fees'        => '0.00',
                        'interchange_fees'    => '0.00',
                        'zimswitch_fee'       => '0.00',
                        'transaction_amount'  => '0.00',
                        'total_debited'       => '0.00',
                        'total_credited'      => '0.00',
                        'batch_id'            => $reference,
                        'switch_reference'    => $reference,
                        'merchant_id'         => '',
                        'transaction_status'  => 0,
                        'account_debited'     => $source_mobile->mobile,
                        'pan'                 => '',
                        'merchant_account'    => '',
                        'description'        => 'Mobile account is blocked',


                    ]);



                    return response([

                        'code' => '02',
                        'description' => 'Mobile account is blocked',

                    ]);

                }



                $number_of_attempts =  $source_mobile->auth_attempts + 1;
                $source_mobile->auth_attempts = $number_of_attempts;
                $source_mobile->save();

                if($number_of_attempts  > '2'){

                    $source_mobile->state = '0';
                    $source_mobile->save();

                }



                WalletTransactions::create([

                    'txn_type_id'         => LINK_WALLET_TO_BR,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $source_mobile->mobile,
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'        => $exception,


                ]);



                return response([

                    'code' => '02',
                    'description' => $exception,

                ]);



                //return new JsonResponse($exception, $e->getCode());
            } else {



                WalletTransactions::create([

                    'txn_type_id'         => LINK_WALLET_TO_BR,
                    'tax'                 => '0.00',
                    'revenue_fees'        => '0.00',
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '0.00',
                    'transaction_amount'  => '0.00',
                    'total_debited'       => '0.00',
                    'total_credited'      => '0.00',
                    'batch_id'            => $reference,
                    'switch_reference'    => $reference,
                    'merchant_id'         => '',
                    'transaction_status'  => 0,
                    'account_debited'     => $source_mobile->mobile,
                    'pan'                 => '',
                    'merchant_account'    => '',
                    'description'        => $e->getMessage(),


                ]);


                return response([

                    'code' => '01',
                    'description' => $e->getMessage(),

                ]);


                //return new JsonResponse($e->getMessage(), 503);
            }


        }








    }





    protected function br_validation(Array $data)
    {
        return Validator::make($data, [
            'br_account' => 'required',
            'source_mobile' => 'required',


        ]);


    }


}

