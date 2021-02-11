<?php
/**
 * Created by PhpStorm.
 * User: namar
 * Date: 25-Oct-18
 * Time: 11:03 AM
 */

namespace App\Business\Services;


use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

define('MAX_EXECUTION_TIME', '6000');
ini_set('max_execution_time', MAX_EXECUTION_TIME);

class GuzzleHttpClientWrapper
{

    private $basicAuthCredentials;
    private $guzzleClient;


    /**
     * GuzzleHttpClientWrapper constructor.
     * @param BasicAuthCredentialsService $basicAuthCredentials
     * @param Client $client
     */
    public function __construct(BasicAuthCredentialsService $basicAuthCredentials, Client $client)
    {
        $this->guzzleClient = $client;
        $this->basicAuthCredentials = $basicAuthCredentials;
    }


    public function sendGetRequest($url)
    {
        try {
            $res = $this->guzzleClient->request('GET', $url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'connect_timeout' => 0,
            ]);
            return $res->getBody();
        } catch (Exception $e) {
            $err = "Server returned error code : " . $e->getCode();
            return view('auth.login')->with(['err' => $err]);
        }
    }

    public function sendGetRequestWithToken($url)
    {
        try {
            $res = $this->guzzleClient->request('GET', $url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => $this->basicAuthCredentials->retrieveCredentialsFromCache(),
                ],
                'connect_timeout' => 0,
            ]);
    return $res->getBody();


        } catch (Exception $e) {
            return redirect('/login')->with('err', "Server returned error code : " . $e->getCode());
        }
    }

    public function sendBankListPostRequest($url, $params)
    {
        $banks = '';
        try {
            $res = $this->guzzleClient->request('POST', $url, [
                'headers' => ['Content-type' => 'application/json',],
                'auth' => [env('REMITA_PROD_BASE_USER'), env('REMITA_PROD_BASE_PASSWORD')],
                'connect_timeout' => 0,
            ]);

            $response = json_decode($res->getBody()->getContents(),true);
            $banks = $response['data']['data']['banks'];
            if(!isset($banks)){
                return response([
                    'code'      => 404,
                    'status'    => 'failed',
                    'message'   => 'Failed to retrieve bank list please try again later.',
                    'data'      => $banks ],404);
            }

            return response([
                'code'      => '200',
                'status'    => 'success',
                'message'   => 'Bank list successfully retrieved',
                'data'      => $banks]);


        } catch (Exception $e) {
            $e->getCode() === 0 ?  $httpCode = 500 : $httpCode = $e->getCode();
            return response([
                'code'      => $httpCode,
                'status'    => 'failed',
                'message'   => 'Failed to retrieve bank list please try again later.',
                'data'      => [] ],$httpCode);
        }
    }

    public function sendCustomerInformationPostRequest($url, $params)
    {
        $code = '';
        try {
            $res = $this->guzzleClient->request('POST', $url, [
                'headers' => ['Content-type' => 'application/json',],
                'auth' => [env('REMITA_PROD_BASE_USER'), env('REMITA_PROD_BASE_PASSWORD')],
                'json' => $params,
                'connect_timeout' => 0,
            ]);

            $response =  json_decode($res->getBody()->getContents(), true);
            $code = $response['data']['data']['response_code'];
            if(!isset($code)){
                return response([
                    'code'      => 400,
                    'status'    => 'failed',
                    'message'   => 'Failed to retrieve customer information.',
                    'data'      => '' ],400);
            }

            if($code != '00'){
                return response([
                    'code'      => 404,
                    'status'    => 'failed',
                    'message'   => $response['data']['data']['response_description'],
                    'data'      => '' ],404);
            }

            $result = array(
                'account_name'  =>  $response['data']['data']['account_name'],
                'account_no'    =>  $response['data']['data']['account_no'],
                'email'    =>  $response['data']['data']['email'],
                'bank_code'    =>  $response['data']['data']['bank_code'],
            );
            return response([
                'code'      => '200',
                'status'    => 'success',
                'message'   => 'Customer information successfully retrieved.',
                'data'      => $result]);

        } catch (Exception $e) {
            $e->getCode() === 0 ?  $httpCode = 500 : $httpCode = $e->getCode();
            return response([
                'code'      => $httpCode,
                'status'    => 'failed',
                'message'   => 'Failed to retrieve customer information please try again later.',
                'data'      => [] ],$httpCode);
        }
    }

    public function sendAuthPostRequest($url, $params)
    {
        try {
            $res = $this->guzzleClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Basic YWR2YW5jZUJhbms6UHJvamVjdDEyMw==',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => $params,
                'connect_timeout' => 0,
            ]);
            $data = json_decode($res->getBody()->getContents());
            return array(
                "status" => "200",
            );

        } catch (Exception $e) {
            $e->getCode() === 0 ?  $httpCode = 500 : $httpCode = $e->getCode();
            return array(
                "status" => $httpCode,
            );
        }
    }

    public function sendPostRequestWithToken($url, $params)
    {
        try {
            $res = $this->guzzleClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => $this->basicAuthCredentials->retrieveCredentialsFromCache(),
                ],
                'body' => json_encode($params),
                'connect_timeout' => 0,
            ]);

            return $res->getBody();

        } catch (Exception $e) {
            Log::debug($e->getMessage());
            if ($e->getCode() == 401) {
                return redirect('/login')->with('err', "Unauthorised Access.");
            } else {
                return redirect('/login')->with('err', $e->getMessage());
            }
        }
    }
}
