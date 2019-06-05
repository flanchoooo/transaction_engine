<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class WalletBalance extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $guarded = [];
    protected $hidden = ['account_number',
                         'first_name',
                         'last_name',
                         'auth_attempts',
                         'gender',
                         'dob',
                         'national_id',
                         'state',
                         'wallet_cos_id',
                         'pin',
                         'merchant_id',
                         'mobile',
                         'updated_at',
                         'id',
                         'created_at'];

    protected $table = 'wallet';




}
