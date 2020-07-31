<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
        return $this->list();
    }

    private function list(){
        return $this->cnx->table('parking')->get();
    }

    public function mginput(){
        $iam = $this->http->input('login');
        $input = $this->http->input('mginput');

        //definiendo si la placa existe
        $exist = $this->cnx->table('plates')->where('plate',$input)->orWhere('hash',$input)->first();

        //acerca de los lugares
        // $placespark = $this->abtplaces();

        if($exist){
            //validar si esta activa en estacionamiento
            $itsinpark = $this->cnx->table('parking')->where('_plate',$exist->id)->first();
            if($itsinpark){// si esta en el estacionamiento
                switch ($itsinpark->_mainservice) {
                    case 2: 
                        $nextaction = $itsinpark->state==1 ? "digCheckOut":"digCheckIn";
                        $rset=["msg"=>"Servicio de pension","inpark"=>$itsinpark,"nextaction"=>$nextaction];
                    break;
                    default:
                        $nextaction = $itsinpark->state==1 ? "preCheckout":"WTF!!!";
                        $rset=["msg"=>"Servicio de parqueo","inpark"=>$itsinpark,"nextaction"=>$nextaction];
                    break;
                }
                
            }else{ $rset=["msg"=>"Reingreso!! ","inpark"=>205,"nextaction"=>"takeYourChoice"]; }//registrada previamente, pero no esta en estacionmaiento
        }else{ $rset=["msg"=>"Sin coincidencias para ".$input,"inpark"=>404,"nextaction"=>"itsYourChoice"]; }//sin registro previo

        return response()->json($rset, 200);
        // return response()->json([$exist,$inputmd5,$input], 200);

    }

    public function stdcheckin(){
        $iam = $this->http->input('login');
        $plate = $this->http->input('plate');
        $platemd5 = md5($plate);
        $tariff = $this->http->input('tariff');

        $platetrycreate = $this->cnx->table('plates')->insertOrIgnore(["plate"=>$plate,"hash"=>$platemd5,"created_at"=>$this->today,"updated_at"=>$this->today,"state"=>1,"vhtype"=>1]);
        $dtplate = $this->cnx->table('plates')->where("plate",$plate)->first();
        $apark = $this->cnx->table('parking')->insertGetId(["_plate"=>$dtplate->id,"_mainservice"=>1,"_tariff"=>$tariff,"state"=>1,"init"=>$this->today ]);
        $dtpark = $this->cnx->table('parking')->where('id',$apark)->first();

        $this->cnx->commit();

        return response()->json(["dtplate"=>$dtplate,"dtpark"=>$dtpark],200);
    }

    public function stdprecheckout(){
        $iam = $this->http->input('login');
        $input = $this->http->input('input');

        //getting data plate
        $dtplate = $this->cnx->table('plates')
            ->join('parking','parking._plate','=','plates.id')
            ->where('plate',$input)
            ->orWhere('hash',$input)
            ->select(
                'parking.id as parkid',
                'parking.init as init',
                'plates.plate as plate',
                'plates.id as plateid',
                'parking._tariff as idtariff',
                'parking._mainservice as idmainservice'
            )
            ->first();
        
        /** me quede aqui, ya hace el calculo del tiempo de estacionamiento */
        $resumePay = $this->resumePay($dtplate->init,35);

        return response()->json(["dtpark"=>$dtplate,"topay"=>$resumePay],200);
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

        if($this->cnx->table('parking')->where('id',$idpark)->first()){

            $opening = $this->currentOpening($iam->rol,$iam->accid);
            if($opening['rset']){
                $operates = $this->operateSTD($partials,$init,$calc_attime);//sacando el total y cambio
                if($operates['rest']>=0){
                    $tkt = $this->openTicket($plateid,$opening['rset'],$partials);//crear el ticket y agregar a una caja
                    $createHist=[ "start"=>$init, "ends"=>$calc_attime, "_plate"=>$plateid, "_tariff"=>$tariff, "_mainservice"=>$mservice, "_tkthead"=>$tkt, "pricetime"=>35];
                    $idcopy = $this->addHistory($createHist);//crear registro historico del parking
                    $freeplace = $this->freeplace($idpark);//liberar el espacio ocupado
                    $this->cnx->commit();
                    //imprimir ticket
                    return response()->json(["calcs"=>$operates,"tkt"=>$tkt,"idcopy"=>$idcopy,"freeplace"=>$freeplace],200);
                }else{ return response()->json(["error"=>true,"msg"=>"Favor de cubrir el total"],200); }
            }else{ return response()->json(["error"=>true,"msg"=>$opening['msg']],200);}
        }else{ return response()->json(["error"=>true,"msg"=>"Este id ($idpark), ya fue liberado"],200);}
    }

    private function freeplace($idpark){
        return $this->cnx->table('parking')->where('id',$idpark)->delete();
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
        $resumePay = $this->resumePay($init,35,$end);
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

    private function abtplaces(){
        $placesparkcfg = $this->cnx->table('park_config')->first();
        $occuped = $this->cnx->table('parking')->get();

        return [
            "maxparkplaces"=>$placesparkcfg,
            "occuped"=>$occuped
        ];
    }
}
