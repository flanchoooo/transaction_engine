<?php

namespace App\Http\Controllers;

use App\LendingKYC;
use App\LoanClassofService;
use App\LoanEngine;
use App\LoanHistory;
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



class LoanAdministrationController extends Controller
{

    public function pendingApprovals(){
        try {
            return response(
                LoanHistory::whereIn('status',['PENDING APPROVAL','PENDING AUTHORIZATION'])->get()
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    public function updateLoansApplication(Request $request){
        $validator = $this->updateLoansApplicationValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }
        DB::beginTransaction();
        try {
            $updateLoan = LoanHistory::whereId($request->id)->lockForUpdate()->first();
            $loanApplicant = LendingKYC::find($updateLoan->applicant_id)->first();
            $loanClassOfService = LoanClassofService::find($updateLoan->loan_cos)->first();
            $affordability = $updateLoan->amount / $loanApplicant->salary;

            if($affordability > $loanClassOfService->affordability_ratio){
                $percentage = $loanClassOfService->affordability_ratio * 100;
                $narration =  'Loan amount should not exceed '.$percentage.' % '.' of your  net salary';
                $updateLoan->status = 'DECLINED';
                $updateLoan->description = $narration;
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan amount should not exceed '.$percentage.' % '.' of your  net salary',],200);
            }

            if($request->status == 'DECLINED'){
                $updateLoan->status = 'DECLINED';
                $updateLoan->description = 'Loan application declined.';
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan application declined.',],200);
            }


            if($request->status == 'PENDING AUTHORIZATION'){
                $updateLoan->status = 'PENDING AUTHORIZATION';
                $updateLoan->description = 'Loan documents and details have been approved.';
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan KYC documents successfully approved.',],200);
            }

            if($request->status == 'AUTHORIZED'){
                $loanFees = $this->simpleInterest($updateLoan->amount, $loanClassOfService->interest_rate,$updateLoan->loan_duration,$loanClassOfService->establishment_fee,$loanClassOfService->draw_down_fee);
                for ($x = 1; $x <= $updateLoan->loan_duration; $x++) {
                  $loanProfile = new LoanEngine();
                  $loanProfile->loan_amount = $updateLoan->amount;
                  $loanProfile->monthly_installments = $loanFees["monthly_installments"];
                  $loanProfile->installment_fee_inclusive = $loanFees["installment_fee_inclusive"];
                  $loanProfile->interest_earnings = $loanFees["interest_earnings"];
                  $loanProfile->establishment_fee = $loanFees["establishment_fee"];
                  $loanProfile->draw_down_fee = $loanFees["draw_down_fee"];
                  $loanProfile->status = 'PENDING PAYMENT';
                  $loanProfile->loan_id  =$request->id;
                  $loanProfile->time_period  = $x;
                  $loanProfile->loan_balance  -=$updateLoan->amount - $loanFees["installment_fee_inclusive"];
                  $loanProfile->save();
                  DB::commit();
                }
                $updateLoan->status = 'LOAN APPROVED';
                $updateLoan->description = 'Loan successfully approved.';
                $updateLoan->save();
                return response(['code' => '00', 'description' => 'Loan successfully approved.',],200);
            }
        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    function simpleInterest($principal,$interest,$time,$establishmentFee,$drawDownFee){
        $period =  $time /12;
        $interestAmount = $principal * $period * $interest;
        $instalments = ($interestAmount + $principal) / $time;
        $interest_earnings = $interestAmount /$time;
        $establishmentFeeInstallment = $establishmentFee / $time;
        $drawDownFeeInstallmentFee = $drawDownFee / $time;
        $instalmentsFees = $establishmentFeeInstallment + $drawDownFeeInstallmentFee +$instalments;
        return array(
            'monthly_installments'  => $instalments,
            'installment_fee_inclusive' => $instalmentsFees,
            'interest_earnings'     => $interest_earnings,
            'establishment_fee'     => $establishmentFeeInstallment,
            'draw_down_fee'         => $drawDownFeeInstallmentFee
        );

    }

    public function profile(Request $request){
        $validator = $this->profileValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        try {

            $loanId = LoanHistory::whereId($request->applicant_details)->first();
            $applicant = LendingKYC::whereId($loanId->applicant_id)->first();
            $repayment = LoanEngine::whereLoanId($loanId->id)->get();
            $sum = LoanEngine::whereLoanId($loanId->id)
                                ->whereStatus('PAID')->sum('installment_fee_inclusive');
            if(!isset($applicant)){
                return response(['code' => '100', 'description' => 'Applicant not found'],400);
            }
            $balance = $loanId->amount - $sum;
            return response([
                'loan_profile' => $loanId,
                'loan_applicant' =>$applicant,
                'loan_repayment' => $repayment,
                'loan_balance' =>  $balance
            ]);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function loanBook(Request $request){
        $validator = $this->profileValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        try {
            $sum = LoanEngine::whereStatus('PENDING PAYMENT')->sum('installment_fee_inclusive');
            $int = LoanEngine::whereStatus('PAID')->sum('interest_earnings');
            return response([
                'loan_book_position' => $sum,
                'interest_earnings' =>$int,
            ]);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function payment(Request $request){
        $validator = $this->paymentValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        DB::beginTransaction();
        try {
            $repayment = LoanEngine::whereId($request->loan_repayment_id)->where('status' ,'=', 'PENDING PAYMENT')->lockForUpdate()->first();
            $repayment->status = 'PAID';
            $repayment->description = 'Loan payment successfully processed.';
            $repayment->save();
            DB::commit();
            return response(['code' => '00', 'description' =>'Loan successfully paid']);
        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }


    protected function updateLoansApplicationValidator(Array $data)
    {
        return Validator::make($data, [
            'id'            => 'required',
            'status'        => 'required',
        ]);


    }

    protected function profileValidator(Array $data)
    {
        return Validator::make($data, [
            'applicant_details'            => 'required',
        ]);


    }

    protected function paymentValidator(Array $data)
    {
        return Validator::make($data, [
            'loan_repayment_id'            => 'required',
        ]);


    }



}

