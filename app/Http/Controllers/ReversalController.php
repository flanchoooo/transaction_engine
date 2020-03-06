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
                $destination = Wallet::whereMobile($wallet_batch->account_credited);
                $currency = CURRENCY;

                $destination_mobile = $destination->lockForUpdate()->first();

                if ($wallet_batch->txn_type_id == BANK_TO_WALLET) {
                    $total = $wallet_batch->transaction_amount;
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance -= $total;
                    $source_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed bank to wallet transaction'
                    ]);
                }

                //Balance On Us reversal
                if ($wallet_batch->txn_type_id == BALANCE_ON_US) {
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                        '0.00', '0.00', BALANCE_ON_US,
                        $wallet_batch->merchant_id,$wallet_batch->account_debited
                    );

                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $balance_onus_fees['acquirer_fee'];
                    $br_job->source_account = REVENUE;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $request->transaction_batch_id;
                    $br_job->narration = 'WALLET | Reversal for on us balance enquiry |' . $request->transaction_batch_id;
                    $br_job->rrn =$request->transaction_batch_id;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

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
                        HQMERCHANT,$wallet_batch->account_debited
                    );


                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $balance_onus_fees['zimswitch_fee'];
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    $reference = $request->transaction_batch_id;
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $balance_onus_fees['acquirer_fee'];;
                    $br_job->source_account = ZIMSWITCH;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration =  'WALLET | Reversal for balance off us |'  . $request->transaction_batch_id;
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance remote on us transaction.'
                    ]);


                }

                //Purchase Off Us
                if ($wallet_batch->txn_type_id == PURCHASE_ON_US) {
                    $fees_charged = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_ON_US,
                        $wallet_batch->merchant_id,$wallet_batch->account_debited
                    );

                     $wallet_batch->transaction_amount;
                    $source_deductions = $fees_charged['tax'] + $fees_charged['acquirer_fee'] + $wallet_batch->transaction_amount;
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $source_deductions;
                    $source_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    //Revenue Settlement
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $fees_charged['acquirer_fee'] +  $fees_charged['mdr'];
                    $br_job->source_account = REVENUE;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $request->transaction_batch_id;
                    $br_job->narration ='WALLET |Purchase on us, revenue reversal |' . $request->account_number . ' ' . $request->transaction_batch_id;
                    $br_job->rrn =$request->transaction_batch_id;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();


                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $fees_charged['tax'];
                    $br_job->source_account = TAX;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $request->transaction_batch_id;
                    $br_job->narration ='WALLET| Tax reversal , purchase on us | ' . $request->account_number . ' ' .  $request->transaction_batch_id;
                    $br_job->rrn =$request->transaction_batch_id;;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    $merchant_account = MerchantAccount::where('merchant_id', $wallet_batch->merchant_id)->first();
                    $total = $wallet_batch->transaction_amount - $fees_charged['mdr'];
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $total;
                    $br_job->source_account = $merchant_account->account_number;
                    $br_job->destination_account =  TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $request->transaction_batch_id;
                    $br_job->narration ='WALLET| Transaction amount reversal, on purchase on us | ' . $request->account_number . ' ' . $request->transaction_batch_id;
                    $br_job->rrn =$request->transaction_batch_id;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed purchase on us transaction.'
                    ]);

                }

                if ($wallet_batch->txn_type_id == PURCHASE_OFF_US) {
                    $purchase_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount, '0.00', PURCHASE_OFF_US,
                        HQMERCHANT,$wallet_batch->account_debited
                    );



                    $total = $wallet_batch->transaction_amount + $purchase_fees['acquirer_fee'] + $purchase_fees['zimswitch_fee'] + $purchase_fees['tax'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    //Tax Settlement
                    $reference = $wallet_batch->transaction_batch_id;
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $purchase_fees['tax'];;
                    $br_job->source_account = TAX;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration =  "WALLET | Tax settlement reversal | $reference | $request->account_number |  $request->rrn";
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();


                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $purchase_fees['acquirer_fee'];;
                    $br_job->source_account = REVENUE;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration =  "WALLET | Revenue settlement  revenue | $reference | $request->account_number |  $request->rrn";
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();


                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $purchase_fees['zimswitch_fee'];;
                    $br_job->source_account = ZIMSWITCH;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration =  "WALLET | Switch fee settlement reversal | $reference | $request->account_number |  $request->rrn";
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();


                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $wallet_batch->transaction_amount;
                    $br_job->source_account = TRUST_ACCOUNT;
                    $br_job->destination_account = ZIMSWITCH;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch = $reference;
                    $br_job->narration = "WALLET | Pos purchase reversal | $request->rrn |  $request->account_number |  $reference";
                    $br_job->rrn =$reference;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();




                    DB::commit();

                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed balance purchase remote on us transaction.'
                    ]);


                }

                if ($wallet_batch->txn_type_id == ZIPIT_RECEIVE) {
                    $destination_mobile->balance -= $wallet_batch->transaction_amount;;
                    $destination_mobile->save();

                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    $reference = $request->transaction_batch_id;
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $wallet_batch->transaction_amount;;
                    $br_job->source_account = TRUST_ACCOUNT;
                    $br_job->destination_account = ZIMSWITCH;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch =$request->transaction_batch_id;
                    $br_job->narration ="WALLET | Zipit receive  reversal | $reference | RRN:$request->rrn";
                    $br_job->rrn =$request->rrn;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT receive transaction'
                    ]);

                }

                if ($wallet_batch->txn_type_id == ZIPIT_SEND) {

                     $zipit_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                        '0.00',
                        ZIPIT_SEND,
                        HQMERCHANT,$wallet_batch->account_debited
                    );



                    //Refund total debited.
                    $total = $wallet_batch->transaction_amount + $zipit_fees['acquirer_fee'] + $zipit_fees['tax'] + $zipit_fees['zimswitch_fee'];
                    $source_mobile = $source->lockForUpdate()->first();
                    $source_mobile->balance += $total;
                    $source_mobile->save();


                    $wallet_batch->reversed = 'REVERSED';
                    $wallet_batch->transaction_status = '3';
                    $wallet_batch->description = 'Original transaction was successfully reversed.';
                    $wallet_batch->save();

                    $reference = $request->transaction_batch_id;
                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $zipit_fees['tax'];;
                    $br_job->source_account = TAX;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch =$reference;
                    $br_job->narration ="WALLET | Zipit send tax reversal | $reference | RRN:$request->rrn" ;
                    $br_job->rrn =$request->rrn;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount = $zipit_fees['zimswitch_fee'];
                    $br_job->source_account = REVENUE;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch =$reference;
                    $br_job->narration ="WALLET | Zipit send revenue reversal | $reference | RRN:$request->rrn" ;
                    $br_job->rrn =$request->rrn;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    $br_job = new BRJob();
                    $br_job->txn_status = 'PENDING';
                    $br_job->amount =  $wallet_batch->transaction_amount ;
                    $br_job->source_account = ZIMSWITCH;
                    $br_job->destination_account = TRUST_ACCOUNT;
                    $br_job->status = 'DRAFT';
                    $br_job->version = 0;
                    $br_job->tms_batch =$reference;
                    $br_job->narration = "WALLET | Transaction amount , Zipit Send reversal | $reference  RRN:$request->rrn"  ;
                    $br_job->rrn =$request->rrn;
                    $br_job->txn_type = WALLET_SETTLEMENT;
                    $br_job->save();

                    DB::commit();
                    return response([
                        'code' => '000',
                        'description' => 'Successfully reversed ZIPIT send transaction'
                    ]);

                }




                //Purchase Off Us Reversal
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

                //Any Other Bill Payment Reversal
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


                }


            } catch (\Exception $e) {

                DB::rollBack();
                WalletTransactions::create([
                    'txn_type_id'       => REVERSAL,
                    'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,

                ]);

                return response([
                    'code'          => '100',
                    'description' => 'Failed to process transaction',

                ]);
            }


        }

        $response = BRJob::where('tms_batch', $request->transaction_batch_id)->first();


        if (isset($response)) {
            if(is_null($response->br_reference)){
                $response->txn_status = 'COMPLETED';
                $response->reversed = 'true';
                $response->response = 'Transaction successfully reversed before posting to CBS';
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
        $br_job->version = 0;
        $request->tms_batch = $request->transaction_batch_id;
        $br_job->txn_type = REVERSAL;
        $br_job->save();

        $transaction = Transactions::where('batch_id', $request->transaction_batch_id )->first();
        $transaction->reversed = 1;
        $transaction->save();

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
