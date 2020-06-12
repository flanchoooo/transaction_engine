<?php

namespace App\Http\Controllers;

use App\ATMOTP;
use App\LendingKYC;
use App\LoanHistory;
use App\OTP;
use App\Services\AESEncryption;
use App\Services\OTPService;
use App\Services\WalletFeesCalculatorService;
use App\TransactionType;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;



class LendingKycController extends Controller
{
    public function register(Request $request){
        $validator = $this->lendingKyc($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {

            $register = new LendingKYC();
            $register->initial_amount = $request->amount;
            $register->first_name = $request->first_name;
            $register->last_name = $request->last_name;
            $register->verified =0;
            $register->tenure =$request->tenure;
            $register->email = $request->email;
            $register->save();
            DB::commit();
            return response(['code'  => '000', 'description'   => 'Step 1 of loan application was successfully completed.',
            ]);

        }catch (\Exception $exception){
            DB::rollback();
            $code = $exception->getCode();
            if($code == "23000"){
                return response([
                    'code'          => '100',
                    'description'   => 'Email account is already taken.',
                    'error_message'   =>$exception->getMessage(),
                ],400);
            }

            return response([
                'code' => '100',
                'description' => 'Please contact support for assistance.',
                'error_message'   =>$exception->getMessage(),
            ],500);
        }

    }

    public function password(Request $request){
        $validator = $this->passwordValidation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {

            $register = LendingKYC::whereEmail($request->email)->first();
            if(!isset($register)){
               return response(['code'  => '100', 'description'   => 'Email not found',
                ],400);
            }

            if(isset($register->password)){
                return response(['code'  => '100', 'description'   => 'Profile already active.',
                ],400);
            }

            $register->password =Hash::make($request->password);
            $register->status ='ACTIVE';
            $register->save();
            DB::commit();
            return response(['code'  => '000', 'description'   => 'Password successfully set.',
            ]);

        }catch (\Exception $exception){
            DB::rollback();
            return response([
                'code' => '100',
                'description' => 'Please contact support for assistance.','error_message' => $exception->getMessage()
            ],500);
        }

    }

    public function send(Request $request){
        $validator = $this->sendMailValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        try {
         $mail = LendingKYC::whereEmail($request->email)->first();
         if(!isset($mail)){
             return response([
                 'code' => '000',
                 'description' => 'If your email is registered with us you will receive a verification.',
             ]);
         }
         $client = new Client();
         $result = $client->post(env('NOTIFY').'/api/mail',
             ['json' => [
                 'name' => $mail->first_name,
                 'email' => $request->email,
             ],
         ]);
         $result->getBody()->getContents();
         return response([
                'code' => '000',
                'description' => 'If your email is registered with us you will receive a verification.',
         ]);
        }catch (\Exception $exception){
            return response([
                'code' => '100',
                'description' => 'Please contact system administrator for assistance. ',
            ],500);
        }

    }

    public function questions(Request $request){
        try {
         $client = new Client();
         $result = $client->post(env('NOTIFY').'/api/lending/questions/email',
             ['json' => [
                 'sender_email' => $request->sender_email,
                 'question' => $request->question,
                 'sender_name' => $request->sender_name,
             ],
         ]);
         $result->getBody()->getContents();
         return response([
                'code' => '000',
                'description' => 'Email successfully sent.',
         ]);
        }catch (\Exception $exception){
            return response([
                'code' => '100',
                'description' => 'Please contact system administrator for assistance. ',
                'error_message' => $exception->getMessage()
            ],500);
        }

    }

    public function generateOtp(Request $request){
        $validator = $this->sendMailValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        try {
         $mail = LendingKYC::whereEmail($request->email)->first();
         $atm_code = OTPService::generateATMWithdrawlOtp();
         if(!isset($mail)){
             return response([
                 'code' => '000',
                 'description' => 'If your email is registered with us you will receive a verification.',
             ]);
         }
         $client = new Client();
         $result = $client->post(env('NOTIFY').'/api/mail',
             ['json' => [
                 'name' => $mail->first_name,
                 'email' => $request->email,
                 'otp' =>$atm_code["authorization_code"]
             ],
         ]);
         $result->getBody()->getContents();

            $update = new ATMOTP();
            $update->amount = $request->amount;
            $update->type = "ATM";
            $update->expired = 0;
            $update->authorization_otp = $atm_code["authorization_code"];
            $update->mobile =  $request->email;
            $update->save();
            DB::commit();
         return response([
                'code' => '000',
                'description' => 'If your email is registered with us you will receive a verification.',
         ]);
        }catch (\Exception $exception){
            return response([
                'code' => '100',
                'description' => 'Please contact system administrator for assistance. ',
            'error_message' => $exception->getMessage()],500);
        }

    }

    public function validateOtp(Request $request){

        $validator = $this->otpValidationValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);

        }

