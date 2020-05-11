<?php

namespace App\Http\Controllers;




use App\Emv;
use App\Logs;
use App\LuhnCards;
use App\Services\LoggingService;
use App\TransactionType;
use App\Wallet;
use App\WalletHistory;
use App\WalletInfo;
use App\WalletTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use mysql_xdevapi\Exception;
use Symfony\Component\Finder\Finder;


class WalletSupportController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }

        try {
            $history_credit = WalletHistory::where('account_credited', $request->source_mobile)
                ->where('credit_amount', '>', 0)
                ->orderBy('id', 'desc')->take(10)
                ->get();

            $history_debit = WalletHistory::where('account_debited', $request->source_mobile)
                ->where('debit_amount', '>', 0)
                ->orderBy('id', 'desc')->take(10)
                ->get();

            $result = [];
            $total_history = $history_credit->merge($history_debit)->sortByDesc('id');
            foreach ($total_history as $item) {
                $temp = array(
                    'id' => $item['id'],
                    'transaction_type' => TransactionType::find($item['txn_type_id'])->name,
                    'date' => \Carbon\Carbon::parse($item->created_at)->format('d M Y'),
                    'debit' => $item['transaction_amount'],
                    'credit' => $item['transaction_amount'],
                    'balance_before' => $item['balance_before'],
                    'balance_after' => $item['balance_after'],
                    'fees' => $item['fees'],
                    'tax' => $item['tax'],
                    'reference' => $item['transaction_reference'],
                );
                array_push($result, $temp);
            }
            return response(['code' => '00', 'description' => 'success', 'time' => Carbon::now()->format('ymdhis'), 'data' => array_reverse($result),]);
        } catch (\Exception $exception) {
            return response(['code' => '100', 'description' => 'Failed to fetch transaction records',]);
        }
    }

    public function virtualCard(Request $request)
    {
        $validator = $this->virtualCardValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        DB::beginTransaction();
        try {

            $pan = $this->completed_number(691988, 16);
            $luhn = new LuhnCards();
            $luhn->track_1 = $pan;
            $luhn->track_2 = $pan . '=' . $request->expiry_year . $request->expiry_month;
            $luhn->status = "ACTIVE";
            $luhn->account_number = $request->source_mobile;
            $luhn->issue_month = $request->issue_month;
            $luhn->issue_year = $request->issue_year;
            $luhn->save();
            DB::commit();

            return response([
                'code'          => '000',
                'description'   => 'Virtual card successfully generated',
                'virtual_pan'   => $pan

            ]);
        } catch (\Exception $exception) {
            DB::rollBack();
            if ($exception->getCode() == "23000") {
                return response(['code' => '100', 'description' => 'Virtual card already exists for this account..']);
            }
            return response(['code' => '100', 'description' => 'Virtual card could be created.',]);
        }

    }

    public function completed_number($prefix, $length)
    {
        $ccnumber = $prefix;
        # generate digits
        while (strlen($ccnumber) < ($length - 1)) {
            $ccnumber .= rand(0, 9);
        }
        # Calculate sum
        $sum = 0;
        $pos = 0;
        $reversedCCnumber = strrev($ccnumber);
        while ($pos < $length - 1) {
            $odd = $reversedCCnumber[$pos] * 2;
            if ($odd > 9) {
                $odd -= 9;
            }
            $sum += $odd;
            if ($pos != ($length - 2)) {
                $sum += $reversedCCnumber[$pos + 1];
            }
            $pos += 2;
        }
        # Calculate check digit
        $checkdigit = ((floor($sum / 10) + 1) * 10 - $sum) % 10;
        $ccnumber .= $checkdigit;
        return $ccnumber;
    }

    public function link(Request $request){
        $validator = $this->linkerValidation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        DB::beginTransaction();
        try {
            $source  = Wallet::whereMobile($request->source_mobile)->lockForUpdate()->first();
            $emv_card = new Emv();
            $emv_card->cvv = $request->cvv;
            $emv_card->pan = $request->pan;
            $emv_card->status = "ACTIVE";
            $emv_card->expiry_date = $request->expiry_date;
            $emv_card->linked = 1;
            $emv_card->wallet_id = $source->id;
            $emv_card->save();
            DB::commit();
            return response(['code' => '000', 'description' => 'Card successfully linked to wallet.']);
        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '000', 'description' => 'Card already linked to wallet.']);
        }
    }

    public function delink(Request $request){
        $validator = $this->updatelinkerValidation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        DB::beginTransaction();
        try {
            $wallet = Wallet::whereMobile($request->source_mobile)->first();

            $emv_card = Emv::whereWalletId($wallet->id)->first();
            $emv_card->cvv = $request->cvv;
            $emv_card->pan = $request->pan;
            $emv_card->status = $request->status;
            $emv_card->expiry_date = $request->expiry_date;
            $emv_card->linked = $request->linked;
            $emv_card->save();
           DB::commit();

            return response(['code' => '000', 'description' => 'Card successfully updated.']);
        }catch (\Exception $exception){
            return $exception;
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Card profile could not be updated.']);
        }
    }


    protected function history_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
        ]);
    }

    protected function virtualCardValidator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'expiry_year'   => 'required',
            'expiry_month'   => 'required',
            'issue_year'    => 'required',
            'issue_month'   => 'required',
        ]);
    }

    protected function linkerValidation(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'cvv'           => 'required',
            'pan'           => 'required',
            'expiry_date'   => 'required |string |min:0|max:4',
        ]);
    }

    protected function updatelinkerValidation(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',
            'cvv'           => 'required',
            'pan'           => 'required',
            'linked'         => 'required',
            'status'         => 'required',
            'expiry_date'   => 'required |string |min:0|max:4',
        ]);
    }




}

