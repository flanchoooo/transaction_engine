<?php

namespace App\Http\Controllers;




use App\WalletHistory;
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


    $history =  WalletHistory::where('account',$request->source_mobile)->take(10)->get();


        return response([

            "code" => '00',
            "description" => 'success',
            'balance' => $history

        ]);

    }








    protected function history_validator(Array $data)
    {
        return Validator::make($data, [
            'source_mobile' => 'required',


        ]);


    }


}

