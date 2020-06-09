<?php

namespace App\Http\Controllers;

use App\Bank;
use App\LendingKYC;
use App\LoanClassofService;
use App\LoanHistory;
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

    function calcPmt( $amt , $i, $term ) {
        $int = $i/1200;
        $int1 = 1+$int;
        $r1 = pow($int1, $term);
        $pmt = $amt*($int*$r1)/($r1-1);
        return $pmt;
    }

    public function apply(Request $request){
        $validator = $this->applyLoanValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {

            $lendingProfile = LendingKYC::whereEmail($request->email)->first();
            if(!isset($lendingProfile)){
                return response(['code' => '100', 'description' => 'Invalid profile'],400);
            }

            $loanClass = LoanClassofService::find($request->loan_cos);
            $repayment = $this->calcPmt($request->amount,$loanClass->interest_rate,$request->loan_tenure);
            if($lendingProfile->initial_amount > 0){
                $application = new Loans();
                $application->applicant_id = $lendingProfile->id;
                $application->amount = $lendingProfile->initial_amount;
                $application->loan_cos = $request->loan_cos;
                $application->status = 'PENDING DOCUMENT UPLOAD';
                $application->employee_reference = 'XEC';
                $application->tenure = $lendingProfile->tenure;
                $application->description = 'Loan application successfully submitted, pending document uploads';
                $lendingProfile->initial_amount =0;
                $lendingProfile->save();
                $application->save();
                DB::commit();
                return response([
                    'code'                  => '000',
                    'description'           => 'Loan application successfully submitted, please proceed to upload supporting documents.',
                    'monthly_installments'  => $repayment,
                    'draw_down_fee'         => $loanClass->draw_down_fee,
                    'establishment_fee'     => $loanClass->establishment_fee,
                    'data'                  =>$application
                ]);

            }

            $application = new Loans();
            $application->applicant_id = $lendingProfile->id;
            $application->amount = $request->amount;
            $application->loan_cos = $request->loan_cos;
            $application->status = 'PENDING APPROVAL';
            $application->employee_reference = 'XEC';
            $application->description = 'Loan application successfully submitted';
            $application->tenure  = $request->tenure;
            $lendingProfile->initial_amount =0;
            $lendingProfile->save();
            $application->save();
            DB::commit();

            return response([
                'code'                  => '000',
                'description'           => 'Loan application successfully submitted, please proceed to upload supporting documents.',
                'monthly_installments'  => $repayment,
                'draw_down_fee'         => $loanClass->draw_down_fee,
                'establishment_fee'     => $loanClass->establishment_fee,
                'data'                  =>$application
            ]);
        }catch (\Exception $exception){
            DB::rollback();
            $code = $exception->getCode();
            if($code == "23000"){
                return response([
                    'code' => '100', 'description'  => 'Email account is already taken.',
                    'error_message' => $exception->getMessage()],400);
            }
            return response([
                'code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
        }
    }

    public function history(Request $request){
        $validator = $this->historyValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {
            $user = LendingKYC::whereEmail($request->email)->first();
            if(!isset($user)){
                return response([
                    'code' => '100', 'description' => 'Please contact support for assistance.',],401);
            }
            $loan_history = LoanHistory::whereApplicantId($user->id)
                ->where('status','!=', 'CANCELLED')
                ->get();
            return response([
                'code' => '000',
                'data' => $loan_history,],200);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    public function pendingApproval(Request $request){
        $validator = $this->historyValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {
            $user = LendingKYC::whereEmail($request->email)->first();
            if(!isset($user)){
                return response([
                    'code' => '100', 'description' => 'Please contact support for assistance.',],400);
            }
            $loan_history = LoanHistory::whereApplicantId($user->id)
                ->where('status','=', 'PENDING APPROVAL')
                ->get();
            return response([
                'code' => '000',
                'data' => $loan_history,],200);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.','error_message'
            => $exception->getMessage()],500);
        }
    }

    public function cancel(Request $request){
        $validator = $this->cancelValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {

            $loan_history = LoanHistory::whereId($request->loan_id)->first();
            $loan_history->status = 'CANCELLED';
            $loan_history->description = 'Loan application successfully cancelled by applicant.';
            $loan_history->save();
            DB::commit();
            return response([
                'code' => '000',
                'data' => $loan_history,],200);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    public function upload(Request $request){
        $validator = $this->uploadValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {

            $upload = LoanHistory::whereId($request->loan_id)->first();
            if(!isset($upload)){
                return response(['code' => '100', 'description' => 'Invalid loan reference.',],201);
            }

            $national_id = $upload->id.$request->file('national_id')->getClientOriginalName();
            $contract = $upload->id.$request->file('contract')->getClientOriginalName();
            $payslip = $upload->id.$request->file('payslip')->getClientOriginalName();
            $statement = $upload->id.$request->file('statement')->getClientOriginalName();

            $request->file('national_id')->move(storage_path(),$national_id);
            $request->file('contract')->move(storage_path(),$contract);
            $request->file('payslip')->move(storage_path(),$payslip);
            $request->file('statement')->move(storage_path(),$statement);

            $upload->photo = $national_id;
            $upload->letter_of_employment = $contract;
            $upload->payslip = $payslip;
            $upload->bank_statement = $statement;
            $upload->save();
            DB::commit();

            return response([
                'code' => '000',
                'description' => "Documents uploaded successfully",],200);

        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    public function banks(){
        return response([Bank::all()]);
    }



    protected function applyLoanValidator(Array $data)
    {
        return Validator::make($data, [
            'amount'            => 'required',
            'email'             => 'required',
            'loan_cos'          => 'required',
            'loan_tenure'       => 'required',
        ]);


    }

    protected function historyValidator(Array $data)
    {
        return Validator::make($data, [
            'email'            => 'required',
        ]);


    }

    protected function cancelValidator(Array $data)
    {
        return Validator::make($data, [
            'loan_id'            => 'required',
        ]);


    }

    protected function uploadValidator(Array $data)
    {
        return Validator::make($data, [
             'loan_id'            => 'required',
             'national_id'        => 'required',
            'statement'     => 'required',
            'contract'=> 'required',
             'payslip'            => 'required',

        ]);


    }



}

