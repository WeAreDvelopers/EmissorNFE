<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;
use App\Support\Tenancy\ContaAtual;

/** Tela inicial: responde "o que preciso fazer agora?" antes de qualquer número. */
class InicioController extends Controller
{
    public function __invoke(ContaAtual $contaAtual, CarteiraService $creditos)
    {
        $this->authorize('consultar');

        $porStatus = NotaFiscal::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $n = fn (StatusNota $s) => (int) ($porStatus[$s->value] ?? 0);

        $carteira = $creditos->saldo($contaAtual->id());
        $recentes = Importacao::with('emitente')->latest()->limit(5)->get();

        $resumos = NotaFiscal::whereIn('importacao_id', $recentes->pluck('id'))
            ->selectRaw('importacao_id, status, count(*) as total')->groupBy('importacao_id', 'status')->get()
            ->groupBy('importacao_id')
            ->map(fn ($l) => collect(StatusNota::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($l->firstWhere('status', $s->value)->total ?? 0)])->all());

        return view('inicio', [
            'carteira'   => $carteira,
            'emitidasMes' => NotaFiscal::where('status', StatusNota::Emitida)->where('emitida_em', '>=', now()->startOfMonth())->count(),
            'andamento'  => $n(StatusNota::Pendente) + $n(StatusNota::Enviando) + $n(StatusNota::AguardandoCredito),
            'aguardandoCredito' => $n(StatusNota::AguardandoCredito),
            'recusadas'  => $n(StatusNota::Rejeitada),
            'invalidas'  => $n(StatusNota::Invalida),
            'falhas'     => $n(StatusNota::Falhou),
            'recentes'   => $recentes,
            'resumos'    => $resumos,
            'temEmitente' => \App\Models\Emitente::exists(),
        ]);
    }
}
