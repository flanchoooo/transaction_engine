<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Batch_Transaction extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $guarded = [];

    protected $hidden = ['created_at','updated_at','created_by','updated_by','transaction_type_id',
        'amount','status','fee','card','pan','batch_id','id','day','merchant','channel'];
    protected $table = 'transaction';

}
