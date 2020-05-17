<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class LoanHistory extends Model implements Authenticatable

{



    use AuthenticableTrait;
    protected $hidden =['disbursed_by','authorized_by','letter_of_employment','bank_statement','applicant_id','loan_cos','updated_at','employee_reference','photo','payslip',];
    protected $guarded = [];
    protected $table = 'lending_applications';

}