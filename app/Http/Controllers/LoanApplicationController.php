<?php

namespace App\Http\Controllers;

use App\LendingKYC;
use App\Loans;
use App\Services\AESEncryption;
use App\Services\OTPService;
use App\Wallet;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;



class LoanApplicationController extends Controller
{

    public function apply(Request $request){


       // return Loans::all();
        $validator = $this->applyLoanValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {

            $lendingProfile = LendingKYC::whereEmail($request->email)->first();
            if(!isset($lendingProfile)){
                return response(['code' => '100', 'description' => 'Invalid profile']);
            }

            if($lendingProfile->amount > 0){
                $application = new Loans();
                $application->amount = $request->amount;

            }


            $application = new Loans();
            $application->amount = $request->amount;



            return Loans::all();

            $register = new LendingKYC();
            $register->initial_amount = $request->amount;
            $register->first_name = $request->first_name;
            $register->last_name = $request->last_name;
            $register->verified =0;
            $register->email = $request->email;
            $register->save();
            DB::commit();
            return response([
                'code'          => '000',
                'description'   => 'Step 1 of loan application was successfully completed.',
            ]);

        }catch (\Exception $exception){
            DB::rollback();
            $code = $exception->getCode();
            if($code == "23000"){
                return response([
                    'code'          => '100',
                    'description'   => 'Email account is already taken.',
                ]);
            }

            return response([
                'code' => '100',
                'description' => 'Please contact support for assistance.',
            ]);
        }

    }



    protected function applyLoanValidator(Array $data)
    {
        return Validator::make($data, [
            'amount'            => 'required',
            'email'             => 'required',
            'email'             => 'required',
        ]);


    }



}

