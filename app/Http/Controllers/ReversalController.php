<?php
namespace App\Http\Controllers;




use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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


            $authentication = TokenService::getToken();

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

        if($response->code != '00'){

            Transactions::create([

                'txn_type_id'         => REVERSAL,
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
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Reversal for batch'.$request->transaction_batch_id,


            ]);
        }

            Transactions::create([

                'txn_type_id'         => REVERSAL,
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
                'transaction_status'  => 1,
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Reversal for batch'.$request->transaction_batch_id,


            ]);


            return response([

                'code' => '00',
                'description' => 'Success'


            ]);




        }catch (RequestException $e) {

            Transactions::create([

                'txn_type_id'         => REVERSAL,
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
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Reversal for batch'.$request->transaction_batch_id,


            ]);


            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);

                return array('code' => '91',
                    'description' => $exception);


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