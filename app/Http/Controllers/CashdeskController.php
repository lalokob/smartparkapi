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
            // descuadre
            $difference = $totaldeclared-$totalOfCash;
            
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
                "changes"=>$changes
            ];

            $resume['cut'] = $this->saveCut($resume);

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
                $printer -> text("  Efectivo en caja ... $ ".$totalOfCash."\n");
                $printer -> text("  Apertura ........... $ ".$total_opening."\n");
                $printer -> text("  Total de entradas .. $ ".($totalOfCash+$total_opening)."\n");
                $printer -> text("  Declaracion (EFE) .. $ ".$totaldeclared."\n");
                $printer -> text("  Descuadre .......... $ ".$difference."\n");
                $printer -> feed(1);
                $printer -> setJustification(Printer::JUSTIFY_CENTER);
                $printer -> text("----------------------------------------\n");
                $printer -> setTextSize(2, 1);
                $printer -> text("RESUMEN\n");
                $printer -> feed(2);
                $printer -> setTextSize(1, 1);
                $printer -> setJustification(Printer::JUSTIFY_LEFT);
                $printer -> text("Apertura: ... ".$openid->id."\n");
                $printer -> text("Corte: ... ".$resume['cut']['id']."\n");
                $printer -> text("Entradas registradas: ... ".sizeof($parksinopen)."\n");
                $printer -> text("Entradas cobradas: ...... ".sizeof($parksclosed)."\n");
                $printer -> text("Entradas sin cobrar: .... ".sizeof($parksopens)."\n");
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
        $closecash=$this->changestate($data['openid']->id,2);

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

                    //aplicando commit
                    $this->cnx->commit();

                    $rset=["msg"=>"opening done!!!","rset"=>["updt"=>$updt,"openid"=>$newid] ];
                }else{
                    $rset=["msg"=>"impossible opening!!","rset"=>null, "cashstate"=>$cashstate ];
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
        return $this->cnx->table('cashregisters')->get();
    }
}
