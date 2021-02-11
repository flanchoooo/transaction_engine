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

    public function disbursements(){
        try {
            return response(
                LoanHistory::whereStatus('AUTHORIZED')->get()
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',],500);
        }
    }

    public function disburse(){

        DB::beginTransaction();
        try {

                $disburse = LoanHistory::whereStatus('AUTHORIZED')->get();
                foreach($disburse as $item){
                    $item->status = 'DISBURSED';
                    $item->description = 'Loan successfully disbursed.';
                    $item->save();
                    DB::commit();
                }
            return response(['code' => '00', 'description' => 'Loan successfully disbursed.',],200);

        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
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
                $narration =  'Loan amount should not exceed '.$percentage.' % '.' of applicant  net salary';
                $updateLoan->status = 'DECLINED';
                $updateLoan->description = $narration;
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan amount should not exceed '.$percentage.' % '.' of applicant  net salary',],200);
            }

            if($request->status == 'DECLINED'){
                $updateLoan->status = 'DECLINED';
                $updateLoan->description = 'Loan application declined.';
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan application declined.',],200);
            }


            if($request->status == 'PENDING AUTHORIZATION'){
                $total = LoanHistory::whereApplicantId($updateLoan->applicant_id)
                    ->where('status','=','AUTHORIZED')->sum('amount');
                $total_loans = $total + $updateLoan->amount;
                $affordability = $total_loans/ $loanApplicant->salary;
                if($affordability > $loanClassOfService->affordability_ratio){
                    $percentage = $loanClassOfService->affordability_ratio * 100;
                    $narration =  'Total loan profiles should not exceed '.$percentage.' % '.' of applicant  net salary';
                    $updateLoan->status = 'DECLINED';
                    $updateLoan->description = $narration;
                    $updateLoan->save();
                    DB::commit();
                    return response(['code' => '00', 'description' =>  'Total loan profiles should not exceed '.$percentage.' % '.' of applicant  net salary',],200);
                }

                $updateLoan->status = 'PENDING AUTHORIZATION';
                $updateLoan->description = 'Loan documents and details have been approved.';
                $updateLoan->save();
                DB::commit();
                return response(['code' => '00', 'description' => 'Loan KYC documents successfully approved.',],200);
            }

            if($request->status == 'AUTHORIZED'){
                $loanFees = $this->simpleInterest($updateLoan->amount, $loanClassOfService->interest_rate,$updateLoan->tenure,$loanClassOfService->establishment_fee,$loanClassOfService->draw_down_fee);
                for ($x = 1; $x <= $updateLoan->tenure; $x++) {
                  $loanProfile = new LoanEngine();
                  $loanProfile->loan_amount = $updateLoan->amount;
                  $loanProfile->monthly_installments = $loanFees["monthly_installments"];
                  $loanProfile->installment_fee_inclusive = $loanFees["installment_fee_inclusive"];
                  $loanProfile->interest_earnings = $loanFees["interest_earnings"];
                  $loanProfile->establishment_fee = $loanFees["establishment_fee"];
                  $loanProfile->draw_down_fee = $loanFees["draw_down_fee"];
                  $loanProfile->status = 'PENDING PAYMENT';
                  $loanProfile->loan_id  =$request->id;
                  $loanProfile->applicant_id  =$updateLoan->applicant_id;
                  $loanProfile->time_period  = $x;
                  $loanProfile->save();
                  DB::commit();
                }
                $updateLoan->status = 'AUTHORIZED';
                $updateLoan->description = 'Loan successfully approved.';
                $updateLoan->save();
                return response(['code' => '00', 'description' => 'Loan successfully authorized.',],200);
            }
        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
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
             $loansByApplicant = LoanHistory::whereApplicantId($applicant->id)->whereIn('status',['PENDING APPROVAL','PENDING AUTHORIZATION','AUTHORIZED'])->get();
             $sum = LoanEngine::whereApplicantId($loanId->applicant_id)->whereStatus('PAID')->sum('installment_fee_inclusive');
             $loans_sum = LoanHistory::whereApplicantId($loanId->applicant_id)->whereStatus('AUTHORIZED')->sum('amount');

            $balance = $loans_sum - $sum;

            $data = [
                'loan_profile'      => $loansByApplicant,
                'loan_applicant'    => $applicant,
                'loan_balance'      =>  $balance
            ];
            return response(
                $data
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function loanBook(){
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
            $loan_repayment_record = LoanEngine::where('id',$request->loan_repayment_id)->lockForUpdate()->first();
            if($loan_repayment_record->status == 'PAID'){
                return response(['code' => '00', 'description' =>'Loan already paid.']);
            }

                $loan_repayment_record->status = 'PAID';
                $loan_repayment_record->loan_payment = $loan_repayment_record->monthly_installments;
                $loan_repayment_record->loan_payment = $loan_repayment_record->monthly_installments;
                $loan_repayment_record->description = 'Loan payment successfully processed.';
                $loan_repayment_record->save();
                DB::commit();
                return response(['code' => '00', 'description' =>'Loan successfully paid']);

        }catch (\Exception $exception){
            DB::rollBack();
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function loanProfile(Request $request){
        $validator = $this->loanprofileValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        try {

            return response(
                LoanEngine::whereLoanId($request->loan_id)->get()
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function search(Request $request){
        $validator = $this->profileValidator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()],400);
        }

        if (filter_var($request->applicant_details, FILTER_VALIDATE_EMAIL)) {
            $filter = 'email';
        }else{
            $filter = 'mobile';
        }
        try {
            $applicant = LendingKYC::where("$filter",$request->applicant_details)->first();
            if(!isset($applicant)){
                return response(['code' => '100', 'description' => 'Applicant not found.',]);
            }
            $loansByApplicant = LoanHistory::whereApplicantId($applicant->id)->whereIn('status',['PENDING APPROVAL','PENDING AUTHORIZATION','AUTHORIZED'])->get();
            $sumPaid = LoanEngine::whereApplicantId($applicant->id)->whereStatus('PAID')->sum('loan_payment');
            $loans_sum = LoanHistory::whereApplicantId($applicant->id)->whereStatus('AUTHORIZED')->sum('amount');

            $data = [
                'code'              => '00',
                'description'       => 'Success',
                'loan_profile'      => $loansByApplicant,
                'loan_applicant'    => $applicant,
                'loan_balance'      => $loans_sum - $sumPaid
            ];
            return response(
                $data
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.', 'error_message' => $exception->getMessage()],500);
        }
    }

    public function getLoanCOS(){
        try {
            return response(
                LoanClassofService::all()
            );
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
        }
    }

    public function createLoanCOS(Request $request){
        DB::beginTransaction();
        try {
               $loanCOS = new LoanClassofService();
               $loanCOS->minimum_amount =$request->minimum_amount;
               $loanCOS->maximum_amount =$request->maximum_amount;
               $loanCOS->establishment_fee =$request->establishment_fee;
               $loanCOS->draw_down_fee =$request->draw_down_fee;
               $loanCOS->interest_rate =$request->interest_rate;
               $loanCOS->affordability_ratio =$request->affordability_ratio;
               $loanCOS->formula =$request->formula;
               $loanCOS->interest_rate =$request->interest_rate;
               $loanCOS->save();
               DB::commit();
              return response(['code' => '00', 'description' => 'Loan Class of Service successfully created.',
              ],200);

        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
        }
    }

    public function cosById(Request $request){
        DB::beginTransaction();
        try {
               $loanCOS = LoanClassofService::whereId($request->id)->first();
               return response($loanCOS);
        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
        }
    }

    public function updateCos(Request $request){
        DB::beginTransaction();
        try {
            $loanCOS = LoanClassofService::whereId($request->id)->first();
            $loanCOS->minimum_amount =$request->minimum_amount;
            $loanCOS->maximum_amount =$request->maximum_amount;
            $loanCOS->establishment_fee =$request->establishment_fee;
            $loanCOS->draw_down_fee =$request->draw_down_fee;
            $loanCOS->interest_rate =$request->interest_rate;
            $loanCOS->affordability_ratio =$request->affordability_ratio;
            $loanCOS->formula =$request->formula;
            $loanCOS->interest_rate =$request->interest_rate;
            $loanCOS->save();
            DB::commit();
            return response(['code' => '00', 'description' => 'Loan Class of Service successfully updated.',
            ],200);

        }catch (\Exception $exception){
            return response(['code' => '100', 'description' => 'Please contact support for assistance.',
                'error_message' => $exception->getMessage()],500);
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

    protected function loanprofileValidator(Array $data)
    {
        return Validator::make($data, [
            'loan_id'            => 'required',
        ]);


    }

    protected function paymentValidator(Array $data)
    {
        return Validator::make($data, [
            'loan_repayment_id'            => 'required',
        ]);


    }



}

