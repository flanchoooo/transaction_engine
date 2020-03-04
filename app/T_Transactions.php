<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class T_Transactions extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $connection = 'sqlsrv';
    protected $table = 't_Transaction';

}