<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

date_default_timezone_set('America/Mexico_City');
Carbon::setLocale('es');

class CashdeskController extends Controller
{
    public function __construct(Request $request){
        $this->http = $request;
        $this->todayobj = Carbon::now();
        $this->today = Carbon::now()->format('Y-m-d H:i');
        $this->cnx = DB::connection(); 
        $this->cnx->beginTransaction();
    }

    public function create(){
        $iam = $this->http->input('login');
        $rset=["msg"=>"NO puedo crear Cajas :("];

        if($iam->rol==1){// solo los root pueden crear cajas
            try {
                //conexion e inicio de transaccion
                $newid = $this->cnx->table('cashregisters')->insertGetId(['created_at'=>$this->today,'updated_at'=>$this->today,"_state"=>1]);
                $cashdesk = $this->prv_findById($newid);
                $rset=[ "msg"=>"New Cashdesk done!", "cashdesk"=>$cashdesk ];//datos a retornar
                $this->cnx->commit();//confirmacion de insercion
            } catch (\Exception $e) {
                $rset=["msg"=>$e->getMessage(),"rset"=>null ];
                $this->cnx->rollback();
            }
        }

        return response()->json($rset,200);
    }

    private function prv_findById($id){
        $cashdesk = $this->cnx->table('cashregisters')
        ->join('cash_states','cash_states.id','=','cashregisters._state')
        ->select(
            'cashregisters.id as cashid',
            'cash_states.id as state',
            'cash_states.shortdesc as shortdesc'
        )
        ->where('cashregisters.id',$id)->first();
        return $cashdesk;
    }

    public function opening(){
        $iam = $this->http->input('login');
        $cashdesk = $this->http->input('cashdesk');
        $rset=["msg"=>"NO puedo Aperturar Cajas :("];

        if($iam->rol==1){// solo los root pueden aperturar cajas
            try {
                $cashid = $cashdesk['id'];
                $cashstate = $this->prv_findById($cashid);
                if($cashstate->state<3){
                    $denoms = collect($cashdesk['denoms']);
                    //creacion de nuevo opening
                    $newid = $this->cnx->table('cash_openings')->insertGetId([
                        "init"=>$this->today,
                        "_cash"=>$cashid,
                        "_assignby"=>$iam->accid,
                        "_assignto"=>$cashdesk['assignto'],
                        "notes"=>$cashdesk['notes']
                    ]);
                    
                    //insercion de denominaciones
                    $denoms->map(function($item,$key) use($newid){ 
                        $ins = $this->cnx->table('opening_denoms')->insert([
                            "_opening"=>$newid,
                            "_denom"=>$item['id'],
                            "quantity"=>$item['cant']
                        ]);
                    });

                    //cambio de status a la caja
                    $updt = $this->changestate($cashid,3);

                    //aplicando commit
                    $this->cnx->commit();

                    $rset=["msg"=>"opening done!!!","rset"=>["updt"=>$updt,"openid"=>$newid] ];
                }else{
                    $rset=["msg"=>"impossible opening!!","rset"=>$cashstate ];
                }
            } catch (\Exception $e) {
                $rset=["msg"=>$e->getMessage(),"rset"=>null ];
            }
        }

        return response()->json($rset,200);
    }

    private function changestate($cashid,$tostate){
        return $this->cnx->table('cashregisters')
            ->where('id',$cashid)
            ->update([
                "_state"=>$tostate,
                'updated_at'=>$this->today
            ]);
    }

    public function index(){
        return $this->list();
    }

    private function list(){
        return $this->cnx->table('cashregisters')->get();
    }
}
