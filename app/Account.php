<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Account extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $connection = 'mysql2';
    protected $table = 'accounts';

}