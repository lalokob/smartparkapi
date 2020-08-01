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
use Illuminate\Http\Request;
$router->group(['prefix'=>'test',], function() use($router){
    $router->get('miniprinter','ParkController@tesprinter');
    $router->get('appcnx',function() use($router){ return $router->app->version(); });
    $router->get('genhashpass',function(){
        $msg="fail password";
        $hashed_password = password_hash("abcdef", PASSWORD_BCRYPT, ['cost'=>12]);
        if(password_verify("abcdef", $hashed_password)) { $msg = "password correct!!"; } 
        return response()->json(['hash'=>$hashed_password,'msg'=>$msg], 200);
    });
    $router->get('md5',function(){
        $output = md5("MZD-202");
        return response()->json($output,200);
    });
});

$router->group(['prefix'=>'account'], function() use($router){
    $router->post('trylogin','AccountController@tryLogin');
    // $router->post('trylogin',function(Request $req){
    //     return response()->json($req->all(),200);
    // });
    $router->group(['middleware'=>'auth'], function() use($router){
        $router->post('create','AccountController@create');
    });
});

$router->group(['prefix'=>'cashdesk','middleware'=>'auth'], function() use($router){
    $router->post('index','CashdeskController@index');
    $router->get('create','CashdeskController@create');
    $router->post('opening','CashdeskController@opening');
});

$router->group(['prefix'=>'park','middleware'=>'auth'], function() use($router){
    $router->post('index','ParkController@index');
    $router->post('mginput','ParkController@mginput');
    $router->post('stdcheckin','ParkController@stdcheckin');
    // $router->post('stdprecheckout','ParkController@stdprecheckout');//quiza deba ser removida
    $router->post('charge','ParkController@charge');
});