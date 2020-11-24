<?php

namespace App\Http\Controllers;



use App\LuhnCards;
use App\Services\AESCtrl;
use App\Services\AESEncryption;
use App\Services\WalletFeesCalculatorService;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;




class CBAController extends Controller
{


    public function balanceEnquiry(Request $request){
        $validator = $this->balanceEnquiryValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        if($cardDetails->status != "ACTIVE"){
            return response(['code' => '62', 'description' => 'Card profile not active',],401);
        }

        if(is_null($cardDetails->wallet_id)){
            return response(['code' => '53', 'description' => 'Card not linked to an account profile',],401);
        }

        DB::beginTransaction();
        try {

            $source              = Wallet::whereId($cardDetails->wallet_id)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();

            if(!isset($source)){
                return response(['code' => '53', 'description' => 'Account not found.',],401);            }


            if(!isset($revenue)){
                return response(['code' => '100', 'description' => 'Revenue account configuration is missing.',],400);
            }


           /* $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $source->auth_attempts+=1;
                $source->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid credentials'],400);
            }

            if (!Hash::check($pin["pin"], $source->pin)) {
                $source->auth_attempts += 1;
                $source->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid credentials'],400);
            }*/

            $wallet_fees = WalletFeesCalculatorService::calculateFees(1,7);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '100', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '116','description' => 'Insufficient funds',],400);
            }

            $reference = 'BE'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;

            $source->balance -= $total_deductions;
            $source->save();

            $revenue->balance +=$wallet_fees["fees_charged"];
            $revenue->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = 7;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $request->amount;
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->source_mobile;
            $transaction->account_credited  = $request->destination_mobile;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->save();

            DB::commit();
            return response(['code' => '00', 'transaction_reference' => "$reference", 'balance' => $source_balance_after]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::debug('Send Money Exceptions:',$errorLog);
            if($e->getCode() == "23000"){
                 return response(['code' => '05', 'description' => 'Invalid transaction request.','error_message' =>$e->getMessage()],500);
             }
            return response(['code' => '05', 'description' => 'Transaction was reversed','error_message' =>$e->getMessage(),],500);
        }

    }

    public function incomingTransfer(Request $request){
        $validator = $this->incomingTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        if($cardDetails->status != "ACTIVE"){
            return response(['code' => '62', 'description' => 'Card profile not active',],401);
        }

        if(is_null($cardDetails->wallet_id)){
            return response(['code' => '53', 'description' => 'Card not linked to an account profile',],401);
        }

        DB::beginTransaction();
        try {

            $destination         = Wallet::whereId($cardDetails->wallet_id)->lockForUpdate()->first();
            $isw                 = Wallet::whereMobile(ISW)->lockForUpdate()->first();

            if(!isset($destination)){
                return response(['code' => '53', 'description' => 'Account not found.',],401);            }


           /* $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $source->auth_attempts+=1;
                $source->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid credentials'],400);
            }

            if (!Hash::check($pin["pin"], $source->pin)) {
                $source->auth_attempts += 1;
                $source->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid credentials'],400);
            }*/


            $destination_balance = $destination->balance;
            $destination_balance_after  = $destination_balance  + $request->amount ;
            $reference = 'CT'.Carbon::now()->timestamp;

            $destination->balance += $request->amount;
            $destination->save();

            $isw->balance -=$request->amount;
            $isw->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = 8;
            $transaction->tax               = 0.00;
            $transaction->fees              =  0.00;
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $request->amount;
            $transaction->credit_amount     = 0.00;
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $isw->mobile;
            $transaction->account_credited  = $request->pan;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $reference;
            $transaction->balance_before    = $destination_balance;
            $transaction->balance_after     = $destination_balance_after;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->save();

            DB::commit();
            return response(['code' => '00', 'transaction_reference' => "$reference", 'description' => "Credit transfer successfully processed, new balance : $destination_balance_after "]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::debug('Send Money Exceptions:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Invalid transaction request please provide unique transaction reference.','error_message' =>$e->getMessage()],500);
             }
            return response(['code' => '05', 'description' => 'Transaction was reversed','error_message' =>$e->getMessage(),],500);
        }

    }

    public function outgoingTransfer(Request $request){
        $validator = $this->incomingTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        if($cardDetails->status != "ACTIVE"){
            return response(['code' => '62', 'description' => 'Card profile not active',],401);
        }

        if(is_null($cardDetails->wallet_id)){
            return response(['code' => '53', 'description' => 'Card not linked to an account profile',],401);
        }

        DB::beginTransaction();
        try {

            $source              = Wallet::whereId($cardDetails->wallet_id)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();
            $isw                 = Wallet::whereMobile(ISW)->lockForUpdate()->first();

            if(!isset($source)){
                return response(['code' => '53', 'description' => 'Account not found.',],401);            }


            if(!isset($revenue)){
                return response(['code' => '100', 'description' => 'Revenue account configuration is missing.',],400);
            }


            /* $pin = AESEncryption::decrypt($request->pin);
             if($pin["pin"] == false){
                 $source->auth_attempts+=1;
                 $source->save();
                 DB::commit();
                 return response(['code' => '807', 'description' => 'Invalid credentials'],400);
             }

             if (!Hash::check($pin["pin"], $source->pin)) {
                 $source->auth_attempts += 1;
                 $source->save();
                 DB::commit();
                 return response(['code' => '100', 'description' => 'Invalid credentials'],400);
             }*/

            $wallet_fees = WalletFeesCalculatorService::calculateFees($request->amount,9);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '05', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '51','description' => 'Insufficient funds',],400);
            }

            $reference = 'OT'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;

            $source->balance -= $total_deductions;
            $source->save();

            $isw->balance += $request->amount;
            $isw->save();

            $revenue->balance +=$wallet_fees["fees_charged"];
            $revenue->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = 9;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $request->amount;
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->pan;
            $transaction->account_credited  =$isw->mobile;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            DB::commit();
            return response(['code' => '00', 'transaction_reference' => "$reference", 'description' => "Debit transfer successfully processed, new balance : $source_balance_after "]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::debug('Send Money Exceptions:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Invalid transaction request please provide unique transaction reference.','error_message' =>$e->getMessage()],500);
            }
            return response(['code' => '05', 'description' => 'Transaction was reversed','error_message' =>$e->getMessage(),],500);
        }

    }

    public function purchase(Request $request){
        $validator = $this->incomingTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        if($cardDetails->status != "ACTIVE"){
            return response(['code' => '62', 'description' => 'Card profile not active',],401);
        }

        if(is_null($cardDetails->wallet_id)){
            return response(['code' => '53', 'description' => 'Card not linked to an account profile',],401);
        }

        DB::beginTransaction();
        try {

            $source              = Wallet::whereId($cardDetails->wallet_id)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();
            $isw                 = Wallet::whereMobile(ISW)->lockForUpdate()->first();

            if(!isset($source)){
                return response(['code' => '53', 'description' => 'Account not found.',],401);            }


            if(!isset($revenue)){
                return response(['code' => '100', 'description' => 'Revenue account configuration is missing.',],400);
            }


            /* $pin = AESEncryption::decrypt($request->pin);
             if($pin["pin"] == false){
                 $source->auth_attempts+=1;
                 $source->save();
                 DB::commit();
                 return response(['code' => '807', 'description' => 'Invalid credentials'],400);
             }

             if (!Hash::check($pin["pin"], $source->pin)) {
                 $source->auth_attempts += 1;
                 $source->save();
                 DB::commit();
                 return response(['code' => '100', 'description' => 'Invalid credentials'],400);
             }*/

            $wallet_fees = WalletFeesCalculatorService::calculateFees($request->amount,10);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '05', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '51','description' => 'Insufficient funds',],400);
            }

            $reference = 'PC'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;

            $source->balance -= $total_deductions;
            $source->save();

            $isw->balance += $request->amount;
            $isw->save();

            $revenue->balance +=$wallet_fees["fees_charged"];
            $revenue->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = 10;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $request->amount;
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->pan;
            $transaction->account_credited  =$isw->mobile;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            DB::commit();
            return response(['code' => '00', 'transaction_reference' => "$reference", 'description' => "Purchase transaction successfully processed, new balance : $source_balance_after"]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::debug('Send Money Exceptions:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Invalid transaction request please provide unique transaction reference.','error_message' =>$e->getMessage()],500);
            }
            return response(['code' => '05', 'description' => 'Transaction was reversed','error_message' =>$e->getMessage(),],500);
        }

    }

    public function limit_checker($source_mobile,$wallet_cos_id,$current_balance,$amount){

        $wallet_cos = WalletCOS::find($wallet_cos_id);
        $monthly_spent =  WalletTransactions::where('account_debited',$source_mobile)
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->where('reversed', '!=', 1)
            ->whereIn('txn_type_id', [SEND_MONEY,CASH_PICK_UP])
            ->sum('transaction_amount');


        if($wallet_cos->maximum_monthly <  $monthly_spent){
            return array('code' => '902', 'description' => 'Wallet monthly limit reached.');
        }

        $daily_spent =  WalletTransactions::where('account_debited',$source_mobile)
            ->where('created_at', '>', Carbon::now()->subDays(1))
            ->where('reversed', '!=', 1)
            ->whereIn('txn_type_id', [SEND_MONEY,CASH_PICK_UP])
            ->sum('transaction_amount');

        if($wallet_cos->maximum_daily <  $daily_spent){
            return array('code' => '902', 'description' => 'Wallet  daily limit reached.');
        }

        $total = $current_balance - $amount;
        if($total <= 0){
            return array('code' => '100', 'description' => 'Overdraft is not permitted.'
            );
        }

        return array(
            'code' => '00',
            'description' => 'success'
        );

    }

    public function checkExpiry($startDate,$endDate){
        $now = Carbon::now();
        $start_date = Carbon::parse($startDate);
        $end_date = Carbon::parse($endDate);

        if($now->between($start_date,$end_date)){
            return array('Coupon is Active');
        } else {
            return 'Coupon is Expired';
        }
    }

    protected function balanceEnquiryValidator(Array $data)
    {
        return Validator::make($data, [
            'pan'                   => 'required',
        ]);
    }

    protected function incomingTransferValidator(Array $data)
    {
        return Validator::make($data, [
            'transaction_identifier'=> 'required',
            'pan'                   => 'required',
            'amount'                => 'required|integer|min:0',
        ]);
    }


}

