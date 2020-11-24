<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class Jobs extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $guarded = [];

    protected $protected = ['payload'];
    protected $table = 'jobs';

}
