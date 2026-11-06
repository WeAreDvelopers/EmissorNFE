<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaMaster;
use App\Models\Carteira;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\MovimentoCredito;
use App\Models\NotaFiscal;
use App\Models\Pagamento;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Área master (somente leitura): visão de toda a plataforma.
 * Os modelos por conta são lidos com semIsolamento() de propósito: é para isso que esta área existe.
 */
class MasterController extends Controller
{
    private const PERIODOS = [7, 30, 90];

    private function notas()
    {
        return NotaFiscal::semIsolamento();
    }

    // ------------------------------------------------------------------ visão geral

    public function visao(Request $request)
    {
        $dias = in_array((int) $request->query('dias', 30), self::PERIODOS, true) ? (int) $request->query('dias', 30) : 30;
        $desde = now()->subDays($dias)->startOfDay();

        $emitidas = $this->notas()->where('status', 'emitida')->where('emitida_em', '>=', $desde)->count();
        $recusadas = $this->notas()->where('status', 'rejeitada')->where('updated_at', '>=', $desde)->count();
        $falhas = $this->notas()->where('status', 'falhou')->where('updated_at', '>=', $desde)->count();

        $fila = $this->notas()->whereIn('status', ['pendente', 'enviando', 'aguardando_credito'])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $pagos = Pagamento::semIsolamento()->where('status', 'pago')->where('pago_em', '>=', $desde);

        return view('master.visao', [
            'dias'     => $dias,
            'periodos' => self::PERIODOS,
            'contas'   => [
                'total'   => Conta::count(),
                'ativas'  => Conta::where('status', 'ativa')->count(),
                'com_atividade' => Importacao::semIsolamento()->where('created_at', '>=', $desde)->distinct()->count('conta_id'),
            ],
            'notas' => [
                'emitidas'  => $emitidas,
                'recusadas' => $recusadas,
                'falhas'    => $falhas,
                'taxa_recusa' => ($emitidas + $recusadas) > 0 ? round($recusadas / ($emitidas + $recusadas) * 100, 1) : null,
                'hoje'      => $this->notas()->where('status', 'emitida')->where('emitida_em', '>=', now()->startOfDay())->count(),
                'valor'     => (float) $this->notas()->where('status', 'emitida')->where('emitida_em', '>=', $desde)->sum('valor'),
            ],
            'fila' => [
                'pendente'  => (int) ($fila['pendente'] ?? 0) + (int) ($fila['enviando'] ?? 0),
                'aguardando_credito' => (int) ($fila['aguardando_credito'] ?? 0),
            ],
            'emitentes' => [
                'total'      => Emitente::semIsolamento()->count(),
                'producao'   => Emitente::semIsolamento()->where('ambiente', 1)->count(),
                'homologacao' => Emitente::semIsolamento()->where('ambiente', '!=', 1)->count(),
                'cert_vencido'  => Emitente::semIsolamento()->whereNotNull('certificado_validade')->where('certificado_validade', '<', now())->count(),
                'cert_vencendo' => Emitente::semIsolamento()->whereBetween('certificado_validade', [now(), now()->addDays(30)])->count(),
            ],
            'creditos' => [
                'disponivel' => (int) Carteira::sum('disponivel'),
                'reservado'  => (int) Carteira::sum('reservado'),
                'vendas_qtd' => (clone $pagos)->count(),
                'vendas_valor' => ((int) (clone $pagos)->sum('valor_centavos')) / 100,
                'vendas_creditos' => (int) (clone $pagos)->sum('creditos'),
            ],
            'serie'          => $this->serieDiaria(14),
            'erros'          => $this->errosFrequentes($desde),
            'atencao'        => $this->atencao(),
            'importacoes'    => $this->importacoesComProblema(),
        ]);
    }

