<?php
namespace App\Http\Controllers;


use App\Devices;
use App\Services\BalanceEnquiryService;
use App\Services\CardCheckerService;
use App\Services\CheckBalanceService;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Services\ApiTokenValidity;
use App\Services\TransactionRecorder;
use App\Traces;;

use App\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class PINController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */
    public function pin(Request $request)
    {

        //APIKEY VALIDATION
        $result = ApiTokenValidity::tokenValidity($request->token);

        if ($result === 'TRUE'){

            //API INPUT VALIDATION
            $validator = $this->balance_enquiry($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }




            //Devices Checker
            $device_existence = Devices::where('imei', $request->imei)->count();
            if ($device_existence === 0){

                return response(['code' => '58', 'Description' => 'Devices does not exists']);

            }

            $status = Devices::all()->where('imei', $request->imei);

            foreach ($status as $state) {
                //Check Device State
                if ($state->state == '0') {

                    return response(['code' => '58', 'Description' => 'Devices is not active']);

                }


               $request_s =  strlen(CardCheckerService::checkCard($request->card_number));

                if($request_s > 2){

                    return response(['code'=> '00']);

                }else{

                    $request_s =  CardCheckerService::checkCard($request->card_number);

                    return response(['code'=> $request_s ]);
                }


            }



        }else{

            return response()->json(['error' => 'Unauthorized'], 401);
        }


       }










    protected function balance_enquiry(Array $data)
    {
        return Validator::make($data, [
            'imei' => 'required',
            'card_number' => 'required',


        ]);
    }









}