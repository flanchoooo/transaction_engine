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
    protected $hidden = [
        'merchant',
        'channel',
        'batch_id',
        'column_18',
        'day',
        'updated_at',
        'pan',
        'card',
        'transaction_type',
        'merchant_account',
        'zimswitch_txn_amount'
       ,'debit_mdr_from_merchant','interchange_fees','zimswitch_fee',
        'switch_reference','merchant_id','transaction_status','employee_id','description','account_credited','trust_account','account_debited'

    ,];

    protected $attributes = ['created_at'];

    protected $table = 'wallet_transactions';





}