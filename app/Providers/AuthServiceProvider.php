<?php

namespace App\Providers;

use App\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Carbon\Carbon;

class AuthServiceProvider extends ServiceProvider
{
    private function hashdecrypt($hash){
        $mix = base64_decode($hash);
        $fkey = base64_decode(base64_encode(env("APP_KEY")));
        $method = "AES-128-CBC";

        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen); 

        $iv2 = substr($mix,0,$ivlen);
        $first_encrypted = substr($mix,$ivlen+64);
        $second_encrypted = substr($mix,$ivlen,64);

        $second_encrypted_new = hash_hmac('sha3-512', $first_encrypted, $fkey, true);

        if (hash_equals($second_encrypted,$second_encrypted_new)){ 
            $data = openssl_decrypt($first_encrypted,$method,$fkey,OPENSSL_RAW_DATA,$iv2);
            $accdata = json_decode($data);
            $dtend = $accdata->ends;
            $today = Carbon::now();
            $dtendparse = Carbon::parse($dtend);
            if($today->lessThan($dtendparse)){ return $accdata; }
            return true;
        }
        return null;
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['auth']->viaRequest('api', function ($request) {
            $apikey = $request->input('apikey');
            // $accountdt = $request->input('account');
            if($apikey){
                $dtdecrypt = $this->hashdecrypt($apikey);
                if($dtdecrypt){
                    $request->request->add([ 'login'=>$dtdecrypt ]);
                    return true;
                } return null;
            } return null;
        });
    }
}
