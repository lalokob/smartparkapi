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

class ParkController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(Request $request){
        $this->http = $request;
        $this->todayobj = Carbon::now();
        $this->today = Carbon::now()->format('Y-m-d H:i');
        $this->cnx = DB::connection(); 
        $this->cnx->beginTransaction();
    }

    public function index(){
        $iam = $this->http->input('login');
        return ["park"=>$this->list(),"user"=>$iam];
    }

    public function tesprinter(){
        $connector = new NetworkPrintConnector(env("PRINTER_IP"), 9100);
        $printer = new Printer($connector);

        try {
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> setEmphasis(true);
            $printer -> text("Estacionamiento\n");
            $printer -> setEmphasis(false);
            $printer -> setTextSize(2, 1);
            $printer -> text("Grupo Vizcarra\n");
            $printer -> setTextSize(1, 1);
            $printer -> feed(1);
            $printer -> text("Calle San Pablo #10\nColonia Centro, C.P. 06060\nTel. 55 2220 2120\n");
            $printer -> feed(1);
            $printer -> setEmphasis(true);
            $printer -> text(" Comprobante de pago.\n");
            $printer -> setEmphasis(false);
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> feed(1);
            $printer -> setEmphasis(true);
            $printer -> text("¡¡Nos encanta servirte!!\n");

            $printer -> feed(3);
            $printer -> cut();

            $rsm = "prueba completada con exito";
        } catch (\Exception $e) {
            $rsm = $e -> getMessage();
        } finally {
            $printer -> close();
            return $rsm;
        }
    }

    private function list(){
        $rangedates=[
            $this->todayobj->startOfDay()->format('Y-m-d H:i:s'),
            $this->todayobj->endOfDay()->format('Y-m-d H:i:s')
        ];

        return $this->cnx->table('parking')
                ->join('plates','plates.id','=','parking._plate')
                ->select(
                    'plates.id as plateid',
                    'plates.plate as plate',
                    'parking._mainservice as idmnservice',
                    'parking.init as init',
                    'parking.init as ends',
                    'parking._tariff as idtariff',
                    'parking.state as parkstate',
                    'parking.id as parkid'
                )
                ->whereBetween('parking.init',$rangedates)
                ->orWhere('parking.state',4)
                ->get();
    }

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

    public function mginput(){
        try {
            $iam = $this->http->input('login');
            $input = $this->http->input('mginput');
            
            //definiendo si la placa existe
            $exist = $this->cnx->table('plates')
                ->where('plate',$input)
                ->orWhere('id',$input)
                ->first();
            if($exist){// existencia de la placa, registro previo
                //obtener ultimo registro del vehiculo en estacionamiento
                
                $inpark = $this->cnx->table('parking')
                    ->where('_plate',$exist->id)
                    ->orderBy('id','desc')
                    ->first();
                // definiendo ultimo servicio principal
                switch ($inpark->_mainservice) {
                    case 2: 
                        // $nextaction = $itsinpark->state==1 ? "digCheckOut":"digCheckIn";
                        $rset=["msg"=>"Servicio de pension","parkexs"=>199];
                    break;
                    default:
                        $cashavls = count($this->cnx->table('cash_openings')->where('active',1)->get());
                    
                        if($inpark->state==1||$inpark->state==4){//es un servicio activo
                            $msg = "Nuevo/Primer ingreso";
                            $precheckout = $this->stdprecheckout($inpark->id);
                            $parkexs = 200;
                        }else{
                            $msg = "Es un Reingreso";
                            $precheckout=null;
                            $parkexs = 205;
                        }

                        $rset=[ "msg"=>$msg, "precheckout"=>$precheckout, "parkexs"=>$parkexs, "cashavls"=>$cashavls,"iam"=>$iam ];
                    break;
                }
            }else{//placa sin registro previo 
                $cashavls = count($this->cnx->table('cash_openings')->where('active',1)->get());
                $rset=["msg"=>"Placa sin registro previo ".$input,"parkexs"=>404,"cashavls"=>$cashavls,"nextaction"=>"itsYourChoice", "iam"=>$iam]; 
            }
        } catch (\Error $e) {
            $rset=["msg"=>$e->getMessage()];
        }
        return response()->json($rset, 200);
    }

    public function stdcheckin(){
        $iam = $this->http->input('login');
        $plate = strtoupper($this->http->input('plate'));
        $platemd5 = md5($plate);
        $tariff = $this->http->input('tariff');
        $notes = $this->http->input('notes');

        try {

            //obtener el opening de caja
            if($iam->rol==4){// el rol 4, puede capturar y enviarlos a las cajas activas
                //obtenemos cualquier instancia
                $_openid = $this->cnx->table('cash_openings')->where('active',1)->first();
                if($_openid){//si hay instancias de cajas
                    $openid = $_openid->id;// se setea el id del opening
                    $msg = "solo puedo capturar placas, no cobrar ni dar salidas";
                }else{// se notifica al usuario que no hay instancias de cajas activas
                    $openid=null;
                    $msg = "no hay instancias de caja activas";
                }
            }elseif ($iam->rol==1||$iam->rol==2) {// cuando el rol es cajero o root
                $mycash = $this->mycash();
                if($mycash){
                    $openid = $mycash->id;
                    $msg="tengo caja asignada";
                }else{
                    $_openid = $this->cnx->table('cash_openings')->where('active',1)->first();
                    if($_openid){//si hay instancias de cajas
                        $openid = $_openid->id;// se setea el id del opening
                        $msg = "soy root y no tengo caja asignada, pero se lo mando a la primer activa que encuentre";
                    }else{// se notifica al usuario que no hay instancias de cajas activas
                        $openid=null;
                        $msg = "no hay instancias de caja activas";
                    }
                }
            }

            if($openid){
                $platetrycreate = $this->cnx->table('plates')->insertOrIgnore(["plate"=>$plate,"hash"=>$platemd5,"created_at"=>$this->today,"updated_at"=>$this->today,"state"=>1,"vhtype"=>1]);
                $dtplate = $this->cnx->table('plates')->where("plate",$plate)->first();
                $apark = $this->cnx->table('parking')->insertGetId(["_plate"=>$dtplate->id,"_mainservice"=>1,"_tariff"=>$tariff['value'],"init"=>$this->today,"notes"=>$notes,"state"=>1,"_opening"=>$openid ]);
                $dtprinted = $this->emmitCheckin($apark);
                $this->cnx->commit();
                $rset = ["msg"=>$msg,"openid"=>$openid,"iam"=>$iam,"dtpark"=>$dtprinted,"idpark"=>$apark];
            }else{
                $rset = ["msg"=>$msg,"dtpark"=>null,"iam"=>$iam];
            }

            return response()->json($rset,200);
        } catch (\Except $e) {
            return response()->json(["dtpark"=>null,"msg"=>$e->getMessage()],200);
        }                
    }

    private function dataforemmit($idpark){
        return $this->cnx->table('parking')
            ->join('plates','plates.id','=','parking._plate')
            ->select(
                'parking.id as idpark',
                'parking.init as init',
                'parking.ends as ends',
                'parking.notes as notes',
                'parking._mainservice as idmservice',
                'parking._tariff as idmtariff',
                'parking.state as parkstate',
                'plates.plate as plate',
                'plates.id as idplate',
                'plates.hash as hashplate'
            )
            ->where('parking.id',$idpark)->first();
    }

    private function emmitCheckin($idpark,$reprint=false){
        $iam = $this->http->input('login');
        if($iam->rol==1||$iam->rol==2){
            $printip = env("PRINTER_IP");
        }else{
            $printip = env("PRINTER_CAP");
        }

        $connector = new NetworkPrintConnector($printip, 9100);
        $printer = new Printer($connector);

        $dtpark = $this->dataforemmit($idpark);

        try {
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> setEmphasis(true);
            $printer -> text("Estacionamiento\n");
            $printer -> setEmphasis(false);
            $printer -> setTextSize(2, 1);
            $printer -> text("Grupo Vizcarra\n");
            $printer -> setJustification(Printer::JUSTIFY_LEFT);
            $printer -> feed(1);
            $printer -> setTextSize(1,1);
            $printer -> text(" Folio: ");
            $printer -> setTextSize(2,1);
            $printer -> setReverseColors(true);
            $printer -> text(" ".$dtpark->idpark." \n");
            $printer -> setReverseColors(false);
            $printer -> setTextSize(1, 1);
            $printer -> text(" Placa: ".$dtpark->plate."\n");
            $printer -> text(" Entrada: ".$dtpark->init."\n");
            if($dtpark->notes!=""){
                $printer -> feed(1);
                $printer -> text($dtpark->notes."\n");
            }
            $printer -> feed(1);
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> barcode($dtpark->idplate);
            $printer -> text("\nCalle San Pablo #10\nColonia Centro, C.P. 06060\nTel. 55 2220 2120\n");
            $printer -> feed(2);
            $printer->cut();

            $printer -> setReverseColors(true);
            $printer -> setTextSize(8, 8);
            $printer -> text(" ".$dtpark->idpark." \n");
            $printer -> setReverseColors(false);
            $printer -> feed(2);
            $printer->cut();
            $rset=["success"=>true,"msg"=>"Impresion correcta","data"=>$dtpark];
        } catch (\Error $e) {
            $rset = ["success"=>false,"msg"=>$e->getMessage(),"data"=>null];
        }finally {
            $printer -> close();
            return $rset;
        }
    }

    private function stdprecheckout($idpark){
        //getting data plate
        $dtplate = $this->cnx->table('parking')
            ->join('plates','parking._plate','=','plates.id')
            ->where('parking.id',$idpark)
            ->select(
                'parking.id as parkid',
                'parking.init as init',
                'plates.plate as plate',
                'plates.id as plateid',
                'parking._tariff as idtariff',
                'parking._mainservice as idmainservice'
            )
            ->first();
        $resumePay = $this->resumePay($dtplate->init,36);

        return ["dtpark"=>$dtplate,"topay"=>$resumePay];
    }

    public function charge(){
        $iam = $this->http->input('login');
        $topay = $this->http->input('topay');
        $partials = $topay['parts'];
        $init = $topay['init'];
        $calc_attime = $topay['time_calc'];
        $plateid = $topay['plateid'];
        $tariff = $topay['idtariff'];
        $mservice = $topay['idmainservice'];
        $idpark = $topay['parkid'];
        
        $opening = $this->currentOpening($iam->rol,$iam->accid);
        if($opening['rset']){
            $operates = $this->operateSTD($partials,$init,$calc_attime);//sacando el total y cambio
            if($operates['rest']>=0){
                $tkt = $this->openTicket($plateid,$opening['rset'],$partials);//crear el ticket y agregar a una caja
                $freeplace = $this->freeplace($idpark,$calc_attime);//liberar el espacio ocupado
                $printed = $this->emmitPay($idpark);//imprimir ticket
                $this->cnx->commit();
                return response()->json(["calcs"=>$operates,"tkt"=>$tkt,"freeplace"=>$freeplace,"printed"=>$printed],200);
            }else{ return response()->json(["error"=>true,"msg"=>"Favor de cubrir el total"],200); }
        }else{ return response()->json(["error"=>true,"msg"=>$opening['msg']],200);}
    }

    private function emmitPay($idpark,$reprint=false){
        $connector = new NetworkPrintConnector(env("PRINTER_IP"), 9100);
        $printer = new Printer($connector);

        $dtpark = $this->dataforemmit($idpark);
        $resume = $this->resumePay($dtpark->init,36,$dtpark->ends);

        try {
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> setEmphasis(true);
            $printer -> text("Estacionamiento\n");
            $printer -> setEmphasis(false);
            $printer -> setTextSize(2, 1);
            $printer -> text("Grupo Vizcarra\n");
            $printer -> setTextSize(1, 1);
            $printer -> setJustification(Printer::JUSTIFY_LEFT);
            $printer -> feed(1);
            $printer -> text("Placa: ".$dtpark->plate."\n");
            $printer -> text("Entrada: ".$dtpark->init."\n");
            $printer -> text("Salida: ".$dtpark->ends."\n");
            $printer -> text("Tiempo Total: ".$resume['hours']." horas, ".$resume['mints_adds']." minutos\n");
            $printer -> feed(1);
            $printer -> setTextSize(2, 1);
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> text("Total: $".$resume['totalcost']." MXN\n");
            $printer -> setTextSize(1, 1);
            $printer -> text("\nCalle San Pablo #10\nColonia Centro, C.P. 06060\nTel. 55 2220 2120\n");
            $printer -> feed(2);
            $printer->cut();
            $rset=["success"=>true,"msg"=>"Impresion correcta","park"=>$dtpark,"resume"=>$resume];
        } catch (\Error $e) {
            $rset = ["success"=>false,"msg"=>$e->getMessage()];
        }finally {
            $printer -> close();
            return $rset;
        }
    }

    private function freeplace($idpark,$timend){
        return $this->cnx->table('parking')->where('id',$idpark)->update(["state"=>3,"ends"=>$timend]);
    }

    private function addHistory($createHist){
        $idcopy = $this->cnx->table('parking_history')->insertGetId($createHist);
        return $idcopy;
    }

    private function currentOpening($rol,$accid){
        switch ($rol) {
            case 1:
                $rset = $this->cnx->table('cash_openings')->where([ 'active'=>1 ])->first();
                $resp = ["rset"=>$rset,"root"=>true];
            break;
            
            case 2:
                $rset = $this->cnx->table('cash_openings')->where([ 'active'=>1, '_assignto'=>$accid ])->first();
                $resp = ["rset"=>$rset,"root"=>false];
            break;
            
            default: $resp=["rset"=>null,"msg"=>"Sin acceso"]; break;
        }

        return $resp;
    }

    private function openTicket($plateid,$opening,$partials){
        $idopen = $opening->id;
        $tktid = $this->cnx->table('tkt_head')->insertGetId([
            "created_at"=>$this->today,
            "updated_at"=>$this->today,
            "_plate"=>$plateid,
            "_opening"=>$idopen,
            "state"=>1
        ]);

        collect($partials)->map(function($item,$key) use($tktid){
            $ins = $this->cnx->table('tkt_payexs')->insert([
                "_tktid"=>$tktid,
                "_payway"=>$item['method'],
                "cover"=>$item['cant'],
                "notes"=>$item['notes']
            ]);
        });

        return $tktid;
    }

    private function operateSTD($partials,$init,$end){
        $resumePay = $this->resumePay($init,36,$end);
        $resume = collect($resumePay)->only('hours','mints_adds');

        if(count($partials)==1){
            $msgparts="Una sola exhibicion";

            $totaltopay=$resumePay['totalcost'];
            $totalammount = $partials[0]['cant'];
            $paymethod = $partials[0]['method'];
            $rest = $totalammount-$totaltopay;

        }else{ $msgparts="Pago en Exhibiciones"; }
        
        return [
            "totaltopay"=>$totaltopay,
            "totalammount"=>$totalammount,
            "rest"=>$rest,
            "exbs"=>1,
            "resume"=>$resume
        ];
    }

    private function resumePay($init,$pricetime,$timend=null){
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
            "totalcost"=>$total_cost,
            "time_calc"=>$this->today
        ];
    }
}
