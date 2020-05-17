<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class LoanClassofService extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $table = 'lending_class_of_service';

}