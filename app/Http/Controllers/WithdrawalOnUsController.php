<?php
namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\PostWalletPurchaseJob;
use App\LuhnCards;
use App\Merchant;
use App\MerchantAccount;
use App\Services\BalanceEnquiryService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletPostPurchaseTxns;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\WalletTransaction;


class WithdrawalOnUsController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */

    public function cash_withdrawal(Request $request)
    {

        $validator = $this->cash_withdrawal_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $merchant_id = Devices::where('imei', $request->imei)->first();


        try {


            //Balance Enquiry On Us Debit Fees
            $fees_charged = FeesCalculatorService::calculateFees(
                $request->amount / 100,
                '0.00',
                CASH_WITHDRAWAL,
                HQMERCHANT

            );

            $transactions = Transactions::where('account_debited', $request->source_account)
                ->where('txn_type_id', CASH_WITHDRAWAL)
                ->where('description', 'Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $transactions_ = Transactions::where('account_debited', $request->source_account)
                ->where('txn_type_id', CASH_WITHDRAWAL)
                ->where('description', 'Transaction successfully processed.')
                ->whereDate('created_at', Carbon::today())
                ->get()->count();

            $total_count = $transactions_ + $transactions;

            if ($total_count >= $fees_charged['transaction_count']) {

                Transactions::create([

                    'txn_type_id' => CASH_WITHDRAWAL,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => '0.00',
                    'total_debited' => '0.00',
                    'total_credited' => '0.00',
                    'batch_id' => '',
                    'switch_reference' => '',
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => $request->br_account,
                    'pan' => '',
                    'description' => 'Transaction limit reached for the day.',


                ]);


                return response([
                    'code' => '121',
                    'description' => 'Transaction limit reached for the day.',

                ]);
            }


            if ($request->amount / 100 > $fees_charged['maximum_daily']) {

                Transactions::create([

                    'txn_type_id' => CASH_WITHDRAWAL,
                    'tax' => '0.00',
                    'revenue_fees' => '0.00',
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => '0.00',
                    'total_debited' => '0.00',
                    'total_credited' => '0.00',
                    'batch_id' => '',
                    'switch_reference' => '',
                    'merchant_id' => '',
                    'transaction_status' => 0,
                    'account_debited' => $request->source_account,
                    'pan' => $request->card_number,
                    'description' => 'Invalid amount, error 902',


                ]);

                return response([
                    'code' => '902',
                    'description' => 'Invalid mount',

                ]);
            }


            $total_funds = $fees_charged['acquirer_fee'] + ($request->amount / 100);
            // Check if client has enough funds.


            $branch_id = substr($request->source_account, 0, 3);

            $revenue = REVENUE;


            $credit_merchant_account = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->destination_account,
                'trx_description_id' => '008',
                'TrxDescription' => 'Credit GL, Withdrawal via POS',
                'TrxAmount' => $request->amount / 100);

            $debit_client_amount = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->source_account,
                'trx_description_id' => '007',
                'TrxDescription' => 'Withdrawal via POS',
                'TrxAmount' => '-' . $request->amount / 100);


            $debit_client_fees = array(
                'serial_no' => '472100',
                'our_branch_id' => $branch_id,
                'account_id' => $request->source_account,
                'trx_description_id' => '007',
                'TrxDescription' => 'Withdrawal fees charged',
                'TrxAmount' => '-' . $fees_charged['acquirer_fee']);


            $credit_revenue_fees = array(
                'serial_no' => '472100',
                'our_branch_id' => '001',
                'account_id' => $revenue,
                'trx_description_id' => '008',
                'TrxDescription' => "Withdrawal fees earned",
                'TrxAmount' => $fees_charged['acquirer_fee']);


            $authentication = TokenService::getToken();
            $client = new Client();


                $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                    'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                    'json' => [
                        'bulk_trx_postings' => array(

                            $credit_merchant_account,
                            $debit_client_amount,
                            $debit_client_fees,
                            $credit_revenue_fees,


                        ),
                    ]
                ]);


                // $response_ = $result->getBody()->getContents();
                $response = json_decode($result->getBody()->getContents());

                if ($response->code != '00') {

                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => $fees_charged['tax'],
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => $request->amount / 100,
                        'total_debited' => $total_funds,
                        'total_credited' => $total_funds,
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => $response->description,


                    ]);


                    return response([

                        'code' => '100',
                        'description' => $response->description


                    ]);


                }


                $revenue = $fees_charged['acquirer_fee'];
                $merchant_amount = -$fees_charged['mdr'] + ($request->amount / 100);

                Transactions::create([

                    'txn_type_id' => CASH_WITHDRAWAL,
                    'tax' => $fees_charged['tax'],
                    'revenue_fees' => $revenue,
                    'interchange_fees' => '0.00',
                    'zimswitch_fee' => '0.00',
                    'transaction_amount' => $request->amount / 100,
                    'total_debited' => $total_funds,
                    'total_credited' => $total_funds,
                    'batch_id' => $response->transaction_batch_id,
                    'switch_reference' => $response->transaction_batch_id,
                    'merchant_id' => '',
                    'transaction_status' => 1,
                    'account_debited' => $request->account_number,
                    'pan' => $request->card_number,
                    'merchant_account' => $merchant_amount,
                    'description' => 'Transaction successfully processed.',

                ]);

            if(isset($request->mobile)) {
                $new_balance = money_format('$%i', $request->amount / 100);
                $merchant = Merchant::find($merchant_id->merchant_id);
                $client = COUNTRY_CODE . substr($request->mobile, 1, 10);
                dispatch(new NotifyBills(
                        $client,
                        "Cash withdrawal of ZWL $new_balance via Getbucks m-POS was successful. Merchant: $merchant->name, reference: $response->transaction_batch_id",
                        'GetBucks',
                        $merchant->mobile,
                        "Your teller account has been credited ZWL $new_balance. Client mobile: $client reference: $response->transaction_batch_id",
                        '2'
                    )
                );
            }


                return response([

                    'code' => '000',
                    'batch_id' => (string)$response->transaction_batch_id,
                    'description' => 'Success'


                ]);


            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $exception = (string)$e->getResponse()->getBody();
                    Log::debug('Account Number:' . $request->account_number . ' ' . $exception);

                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => '0.00',
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => '0.00',
                        'total_debited' => '0.00',
                        'total_credited' => '0.00',
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => 'Failed to process BR transaction',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process BR transaction'


                    ]);

                    //return new JsonResponse($exception, $e->getCode());
                } else {
                    Log::debug('Account Number:' . $request->account_number . ' ' . $e->getMessage());
                    Transactions::create([

                        'txn_type_id' => CASH_WITHDRAWAL,
                        'tax' => '0.00',
                        'revenue_fees' => '0.00',
                        'interchange_fees' => '0.00',
                        'zimswitch_fee' => '0.00',
                        'transaction_amount' => '0.00',
                        'total_debited' => '0.00',
                        'total_credited' => '0.00',
                        'batch_id' => '',
                        'switch_reference' => '',
                        'merchant_id' => '',
                        'transaction_status' => 0,
                        'account_debited' => $request->account_number,
                        'pan' => $request->card_number,
                        'description' => 'Failed to process transactions',


                    ]);

                    return response([

                        'code' => '100',
                        'description' => 'Failed to process BR transaction',


                    ]);

                }
            }

        }


    protected function cash_withdrawal_validator(Array $data)
    {
        return Validator::make($data, [
            'source_account'        => 'required',
            'destination_account'   => 'required',
            'amount'                => 'required',

        ]);

    }

}














