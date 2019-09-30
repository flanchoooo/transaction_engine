<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Fee extends Model implements Authenticatable

{

    use AuthenticableTrait;

    protected $guarded = [];
    protected $keyType = 'bigint';
    protected $casts = ['id' => 'bigint'];
    protected $table = 'fee';



}