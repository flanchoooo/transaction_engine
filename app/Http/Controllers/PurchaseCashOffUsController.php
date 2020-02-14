<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\PenaltyDeduction;
use App\Services\AccountInformationService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transactions;
use App\WalletTransactions;
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


        return response([
            'code' => '902',
            'description' => 'Transaction currently unavailable',
        ]);
        try {

            $results =  AccountInformationService::getUserDetails($request->account_number);
            if($results["code"] != '00'){
                return response([
                    'code' => '100',
                    'description' => 'Failed to fetch customer information',
                ]);
            }

            if($results["status"] != 'Active'){
                return response([
                    'code' => '114',
                    'description' => 'Account closed',
                ]);
            }


            $purchase_amount = $request->amount/100;
            $cash_amount = $request->cashback_amount/100;
            $purchase_amounts = $purchase_amount - $cash_amount;


            $authentication = $results["token"];
            //Balance Enquiry On Us Debit Fees
                $fees_charged = FeesCalculatorService::calculateFees(
                    $purchase_amounts,
                $request->cashback_amount / 100,
                 PURCHASE_CASH_BACK_OFF_US,
                 HQMERCHANT // configure a default merchant for the HQ,

            );


            $response =   $this->switchLimitChecks(
                $request->account_number,
                $request->amount/100 ,
                $fees_charged['maximum_daily'],
                $card_number,$fees_charged['transaction_count'],
                $fees_charged['max_daily_limit']);

            if($response["code"] != '000'){
                return response([
                    'code' => $response["code"],
                    'description' => $response["description"],
                ]);
            }



            $revenue = REVENUE;
            $tax = TAX;
            $zimswitch = ZIMSWITCH;

           $total_fees =  $fees_charged['fees_charged'] +  $fees_charged['interchange_fee'];
                $debit_client_amount = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => substr($request->account_number, 0, 3),
                    'account_id'         => $request->account_number,
                    'trx_description_id'  => '007',
                    'TrxDescription'    => "POS Purchase + Cash RRN:$request->rrn",
                    'TrxAmount'         => '-'. $purchase_amount);




                $debit_client_fees = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => substr($request->account_number, 0, 3),
                    'account_id'         => $request->account_number,
                    'trx_description_id'  => '007',
                    'TrxDescription'    => "POS Purchase + Cash fees RRN:$request->rrn",
                    'TrxAmount'         => '-'. $total_fees);


                $credit_tax = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $tax,
                    'trx_description_id'  => '008',
                    'TrxDescription'    =>"POS Purchase + Cash tax fee RRN:$request->rrn",
                    'TrxAmount'         => $fees_charged['tax']);

                $credit_zimswitch_amount = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $zimswitch,
                    'trx_description_id'  => '008',
                    'TrxDescription'    => "POS Purchase + Cash RRN:$request->rrn",
                    'TrxAmount'         => $purchase_amount);



            $acquirer_fee = array(
                'serial_no'          => '472100',
                'our_branch_id'       => $branch_id,
                'account_id'         => $revenue,
                'trx_description_id'  => '008',
                'TrxDescription'    => "POS Purchase + Cash Acquirer fee RRN:$request->rrn",
                'TrxAmount'         =>    $fees_charged['acquirer_fee']);


            $cash_back_fee = array(
                'serial_no'          => '472100',
                'our_branch_id'       => $branch_id,
                'account_id'         => $zimswitch,
                'trx_description_id'  => '008',
                'TrxDescription'    => "Cashback fee RRN:$request->rrn",
                'TrxAmount'         =>  $fees_charged['cash_back_fee']);


            $credit_zimswitch_fee = array(
                'serial_no'          => '472100',
                'our_branch_id'       => $branch_id,
                'account_id'         => $zimswitch,
                'trx_description_id'  => '007',
                'TrxDescription'    => "POS Purchase  + Cash Switch fee RRN:$request->rrn",
                'TrxAmount'         => $fees_charged['zimswitch_fee']);



            $credit_revenue = array(
                    'serial_no'          => '472100',
                    'our_branch_id'       => $branch_id,
                    'account_id'         => $revenue,
                    'trx_description_id'  => '008',
                    'TrxDescription'    => "POS Purchase  + Cash Interchange fee RRN:$request->rrn",
                    'TrxAmount'         => $fees_charged['interchange_fee']);




                $client = new Client();


                    $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                        'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                        'json' => [
                            'bulk_trx_postings' => array(
                                $debit_client_amount,
                                $debit_client_fees,
                                $credit_tax,
                                $credit_zimswitch_amount,
                                $credit_revenue,
                                $acquirer_fee,
                                $cash_back_fee,
                                $credit_zimswitch_fee
                            )
                        ]

                    ]);


                    //r//eturn $response_ = $result->getBody()->getContents();
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

                        PenaltyDeduction::create([
                            'amount'                => ZIMSWITCH_PENALTY_FEE,
                            'imei'                  => '000',
                            'merchant'              => HQMERCHANT,
                            'source_account'        => $request->account_number,
                            'destination_account'   => ZIMSWITCH,
                            'txn_status'            => 'PENDING',
                            'description'           => 'Zimswitch Penalty'

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

    public function switchLimitChecks($account_number,$amount,$maximum_daily,$card_number,$transaction_count,$max_daily_limit){


        $account = substr($account_number, 0,3);
        if($account == '263'){
            $total_count  = WalletTransactions::where('account_debited',$account_number)
                ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
                ->where('description','Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $daily_spent =  WalletTransactions::where('account_debited', $account_number)
                ->where('created_at', '>', Carbon::now()->subDays(1))
                ->sum('transaction_amount');


            if($amount > $maximum_daily){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => $card_number,
                    'description'         => 'Exceeds maximum purchase limit',

                ]);

                return array(
                    'code' => '121',
                    'description' => 'Exceeds maximum purchase limit',

                );

            }


            if($total_count  >= $transaction_count ){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Exceeds purchase frequency limit.',
                ]);

                return array(
                    'code' => '123',
                    'description' => 'Exceeds purchase frequency limit.',

                );

            }

            if($daily_spent  >= $max_daily_limit ){
                WalletTransactions::create([
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
                    'account_debited'     => $account_number,
                    'pan'                 => '',
                    'description'         => 'Transaction limit reached for the day.',
                ]);

                return array(
                    'code' => '121',
                    'description' => 'Exceeds purchase frequency limit.',

                );
            }



            return array(
                'code' => '000',
                'description' => 'Success',

            );

        }


        $total_count  = Transactions::where('account_debited',$account_number)
            ->whereIn('txn_type_id',[PURCHASE_CASH_BACK_ON_US,PURCHASE_OFF_US,PURCHASE_ON_US])
            ->where('description','Transaction successfully processed.')
            ->whereDate('created_at', Carbon::today())
            ->get()->count();

        $daily_spent =  Transactions::where('account_debited', $account_number)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->sum('transaction_amount');


        if($amount > $maximum_daily){
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
                'account_debited'     => $account_number,
                'pan'                 => $card_number,
                'description'         => 'Exceeds maximum purchase limit',

            ]);

            return array(
                'code' => '121',
                'description' => 'Exceeds maximum purchase limit',

            );

        }


        if($total_count  >= $transaction_count ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Exceeds purchase frequency limit.',
            ]);

            return array(
                'code' => '123',
                'description' => 'Exceeds purchase frequency limit.',

            );

        }

        if($daily_spent  >= $max_daily_limit ){
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
                'account_debited'     => $account_number,
                'pan'                 => '',
                'description'         => 'Transaction limit reached for the day.',
            ]);

            return array(
                'code' => '121',
                'description' => 'Exceeds purchase frequency limit.',

            );
        }



        return array(
            'code' => '000',
            'description' => 'Success',

        );






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