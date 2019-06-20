<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return 'Secure-pay-txn' .'  '. $router->app->version();
});


/*
|--------------------------------------------------------------------------
| Registration & Login Routes
|--------------------------------------------------------------------------
|
| These Routes will enable you to create an API user and The login user
| will generate token to that will be used to access the lumen APIs.
|
*/

$router->post('/login', 'LoginController@index');
$router->post('/register', 'UserController@register');
$router->get('/loaderio-8cac7463ae09a319247f053ee1fb3acd/', 'UserController@tester');


/*
|--------------------------------------------------------------------------
| Transaction Handling module
|--------------------------------------------------------------------------
|
|
*/


$router->group(['prefix'=>'api/', 'middleware' => 'BasicAuth'], function($app) {

    $app->post('balance',  'BalanceController@balance');
    $app->post('mini-statement',  'BalanceController@mini');
    $app->post('purchase',  'PurchaseController@purchase'); // Refactored
    $app->post('history',  'TransactionHistoryController@history');
    $app->post('batch',  'BatchCutOffController@batch');
    $app->post('last_transaction',  'BatchCutOffController@last_transaction');
    $app->post('pin',  'PINController@pin');
    $app->post('zipitsend',  'ZipitController@send');// Refactored
    $app->post('zipitreceive',  'ZipitController@receive');// Refactored
    $app->post('reversal',  'ReversalController@reversal'); //Refactored
    $app->post('customer_info',  'DepositController@info');
    $app->post('deposit',  'DepositController@deposit'); // Refactored
    $app->post('withdrawal',  'WithdrawalController@withdrawal');// Refactored
    $app->post('purchase_cashback',  'PurchaseController@purchase_cashback'); // Refactored
    $app->post('launch',  'LaunchController@index'); // Refactored

    //Balance Getbucks Card on Bank X POS
    $app->post('balance_off_us',  'BalanceController@balance_off_us');
    $app->post('purchase_off_us',  'PurchaseController@purchase_off_us');
    $app->post('purchase_cash_back_off_us',  'PurchaseController@purchase_cash_back_off_us');


    //Balance BankX
    $app->get('balance_bank_x',  'BalanceBankXController@balance');


    //Wallet Support
    $app->get('cash_in',  'WalletCashInController@cash_in_business');
    $app->post('adjustment_preauth',  'WalletAdjustmentsController@adjustment_preauth');
    $app->post('adjustment',  'WalletEValueController@adjustment');
    $app->post('create_value',  'WalletEValueController@create_value');
    $app->post('destroy_value',  'WalletEValueController@e_value_destroy');

    //Wallet
    $app->post('wallet_sign_up',  'WalletController@wallet_sign_up');
    $app->post('send_money_preauth',  'WalletSendMoneyController@send_money_preauth');
    $app->post('send_money',  'WalletSendMoneyController@send_money');

    //Bill Payment
    $app->post('paybill',  'WalletBillPaymentController@paybill');

    //Wallet supporting APIs
    $app->post('history',  'WalletSupportController@history');

    // Wallet Balance
    $app->post('balance_request',  'WalletBalanceController@balance_request');

    //Change Pin
    $app->post('change_pin',  'WalletChangePinController@change_pin');
    $app->post('link_wallet',  'WalletLinkBrController@link_wallet');


    //Bank 2 Wallet & Wallet 2 Bank
    $app->post('bank_to_wallet',  'WalletB2WController@bank_to_wallet');
    $app->post('wallet_to_bank',  'WalletW2BController@wallet_to_bank');


    //e_value_management
    $app->post('e_value_management',  'WalletEValueController@e_value_management');
    $app->get('pending_approval',  'WalletEValueController@all_e_value_management');
    $app->get('pending_approvals',  'WalletEValueController@all_destroy_value');

    //employee management
    $app->post('employee_register',  'EmployeeController@employee_register');
    $app->post('employee_login',  'EmployeeController@employee_login');
    $app->post('employee_logout',  'EmployeeController@employee_logout');
    $app->post('change_status',  'EmployeeController@change_status');



});


