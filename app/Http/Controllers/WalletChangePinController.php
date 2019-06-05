<?php

namespace App\Http\Controllers;


use App\Accounts;
use App\Devices;
use App\Jobs\ProcessPendingTxns;
use App\Jobs\SaveTransaction;
use App\License;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Transaction;
use App\Wallet;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;




class WalletChangePinController extends Controller
{


    public function change_pin(Request $request)
    {


        //return $request->all();
        $validator = $this->change_pin_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        //Declarations
        $number_exists =  Wallet::where('mobile',$request->mobile)->first();
        $current_pin = $request->old_pin;
        $new_pin = $request->new_pin;




       if(!isset($number_exists)){

           return response([

               'code' => '01',
               'description' => 'Failed',
               'message'=>'Invalid Mobile Number'
           ]);
       }


        //Check Status of the account
        if($number_exists->state == '0') {

        return response([

            'code' => '02',
            'description' => 'Mobile account is blocked',

        ]);


    }

       //Check Current PIN

        if (!Hash::check($request->old_pin,$number_exists->pin)){



            $number_of_attempts =  $number_exists->auth_attempts + 1;
            $number_exists->auth_attempts = $number_of_attempts;
            $number_exists->save();

            if($number_of_attempts  > '2'){

                $number_exists->state = '0';
                $number_exists->save();

            }


            return response([

                'code' => '01',
                'description' => 'Authentication Failed',

            ]);

        }



        //Check Old Pin not same as New Pin
        if(strcmp($current_pin, $new_pin) == 0){

            return response([

                'code' => '01',
                'description' => 'Failed',
                'message'=>'Do not repeat old pin'
            ]);
        }



        //Check if Pin and Pin Confirm are the same
        if($request->new_pin != $request->confirm_new_pin){

            return response([

                'code' => '01',
                'description' => 'Failed',
                'message'=>'Pin do not match'
            ]);
        }




        // Change PIN
        $number_exists->pin = Hash::make($request->confirm_new_pin);
        $number_exists->save();

        return response([

            'code' => '00',
            'description' => 'Success',
            'message'=>'Pin change successful'
        ]);








    }




    protected function change_pin_validator(Array $data)
    {
        return Validator::make($data, [
            'mobile' => 'required',
            'old_pin' => 'required',
            'new_pin' => 'required|string|max:4|min:4',
            'confirm_new_pin' => 'required|string|max:4|min:4',



        ]);


    }


}

