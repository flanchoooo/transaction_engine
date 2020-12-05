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
        Log::info('Balance Request:',$request->all());
        $validator = $this->balanceEnquiryValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->pan)->orWhere('account_number', $request->pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        $transaction = TransactionType::find(7);
        if(!isset($transaction)){
            return response(['code' => '05', 'description' => 'Unknown transaction type.',],400);
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
            $revenue             = Wallet::whereAccountNumber(REVENUE)->lockForUpdate()->first();

            if(!isset($source)){
                $source = Wallet::whereMobile($request->pan)->lockForUpdate()->first();
                if(!isset($source)){
                    return response(['code' => '53', 'description' => 'Account not found.',],404);
                }

            }


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
                return response(['code'=> '05', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '51','description' => 'Insufficient funds',],400);
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
            $transaction->debit_amount      = $wallet_fees["fees_charged"];
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->pan;
            $transaction->account_credited  = $revenue->account_number;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->save();

            DB::commit();
            $data = ['code' => '00', 'transaction_reference' => "$request->transaction_identifier", 'balance' => $source_balance_after];
            Log::info('Balance Request:', $data);
            return response($data);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::info('Balance:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Please provide unique transaction identifier.'],400);
             }
            return response(['code' => '05', 'description' => 'Transaction request was not processed.'],400);
        }

    }

    public function incomingTransfer(Request $request){
        Log::info('Incoming Request:',$request->all());
        $validator = $this->incomingTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->to_pan)->orWhere('account_number', $request->to_pan)->first();
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
            $isw                 = Wallet::whereAccountNumber(ISW)->lockForUpdate()->first();

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
            $transaction->account_credited  = $request->to_pan;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $reference;
            $transaction->balance_before    = $destination_balance;
            $transaction->balance_after     = $destination_balance_after;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->save();

            DB::commit();
            $data = ['code' => '00', 'transaction_reference' => "$request->transaction_identifier", 'description' => "Credit transfer successfully processed, new balance : $destination_balance_after "];
            Log::info('Incoming Request:', $data);
            return response($data);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::info('Balance:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Please provide unique transaction identifier.'],400);
            }
            return response(['code' => '05', 'description' => 'Transaction request was not processed.'],400);
        }

    }

    public function outgoingTransfer(Request $request){
        Log::info('Outgoing Request:',$request->all());
        $validator = $this->outTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->from_pan)->orWhere('account_number', $request->from_pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        $transaction = TransactionType::find(9);
        if(!isset($transaction)){
            return response(['code' => '05', 'description' => 'Unknown transaction type.',],400);
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
            $revenue             = Wallet::whereAccountNumber(REVENUE)->lockForUpdate()->first();
            $isw                 = Wallet::whereAccountNumber(ISW)->lockForUpdate()->first();

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
            $transaction->debit_amount      = $total_deductions;
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->from_pan;
            $transaction->account_credited  =$isw->account_number;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            DB::commit();
            $data = ['code' => '00', 'transaction_reference' => "$request->transaction_identifier", 'description' => "Debit transfer successfully processed, new balance : $source_balance_after "];
            Log::info('Outgoing Request:',$data);
            return response($data);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::info('Balance:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Please provide unique transaction identifier.'],400);
            }
            return response(['code' => '05', 'description' => 'Transaction request was not processed.'],400);
        }

    }

    public function purchase(Request $request){
        Log::info('Purchase Request:',$request->all());
        $validator = $this->outTransferValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        $cardDetails = LuhnCards::where('track_1',$request->from_pan)->orWhere('account_number', $request->from_pan)->first();
        if(!isset($cardDetails)){
            return response(['code' => '42', 'description' => 'Card profile not found.',],404);
        }

        $transaction = TransactionType::find(10);
        if(!isset($transaction)){
            return response(['code' => '05', 'description' => 'Unknown transaction type.',],400);
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
            $revenue             = Wallet::whereAccountNumber(REVENUE)->lockForUpdate()->first();
            $isw                 = Wallet::whereAccountNumber(ISW)->lockForUpdate()->first();

            if(!isset($source)){
                return response(['code' => '53', 'description' => 'Account not found.',],401);            }


            if(!isset($revenue)){
                return response(['code' => '05', 'description' => 'Revenue account configuration is missing.',],400);
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
            $transaction->debit_amount      = $total_deductions;
            $transaction->credit_amount     = $wallet_fees["fees_charged"];
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->from_pan;
            $transaction->account_credited  =$isw->account_number;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            DB::commit();
            $data = ['code' => '00', 'transaction_reference' => "$request->transaction_identifier", 'description' => "Purchase transaction successfully processed, new balance : $source_balance_after"];
            Log::info('Outgoing Request:',$data);
            return response($data);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::info('Balance:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Pleae provide unique transaction identifier.'],400);
            }
            return response(['code' => '05', 'description' => 'Transaction request was not processed.'],400);
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

    public function sendMoney(Request $request){
        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        if($request->from_pan == $request->to_pan) {
            return response(['code' => '05', 'description' => 'Transaction request is not permitted.',],400);
        }
        $transaction = TransactionType::find(11);
        if(!isset($transaction)){
            return response(['code' => '05', 'description' => 'Unknown transaction type.',],400);
        }

        if($transaction->status !='ACTIVE'){
                return response(['code' => '100', 'description' => 'Service is currently unavailable, please try again later.',],401);
        }


        DB::beginTransaction();
        try {

            $source              = Wallet::whereAccountNumber($request->from_pan)->lockForUpdate()->first();
            $destination         = Wallet::whereAccountNumber($request->to_pan)->lockForUpdate()->first();
            $tax                 = Wallet::whereMobile(TAX)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();


            if(!isset($source)){
                return response(['code' => '05', 'description' => 'Source account is not registered.',],400);
            }

            if(!isset($destination)){
                return response(['code' => '05', 'description' => 'Destination account is not registered.',],400);
            }

            if(!isset($tax)){
                return response(['code' => '05', 'description' => 'Tax account configuration is missing.',],400);
            }

            if(!isset($revenue)){
                return response(['code' => '05', 'description' => 'Revenue account configuration is missing.',],400);
            }


          /*  $pin = AESEncryption::decrypt($request->pin);
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

            $wallet_fees = WalletFeesCalculatorService::calculateFees($request->amount,11);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '05', 'description' => 'Invalid transaction amount.'],400);
            }


            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '51','description' => 'Insufficient funds',],400);
            }

           /* $limit_checker = $this->limits_checker($request->source_mobile,$source->wallet_cos_id,$source->balance,$total_deductions);
            if($limit_checker["code"] != 00){
                return response(['code' =>'05','description' => $limit_checker["description"],],400);
            }*/


            $reference = 'SM'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;
            $destination_balance_before = $destination->balance;
            $destination_balance_after  = $destination->balance + $request->amount;

            $source->balance -= $total_deductions;
            $source->save();
            $destination->balance += $request->amount;
            $destination->save();

            $tax->balance +=$wallet_fees["tax"];
            $tax->save();
            $revenue->balance +=$wallet_fees["revenue_fees"];
            $revenue->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = 11;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $total_deductions;
            $transaction->credit_amount     = '0.00';
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->from_pan;
            $transaction->account_credited  = $request->to_pan;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = MONEY_RECEIVED;
            $transaction->tax               = '0.0000';
            $transaction->fees              = '0.0000';
            $transaction->transaction_amount= $request->amount;
            $transaction->credit_amount     = $request->amount;
            $transaction->debit_amount      = '0.00';
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->source_mobile;
            $transaction->account_credited  = $request->destination_mobile;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->balance_before    = $destination_balance_before;
            $transaction->balance_after     = $destination_balance_after;
            $transaction->save();

            DB::commit();
            $data = ['code' => '00', 'transaction_reference' => "$request->transaction_identifier", 'description' => 'Transfer successfully processed.'];
            Log::info('Internal Transfer:', $data);
            return response([$data]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorLog = array(
                'requests'  => $request->all(),
                'error_log' => $e->getMessage()
            );

            Log::info('Internal Transfer:',$errorLog);
            if($e->getCode() == "23000"){
                return response(['code' => '05', 'description' => 'Pleae provide unique transaction identifier.'],400);
            }
            return response(['code' => '05', 'description' => 'Transaction request was not processed.'],400);
        }

    }

    public function limits_checker($source_mobile,$wallet_cos_id,$current_balance,$amount){

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
    protected function balanceEnquiryValidator(Array $data)
    {
        return Validator::make($data, [
            'pan'                   => 'required',
            'transaction_identifier'=> 'required',
        ]);
    }

    protected function incomingTransferValidator(Array $data)
    {
        return Validator::make($data, [
            'transaction_identifier'=> 'required',
            'to_pan'                   => 'required',
            'amount'                => 'required|integer|min:0',
        ]);
    }

    protected function outTransferValidator(Array $data)
    {
        return Validator::make($data, [
            'transaction_identifier'=> 'required',
            'from_pan'                   => 'required',
            'amount'                => 'required|integer|min:0',
        ]);
    }

    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'to_pan'    => 'required',
            'from_pan'         => 'required',
            'amount'                => 'required|integer|min:0',
            'transaction_identifier'=> 'required',
        ]);
    }


}

