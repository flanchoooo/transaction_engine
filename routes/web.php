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
    return 'ADVANCE-BANK-WALLET-SERVICE: AUTHOR: FLAVIAN .T. MACHIMBIRIKE' .'  '. $router->app->version();
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



/*
|--------------------------------------------------------------------------
| Transaction Handling module
|--------------------------------------------------------------------------
|
|
*/


$router->group(['prefix'=>'api/', 'middleware' => 'BasicAuth'], function($app) {


    /*
    //Balance Enquiry
    $app->post('balance',  'BalanceOnUsController@balance');
    $app->post('br_balance',  'BRBalanceController@br_balance');
    $app->post('post_transaction',  'BRBalanceController@post_transaction');
    $app->post('update_transaction',  'BRBalanceController@update_transaction');
    $app->get('post_pending_transaction',  'BRBalanceController@post_pending_transaction');
    $app->post('balance_off_us',  'BalanceOffUsController@balance_off_us');
    $app->get('balance_bank_x',  'BalanceBankXController@balance');


    //Purchase
    $app->post('purchase',  'PurchaseOnUsController@purchase');
    $app->get('mdr',  'PurchaseOnUsController@mdr');
    $app->post('purchase_off_us',  'PurchaseOffUsController@purchase_off_us');

    //Banking
    $app->post('cash_withdrawal',  'WithdrawalOnUsController@cash_withdrawal');
    $app->post('cash_deposit',  'CashDepositController@cash_deposit');


    //Sale + Cash
    $app->post('purchase_cash_back',  'PurchaseCashOnUsController@purchase_cashback');
    $app->post('purchase_cash_back_off_us',  'PurchaseCashOffUsController@purchase_cash_back_off_us');


    //Batch
    $app->post('batch',  'BatchCutOffController@batch');

    //Cash In Txn
    $app->post('/cash_in_preauth',  'WalletCashInController@cash_in_preauth');
    $app->post('/cash_in',  'WalletCashInController@cash_in');
    $app->post('/cash_out',  'WalletCashOutController@cash_out');


    $app->post('mini-statement',  'BalanceController@mini');
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
    // Refactored
    $app->post('launch',  'LaunchController@index'); // Refactored

    //Wallet Support
    $app->post('adjustment_preauth',  'WalletAdjustmentsController@adjustment_preauth');
    $app->post('adjustment',  'WalletEValueController@adjustment');
    $app->post('create_value',  'WalletEValueController@create_value');
    $app->post('destroy_value',  'WalletEValueController@e_value_destroy');

    //Wallet
    $app->post('wallet_sign_up',  'WalletController@wallet_sign_up');
    $app->post('wallet_agent_sign_up',  'WalletController@wallet_agent_sign_up');
    $app->post('send_money_preauth',  'WalletSendMoneyController@send_money_preauth');
    $app->post('send_money',  'WalletSendMoneyController@send_money');

    //Bill Payment
    $app->post('paybill',  'WalletBillPaymentController@paybill');

    //Wallet supporting APIs
    $app->post('history',  'WalletSupportController@history');
    $app->post('history_web',  'WalletSupportController@history_web');
    $app->post('customer_info',  'WalletSupportController@customer_info');
    $app->post('settle',  'WalletSupportController@settle_agent');
    $app->post('agent',  'WalletSupportController@agent');
    $app->post('agent_history',  'WalletSupportController@agent_history');

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

    //Bulk Disbursements
    $app->post('bulk_upload',  'WalletDisbursementsController@bulk_upload');
    $app->post('disburse',  'WalletDisbursementsController@disburse');
    $app->get('/disburse/all',  'WalletDisbursementsController@all');
    $app->get('/disburse/cancel',  'WalletDisbursementsController@cancel');
    $app->get('/disburse/reports',  'WalletDisbursementsController@reports');

    */

    //Wallet Operations
    $app->post('wallet_sign_up',  'WalletSignUpController@wallet_sign_up');
    $app->post('login',  'WalletLoginController@login');
    $app->post('preauth',  'WalletLoginController@preauth');
    $app->post('change_pin',  'WalletSignUpController@changePin');
    $app->post('validate_otp',  'WalletLoginController@validateOtp');

    //Send Money
    $app->post('send_money',  'WalletSendMoneyController@sendMoney');

    //Wallet ATM Withdrawal
    $app->post('withdrawal/atm/otp',  'WalletATMWithdrawalController@generateOtp');
    $app->post('/atm/withdrawal',  'WalletATMWithdrawalController@atmWithdrawal');
    $app->post('/atm/deposit',  'WalletATMDepositController@deposit');

    //Support
    $app->post('history',  'WalletSupportController@history');
    $app->post('/generate/virtual/card',  'WalletSupportController@virtualCard');
    $app->post('/link/emv/card',  'WalletSupportController@link');
    $app->post('/update/emv/card',  'WalletSupportController@delink');

    //Pay Merchant
    $app->post('/pay/merchant',  'WalletPayMerchantController@payMerchant');

    //Loan KYC
    $app->post('/lending/register',  'LendingKycController@register');
    $app->post('/lending/email',  'LendingKycController@send');
    $app->post('/lending/login',  'LendingKycController@login');
    $app->post('/lending/kyc/update',  'LendingKycController@updateKyc');

    //Loan Application
    $app->post('/lending/apply',  'LoanApplicationController@apply');
    $app->post('/lending/history',  'LoanApplicationController@history');
    $app->post('/lending/cancel/loan',  'LoanApplicationController@cancel');
    $app->post('/lending/upload/documents',  'LoanApplicationController@upload');

    //Loan Administration Controller
    $app->get('/lending/pending/approval','LoanAdministrationController@pendingApprovals');
    $app->post('/lending/update', 'LoanAdministrationController@updateLoansApplication');
    $app->post('/lending/download/kyc',  'LoanAdministrationController@pendingApprovals');


    //Loan profile
    $app->post('/lending/profile',  'LoanAdministrationController@profile');
    $app->post('/lending/payment',  'LoanAdministrationController@payment');
    $app->post('/lending/loan/profile',  'LoanAdministrationController@loanProfile');
    $app->post('/lending/loan/profile/search',  'LoanAdministrationController@search');

    //Loan Book Position
    $app->get('/lending/loan/book',  'LoanAdministrationController@loanBook');

    //Evalue
    $app->post('e_value_management',  'WalletEValueController@e_value_management');
    $app->get('pending_approval',  'WalletEValueController@all_e_value_management');
    $app->get('pending_approvals',  'WalletEValueController@all_destroy_value');

});



