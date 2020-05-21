<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\ATMOTP;
use App\Devices;
use App\Jobs\NotifyBills;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\Jobs\WalletCashInJob;
use App\License;
use App\Merchant;
use App\PendingTxn;
use App\Services\AESEncryption;
use App\Services\FeesCalculatorService;
use App\Services\OTPService;
use App\Services\SmsNotificationService;
use App\Services\TokenService;
use App\Services\WalletFeesCalculatorService;
use App\Transaction;
use App\TransactionType;
use App\Wallet;
use App\WalletCOS;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;




class WalletATMWithdrawalController extends Controller
{

    public function generateOtp(Request $request){

        $validator = $this->generateOTPValidation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        DB::beginTransaction();
        try {
            $source = Wallet::whereMobile($request->source_mobile)->lockForUpdate()->first();
            $pin = AESEncryption::decrypt($request->pin);
            if ($pin["pin"] == false) {
                $source->auth_attempts += 1;
                $source->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid credentials'],400);
            }

            if (!Hash::check($pin["pin"], $source->pin)) {
                $source->auth_attempts += 1;
                $source->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid credentials'],400);
            }

            $wallet_fees = WalletFeesCalculatorService::calculateFees($request->amount,CASH_PICK_UP);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '100', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '116','description' => 'Insufficient funds',],400);
            }

            $limit_checker = $this->limit_checker($request->source_mobile,$source->wallet_cos_id,$source->balance,$total_deductions);
            if($limit_checker["code"] != 00){
                return response(['code' => $limit_checker["code"],'description' => $limit_checker["description"],],400);
            }

            $atm_code = OTPService::generateATMWithdrawlOtp();
            $update = new ATMOTP();
            $update->amount = $request->amount;
            $update->type = "ATM";
            $update->expired = 0;
            $update->authorization_otp = $atm_code["authorization_code"];
            $update->mobile = $request->source_mobile;
            $update->save();

            DB::commit();
            return response([
                'code'                       => '000',
                'description'                => 'ATM cash withdrawal OTP successfully generated.',
                'atm_code'                   => $atm_code["authorization_code"]
            ]);

        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Your request could be processed please try again later.','error_message' => $exception->getMessage(),],500);
        }

    }

    public function atmWithdrawal(Request $request){

        $validator = $this->atmWithdrawalValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);

        }

        if(TransactionType::find(CASH_PICK_UP)->status != "ACTIVE"){
            return response(['code' => '100', 'description' => 'Service under maintenance, please try again later.',],500);
        }

        DB::beginTransaction();
        try {

            $source = Wallet::whereMobile($request->source_mobile)->lockForUpdate()->first();
            $tax                 = Wallet::whereMobile(TAX)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();
            if(!isset($source)){
                return response(['code'=> '100', 'description' => 'Invalid mobile account.'],400);
            }

            //TODO -- SECURE OTP
            $otp = ATMOTP::whereAuthorizationOtp($request->otp)
                               ->whereMobile($request->source_mobile)
                               ->first();

            if(!isset($otp)){
                return response(['code'=> '100', 'description' => 'Invalid OTP.'],400);
            }

            if($otp->expired == 1){
                return response(['code'=> '102', 'description' => 'Invalid OTP.'],400);
            }

            $validity =  ATMOTP::whereAuthorizationOtp($request->otp)
                                 ->whereMobile($request->source_mobile)
                                 ->where('created_at', '>', Carbon::now()->subMinutes(30))
                                 ->first();

            if(!isset($validity)){
                $otp->expired =1;
                $otp->save();
                DB::commit();
                return response(['code'=> '100', 'description' => 'OTP expired.'],400);
            }


            $amount = $otp->amount;
            $wallet_fees = WalletFeesCalculatorService::calculateFees($amount,CASH_PICK_UP);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '100', 'description' => 'Invalid transaction amount.'],400);
            }

            $total_deductions = $wallet_fees["fees_charged"] + $amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '116','description' => 'Insufficient funds',],400);
            }

            $limit_checker = $this->limit_checker($request->source_mobile,$source->wallet_cos_id,$source->balance,$total_deductions);
            if($limit_checker["code"] != 00){
                return response(['code' => $limit_checker["code"],'description' => $limit_checker["description"],],400);
            }

            $reference = 'AT'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;

            $tax->balance +=$wallet_fees["tax"];
            $tax->save();
            $revenue->balance +=$wallet_fees["revenue_fees"];
            $revenue->save();


            $source->balance -= $total_deductions;
            $source->save();
            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = CASH_PICK_UP;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $amount;
            $transaction->debit_amount       = $amount;
            $transaction->credit_amount      = '0.00';
            $transaction->transaction_status = "APPROVED";
            $transaction->transaction_reference= $reference;
            $transaction->account_debited   = $request->source_mobile;
            $transaction->account_credited  = $request->destination_mobile;
            $transaction->description       = 'Transaction successfully processed.';
            $transaction->reversed          = 0;
            $transaction->transaction_identifier= $request->transaction_identifier;
            $transaction->balance_before    = $source_balance_before;
            $transaction->balance_after     = $source_balance_after;
            $transaction->save();

            $otp->expired =1;
            $otp->save();
            DB::commit();

            return response(['code' => '000', 'transaction_reference' => "$reference", 'description' => 'Atm cash withdrawal successful.']);


        }catch (\Exception $exception){
            DB::rollBack();
            if($exception->getCode() == "23000"){
                return response(['code' => '100', 'description' => 'Invalid transaction request.'],500);
            }
            return response(['code' => '100', 'description' => 'Your request could be processed please try again later.','error_message' => $exception->getMessage(),],500);
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

    protected function generateOTPValidation(Array $data)
    {
        return Validator::make($data, [
            'source_mobile'            => 'required | string |min:0|max:20',
            'pin'               => 'required',

        ]);
    }
    protected function atmWithdrawalValidator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile'            => 'required | string |min:0|max:20',
            'otp'                      => 'required',
            'transaction_identifier'   => 'required',

        ]);
    }

}