        DB::beginTransaction();
        try {
            //TODO -- SECURE OTP
            $otp = ATMOTP::whereAuthorizationOtp($request->otp)
                ->whereMobile($request->email)
                ->first();

            if(!isset($otp)){
                return response(['code'=> '100', 'description' => 'Invalid OTP.'],400);
            }

            if($otp->expired == 1){
                return response(['code'=> '102', 'description' => 'Invalid OTP.'],400);
            }

            $validity =  ATMOTP::whereAuthorizationOtp($request->otp)
                ->whereMobile($request->email)
                ->where('created_at', '>', Carbon::now()->subMinutes(30))
                ->first();

            if(!isset($validity)){
                $otp->expired =1;
                $otp->save();
                DB::commit();
                return response(['code'=> '100', 'description' => 'OTP expired.'],400);
            }

            $otp->expired =1;
            $otp->save();
            DB::commit();
            return response(['code' => '000', 'description'=> 'OTP successfully validated.']);
        }catch (\Exception $exception){
            DB::rollBack();
            if($exception->getCode() == "23000"){
                return response(['code' => '100', 'description' => 'Invalid transaction request.','error_message' => $exception->getMessage()],500);
            }
            return response(['code' => '100', 'description' => 'Your request could be processed please try again later.','error_message' => $exception->getMessage(),],500);
        }

    }

    public function login(Request $request){
        $validator = $this->loginValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {
            $lendingProfile = LendingKYC::whereEmail($request->email)->first();
            if(!isset($lendingProfile)){
                return response(['code' => '100', 'description' => 'Invalid login credentials'],400);
            }

            $loans = LoanHistory::whereApplicantId($lendingProfile->id)->get();
            if($lendingProfile->status != "ACTIVE"){
                return response(['code' => '100', 'description' => 'Account is blocked',],401);
            }

            if($lendingProfile->auth_attempts > 10){
                $lendingProfile->status = "BLOCKED";
                $lendingProfile->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Account is blocked'],401);
            }


            if (Hash::check($request->password,$lendingProfile->password)) {
                $lendingProfile->auth_attempts = 0;
                $lendingProfile->verified = 1;
                $lendingProfile->save();
                DB::commit();
                return response(['code' => '000', 'description' => 'Login successful', 'data'=> $lendingProfile,
                'loans' => $loans]);
            }

            $lendingProfile->auth_attempts+=1;
            $lendingProfile->save();
            DB::commit();
            return response(['code' => '100','description' => 'Incorrect username or password',],401);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100',
                'description' => 'Login Failed.',
                'error_message' => $exception->getMessage()],500);
        }

    }

    public function updateKyc(Request $request){
        $validator = $this->updateKycValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }
        DB::beginTransaction();
        try {
          $lendingProfile = LendingKYC::whereEmail($request->email)->first();
            if(!isset($lendingProfile)){
                return response(['code' => '100', 'description' => 'Invalid profile'],400);
            }
            $lendingProfile->first_name = $request->first_name;
            $lendingProfile->last_name = $request->last_name;
            $lendingProfile->email     = $request->email;
            $lendingProfile->national_id = $request->national_id;
            $lendingProfile->employee_reference = $request->employee_reference;
            $lendingProfile->employee_number = $request->employee_number;
            $lendingProfile->gender = $request->gender;
            $lendingProfile->initial_amount = $request->initial_amount;
            $lendingProfile->mobile = $request->mobile;
            $lendingProfile->dob = $request->dob;
            $lendingProfile->tenure = $request->tenure;
            $lendingProfile->salary = $request->salary;
            $lendingProfile->address = $request->address;
            $lendingProfile->account_number = $request->account_number;
            $lendingProfile->save();
            DB::commit();
            return response(['code' => '000','description' => 'Profile successfully updated.', 'data'=> $lendingProfile]);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100',
                            'description' => 'Failed to update kyc',
                            'error_message' => $exception->getMessage(),]

                ,500);
        }

    }


    protected function lendingKyc(Array $data)
    {
        return Validator::make($data, [
            'amount'            => 'required',
            'first_name'        => 'required | max:64',
            'last_name'         => 'required',
            'email'             => 'required',
        ]);


    }

    protected function passwordValidation(Array $data)
    {
        return Validator::make($data, [
            'password'         => 'required',
            'email'             => 'required',
        ]);


    }

    protected function sendMailValidator(Array $data)
    {
        return Validator::make($data, [
            'email'            => 'required',
        ]);


    }

    protected function updateKycValidator(Array $data)
    {
        return Validator::make($data, [
            'first_name'    => 'required',
            'last_name'     => 'required',
            'email'         => 'required',
            'national_id'   => 'required',
            'salary'        => 'required',
            'employee_reference'=> 'required',
            'employee_number'=> 'required',
            'gender'        => 'required',
            'bank'          => 'required',
            'mobile'        => 'required',
            'dob'           => 'required',
            'initial_amount'=> 'required',
            'address'       => 'required',
            'account_number'=> 'required'
        ]);
    }
    
    protected function loginValidator(Array $data)
    {
        return Validator::make($data, [
            'email'            => 'required',
            'password'         => 'required',
        ]);


    }

    protected function otpValidationValidator(Array $data)
    {
        return Validator::make($data, [
            'email'            => 'required',
            'otp'         => 'required',
        ]);


    }



}

