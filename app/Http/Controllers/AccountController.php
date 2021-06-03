<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

date_default_timezone_set('America/Mexico_City');
Carbon::setLocale('es');

class AccountController extends Controller
{
    public function __construct(Request $request)
    {
        $this->http = $request;
        $this->todayobj = Carbon::now();
        $this->today = Carbon::now()->format('Y-m-d H:i');
    }

    private function genApiKey($datacrypt){
        $hash = "UHT";
        $fkey = base64_encode(env("APP_KEY"));
        $skey = base64_encode(env("APP_KEY"));
        $first_key = base64_decode($fkey);
        $second_key = base64_decode($skey);   
        $method = "AES-128-CBC";

        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen); 
        $first_encrypted = openssl_encrypt($datacrypt,$method,$first_key, OPENSSL_RAW_DATA ,$iv);   
        $second_encrypted = hash_hmac('sha3-512', $first_encrypted, $second_key, true);

        $crypted = base64_encode($iv.$second_encrypted.$first_encrypted);
        return $crypted;
    }

    private function getModules($rol){
        return DB::table('rolhasmodules')
            ->join('modules_system','modules_system.id','=','rolhasmodules._module')
            ->where('_rol',$rol)->get();
    }

    public function tryLogin(){
        $nick = $this->http->input('nick');
        $pass = $this->http->input('pass');

        try {
            $account = DB::table('users')
                    ->join('roles_user','roles_user.id','=','users._rol')
                    ->select(
                        'users.id as id',
                        'users.picprofile as picprofile',
                        'users.fnames as names',
                        'users.pass as pass',
                        'users.lnames as lnames',
                        'users.nick as nick',
                        'roles_user.name as rolname',
                        'roles_user.id as rolid'                        
                    )
                    ->where('nick',$nick)->first();
                    
            if($account){
                if(password_verify($pass, $account->pass)) {
                    $modules = $this->getModules($account->rolid);
                    $dtcrypt = json_encode([ "accid"=>$account->id,"rol"=>$account->rolid,"ends"=>$this->todayobj->endOfDay() ]);
                    $apikey = $this->genApiKey($dtcrypt);
                    $usdata = collect($account)->except(['pass']);
                    $rset = [ "msg"=>"Welcome!!!","apikey"=>$apikey,"usdata"=>$usdata,"modules"=>$modules,"dtcrypt"=>$dtcrypt ];
                }else{ $rset = ["msg"=>"Credenciales Erroneas","apikey"=>null]; }
            }else{ $rset = ["msg"=>"Credenciales Incorrectas","apikey"=>null]; }

            return response()->json([ "rset"=>$rset ], 200);
        } catch (\Exp $th) {
            return response()->json([ "error"=>$th->getMessage() ]);
        }
    }

    public function create(){
        $iam = $this->http->input('login');
        $rset=["msg"=>"NO puedo crear usuarios :("];
        
        if($iam->rol==1){// solo los root pueden crear cuentas
            try {
                $acc=$this->http->input('newacc');//datos de la cuenta a crear
                $hashpass = password_hash($acc['nick'], PASSWORD_BCRYPT, ['cost'=>12]);//generacion d epassword
                $cnx=DB::connection(); $cnx->beginTransaction();//conexion e inicio de transaccion
                //insercion de datos
                $newid = $cnx->table('users')->insertGetId([ 'fnames'=>$acc['fnames'], 'lnames'=>$acc['lnames'], 'nick'=>$acc['nick'], 'pass'=>$hashpass, 'created_at'=>$this->today, '_rol'=>$acc['rol'], 'state'=>1 ]);
                $cnx->commit();//confirmacion de insercion
                $rset=[ "msg"=>"New Account done!", "id"=>$newid, "data"=>$acc ];//datos a retornar
            } catch (\Exception $e) {
                $rset=["msg"=>$e->getMessage(),"rset"=>null ];
            }
        }
        return response()->json($rset, 200);
    }
}