<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;

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

    public function cut(){
        $connector = new NetworkPrintConnector(env("PRINTER_IP"), 9100);
        $printer = new Printer($connector);

        $iam = $this->http->input('login');
        $cashdesk = $this->http->input('cashdesk');
        $idcash = $cashdesk['id'];
        $declaredcash = collect($cashdesk['denoms']);
        $declareds=[]; $totaldeclared=0;

        $rangedates=[
            $this->todayobj->startOfDay()->format('Y-m-d H:i:s'),
            $this->todayobj->endOfDay()->format('Y-m-d H:i:s')
        ];

        
        try {
            
            foreach ($declaredcash as $decl) {
                $row = $this->cnx->table('currency_denoms')->where('id',$decl['id'])->first();
                $value=$row->value;
                $total=$row->value*$decl['cant'];
                $totaldeclared+=$total;
                $declareds[]=["value"=>$value,"total"=>$total,"_denom"=>$row->id,"quantity"=>$decl['cant']];
            }

            //id del opening de la caja
            $openid = $this->cnx->table('cash_openings')
                ->where([ ['_cash','=',$idcash], ['active','=',1] ])
                // ->whereBetween('init',$rangedates)
                ->first();

            //denominaciones con la que se aperturo la caja
            $opening = $this->cnx->table('opening_denoms')
                ->join('currency_denoms','currency_denoms.id','=','opening_denoms._denom')
                ->where('opening_denoms._opening','=',$openid->id)
                ->get();

            //cantidad total de paertura
            $total_opening = collect($opening)->reduce(function($ammount,$item){ return $ammount + ($item->quantity*$item->value); });
            
            //obtener tickets del opening
            $parksinopen = $this->cnx->table('parking')->where('_opening',$openid->id)->get();

            //obtenemos parks cerrados/pagados
            $parksclosed = collect($parksinopen)->filter(function($item,$key){
                return $item->state==3;
            })->map(function($item,$key){
                $item->totaltkt = $this->calcPay($item->init,36,$item->ends);
                return $item;
            })->values()->all();
            // estacionamientos activos
            $parksopens = collect($parksinopen)->filter(function($item,$key){ return $item->state==1; })->values()->all();

            if(count($parksopens)){
                $changes = collect($parksopens)->map(function($item,$key){
                    return $this->parkchangestate($item->id,4);
                });
            }else{$changes=null;}

            // obtenemod el total de lo cobrado
            $totalOfCash = collect($parksclosed)->reduce(function ($ammount, $item) { return $ammount + $item->totaltkt['totalcost'];  });
            // estacionamientos activos
            $parksopens = collect($parksinopen)->filter(function($item,$key){ return $item->state==1; })->values()->all();
            //total de entradas
            $totalentries = $totalOfCash+$total_opening;
            // descuadre
            $difference = $totaldeclared-$totalentries;

            //obtener apertura y corte formateados
            $openandcut = $this->openandcut($idcash);
            
            $resume=[
                "msg"=>"Corte realizado",
                "cashdesk"=>$cashdesk,
                "openid"=>$openid,
                "opening"=>$opening,
                "ragedates"=>$rangedates,
                "parksinopen"=>$parksinopen,
                "parksclosed"=>$parksclosed,
                "parksopens"=>$parksopens,
                "totalofcash"=>$totalOfCash,
                "totalopening"=>$total_opening,
                "declaredcash"=>$declareds,
                "totaldeclared"=>$totaldeclared,
                "cut"=>null,
                "changes"=>$changes,
                "openandcut"=>null
            ];

            $resume['cut'] = $this->saveCut($resume);
            $resume['openandcut'] = $this->openandcut($idcash);

            try {
                $this->cnx->commit();
                $printer -> setJustification(Printer::JUSTIFY_CENTER);
                $printer -> setEmphasis(true);
                $printer -> text("Estacionamiento\n");
                $printer -> setEmphasis(false);
                $printer -> setTextSize(2, 1);
                $printer -> text("Grupo Vizcarra\n");
                $printer -> feed(1);
                $printer -> setTextSize(1, 1);
                $printer -> text("----------------------------------------\n");
                $printer -> setTextSize(2, 2);
                $printer -> text("Corte de caja ".$idcash."\n");
                $printer -> setTextSize(1, 1);
                $printer -> text("----------------------------------------\n");
                $printer -> setJustification(Printer::JUSTIFY_LEFT);
                $printer -> feed(1);
                $printer -> setTextSize(1, 1);
                $printer -> text("  Cobros ............. $".$totalOfCash."\n");
                $printer -> text("  Apertura ........... $".$total_opening."\n");
                $printer -> text("  Efectivo en caja ... $".$totalentries."\n");
                $printer -> text("  Declaracion (EFE) .. $".$totaldeclared."\n");
                $printer -> text("  Descuadre .......... $".$difference."\n");
                $printer -> feed(1);
                $printer -> setJustification(Printer::JUSTIFY_CENTER);
                $printer -> text("----------------------------------------\n");
                $printer -> setTextSize(2, 1);
                $printer -> text("RESUMEN\n");
                $printer -> setTextSize(1, 1);
                $printer -> text("----------------------------------------\n");
                $printer -> setJustification(Printer::JUSTIFY_LEFT);
                $printer -> feed(1);
                $printer -> text(" Entradas registradas: ... ".sizeof($parksinopen)."\n");
                $printer -> text(" Entradas cobradas: ...... ".sizeof($parksclosed)."\n");
                $printer -> text(" Entradas sin cobrar: .... ".sizeof($parksopens)."\n");
                $printer -> feed(3);
                $printer->cut();
                $rset=["success"=>true,"msg"=>"Impresion correcta","resume"=>$resume];
            } catch (\Error $e) {
                $rset = ["success"=>false,"msg"=>$e->getMessage()];
            }finally {
                $printer -> close();
                return $rset;
            }
        } catch (\Exception $e) {
            $rset=["cut"=>null,"msg"=>$e->getMessage(),"opening"=>null];
        }

        return response()->json($rset,200);
    }

    private function parkchangestate($idpark,$state){
        return $this->cnx->table('parking')->where('id',$idpark)->update(["state"=>$state]);
    }

    private function saveCut($data){
        $iam = $this->http->input('login');
        //generar id de corte
        $idcut = $this->cnx->table('cash_cuts')->insertGetId([
            "_opening"=>$data['openid']->id,
            "cut_by"=>$iam->accid,
            "cut_init"=>$this->today,
            "cut_end"=>$this->today,
            "notes"=>""
        ]);
        
        //guardar denominaciones
        $denoms = collect($data['declaredcash'])->map(function($item,$key) use($idcut){
            $newitem = ['_cut'=>$idcut,'_denom'=>$item['_denom'],"quantity"=>$item['quantity']];
            return $newitem;
        })->values()->all();
        $savedenoms = $this->cnx->table('cut_denoms')->insert($denoms);

        //actualizar el estado de opening=0 (cash_openings) y caja=2 (cashregisters)
        $closeopen=$this->cnx->table('cash_openings')->where('id',$data['openid']->id)->update(['active'=>0]);
        $closecash=$this->changestate($data['cashdesk']['id'],2);

        return ["id"=>$idcut,"declaredsave"=>$denoms,"closedopening"=>$closeopen,"closedcash"=>$closecash];
    }

    private function calcPay($init,$pricetime,$timend){
        $ends = $timend ? $timend : $this->todayobj;
        $timeinit = Carbon::parse($init);
        $total_minutes = $timeinit->diffInMinutes($ends);
        $total_hours = ($total_minutes/60);
        $hours = intval($total_hours);
        $mints_adds = round(floatval(".".explode('.',$total_hours)[1])*60,2);
        $pricehour=$pricetime;
        $fracc_price=9;
        $fracc=0;
        $total_cost=0;

        if($hours<1){ $total_hours=$pricehour; }else{
            $total_hours=$hours*$pricehour;
            switch ($mints_adds) {
                case ($mints_adds>=1&&$mints_adds<=15): $fracc=$fracc_price; break;
                case ($mints_adds>=16&&$mints_adds<=30): $fracc=$fracc_price*2; break;
                case ($mints_adds>=31&&$mints_adds<=44): $fracc=$fracc_price*3; break;
                case ($mints_adds>=45): $fracc=$pricehour; break;
            }
        }

        $total_cost = $total_hours+$fracc;

        return [
            "pricehour"=>$pricehour,
            "total_minutes"=>$total_minutes,
            "hours"=>$hours,
            "mints_adds"=>$mints_adds,
            "fraccprice"=>$fracc_price,
            "totalforhours"=>$total_hours,
            "totalforfracc"=>$fracc,
            "totalcost"=>$total_cost
        ];
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

                    $openandcut = $this->openandcut($cashid);
                    $opening = $openandcut['opening'];

                    //aplicando commit
                    $this->cnx->commit();

                    $rset=["msg"=>"opening done!!!","rset"=>["updt"=>$updt,"opening"=>$opening,"openid"=>$newid] ];
                }else{
                    $rset=["msg"=>"impossible opening!!","rset"=>null, "cashstate"=>$cashstate ];
                }
            } catch (\Exception $e) {
                $rset=["msg"=>$e->getMessage(),"rset"=>null ];
            }
        }

        return response()->json($rset,200);
    }

    public function reactive(){
        $opening = $this->http->input('opening');
        $openid = $opening['id'];

        //obtener la data del opening
        $openingdata = $this->cnx->table('cash_openings')->where('id',$openid)->first();

        //localizar el id del cash_cut correspondiente al cashcut entrante
        $cut = $this->cnx->table('cash_cuts')->where('_opening',$openid)->first();

        //eliminar el cut_denoms, correspondiente al cashcut localizado
        $cutdeclared = $this->cnx->table('cut_denoms')->where('_cut',$cut->id)->delete();
        
        //eliminar el cash_cut localizado
        $cutdelete = $this->cnx->table('cash_cuts')->where('_opening',$openid)->delete();

        //volver a reactivar el campo active correspondiente al opening entrante
        $react_opening = $this->cnx->table('cash_openings')->where('id',$openid)->update(['active'=>1]);
        
        //cambiar status de la caja a en uso
        $react_cash = $this->changestate($openingdata->_cash,3);

        $this->cnx->commit();

        //obtener ultimo 
        return response()->json([
            "openingdata"=>$openingdata->_cash,
            "opening"=>$opening,
            "cut"=>$cut,
            "cutdeclared"=>$cutdeclared,
            "cutdelete"=>$cutdelete,
            "react_opening"=>$react_opening,
            "react_cash"=>$react_cash
        ],200);
    }

    private function changestate($cashid,$tostate){
        return $this->cnx->table('cashregisters')
            ->where('id',$cashid)
            ->update([
                "_state"=>$tostate,
                "updated_at"=>$this->today
            ]);
    }

    public function index(){
        $cashdesks = $this->list();
        $currencies = $this->getcurrencies();
        $cashiers = $this->cashiers();

        $index = [
            "cashdesks"=>$cashdesks,
            "currencies"=>$currencies,
            "cashiers"=>$cashiers
        ];

        return response()->json($index,200);
    }

    private function cashiers(){
        //solo roles cajeros y roots pueden usar cajas
        try {
            $cashiers = $this->cnx->table('users')
                ->leftJoin('cash_openings', function($join){
                    $join->on('users.id','=','cash_openings._assignto')->where('cash_openings.active','=',1);
                })->select(
                    'users.id as accid',
                    'users.fnames as fnames',
                    'users.lnames as lnames',
                    'users.nick as nick',
                    'cash_openings._cash as usingcash'
                )->where('users._rol','<=',2)->get();
        } catch (\Except $e) { $cashiers = $e->getMessage(); }
        return $cashiers;
    }

    public function getcurrencies(){
        return $this->cnx->table('currency_denoms')->get();
    }

    private function list(){

        $cashesdb = $this->cnx->table('cashregisters')->get();
    
        $cashdesks = collect($cashesdb)->map(function($cash,$key){
            $openandcut = $this->openandcut($cash->id);
            $cash->opening=$openandcut['opening'];
            $cash->cut=$openandcut['cut'];

            return $cash;
        })->values()->all();

        return $cashdesks;
    }

    private function openandcut($cash){
        /**
         * Devuelve la ultima apertura y su correspondiente corte
         * de "X" caja
         */
        $opening = $this->cnx->table('cash_openings AS copn')
                    ->select(
                        'copn.id as id',
                        'copn.active as isactive',
                        'copn.init as init',
                        'usby.id as usbyid',
                        'usby.fnames as usbynames',
                        'usby.lnames as usbylnames',
                        'usby.nick as usbynick',
                        'usto.id as ustoid',
                        'usto.fnames as ustonames',
                        'usto.lnames as ustolnames',
                        'usto.nick as ustonick'
                    )
                    ->join('users AS usby','usby.id','=','copn._assignby')
                    ->join('users AS usto','usto.id','=','copn._assignto')
                    ->where('copn._cash',$cash)
                    // ->whereBetween('copn.init',$rangedates)
                    ->orderBy('id','desc')
                    ->first();

            if($opening){
                $cut = $this->cnx->table('cash_cuts AS cut')
                    ->select(
                        'cut.id as id',
                        'cut.cut_init as makeit',
                        'cut._opening as openingid',
                        'usby.fnames as fnames',
                        'usby.lnames as lnames',
                        'usby.nick as nick'
                    )
                    ->join('users AS usby','usby.id','=','cut.cut_by')
                    ->where('cut._opening',$opening->id)
                    // ->whereBetween('cut.cut_init',$rangedates)
                    ->first();
            }else{ $cut=null; }

        return ["opening"=>$opening,"cut"=>$cut];
    }

    /**
     * trabajo con una sola caja
     */

    private function mycash(){
        $iam = $this->http->input('login');

        try {
            $cashinstance = $this->cnx->table('cash_openings')
                ->where([
                    ['_assignto','=',$iam->accid],
                    ['active','=',1]
                ])->first();
        } catch (\Except $e) { $cashinstance = $e->getMessage(); }
        return $cashinstance;
    }

    private function exists($id){
        return $this->cnx->table('cashregisters')->where('id',$id)->first();
    }

    public function shield(){
        $iam = $this->http->input('login');
        $cashdesk = $this->http->input('cashdesk');
        $instance = null;
        $currencies = null;
        $cashiers = null;
        $openandcut = null;
        $id = $cashdesk['id'];
        $cash = $this->exists($id);

        //Comprobar existencia de la caja
        if($cash){
            switch($iam->rol){
                // el rol root tiene acceso  directo
                case 1: $shield=true; break;
                // el rol cajero, debe autenticarse contra EL OPENING ACTUAL de la caja
                case 2:
                    $instance = $this->mycash();
                    if($instance){ $shield=true; }else{ $shield=false; }
                break;

                default: $shield=false; break;
            }

            if($shield){
                $currencies = $this->getcurrencies();
                $cashiers = $this->cashiers();
                $openandcut = $this->openandcut($id);
            }

            $resp = [
                "shield"=>$shield,
                "instance"=>$instance,
                "currencies"=>$currencies,
                "cashiers"=>$cashiers,
                "openandcut"=>$openandcut,
                "cash"=>$cash
            ];
        }else{
            $resp = ["shield"=>false ];
        }

        return response()->json($resp,200);
    }

    
}