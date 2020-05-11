<?php

namespace App\Http\Controllers;



use App\Fee;
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
use Illuminate\Support\Facades\Validator;




class WalletPayMerchantController extends Controller
{
    public function payMerchant(Request $request){
        $validator = $this->wallet_send_money($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        if($request->source_mobile == $request->destination_mobile) {
            return response(['code' => '100', 'description' => 'Transaction request is not permitted.',]);
        }
        if(TransactionType::find(SEND_MONEY)->status != "ACTIVE"){
            return response(['code' => '100', 'description' => 'Service under maintenance, please try again later.',]);
        }
        DB::beginTransaction();
        try {

            $source              = Wallet::whereMobile($request->source_mobile)->lockForUpdate()->first();
            $destination         = Wallet::whereMobile($request->destination_mobile)->lockForUpdate()->first();
            $tax                 = Wallet::whereMobile(TAX)->lockForUpdate()->first();
            $revenue             = Wallet::whereMobile(REVENUE)->lockForUpdate()->first();

            $pin = AESEncryption::decrypt($request->pin);
            if($pin["pin"] == false){
                $source->auth_attempts+=1;
                $source->save();
                DB::commit();
                return response(['code' => '807', 'description' => 'Invalid credentials']);
            }

            if (!Hash::check($pin["pin"], $source->pin)) {
                $source->auth_attempts += 1;
                $source->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Invalid credentials']);
            }

            $wallet_fees = WalletFeesCalculatorService::calculateFees($request->amount,PAY_MERCHANT);
            if($wallet_fees["code"] != "00"){
                return response(['code'=> '100', 'description' => 'Invalid transaction amount.']);
            }

             $total_deductions = $wallet_fees["fees_charged"] + $request->amount;
            if ($total_deductions > $source->balance) {
                return response(['code' => '116','description' => 'Insufficient funds',]);
            }

            $limit_checker = $this->limit_checker($request->source_mobile,$source->wallet_cos_id,$source->balance,$total_deductions);
            if($limit_checker["code"] != 00){
                return response(['code' => $limit_checker["code"],'description' => $limit_checker["description"],]);
            }

            $reference = 'PM'.Carbon::now()->timestamp;
            $source_balance_before = $source->balance;
            $source_balance_after  = $source->balance - $total_deductions;
            $destination_balance_before = $destination->balance;
            $destination_balance_after  = $destination->balance + $request->amount;

            $merchant_service_fee = $request->amount  * ($destination->merchant_service_fee / 100);
            $revenues = $wallet_fees["revenue_fees"] + $merchant_service_fee;
            $merchant_settlement_amount = $request->amount - $merchant_service_fee;


            $source->balance -= $total_deductions;
            $source->save();
            $destination->balance += $merchant_settlement_amount;
            $destination->save();

            $tax->balance +=$wallet_fees["tax"];
            $tax->save();
            $revenue->balance +=$revenues;
            $revenue->save();

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = PAY_MERCHANT;
            $transaction->tax               =  $wallet_fees['tax'];
            $transaction->fees              =  $wallet_fees['revenue_fees'];
            $transaction->transaction_amount= $request->amount;
            $transaction->debit_amount      = $request->amount;
            $transaction->credit_amount     = '0.00';
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

            $transaction                    = new WalletTransactions();
            $transaction->txn_type_id       = PAYMENT_RECEIVED;
            $transaction->tax               = '0.0000';
            $transaction->fees              = $merchant_service_fee;
            $transaction->transaction_amount= $request->amount;
            $transaction->credit_amount     =$merchant_settlement_amount;
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
            return response(['code' => '000', 'transaction_reference' => "$reference", 'description' => 'Transfer successfully processed.']);
        } catch (\Exception $e) {
            return $e;
            DB::rollBack();
             if($e->getCode() == "23000"){
                 return response(['code' => '100', 'description' => 'Invalid transaction request.']);
             }
            return response(['code' => '100', 'description' => 'Transaction was reversed',]);
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

    protected function wallet_send_money(Array $data)
    {
        return Validator::make($data, [
            'destination_mobile'    => 'required',
            'source_mobile'         => 'required',
            'amount'                => 'required|integer|min:0',
        ]);
    }


}

