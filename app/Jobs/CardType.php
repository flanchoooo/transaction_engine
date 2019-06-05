<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Contracts\Auth\Authenticatable;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class User extends Model implements Authenticatable

{

    //

    use AuthenticableTrait;

    protected $fillable = ['name','email','password','api_token'];

    protected $table = 'users';

}