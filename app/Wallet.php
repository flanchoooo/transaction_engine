<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Wallet extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;
    protected $guarded = [];
    protected $hidden = ['id','pin','commissions','device_uuid','created_at','updated_at'];
    protected $table = 'wallet';

}