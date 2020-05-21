<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;
class LoanHistory extends Model implements Authenticatable
{
    use AuthenticableTrait;
    protected $hidden =['disbursed_by','authorized_by','loan_cos','updated_at','employee_reference',];
    protected $guarded = [];
    protected $table = 'lending_applications';

}