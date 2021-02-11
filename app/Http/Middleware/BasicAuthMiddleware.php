<?php

namespace App\Http\Middleware;

use Closure;

class BasicAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next) {
        /*if($request->getUser() != 'admin' || $request->getPassword() != 'admin') {
            $headers = array('WWW-Authenticate' => 'Basic');
            $return =  array(
                'code' => '01',
                'description' => 'Unauthorized',

            );
            return response($return , 401, $headers);

        }*/
        return $next($request);
    }
}