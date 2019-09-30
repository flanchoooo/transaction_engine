<?php

namespace App\Http\Controllers;




use App\TransactionType;
use App\WalletHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;




class WalletSupportController extends Controller
{


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request){

        $validator = $this->history_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);

        }


      return $history =  WalletHistory::where('account_debited',$request->source_mobile)
                                ->orWhere('account_credited',$request->source_mobile)->take(10)->get();



       $result =[];

        foreach ($history as $item){
           return $txn_type =  TransactionType::find($item->txn_type_id);
            $temp = array(
                'trx_date'      =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'value_date'    =>\Carbon\Carbon::parse($txn_type->created_at)->format('d/m/Y'),
                'particulars'   => $txn_type->name,
                'debit'         => $item->total_debited,
                'credit'        => $item->total_credited,
                'closing'       => $item->balance_after_txn,
                'operator_id'   =>"",
                'supervisor_id' =>""
            );

            array_push($result,$temp);
        }


       return response([
           'code'                       => '00',
           'description'                => 'success',
           'time'                       => Carbon::now()->format('ymdhis'),
           'error_list'                 => [],
           'account_balance_list'       => $result,
       ]);


    }








    protected function history_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',


        ]);


    }


}

