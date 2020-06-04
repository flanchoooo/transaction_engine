<?php

namespace App\Http\Controllers;

use App\LendingKYC;
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



class LendingKycController extends Controller
{
    protected  $password = 'TE$LAMOdelx';
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
                ],500);
            }

            return response([
                'code' => '100',
                'description' => 'Please contact support for assistance.',
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


            if($lendingProfile->status != "ACTIVE"){
                return response(['code' => '100', 'description' => 'Account is blocked',],201);
            }

            if($lendingProfile->auth_attempts > 2){
                $lendingProfile->status = "BLOCKED";
                $lendingProfile->save();
                DB::commit();
                return response(['code' => '100', 'description' => 'Account is blocked'],201);
            }


            if (Hash::check($request->password,$lendingProfile->password)) {
                $lendingProfile->auth_attempts = 0;
                $lendingProfile->verified = 1;
                $lendingProfile->save();
                DB::commit();
                return response(['code' => '000', 'description' => 'Login successful', 'data'=> $lendingProfile]);
            }

            $lendingProfile->auth_attempts+=1;
            $lendingProfile->save();
            DB::commit();
            return response(['code' => '100','description' => 'Invalid login credentials',],400);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Login Failed.',],500);
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
                return response(['code' => '100', 'description' => 'Invalid profile'],201);
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
            $lendingProfile->salary = $request->salary;
            $lendingProfile->address = $request->address;
            $lendingProfile->account_number = $request->account_number;
            $lendingProfile->save();
            DB::commit();
            return response(['code' => '000','description' => 'Profile successfully updated.', 'data'=> $lendingProfile]);

        }catch (\Exception $exception){
            DB::rollback();
            return response(['code' => '100', 'description' => 'Login Failed.',],500);
        }

    }

    function encrypt($plaintext, $password) {
        $method = "AES-256-CBC";
        $key = hash('sha256', $password, true);
        $iv = openssl_random_pseudo_bytes(16);

        $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        $hash = hash_hmac('sha256', $ciphertext . $iv, $key, true);

        return $iv . $hash . $ciphertext;
    }

    function decrypt($ivHashCiphertext, $password) {
        $method = "AES-256-CBC";
        $iv = substr($ivHashCiphertext, 0, 16);
        $hash = substr($ivHashCiphertext, 16, 32);
        $ciphertext = substr($ivHashCiphertext, 48);
        $key = hash('sha256', $password, true);

        if (!hash_equals(hash_hmac('sha256', $ciphertext . $iv, $key, true), $hash)) return null;

        return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
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



}

