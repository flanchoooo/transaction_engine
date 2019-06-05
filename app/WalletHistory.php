<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class WalletHistory extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $guarded = [];
    protected $hidden = ['merchant','channel','batch_id','column_18','day','updated_at','account','pan','card','transaction_type','amount'];

    protected $table = 'wallet_transaction';




}