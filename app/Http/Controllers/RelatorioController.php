<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Relatório mensal por competência (para o contador): totais, por cliente, por dia, e exportação em CSV. */
class RelatorioController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('consultar');
        [$inicio, $fim, $mes] = $this->periodo($request);

        $base = fn () => NotaFiscal::query()->whereBetween('data_competencia', [$inicio, $fim]);
        $emitidas = fn () => $base()->where('status', StatusNota::Emitida);

        $porStatus = $base()->where('status', '!=', StatusNota::Rascunho)->selectRaw('status, count(*) as qtd')->groupBy('status')->pluck('qtd', 'status');

        return view('relatorios.index', [
            'mes'        => $mes,
            'inicio'     => Carbon::parse($inicio),
            'empresa'    => app(\App\Support\Tenancy\EmitenteAtual::class)->emitente(),
            'qtdEmitidas' => (int) $emitidas()->count(),
            'valorEmitido' => (float) $emitidas()->sum('valor'),
            'recusadas'  => (int) ($porStatus[StatusNota::Rejeitada->value] ?? 0),
            'invalidas'  => (int) ($porStatus[StatusNota::Invalida->value] ?? 0),
            'falhas'     => (int) ($porStatus[StatusNota::Falhou->value] ?? 0),
            'andamento'  => (int) collect(StatusNota::andamento())->sum(fn ($s) => $porStatus[$s->value] ?? 0),
            'semDestinatario' => (float) $emitidas()->where('sem_tomador', true)->sum('valor'),
            'porCliente' => $emitidas()->selectRaw('sem_tomador, tomador_documento, max(tomador_nome) as nome, count(*) as qtd, sum(valor) as total')
                ->groupBy('sem_tomador', 'tomador_documento')->orderByDesc('total')->limit(50)->get(),
            'porDia'     => $emitidas()->selectRaw('data_competencia as dia, count(*) as qtd, sum(valor) as total')->groupBy('data_competencia')->orderBy('data_competencia')->get(),
        ]);
    }

    /** CSV das notas emitidas do mês (abre direto no Excel: UTF-8 com BOM e ponto e vírgula). */
    public function csv(Request $request)
    {
        $this->authorize('consultar');
        [$inicio, $fim, $mes] = $this->periodo($request);

        $notas = NotaFiscal::with('emitente')->where('status', StatusNota::Emitida)->whereBetween('data_competencia', [$inicio, $fim])
            ->orderBy('data_competencia')->orderBy('id');

        return response()->streamDownload(function () use ($notas) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Competência', 'NFS-e nº', 'Emitida em', 'Cliente', 'CNPJ/CPF', 'Valor (R$)', 'Código de serviço', 'Descrição', 'Empresa', 'Chave de acesso'], ';');
            $notas->chunkById(500, function ($lote) use ($f) {
                foreach ($lote as $n) {
                    fputcsv($f, [
                        $n->data_competencia?->format('d/m/Y'), $n->numero_nfse, $n->emitida_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                        $n->sem_tomador ? 'Sem destinatário informado' : $n->tomador_nome, $n->sem_tomador ? '' : \App\Support\Documento::formatar($n->tomador_documento),
                        number_format((float) $n->valor, 2, ',', ''), $n->codigo_servico, $n->descricao, $n->emitente?->razao_social, $n->chave_acesso,
                    ], ';');
                }
            });
            fclose($f);
        }, "notas-emitidas-{$mes}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0:string,1:string,2:string} início, fim (datas) e o mês no formato AAAA-MM */
    private function periodo(Request $request): array
    {
        $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('mes')) ? $request->query('mes') : now()->format('Y-m');
        $d = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth();

        return [$d->toDateString(), $d->copy()->endOfMonth()->toDateString(), $mes];
    }
}
