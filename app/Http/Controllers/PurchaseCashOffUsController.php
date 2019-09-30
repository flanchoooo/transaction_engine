<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;



class PurchaseCashOffUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */






    public function purchase_cash_back_off_us(Request $request)
    {

        $validator = $this->purchase_cashback_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $card_number = str_limit($request->card_number,16,'');
        $branch_id = substr($request->account_number, 0, 3);


        try {



            $authentication = TokenService::getToken();
            //Balance Enquiry On Us Debit Fees
              $fees_charged = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                $request->cashback_amount / 100,
                 PURCHASE_CASH_BACK_OFF_US,
                '28' // configure a default merchant for the HQ,

            );



            $total_count  = Transactions::where('account_debited',$request->account_number)
                ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_OFF_US,PURCHASE_CASH_BACK_ON_US,PURCHASE_ON_US,PURCHASE_OFF_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();





            if($total_count  >= $fees_charged['transaction_count'] ){

                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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
                    'account_debited'     => $request->br_account,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',


                ]);


                return response([
                    'code' => '121',
                    'description' => 'Transaction limit reached for the day.',

                ]);
            }



            if($request->amount /100 > $fees_charged['maximum_daily']){

                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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


            // deductable amt = amount = variable????
            $deductable_funds = $request->amount / 100 +
                                $request->cashback_amount / 100 +
                                $fees_charged['fees_charged'];

            // Check if client has enough funds.


            $revenue = REVENUE;
            $tax = TAX;
            $zimswitch = ZIMSWITCH;


                $zimswitch_amount = $request->amount/100 +
                                    $request->cashback_amount/100 +
                                    $fees_charged['zimswitch_fee'] +
                                    $fees_charged['acquirer_fee']  +
                                    $fees_charged['cash_back_fee'];


                $debit_client_amount = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => substr($request->account_number, 0, 3),
                    'AccountID'         => $request->account_number,
                    'TrxDescriptionID'  => '007',
                    'TrxDescription'    => 'Purchase + Cash off us, debit purchase amount',
                    'TrxAmount'         => '-'. $request->amount/100);

                $debit_client_cash_back = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => substr($request->account_number, 0, 3),
                    'AccountID'         => $request->account_number,
                    'TrxDescriptionID'  => '007',
                    'TrxDescription'    => 'Purchase + Cash off us, debit cash amount',
                    'TrxAmount'         => '-'. $request->cashback_amount/100);


                $debit_client_fees = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => substr($request->account_number, 0, 3),
                    'AccountID'         => $request->account_number,
                    'TrxDescriptionID'  => '007',
                    'TrxDescription'    => 'Purchase + Cash off us, debit fees',
                    'TrxAmount'         => '-'. $fees_charged['fees_charged']);


                $credit_tax = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => $branch_id,
                    'AccountID'         => $tax,
                    'TrxDescriptionID'  => '008',
                    'TrxDescription'    => 'Purchase + Cash off us,credit tax',
                    'TrxAmount'         => $fees_charged['tax']);

                $credit_zimswitch = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => $branch_id,
                    'AccountID'         => $zimswitch,
                    'TrxDescriptionID'  => '008',
                    'TrxDescription'    => 'Purchase + Cash off us,credit zimswitch ',
                    'TrxAmount'         => $zimswitch_amount);

                $debit_zimswitch_interchange = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => $branch_id,
                    'AccountID'         => $zimswitch,
                    'TrxDescriptionID'  => '007',
                    'TrxDescription'    => 'Purchase + Cash off us,debit zimswitch inter change fee',
                    'TrxAmount'         => '-'.$fees_charged['interchange_fee']);


                $credit_revenue = array(
                    'SerialNo'          => '472100',
                    'OurBranchID'       => $branch_id,
                    'AccountID'         => $revenue,
                    'TrxDescriptionID'  => '008',
                    'TrxDescription'    => 'Purchase + Cash off us,credit revenue',
                    'TrxAmount'         => $fees_charged['interchange_fee']);


                $client = new Client();


                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' => array(
                                $debit_client_amount,
                                $debit_client_cash_back,
                                $debit_client_fees,
                                $credit_tax,
                                $credit_zimswitch,
                                $debit_zimswitch_interchange,
                                $credit_revenue,

                            ),
                        ]

                    ]);


                    //return $response_ = $result->getBody()->getContents();
                    $response = json_decode($result->getBody()->getContents());

                    if($response->description == 'API : Validation Failed: Customer TrxAmount cannot be Greater Than the AvailableBalance') {

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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
                            'pan'                 => $card_number,
                            'description'         => 'Insufficient Funds',


                        ]);

                        return response([
                            'code' => '116 ',
                            'description' => 'Insufficient Funds',

                        ]);

                    }

                    if($response->code != '00'){

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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
                            'pan'                 => $card_number,
                            'merchant_account'    => '',
                            'description'         => 'Invalid BR Account',
                        ]);


                        return response([

                            'code' => '100',
                            'description' => 'Invalid BR Account',


                        ]);

                    }


                        $transaction_amount = $request->amount /100 + $request->cashback_amount/100;
                        $revenue = $fees_charged['mdr']  +  $fees_charged['acquirer_fee'] + $fees_charged['interchange_fee'];
                        $merchant_amount = $transaction_amount - $fees_charged['mdr'];
                        $total_ = $transaction_amount + $fees_charged['fees_charged'];

                        Transactions::create([

                            'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
                            'tax'                 => $fees_charged['mdr'],
                            'revenue_fees'        => $revenue,
                            'interchange_fees'    => $fees_charged['interchange_fee'],
                            'zimswitch_fee'       => $fees_charged['zimswitch_fee'],
                            'transaction_amount'  => $transaction_amount,
                            'total_debited'       => $total_,
                            'total_credited'      => $total_,
                            'batch_id'            => $response->transaction_batch_id,
                            'switch_reference'    => $response->transaction_batch_id,
                            'merchant_id'         => '',
                            'transaction_status'  => 1,
                            'account_debited'     => $request->account_number,
                            'pan'                 => $card_number,
                            'merchant_account'    => $merchant_amount,
                            'description'    => 'Transaction successfully processed.',

                        ]);


                        return response([

                            'code' => '000',
                            'fees_charged' => $fees_charged['fees_charged'],
                            'batch_id' => (string)$response->transaction_batch_id,
                            'description' => 'Success'


                        ]);





        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Account Number:'.$request->account_number.' '. $exception);

                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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
                    'description'         => 'Failed to process BR transaction',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction.'


                ]);


                //return new JsonResponse($exception, $e->getCode());
            } else {

                Log::debug('Account Number:'.$request->account_number.' '. $e->getMessage());
                Transactions::create([

                    'txn_type_id'         => PURCHASE_CASH_BACK_OFF_US,
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
                    'description'         => 'Failed to process BR transaction',


                ]);

                return response([

                    'code' => '100',
                    'description' => 'Failed to process BR transaction'


                ]);

                //return new JsonResponse($e->getMessage(), 503);
            }
        }




    }

    protected function purchase_cashback_validation(Array $data)
    {
        return Validator::make($data, [
            'account_number' => 'required',
            'amount' => 'required',
            'card_number' => 'required',
            'cashback_amount' => 'required',


        ]);
    }






}