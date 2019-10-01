<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class WalletDisbursements extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $guarded = [];
    protected $hidden = [];
    protected $table = 'wallet_disbursements';





}