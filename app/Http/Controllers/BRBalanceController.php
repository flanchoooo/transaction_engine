<?php /** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;


use App\Accounts;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\CheckBalanceService;
use App\Services\ElectricityService;
use App\Services\FeesCalculatorService;
use App\Services\HotRechargeService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transactions;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use Composer\DependencyResolver\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class BRBalanceController extends Controller
{


    public function br_balance(Request $request)
    {

        $validator = $this->br_balance_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $balance_res = CheckBalanceService::checkBalance($request->account_number);
        if($balance_res["code"] != '000'){
            return response([
                'code'          => $balance_res["code"],
                'description'   => $balance_res["description"],
            ]);
        }

         $amounts = BRJob::where('source_account',$request->account_number)
             ->whereIn('txn_status',['FAILED_','FAILED','PROCESSING','PENDING'])
             ->where('txn_type', '!=',  '156579070528551244')
             ->get()->sum(['amount_due']);

        $balance = $balance_res["available_balance"] - $amounts;
        return response([
            'available_balance'     => "$balance",
            'ledger_balance'        => "$balance",
        ]);

    }


    public function post_transaction(Request $request)
    {
        return "OK";
        $validator = $this->post_transaction_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        DB::beginTransaction();
        try {

            LoggingService::message(" REQUEST: $request->amount | $request->fees | $request->account_number  | $request->transaction_id | $request->mobile " );

            $amount_due = $request->amount + $request->fees;
            $now = Carbon::now();
            $reference = $now->format('mdHisu');
            $br_job                 = new BRJob();
            $br_job->txn_status     = 'PENDING';
            $br_job->status         = 'DRAFT';
            $br_job->version        = 0;
            $br_job->amount         = $request->amount;
            $br_job->amount_due     = $amount_due;
            $br_job->tms_batch      = $reference;
            $br_job->source_account = $request->account_number;
            $br_job->rrn            = $reference;
            $br_job->txn_type       = $request->transaction_id;
            $br_job->narration      = $request->narration;
            $br_job->mobile         = $request->mobile;
            $br_job->save();
            DB::commit();

            LoggingService::message("$request->amount | $request->fees | $request->account_number  | $request->transaction_id |$request->mobile " );

            return response([
                'code'              => '00',
                'description'       => 'Transaction successfully posted',
                'batch_id'          => $reference
            ]);


        } catch (QueryException $queryException) {
            $response = $queryException->getMessage();
            LoggingService::message(" Ecocash::::: $response");
            DB::rollBack();
            return response([
                'code'          => '100',
                'description'   => $queryException,
            ]);
        }

    }




    protected function br_balance_validation(Array $data){
        return Validator::make($data, [
            'account_number'    => 'required',
        ]);
    }

    protected function post_transaction_validation(Array $data){
        return Validator::make($data, [
            'amount'                => 'required',
            'account_number'        => 'required',
            'transaction_id'        => 'required',
            'mobile'                => 'required',
            'narration'             => 'required',
            'fees'                  => 'required',
        ]);
    }





}



























