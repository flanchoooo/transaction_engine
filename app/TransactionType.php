<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 3/27/19
 * Time: 9:53 AM
 */

namespace App;


use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Auth\Authenticatable as AuthenticableTrait;


class TransactionType extends Model implements Authenticatable
{
    use AuthenticableTrait;
    protected $table = 'transaction_type';


}