    /** Notas emitidas por dia nos últimos N dias (inclui dias zerados, para o gráfico). */
    private function serieDiaria(int $dias): array
    {
        $inicio = now()->subDays($dias - 1)->startOfDay();
        $porDia = $this->notas()->where('status', 'emitida')->where('emitida_em', '>=', $inicio)
            ->selectRaw('date(emitida_em) as dia, count(*) as total')->groupBy('dia')->pluck('total', 'dia');

        return collect(range(0, $dias - 1))->map(function ($i) use ($inicio, $porDia) {
            $dia = $inicio->copy()->addDays($i);

            return ['dia' => $dia, 'total' => (int) ($porDia[$dia->toDateString()] ?? 0)];
        })->all();
    }

    /** Códigos de erro que mais aparecem nas notas recusadas/com falha (E0312, E1235, XSD…). */
    private function errosFrequentes(Carbon $desde): array
    {
        $contagem = [];

        $this->notas()->whereIn('status', ['rejeitada', 'falhou'])->where('updated_at', '>=', $desde)->whereNotNull('erros')
            ->orderByDesc('id')->limit(1500)->get(['id', 'conta_id', 'erros'])
            ->each(function ($nota) use (&$contagem) {
                foreach ((array) $nota->erros as $linha) {
                    $linha = (string) $linha;
                    $codigo = preg_match('/^(E\d{4}|XSD)\b/', $linha, $m) ? $m[1] : 'Outros';
                    $contagem[$codigo] ??= ['codigo' => $codigo, 'qtd' => 0, 'contas' => [], 'exemplo' => mb_substr($linha, 0, 160)];
                    $contagem[$codigo]['qtd']++;
                    $contagem[$codigo]['contas'][$nota->conta_id] = true;
                }
            });

        return collect($contagem)->map(fn ($e) => [...$e, 'contas' => count($e['contas'])])
            ->sortByDesc('qtd')->take(8)->values()->all();
    }

    /** Quem precisa de uma olhada: certificado, saldo e recusas. */
    private function atencao(): array
    {
        $contas = fn ($ids) => Conta::whereIn('id', $ids)->pluck('nome', 'id');

        $certs = Emitente::semIsolamento()->whereNotNull('certificado_validade')
            ->where('certificado_validade', '<', now()->addDays(30))->orderBy('certificado_validade')->limit(8)
            ->get(['id', 'conta_id', 'razao_social', 'certificado_validade']);

        $semSaldo = $this->notas()->where('status', 'aguardando_credito')
            ->selectRaw('conta_id, count(*) as total')->groupBy('conta_id')->orderByDesc('total')->limit(6)->get();

        $recusas = $this->notas()->where('status', 'rejeitada')->where('updated_at', '>=', now()->subDays(7))
            ->selectRaw('conta_id, count(*) as total')->groupBy('conta_id')->havingRaw('count(*) >= 3')->orderByDesc('total')->limit(6)->get();

        $nomes = $contas($certs->pluck('conta_id')->merge($semSaldo->pluck('conta_id'))->merge($recusas->pluck('conta_id'))->unique());

        return [
            'certificados' => $certs->map(fn ($e) => ['conta_id' => $e->conta_id, 'conta' => $nomes[$e->conta_id] ?? '—', 'empresa' => $e->razao_social, 'validade' => $e->certificado_validade])->all(),
            'sem_saldo'    => $semSaldo->map(fn ($l) => ['conta_id' => $l->conta_id, 'conta' => $nomes[$l->conta_id] ?? '—', 'total' => $l->total])->all(),
            'recusas'      => $recusas->map(fn ($l) => ['conta_id' => $l->conta_id, 'conta' => $nomes[$l->conta_id] ?? '—', 'total' => $l->total])->all(),
        ];
    }

    /** Importações que falharam na leitura ou estão travadas (lendo há mais de 15 minutos). */
    private function importacoesComProblema(): array
    {
        $base = Importacao::semIsolamento()->with(['conta:id,nome']);

        return [
            'falhas'   => (clone $base)->where('status', 'falhou')->where('created_at', '>=', now()->subDays(7))->latest()->limit(6)->get(),
            'travadas' => (clone $base)->whereIn('status', ['pendente', 'lendo'])->where('created_at', '<', now()->subMinutes(15))->latest()->limit(6)->get(),
        ];
    }

    // ------------------------------------------------------------------ clientes

