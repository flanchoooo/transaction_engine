<?php

namespace App\Http\Controllers;

use App\Wallet;
use App\WalletTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReversalsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function reversals($id)
    {
          $wallet_batch = WalletTransactions::whereTransactionIdentifier($id)->first();

       if(!isset($wallet_batch)){
           return response(['code' => '01', 'description' => 'Unknown RRN'], 404);
       }
        if ($wallet_batch->reversed == 1) {
            return response(['code' => '01', 'description' => 'Transaction already reversed.'], 401);
        }

        DB::beginTransaction();
        try {
            $source = Wallet::whereAccountNumber($wallet_batch->account_debited)->lockForUpdate()->first();
            $revenue = Wallet::whereAccountNumber(REVENUE)->lockForUpdate()->first();
            $isw = Wallet::whereAccountNumber(ISW)->lockForUpdate()->first();
            $destination = Wallet::whereAccountNumber($wallet_batch->account_credited)->lockForUpdate()->first();

            if($wallet_batch->txn_type_id == 7){
                $revenue->balance -=$wallet_batch->debit_amount;
                $revenue->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Balance Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }

            if($wallet_batch->txn_type_id == 8){
                $isw->balance +=$wallet_batch->debit_amount;
                $isw->save();
                $destination->balance -= $wallet_batch->debit_amount;
                $destination->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Incoming Reversal with RRN: $id successfully processed. Balance : $destination->balance",]);
            }


            if($wallet_batch->txn_type_id == 9){
                $revenue->balance -=$wallet_batch->fees;
                $revenue->save();
                $isw->balance -=$wallet_batch->transaction_amount;
                $isw->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Outgoing Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }


            if($wallet_batch->txn_type_id == 9){
                $revenue->balance -=$wallet_batch->fees;
                $revenue->save();
                $isw->balance -=$wallet_batch->transaction_amount;
                $isw->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Outgoing Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }

            if($wallet_batch->txn_type_id == 10){
                $revenue->balance -=$wallet_batch->fees;
                $revenue->save();
                $isw->balance -=$wallet_batch->transaction_amount;
                $isw->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Purchase Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }


            if($wallet_batch->txn_type_id == 10){
                $revenue->balance -=$wallet_batch->fees;
                $revenue->save();
                $isw->balance -=$wallet_batch->transaction_amount;
                $isw->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Purchase Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }


            if($wallet_batch->txn_type_id == 11){
                $revenue->balance -=$wallet_batch->fees;
                $revenue->save();
                $destination->balance -=$wallet_batch->transaction_amount;
                $destination->save();
                $source->balance += $wallet_batch->debit_amount;
                $source->save();
                $wallet_batch->reversed = 1;
                $wallet_batch->save();
                DB::commit();
                return response(['code' => '00', 'description'   => "Internal Transfer Reversal with RRN: $id successfully processed. Balance : $source->balance",]);
            }


        } catch (\Exception $e) {
            DB::rollBack();
            return response(['code' => '00', 'description'   =>'Request could not be processed'. $e->getMessage(),401]);
        }






    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
