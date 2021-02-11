<?php

namespace App\Http\Controllers;
use App\Business\Services\GuzzleHttpClientWrapper;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class BrassController extends Controller
{

    private $guzzleHttpClientWrapper;

    public function __construct(GuzzleHttpClientWrapper $guzzleHttpClientWrapper)
    {
        $this->guzzleHttpClientWrapper = $guzzleHttpClientWrapper;
    }


    public function banks(Request $request){
        $authBaseUrl = env('AUTH_BASE_URL') . 'auth-server/oauth/token';
        $data = array("username" => $request->getUser(), "password" => $request->getPassword(), "grant_type" => 'password', "scope" => '',);

        $response = $this->guzzleHttpClientWrapper->sendAuthPostRequest($authBaseUrl,$data);
        if($response["status"] != 200){
            return response([
                'code'      => 401,
                'status'    => 'failed',
                'message'   => 'Invalid header username and password',
                'data'      => [] ],401);
        }

        $baseUrl = env('REMITA_PROD_URL') . 'remita/banks';
        $response = $this->guzzleHttpClientWrapper->sendBankListPostRequest($baseUrl,array());
        if ($response instanceof RedirectResponse) {
            return $response;
        }

        return $response;
    }

    public function customerInformation(Request  $request){
        $validator = $this->customerInformationValidation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '400', 'message' => $validator->errors(), 'data' => ''],400);
        }
        $authBaseUrl = env('AUTH_BASE_URL') . 'auth-server/oauth/token';
        $data = array("username" => $request->getUser(), "password" => $request->getPassword(), "grant_type" => 'password', "scope" => '',);
        $response = $this->guzzleHttpClientWrapper->sendAuthPostRequest($authBaseUrl,$data);
        if($response["status"] != 200){
            return response([
                'code'      => 401,
                'status'    => 'failed',
                'message'   => 'Invalid header username and password',
                'data'      => [] ],401);
        }

        $data = array('bank_code' => $request->bank_code, 'account_number' => $request->account_number,);
        $baseUrl = env('REMITA_PROD_URL') . 'remita/account/information';
        $response = $this->guzzleHttpClientWrapper->sendCustomerInformationPostRequest($baseUrl,$data);
        if ($response instanceof RedirectResponse) {
            return $response;
        }

        return $response;
    }

    protected function customerInformationValidation(Array $data)
    {
        return Validator::make($data, [
            'bank_code'              => 'required',
            'account_number'         => 'required',
        ]);
    }
}

