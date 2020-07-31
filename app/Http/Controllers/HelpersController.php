<?php

namespace App\Http\Controllers;
use Carbon\Carbon;

date_default_timezone_set('America/Mexico_City');
Carbon::setLocale('es');

class HelpersController extends Controller
{
    public function createtoken(){

        $dtend = Carbon::now()->endOfDay();
        $arr_tocrypt = json_encode([
            "id"=>1,
            "rol"=>1,
            "user"=>"lalodev",
            "names"=>"Eduardo Lopez",
            "ends"=>$dtend 
        ]);
        $hash="UHT";
        $fkey = base64_encode(env("APP_KEY"));
        $skey = base64_encode(env("APP_KEY"));
        $first_key = base64_decode($fkey);
        $second_key = base64_decode($skey);   
        $method = "AES-128-CBC";

        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen); 
        $first_encrypted = openssl_encrypt($arr_tocrypt,$method,$first_key, OPENSSL_RAW_DATA ,$iv);   
        $second_encrypted = hash_hmac('sha3-512', $first_encrypted, $second_key, true);
                
        $crypted = base64_encode($iv.$second_encrypted.$first_encrypted);

        return $crypted;
    }
}
