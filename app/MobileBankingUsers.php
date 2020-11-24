<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class MobileBankingUsers extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $connection = 'mysql2';
    protected $table = 'mobile_user';

}