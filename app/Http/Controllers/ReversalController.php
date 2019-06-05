<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Batch_Transaction;
use App\Devices;
use App\MerchantAccount;
use App\Reversal;
use App\Services\BalanceEnquiryService;
use App\Services\CardCheckerService;
use App\Services\CheckBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\LimitCheckerService;
use App\Services\TokenService;
use App\Services\ApiTokenValidity;
use App\Services\TransactionRecorder;
use App\Transaction;
use App\Zipit;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class ReversalController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */


    public function reversal(Request $request)
    {



      //  return $request->all();

        $validator = $this->reversals_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
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
            $result = $client->post(env('BASE_URL') . '/api/reversals', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'branch_id' => '001',
                    'transaction_batch_id' => $request->transaction_batch_id,
                ]
            ]);

       // return $response = $result->getBody()->getContents();
        $response = json_decode($result->getBody()->getContents());

        if($response->code == '00'){


            Transaction::create([

                'transaction_type' => '25',
                'status' => 'COMPLETED',
                'account' => '',
                'pan' => '',
                'credit' => '0.00',
                'debit' => '0.00',
                'description' => 'Reversal for batch'.$request->transaction_batch_id,
                'fee' => '0.00',
                'batch_id' => '',
                'merchant' => '']);


            return response([

                'code' => '00',
                'description' => 'Success'


            ]);
        }



        }catch (RequestException $e) {


            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);

                return array('code' => '91',
                    'error' => $exception);


            }


        }


    }




    protected function reversals_validation(Array $data)
    {
        return Validator::make($data, [
            'transaction_batch_id' => 'required',
        ]);
    }

























    protected function reversal_data(Array $data)
    {
        return Validator::make($data, [

            'batch_id' => 'required',

        ]);
    }







}