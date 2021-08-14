<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

date_default_timezone_set('America/Mexico_City');
Carbon::setLocale('es');

class ReportsController extends Controller
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
        $this->tariff36 = Carbon::parse('2021-02-17 23:50:00');

        $this->rangeDates = $this->setPeriod();
    }

    public function test(){ return response()->json(['message' => 'Successfully TEST!!!']); }

    public function fix(){
        
        try {

            
        } catch (\Exp $e) { return response()->json( ['error'=>$e->getMessage()] ); }

    }

    public function period(){

        $namefile = "SmartPark_Period_".$this->todayobj->format('Ymd_His').".xlsx";

        try {

            $cash = new CashdeskController($this->http);

            $entries= collect(
                DB::table('parking')
                ->leftJoin('tkt_head',function($join){
                    $join->on('parking._opening','=','tkt_head._opening')->on('parking._plate','=','tkt_head._plate');
                })
                ->join('plates','plates.id','=','parking._plate')
                ->leftJoin('tkt_payexs','tkt_head.id','=','tkt_payexs._tktid')
                ->select(
                    'parking.id as folio_pid',
                    'tkt_payexs._tktid as tktid',
                    'tkt_head.id as thid',
                    'plates.plate as placa',
                    'parking.state as estado',
                    'parking.init as entrada',
                    'parking.ends as salida',
                    'parking.notes as vehiculo',
                    'parking._opening as p_apertura',
                    'tkt_head._opening as th_apertura',
                    'parking._plate as p_idplaca',
                    'tkt_head._plate as th_plate',
                    'tkt_payexs.cover as pago'
                )
                ->orderBy('parking.init','asc')
                ->whereBetween('parking.init',[ $this->rangeDates["from"],$this->rangeDates["to"] ])
                ->get()
            )->map(function($park,$key) use($cash){
                $park->tarifa=null;
                $park->total_desc=null;
                $park->total=null;
                $park->cambio=null;

                if ($park->estado==3) {
                    $tarifa = Carbon::parse($park->entrada)->lessThan($this->tariff36) ? 36:30;
                    $park->total_desc = $cash->calcPay($park->entrada,$tarifa,$park->salida);
                    $park->total=$park->total_desc["totalcost"];
                    $park->tarifa = $tarifa;
                    $park->cambio = ($park->pago-$park->total_desc["totalcost"]);
                }

                return $park;
            });

            $total_entries = count($entries);

            $response = [
                'msg'=>"OK",
                'total_entries'=>$total_entries,
                'period'=>$this->rangeDates,
                'file'=>$namefile,
                'parks'=>$entries
            ];

            return response()->json( $response );
        }catch (\Exp $e) { return response()->json( ['error'=>$e->getMessage()] ); }
    }

    public function master(Request $request){

        $namefile = "SmartPark_Master_".$this->todayobj->format('Ymd_His').".xlsx";

        try {
            $cash = new CashdeskController($this->http);

            $parks= collect(
                DB::table('parking')
                ->leftJoin('tkt_head',function($join){
                    $join->on('parking._opening','=','tkt_head._opening')
                    ->on('parking._plate','=','tkt_head._plate');
                })
                ->join('plates','plates.id','=','parking._plate')
                ->select(
                    'tkt_head.id as ticket_id',
                    'plates.plate as placa',
                    'parking._plate as idplaca',
                    'parking.init as entrada',
                    'parking.ends as salida',
                    'parking.state as estado',
                    'parking.notes as notas',
                    'parking._opening as apertura'
                )
                ->orderBy('parking.init','asc')
                // ->limit(15000)
                ->get()
            );

            $totalparks = count($parks);

            $_parksCovers = $parks->filter(function($park,$key){ return $park->estado==3; })->map(function($park,$key) use($cash){
                $tarifa = Carbon::parse($park->entrada)->lessThan($this->tariff36) ? 36:30;
                $park->total = $cash->calcPay($park->entrada,$tarifa,$park->salida);
                $park->tarifa = $tarifa;
                return $park;
            })->values();

            $months = [];
            $firstentry = Carbon::parse($_parksCovers[0]->entrada);
            $lastentry = Carbon::parse($_parksCovers[(sizeof($_parksCovers)-1)]->entrada);
            // $dateStart = $firstentry->copy()->startOfMonth();
            $currDate = $firstentry->copy()->startOfMonth();
            $dateEnd = $lastentry->copy()->endOfMonth();

            $spreadsheet = new Spreadsheet();
            $workSheet = new Worksheet($spreadsheet, "Resumen");

            $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('H')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('I')->setAutoSize(true);
                    $spreadsheet->getActiveSheet()->getColumnDimension('j')->setAutoSize(true);

                    $spreadsheet->getActiveSheet()->getCell("A1")->setValue("FOLIO");
                    $spreadsheet->getActiveSheet()->getCell("B1")->setValue("PLACA");
                    $spreadsheet->getActiveSheet()->getCell("C1")->setValue("NOTAS");
                    $spreadsheet->getActiveSheet()->getCell("D1")->setValue("APERTURA");
                    $spreadsheet->getActiveSheet()->getCell("E1")->setValue("ENTRADA");
                    $spreadsheet->getActiveSheet()->getCell("F1")->setValue("SALIDA");
                    $spreadsheet->getActiveSheet()->getCell("G1")->setValue("TARIFA");
                    $spreadsheet->getActiveSheet()->getCell("H1")->setValue("TIEMPO");
                $spreadsheet->getActiveSheet()->getCell("I1")->setValue("TOTAL");

                $cell = 2;

                foreach ($_parksCovers as $park) {
                    $spreadsheet->getActiveSheet()->getCell("A{$cell}")->setValue($park->ticket_id);
                    $spreadsheet->getActiveSheet()->getCell("B{$cell}")->setValue($park->placa);
                    $spreadsheet->getActiveSheet()->getCell("C{$cell}")->setValue($park->notas);
                    $spreadsheet->getActiveSheet()->getCell("D{$cell}")->setValue($park->apertura);
                    $spreadsheet->getActiveSheet()->getCell("E{$cell}")->setValue($park->entrada);
                    $spreadsheet->getActiveSheet()->getCell("F{$cell}")->setValue($park->salida);
                    $spreadsheet->getActiveSheet()->getCell("G{$cell}")->setValue($park->tarifa);
                    $spreadsheet->getActiveSheet()->getCell("H{$cell}")->setValue($park->total["hours"].":".$park->total["mints_adds"]." (".$park->total["total_minutes"]." mins)");
                    $spreadsheet->getActiveSheet()->getCell("I{$cell}")->setValue($park->total["totalcost"]);
                    $cell++;
                }

            $cell--;

            while ($currDate->lessThan($dateEnd)) {

                $data = [
                    'humanDate'=>$currDate->isoFormat('MMMM, Y'),
                    'from'=>$currDate->startOfMonth()->format('Y-m-d H:m:i'),
                    'to'=>$currDate->copy()->endOfMonth()->format('Y-m-d H:m:i'),
                ];

                $entries = $_parksCovers->filter(function($park,$key) use($data){ return Carbon::parse($park->entrada)->between($data['from'], $data['to']); });
                $amount = $entries->reduce(function($amt,$park){ return $amt+$park->total["totalcost"]; },0);

                $data['entradas'] = sizeof($entries);
                $data['total'] = $amount;

                $months[]=$data;
                $currDate->addMonth();
            }

            $xlsx = new Xlsx($spreadsheet);
            $xlsx->save("./".$namefile);
            
            $_parksCancels = $parks->filter(function($park,$key){ return $park->estado!==3; });            

            $response = [
                'msg'=>"OK",
                'totalparks'=>$totalparks,
                'cell'=>$cell,
                'firstentry'=>$firstentry->format('Y-m-d H:m:i'),
                'lastentry'=>$lastentry->format('Y-m-d H:m:i'),
                'months'=>$months
            ];
            return response()->json( $response );
        } catch (\Exp $e) { return response()->json( ['error'=>$e->getMessage()] ); }

    }

    public function setPeriod(){
        if($this->http->input('periodo')){
            $range = explode(",",$this->http->input('periodo'));

            if (count($range)==1) {
                $date = Carbon::parse($range[0]);
                return [
                    "dia"=>$date->isoFormat('dddd'),
                    "from"=>$date->startOfDay()->format('Y-m-d H:i:s'),
                    "to"=>$date->endOfDay()->format('Y-m-d H:i:s')
                ];
            }

            if(count($range)==2){
                $start = Carbon::parse($range[0]);
                $end = Carbon::parse($range[1]);

                return [
                    "from"=>Carbon::parse(trim($start))->format('Y-m-d H:i:s'),
                    "to"=>Carbon::parse(trim($end))->format('Y-m-d H:i:s')
                ];
            }
        }else{
            return [
                "from"=>$this->todayobj->startOfDay()->format('Y-m-d H:i:s'),
                "to"=>$this->todayobj->endOfDay()->format('Y-m-d H:i:s')
            ];
        }
    }
}