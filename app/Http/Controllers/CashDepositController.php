<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Services\BalanceEnquiryService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use App\Zipit;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class CashDepositController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */


    public function cash_deposit(Request $request)
    {


        $validator = $this->deposit_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        try {


            $destination_account_credit = array(
                'SerialNo'            => '472100',
                'OurBranchID'         => substr($request->destination_account, 0, 3),
                'AccountID'           => $request->destination_account,
                'TrxDescriptionID'    => '007',
                'TrxDescription'      => 'Cash deposit via POS',
                'TrxAmount'           => $request->amount / 100);


            $zimswitch_debit = array(
                'SerialNo'          => '472100',
                'OurBranchID'       => substr($request->destination_account, 0, 3),
                'AccountID'         => $request->source_account,
                'TrxDescriptionID'  => '008',
                'TrxDescription'    => 'Debit Cash deposit via POS',
                'TrxAmount'         => - $request->amount / 100);


            $auth = TokenService::getToken();
            $client = new Client();


                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $auth, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(
                            $destination_account_credit,
                            $zimswitch_debit,

                        )
                    ]

                ]);

              // $response_ = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());

                if ($response->code != '00') {
                    return response([

                        'code' => '100',
                        'description' => $response->description


                    ]);


                }
                Transactions::create([

                    'txn_type_id'           => CASH_DEPOSIT,
                    'tax'                   => '0.00',
                    'revenue_fees'          => '0.00',
                    'interchange_fees'      => '0.00',
                    'zimswitch_fee'         => '0.00',
                    'transaction_amount'    => $request->amount / 100,
                    'total_debited'         => $request->amount / 100,
                    'total_credited'        => $request->amount / 100,
                    'batch_id'              => $response->transaction_batch_id,
                    'switch_reference'      => $response->transaction_batch_id,
                    'merchant_id'           => '',
                    'transaction_status'    => 1,
                    'account_debited'       => $request->source_account,
                    'pan'                   => $request->card_number,
                    'merchant_account'      => '',
                    'account_credited'      => $request->destination_account,
                    'description'           => 'Transaction successfully processed.',
                ]);

                return response([

                    'code' => '100',
                    'batch_id' =>"$response->transaction_batch_id",
                    'description' => 'Success'


                ]);




        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Account Number:'. $request->account_number.' '. $exception);

                Transactions::create([

                    'txn_type_id'           => CASH_DEPOSIT,
                    'tax'                   => '0.00',
                    'revenue_fees'          => '0.00',
                    'interchange_fees'      => '0.00',
                    'zimswitch_fee'         => '0.00',
                    'transaction_amount'    => '0.00',
                    'total_debited'         => '0.00',
                    'total_credited'        => '0.00',
                    'batch_id'              => '',
                    'switch_reference'      => '',
                    'merchant_id'           => '',
                    'transaction_status'    => 0,
                    'account_credited'      => $request->br_account,
                    'pan'                   => $request->card_number,
                    'description'           => 'Failed to process BR transaction',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);

                //return new JsonResponse($exception, $e->getCode());
            } else {
                Log::debug('Account Number:'. $request->account_number.' '. $e->getMessage());
                Transactions::create([

                    'txn_type_id'       => CASH_DEPOSIT,
                    'tax'               => '0.00',
                    'revenue_fees'      => '0.00',
                    'interchange_fees'  => '0.00',
                    'zimswitch_fee'     => '0.00',
                    'transaction_amount'=> '0.00',
                    'total_debited'     => '0.00',
                    'total_credited'    => '0.00',
                    'batch_id'          => '',
                    'switch_reference'  => '',
                    'merchant_id'       => '',
                    'transaction_status'=> 0,
                    'account_credited'  => $request->br_account,
                    'pan'               => $request->card_number,
                    'description'       => 'Failed to BR process transactions,error 01',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to BR process transactions,error 01'


                ]);

            }
        }


    }


    protected function deposit_validation(Array $data)
    {
        return Validator::make($data, [
            'source_account'        => 'required',
            'destination_account'   => 'required',
            'amount'                => 'required',

        ]);

    }

}