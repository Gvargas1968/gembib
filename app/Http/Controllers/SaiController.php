<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use Carbon\Carbon;
use App\Http\Requests\ItemRequest;
use Illuminate\Support\Facades\Auth;
use Rap2hpoutre\FastExcel\FastExcel;
use App\Utils\Util;
use PDF;
use DB;

class SaiController extends Controller
{
    private $campos = Item::campos;
    private $status = Item::status;
    private $procedencia = Item::procedencia;
    private $tipo_material = Item::tipo_material;
    private $tipo_aquisicao = Item::tipo_aquisicao;

    private function search(){
        $request = request();
        $itens = Item::orderBy('tombo', 'desc');

        if($request->has('campos')) {
            $campos = Item::campos;
            unset($campos['todos_campos']);
            //foreach responsável por varrer o select na index do SAI
            foreach($request->campos as $key => $value) {
                $itens->when(!is_null($value) && !is_null($request->search[$key]),
                    function($query) use ($request, $campos, $key, $value) {
                        //string e string_reverse para encontrar autor por "NOME SOBRENOME" ou "SOBRENOME, NOME"
                        $string = explode(' ', $request->search[$key]);
                        $string_reverse = array_reverse($string);
                        $string = implode('%',$string);
                        $string_reverse = implode('%', $string_reverse);

                        if($value == 'todos_campos'){
                            foreach($campos as $chave => $campo) {
                                if($chave == 'titulo'){
                                    $query->where($chave, 'LIKE', '%' . $string . '%');
                                    $query->orWhere($chave, 'LIKE', '%' . $string_reverse . '%');
                                }
                                else{
                                    $query->orWhere($chave, 'LIKE', '%' . $string . '%');
                                    $query->orWhere($chave, 'LIKE', '%' . $string_reverse . '%');                                }
                            }
                        }
                        else {
                            $query->where($value,'LIKE', '%'.$string.'%');
                            $query->orWhere($value,'LIKE', '%' . $string_reverse . '%');
                        }
                    }
                );
                if(!$request->search[$key] && $value){
                    request()->session()->flash('alert-danger','Insira um valor no campo de pesquisa!');
                }
            }
        }

        $itens->when($request->status, function($query) use ($request) {
            $query->where('status', '=', $request->status);
        });

        $itens->when($request->procedencia, function($query) use ($request) {
            $query->where('procedencia', '=', $request->procedencia);
        });

        $itens->when($request->tipo_material, function($query) use ($request) {
            $query->where('tipo_material', '=', $request->tipo_material);
        });

        $itens->when($request->tipo_aquisicao, function($query) use ($request) {
            $query->where('tipo_aquisicao', '=', $request->tipo_aquisicao);
        });
        
        //data_processamento é a data em que entrou no status PROCESSADO
        $itens->when(($request->data_processamento_inicio) && ($request->data_processamento_fim), function($query) use ($request) {
            $from =  Carbon::createFromFormat('d/m/Y', $request->data_processamento_inicio);
            $to = Carbon::createFromFormat('d/m/Y', $request->data_processamento_fim);
            $query->whereBetween('data_processado', [$from, $to]);
            $query->whereNotNull('data_processado');
        });

        $itens->when(($request->data_tombamento_inicio) && ($request->data_tombamento_fim), function($query) use ($request) {
            $from =  Carbon::createFromFormat('d/m/Y', $request->data_tombamento_inicio);
            $to = Carbon::createFromFormat('d/m/Y', $request->data_tombamento_fim);
            $query->whereBetween('data_tombamento', [$from, $to]);
            $query->whereNotNull('data_tombamento');
        });
        
        //data de aquisição e data de tombamento são a mesma coisa, os setores chamam por nomes diferentes e resultou numa confusão entre os termos
        $itens->when(($request->data_aquisicao_inicio) && ($request->data_aquisicao_fim), function($query) use ($request) {
            $from =  Carbon::createFromFormat('d/m/Y', $request->data_aquisicao_inicio);
            $to = Carbon::createFromFormat('d/m/Y', $request->data_aquisicao_fim);
            $query->whereBetween('data_tombamento', [$from, $to]);
            $query->whereNotNull('data_tombamento');
        });
        
        $itens->when(($request->data_sugestao_inicio) && ($request->data_sugestao_fim), function($query) use ($request) {
            $from =  Carbon::createFromFormat('d/m/Y', $request->data_sugestao_inicio);
            $to = Carbon::createFromFormat('d/m/Y', $request->data_sugestao_fim);
            $query->whereBetween('data_sugestao', [$from, $to]);
            $query->whereNotNull('data_sugestao');
        });
        return $itens->toBase();
    }

    public function index(Request $request){
        $this->authorize('sai');

        if($request->relatorio == 'relatorio'){
            return $this->reportItens();
        }

        if($request->excel == 'excel'){
            return $this->excel();
        }

        $query = $this->search()->paginate(15);

        return view('sai.index',[
            'campos'        => $this->campos,
            'query'         => $query,
            'quantidades'   => Util::quantidades($request),
            'procedencia'   => $this->procedencia,
            'tipo_material' => $this->tipo_material,
            'tipo_aquisicao'=> $this->tipo_aquisicao,
            'status'        => $this->status
        ]);
    }

    private function reportItens() {
        $query = $this->search();
        $itens = $query->get();
        $total = $query->sum('preco');

        $pdf = PDF::loadView('pdfs.relatorio', compact('itens','total'));
        $pdf->output();
        $dom_pdf = $pdf->getDomPDF();

        $canvas = $dom_pdf ->get_canvas();
        //A função abaixo serve para colocar o marcador de página na relatorioSTL
        $canvas->page_text(0, 0, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 10, array(0, 0, 0));
        return $pdf->download('relatorioSAI.pdf',[
            'itens' => $itens,
        ]);
    }

    //O excel será criado com todos os campos do Item, caso seja necessário remover algum campo do excel, será necessária a inclusão de um select na criação do $itens na search
    public function excel(){
        $query = $this->search();
        $q = clone $query;
        if($q->count() > 10000){
            request()->session()->flash('alert-danger',"Não foi possível baixar o arquivo,
            limite de 10000 registros excedido");
            return redirect('/sai');
        }
        $export = new FastExcel($query->get());
        return $export->download(date("YmdHi").'gembib.xlsx');
    }
}
