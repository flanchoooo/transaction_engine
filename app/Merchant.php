<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Merchant extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $hidden = ['created_at','updated_at','created_by','updated_by'];
    protected $table = 'merchant';

}