    public function clientes(Request $request)
    {
        $mes = now()->startOfMonth();
        $busca = trim((string) $request->query('q', ''));
        $situacao = $request->query('situacao');
        $ordem = $request->query('ordem', 'atividade');

        $consulta = Conta::query()->select('contas.*')
            ->selectSub(Carteira::select('disponivel')->whereColumn('conta_id', 'contas.id')->limit(1), 'saldo')
            ->selectSub($this->notas()->selectRaw('count(*)')->whereColumn('conta_id', 'contas.id')->where('status', 'emitida')->where('emitida_em', '>=', $mes), 'emitidas_mes')
            ->selectSub($this->notas()->selectRaw('count(*)')->whereColumn('conta_id', 'contas.id')->where('status', 'rejeitada')->where('updated_at', '>=', now()->subDays(30)), 'recusadas_30d')
            ->selectSub($this->notas()->selectRaw('max(updated_at)')->whereColumn('conta_id', 'contas.id'), 'ultima_atividade')
            ->withCount(['usuarios', 'emitentes']);

        if ($busca !== '') {
            $digitos = preg_replace('/\D/', '', $busca);
            $consulta->where(function ($q) use ($busca, $digitos) {
                $q->where('nome', 'like', "%{$busca}%")->orWhere('email_financeiro', 'like', "%{$busca}%");
                if ($digitos !== '') {   // sem dígitos, "%%" casaria com todos os documentos
                    $q->orWhere('documento', 'like', "%{$digitos}%");
                }
            });
        }
        if (in_array($situacao, ['ativa', 'suspensa', 'bloqueada_saldo_negativo'], true)) {
            $consulta->where('status', $situacao);
        }

        match ($ordem) {
            'nome'     => $consulta->orderBy('nome'),
            'saldo'    => $consulta->orderBy('saldo'),
            'emitidas' => $consulta->orderByDesc('emitidas_mes'),
            'recusas'  => $consulta->orderByDesc('recusadas_30d'),
            default    => $consulta->orderByRaw('ultima_atividade is null')->orderByDesc('ultima_atividade'),
        };

        $contas = $consulta->paginate(25)->withQueryString();
        $contas->getCollection()->load(['emitentes' => fn ($q) => $q->withoutGlobalScope('conta')]);

        return view('master.clientes', compact('contas', 'busca', 'situacao', 'ordem'));
    }

    // ------------------------------------------------------------------ um cliente

    public function cliente(Conta $conta)
    {
        AuditoriaMaster::registrar('ver_conta', $conta->id);

        $mes = now()->startOfMonth();
        $notas = fn () => $this->notas()->where('conta_id', $conta->id);

        return view('master.cliente', [
            'conta'    => $conta->load('revenda'),
            'usuarios' => $conta->usuarios()->get(),
            'emitentes' => $conta->emitentes()->orderBy('razao_social')->get(),
            'carteira' => Carteira::where('conta_id', $conta->id)->first(),
            'movimentos' => MovimentoCredito::semIsolamento()->where('conta_id', $conta->id)->latest('id')->limit(12)->get(),
            'porStatus' => $notas()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'emitidasMes' => $notas()->where('status', 'emitida')->where('emitida_em', '>=', $mes)->count(),
            'valorMes'    => (float) $notas()->where('status', 'emitida')->where('emitida_em', '>=', $mes)->sum('valor'),
            'importacoes' => Importacao::semIsolamento()->where('conta_id', $conta->id)
                ->with(['emitente' => fn ($q) => $q->withoutGlobalScope('conta')])->latest()->limit(8)->get(),
            'ultimosErros' => $notas()->whereIn('status', ['rejeitada', 'falhou'])->latest('updated_at')->limit(10)
                ->get(['id', 'linha_planilha', 'tomador_nome', 'codigo_servico', 'status', 'erros', 'updated_at']),
            'pagamentos'  => Pagamento::semIsolamento()->where('conta_id', $conta->id)->where('status', 'pago')->latest('pago_em')->limit(5)->get(),
        ]);
    }
}
