<?php
namespace App\Http\Controllers;




use App\BRJob;
use App\Console\Commands\REVERSAL;
use App\Deduct;
use App\ManageValue;
use App\MDR;
use App\MerchantAccount;
use App\Services\FeesCalculatorService;
use App\Services\SmsNotificationService;
use App\Services\TokenService;
use App\Services\WalletFeesCalculatorService;
use App\Transactions;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class ReversalController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */


    public function reversal_copy(Request $request)
    {

        $validator = $this->reversals_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        $wallet_batch = WalletTransactions::whereBatchId($request->transaction_batch_id)->first();

        if (isset($wallet_batch)) {
            if ($wallet_batch->reversed == 'REVERSED') {
                return response([
                    'code' => '01',
                    'description' => 'Transaction already reversed.'
                ]);
            }


            DB::beginTransaction();
            try {

                //Source Account
                $source = Wallet::whereMobile($wallet_batch->account_debited);
                $revenue = Wallet::whereMobile(WALLET_REVENUE);
                $tax = Wallet::whereMobile(WALLET_TAX);
                $destination = Wallet::whereMobile($wallet_batch->account_credited);
                $currency = CURRENCY;

                $destination_mobile = $destination->lockForUpdate()->first();

                if ($wallet_batch->txn_type_id == ZIPIT_SEND) {

                    $zipit_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        '0.00',
                        ZIPIT_SEND,
                        HQMERCHANT
                    );

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }

                    //Refund total debited.
                    $total = $wallet_batch->transaction_amount + $zipit_fees['acquirer_fee'] + $zipit_fees['tax'] + $zipit_fees['zimswitch_fee'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $zipit_fees['acquirer_fee'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $zipit_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -= $wallet_batch->transaction_amount + $zipit_fees['zimswitch_fee'];
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT send transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == BALANCE_ON_US) {
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ON_US,
                        $wallet_batch->merchant_id
                    );

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $balance_onus_fees['acquirer_fee'];
                    $source_mobile->save();


                    $destination_mobile->balance -= $balance_onus_fees['acquirer_fee'];
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == BALANCE_ENQUIRY_OFF_US) {
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ENQUIRY_OFF_US,
                        HQMERCHANT
                    );

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $balance_onus_fees['zimswitch_fee'];
                    $source_mobile->save();


                    $destination_mobile->balance -= $balance_onus_fees['zimswitch_fee'];
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance remote on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == PURCHASE_ON_US) {
                    $purchase_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_ON_US,
                        $wallet_batch->merchant_id
                    );

                    $less_mdr = $wallet_batch->transaction_amount - $purchase_fees['mdr'];
                    $less_revenue = $purchase_fees['mdr'] + $purchase_fees['acquirer_fee'];
                    $less_fees = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                    $credit_source = $wallet_batch->transaction_amount + $less_fees;


                    if ($destination_mobile->balance < $less_mdr) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $credit_source;
                    $source_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $purchase_fees['tax'];
                    $tax_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $less_revenue;
                    $revenue_mobile->save();


                    $destination_mobile->balance -= $less_mdr;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed purchase on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == PURCHASE_OFF_US) {
                    $purchase_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_OFF_US,
                        HQMERCHANT
                    );

                    $less_mdr = $less_revenue = -$purchase_fees['interchange_fee'] + $purchase_fees['acquirer_fee'] + $wallet_batch->transaction_amount;
                    $less_revenue = $purchase_fees['interchange_fee'];
                    $less_fees = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                    $credit_source = $wallet_batch->transaction_amount + $less_fees;


                    if ($destination_mobile->balance < $less_mdr) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $credit_source;
                    $source_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $purchase_fees['tax'];
                    $tax_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $less_revenue;
                    $revenue_mobile->save();


                    $destination_mobile->balance -= $less_mdr;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance purchase remote on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == ZIPIT_RECEIVE) {

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $wallet_batch->transaction_amount;
                    $source_mobile->save();

                    $destination_mobile->balance -= $wallet_batch->transaction_amount;;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT receive transaction'
                    ]);

                    //return $wallet_batch;
                }

                if ($wallet_batch->txn_type_id == SEND_MONEY) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        $wallet_batch->txn_type_id);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }

                    $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $wallet_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -= $wallet_batch->transaction_amount;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    SmsNotificationService::send(
                        '1',
                        '',
                        '',
                        '',
                        '',
                        $source_mobile->mobile,
                        "Transaction with reference: $request->transaction_batch_id  of $currency $wallet_batch->transaction_amount was successfully reversed.  Thank you for using " . env('SMS_SENDER') . ' .'

                    );


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed send money transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == CASH_IN) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        $wallet_batch->txn_type_id);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $wallet_batch->transaction_amount;
                    $source_mobile->commissions -= $wallet_fees['fee'];
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance += $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $destination_mobile->balance -= $wallet_batch->transaction_amount;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed cash in transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == CASH_OUT) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        CASH_OUT);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }

                    //Credit Source
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance -= $wallet_batch->transaction_amount;
                    $source_mobile->commissions -= $wallet_fees['exclusive_agent_portion'];
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['exclusive_revenue_portion'];
                    $revenue_mobile->save();

                    $credit = $wallet_batch->transaction_amount + $wallet_fees['fee'];
                    $destination_mobile->balance -= $credit;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed cash out transaction'
                    ]);

                }


                $wallet_fees = WalletFeesCalculatorService::calculateFees(
                    $wallet_batch->transaction_amount,
                    $wallet_batch->txn_type_id

                );


                if ($wallet_fees['fee_type'] == 'EXCLUSIVE') {
                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $wallet_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -= $wallet_batch->transaction_amount;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed bill-payment transaction'
                    ]);


                }

                if ($wallet_fees['fee_type'] == 'INCLUSIVE') {

                    $deducatable_amount = $wallet_batch->transaction_amount - $wallet_fees['inclusive_revenue_portion'];

                    if ($destination_mobile->balance < $deducatable_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $total = $deducatable_amount + $wallet_fees['inclusive_revenue_portion'] + $wallet_fees['tax'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['inclusive_revenue_portion'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $wallet_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -= $deducatable_amount;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed bill-payment transaction'
                    ]);


                }


            } catch (\Exception $e) {

                return $e;

                DB::rollBack();
                Log::debug('Account Number:' . $request->account_number . ' ' . $e);
                WalletTransactions::create([
                    'txn_type_id' => SEND_MONEY,
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
                    'pan' => '',
                    'description' => 'Transaction was reversed for mobbile:' . $request->account_number,

                ]);

                return response([
                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }


        }


        try {


            $response = BRJob::where('tms_bach', $request->transaction_batch_id)->first();
            if (isset($response)) {
                return $batch_id = $response->br_reference;
            } else {

                return $batch_id = $request->transaction_batch_id;
            }

            $authentication = TokenService::getToken();

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/reversals', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'branch_id' => '001',
                    'transaction_batch_id' => $batch_id,
                ]
            ]);

            return $response = $result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());

            if ($response->code != '00') {

                Transactions::create([

                    'txn_type_id' => REVERSAL,
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
                    'account_debited' => '',
                    'pan' => '',
                    'description' => 'Reversal for batch' . $request->transaction_batch_id,


                ]);
            }

            Transactions::create([

                'txn_type_id' => REVERSAL,
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
                'transaction_status' => 1,
                'account_debited' => '',
                'pan' => '',
                'description' => 'Reversal for batch' . $request->transaction_batch_id,


            ]);


            return response([

                'code' => '00',
                'description' => 'Success'


            ]);


        } catch (RequestException $e) {

            Transactions::create([

                'txn_type_id' => REVERSAL,
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
                'account_debited' => '',
                'pan' => '',
                'description' => 'Failed to process reversal, batch id not found.',


            ]);

            if ($e->hasResponse()) {
                // $exception = (string)$e->getResponse()->getBody();
                // $exception = json_decode($exception);

                return array('code' => '91',
                    'description' => 'Batch id not found.');


            }

        }

    }

    public function reversal(Request $request)
    {

        $validator = $this->reversals_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $wallet_batch = WalletTransactions::whereBatchId($request->transaction_batch_id)->first();
        if (isset($wallet_batch)) {
            if ($wallet_batch->reversed == 'REVERSED') {
                return response([
                    'code' => '01',
                    'description' => 'Transaction already reversed.'
                ]);
            }


            DB::beginTransaction();
            try {

                //Source Account
                $source = Wallet::whereMobile($wallet_batch->account_debited);
                $revenue = Wallet::whereMobile(WALLET_REVENUE);
                $tax = Wallet::whereMobile(WALLET_TAX);
                $destination = Wallet::whereMobile($wallet_batch->account_credited);
                $currency = CURRENCY;

                $destination_mobile = $destination->lockForUpdate()->first();

                if ($wallet_batch->txn_type_id == ZIPIT_SEND) {

                    $zipit_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        '0.00',
                        ZIPIT_SEND,
                        HQMERCHANT
                    );

                    /*  if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                          return response([
                              'code'          => '100',
                              'description'   => 'Source account does not have sufficient funds to perform a reversal',
                          ]);

                      }


                      $revenue_mobile = $revenue->lockForUpdate()->first();
                      $revenue_mobile->balance -= $zipit_fees['acquirer_fee'];
                      $revenue_mobile->save();

                      $tax_mobile = $tax->lockForUpdate()->first();
                      $tax_mobile->balance -=$zipit_fees['tax'];
                      $tax_mobile->save();


                      $destination_mobile->balance -= $wallet_batch->transaction_amount + $zipit_fees['zimswitch_fee'] ;
                      $destination_mobile->save();

                    */

                    //Refund total debited.
                    $total = $wallet_batch->transaction_amount + $zipit_fees['acquirer_fee'] + $zipit_fees['tax'] + $zipit_fees['zimswitch_fee'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $zipit_fees['tax'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = TAX;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Tax settlement via wallet reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();


                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $zipit_fees['acquirer_fee'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = REVENUE;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Revenue settlement via wallet reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $wallet_batch->transaction_amount + $zipit_fees['acquirer_fee'] + $zipit_fees['zimswitch_fee'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = ZIMSWITCH;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Revenue settlement via wallet reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT send transaction'
                    ]);

                }

                //Balance On Us reversal
                if ($wallet_batch->txn_type_id == BALANCE_ON_US) {
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ON_US,
                        $wallet_batch->merchant_id
                    );

                    /* if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                         return response([
                             'code'          => '100',
                             'description'   => 'Source account does not have sufficient funds to perform a reversal',
                         ]);

                     }

                     // $destination_mobile->balance -= $balance_onus_fees['acquirer_fee'];
                     // $destination_mobile->save();

                    */

                    $value_management = new ManageValue();
                    $value_management->account_number = WALLET_REVENUE;
                    $value_management->amount = $balance_onus_fees['acquirer_fee'];
                    $value_management->txn_type = CREATE_VALUE;
                    $value_management->state = 1;
                    $value_management->initiated_by = 3;
                    $value_management->validated_by = 3;
                    $value_management->narration = 'Create E-Value';
                    $value_management->description = 'Create E-Value on balance fee reversal' . $request->account_number . 'reference:' . $request->transaction_batch_id;
                    $value_management->save();

                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $balance_onus_fees['acquirer_fee'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = REVENUE;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Reversal for:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $balance_onus_fees['acquirer_fee'];
                    $source_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance on us transaction.'
                    ]);


                }

                //Balance Off Us reversal
                if ($wallet_batch->txn_type_id == BALANCE_ENQUIRY_OFF_US) {
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ENQUIRY_OFF_US,
                        HQMERCHANT
                    );

                    /* if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                         return response([
                             'code'          => '100',
                             'description'   => 'Source account does not have sufficient funds to perform a reversal',
                         ]);

                     }
                     $destination_mobile->balance -= $balance_onus_fees['zimswitch_fee'];
                     $destination_mobile->save();
                     */

                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $balance_onus_fees['zimswitch_fee'];
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    $value_management = new ManageValue();
                    $value_management->account_number = WALLET_REVENUE;
                    $value_management->amount = $balance_onus_fees['zimswitch_fee'];
                    $value_management->txn_type = CREATE_VALUE;
                    $value_management->state = 1;
                    $value_management->initiated_by = 3;
                    $value_management->validated_by = 3;
                    $value_management->narration = 'Create E-Value';
                    $value_management->description = 'Create E-Value on balance fee reversal' . $request->account_number . 'reference:' . $request->transaction_batch_id;
                    $value_management->save();

                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $balance_onus_fees['acquirer_fee'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = ZIMSWITCH;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Reversal for:' . $request->transaction_batch_id;
                    $auto_deduction->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance remote on us transaction.'
                    ]);


                }

                //Purchase Off Us
                if ($wallet_batch->txn_type_id == PURCHASE_ON_US) {
                    $purchase_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_ON_US,
                        $wallet_batch->merchant_id
                    );

                    $less_mdr = $wallet_batch->transaction_amount - $purchase_fees['mdr'];
                    $less_revenue = $purchase_fees['mdr'] + $purchase_fees['acquirer_fee'];
                    $less_fees = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                    $credit_source = $wallet_batch->transaction_amount + $less_fees;

                    $source_deductions = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'] + $wallet_batch->transaction_amount;

                    /*  if($destination_mobile->balance <  $less_mdr){
                          return response([
                              'code'          => '100',
                              'description'   => 'Source account does not have sufficient funds to perform a reversal',
                          ]);

                      }

                      $tax_mobile = $tax->lockForUpdate()->first();
                      $tax_mobile->balance -= $purchase_fees['tax'];
                      $tax_mobile->save();

                      $revenue_mobile = $revenue->lockForUpdate()->first();
                      $revenue_mobile->balance -= $less_revenue;
                      $revenue_mobile->save();


                      $destination_mobile->balance -= $less_mdr;
                      $destination_mobile->save();

                    */

                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $source_deductions;
                    $source_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    //Revenue Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $purchase_fees['mdr'] + $purchase_fees['acquirer_fee'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = REVENUE;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet settlement on purchase reversal:' . $request->account_number . ' ' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    //Tax Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $purchase_fees['tax'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = TAX;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet settlement on purchase reversal:' . $request->account_number . ' ' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    $merchant_account = MerchantAccount::where('merchant_id', $wallet_batch->merchant_id)->first();
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $less_mdr;
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = TRUST_ACCOUNT;
                    $auto_deduction->destination_account = $merchant_account->account_number;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet settlement on purchase reversal:' . $request->account_number . ' ' . $request->transaction_batch_id;
                    $auto_deduction->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed purchase on us transaction.'
                    ]);


                }

                //Purchase Off Us Reversal
                if ($wallet_batch->txn_type_id == PURCHASE_OFF_US) {
                    $purchase_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_OFF_US,
                        HQMERCHANT
                    );

                    $less_revenue = $purchase_fees['interchange_fee'];
                    $less_fees = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                    $credit_source = $wallet_batch->transaction_amount + $less_fees;

                    $total = $wallet_batch->transaction_amount + $purchase_fees['acquirer_fee'] + $purchase_fees['zimswitch_fee'] + $purchase_fees['tax'] + $purchase_fees['interchange_fee'];


                    /*if($destination_mobile->balance <  $less_mdr){
                        return response([
                            'code'          => '100',
                            'description'   => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }
                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -= $purchase_fees['tax'];
                    $tax_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $less_revenue;
                    $revenue_mobile->save();


                    $destination_mobile->balance -= $less_mdr;
                    $destination_mobile->save();
                    */


                    //Tax Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $purchase_fees['tax'];
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = TAX;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet tax settlement on purchase (off us)reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    //Revenue Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $less_revenue;
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = REVENUE;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet revenue settlement on purchase:(off us) reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    //Revenue Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $total;
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = ZIMSWITCH;
                    $auto_deduction->destination_account = TRUST_ACCOUNT;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Wallet  settlement on purchase:(off us)reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance purchase remote on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == ZIPIT_RECEIVE) {

                    /*  if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                          return response([
                              'code'          => '100',
                              'description'   => 'Source account does not have sufficient funds to perform a reversal',
                          ]);

                      }

                      $source_mobile = $source->lockForUpdate()->first();
                      $source_mobile->balance += $wallet_batch->transaction_amount;
                      $source_mobile->save();

                    */


                    $destination_mobile->balance -= $wallet_batch->transaction_amount;;
                    $destination_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    //BR Settlement
                    $auto_deduction = new Deduct();
                    $auto_deduction->imei = '000';
                    $auto_deduction->amount = $wallet_batch->transaction_amount;;
                    $auto_deduction->merchant = HQMERCHANT;
                    $auto_deduction->source_account = TRUST_ACCOUNT;
                    $auto_deduction->destination_account = ZIMSWITCH;
                    $auto_deduction->txn_status = 'PENDING';
                    $auto_deduction->description = 'Credit wallet via wallet zipit receive reversal:' . $request->transaction_batch_id;
                    $auto_deduction->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT receive transaction'
                    ]);

                    //return $wallet_batch;
                }

                if ($wallet_batch->txn_type_id == SEND_MONEY) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        $wallet_batch->txn_type_id);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }

                    $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    /*$revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -=$wallet_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -=$wallet_batch->transaction_amount;
                    $destination_mobile->save();
                     */

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    SmsNotificationService::send(
                        '1',
                        '',
                        '',
                        '',
                        '',
                        $source_mobile->mobile,
                        "Transaction with reference: $request->transaction_batch_id  of $currency $wallet_batch->transaction_amount was successfully reversed.  Thank you for using " . env('SMS_SENDER') . ' .'

                    );


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed send money transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == CASH_IN) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        $wallet_batch->txn_type_id);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $wallet_batch->transaction_amount;
                    $source_mobile->commissions -= $wallet_fees['fee'];
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance += $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $destination_mobile->balance -= $wallet_batch->transaction_amount;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed cash in transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == CASH_OUT) {

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        CASH_OUT);

                    if ($destination_mobile->balance < $wallet_batch->transaction_amount) {
                        return response([
                            'code' => '100',
                            'description' => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }

                    //Credit Source
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance -= $wallet_batch->transaction_amount;
                    $source_mobile->commissions -= $wallet_fees['exclusive_agent_portion'];
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['exclusive_revenue_portion'];
                    $revenue_mobile->save();

                    $credit = $wallet_batch->transaction_amount + $wallet_fees['fee'];
                    $destination_mobile->balance -= $credit;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();


                    DB::commit();


                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed cash out transaction'
                    ]);

                }


                $wallet_fees = WalletFeesCalculatorService::calculateFees(
                    $wallet_batch->transaction_amount,
                    $wallet_batch->txn_type_id

                );

                if ($wallet_fees['fee_type'] == 'EXCLUSIVE') {
                    $source_mobile = $source->lockForUpdate()->first();
                    if ($source_mobile->wallet_type != 'BILLER') {

                        $total = $wallet_fees['fee'] + $wallet_fees['tax'] + $wallet_batch->transaction_amount;
                        $source_mobile = $source->lockForUpdate()->first();
                        $source_mobile->balance += $total;
                        $source_mobile->save();

                        $wallet_batch->reversed = 'REVERSED';
                        $wallet_batch->description = 'Original transaction was successfully reversed.';
                        $wallet_batch->save();

                        DB::commit();

                        return response([
                            'code' => '000',
                            'description' => 'Successfully reversed bill-payment transaction'
                        ]);

                    }


                    $total = $wallet_fees['fee'] + $wallet_fees['tax'] + $wallet_batch->transaction_amount;
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed bill-payment transaction'
                    ]);


                    /*if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                        return response([
                            'code'          => '100',
                            'description'   => 'Source account does not have sufficient funds to perform a reversal',
                        ]);

                    }


                    $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'] ;
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();

                    $revenue_mobile = $revenue->lockForUpdate()->first();
                    $revenue_mobile->balance -= $wallet_fees['fee'];
                    $revenue_mobile->save();

                    $tax_mobile = $tax->lockForUpdate()->first();
                    $tax_mobile->balance -=$wallet_fees['tax'];
                    $tax_mobile->save();


                    $destination_mobile->balance -=$wallet_batch->transaction_amount;
                    $destination_mobile->save();
                    */


                }


            } catch (\Exception $e) {


                DB::rollBack();
                Log::debug('Account Number:' . $request->account_number . ' ' . $e);
                WalletTransactions::create([
                    'txn_type_id' => SEND_MONEY,
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
                    'pan' => '',
                    'description' => 'Transaction was reversed for mobbile:' . $request->account_number,

                ]);

                return response([
                    'code' => '400',
                    'description' => 'Transaction was reversed',

                ]);
            }


        }

        $response = BRJob::where('tms_batch', $request->transaction_batch_id)->first();
        if (isset($response)) {

            $response->reversed = 'false';
            $response->save();

            Transactions::create([
                'txn_type_id'           => REVERSAL,
                'transaction_status'    => 1,
                'description'           => 'Reversal for batch' . $request->transaction_batch_id,
            ]);
            return response([
                'code' => '00',
                'description' => 'Reversal successfully processed.'
            ]);
        }


        $br_job = new BRJob();
        $br_job->txn_status = 'PENDING';
        $br_job->reversed = 'false';
        $br_job->status = 'DRAFT';
        $br_job->br_reference = $request->transaction_batch_id;
        $br_job->txn_type = REVERSAL;
        $br_job->save();

        Transactions::create([
            'txn_type_id'           => REVERSAL,
            'transaction_status'    => 1,
            'description'           => 'Reversal for batch' . $request->transaction_batch_id,
        ]);

        return response([
            'code' => '00',
            'description' => 'Reversal successfully processed.'
        ]);
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
