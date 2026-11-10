<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Services\Nfse\ConciliacaoNfse;
use App\Support\Ordenacao;
use Illuminate\Http\Request;

/** Consulta de notas de todos os lotes: por cliente, número da NFS-e, chave, situação, empresa e competência. */
class NotaConsultaController extends Controller
{
    public const GRUPOS = ['todas' => 'Todas', 'emitida' => 'Emitidas', 'andamento' => 'Em andamento', 'atencao' => 'Precisam de você'];

    public const ORDENAVEIS = ['cliente' => 'tomador_nome', 'competencia' => 'data_competencia', 'valor' => 'valor', 'situacao' => 'status', 'nfse' => 'numero_nfse'];

    public function index(Request $request)
    {
        $this->authorize('consultar');

        $consulta = self::filtrar(NotaFiscal::with(['emitente', 'importacao', 'tomador']), $request)->orderByDesc('id');
        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, self::ORDENAVEIS);

        return view('notas.index', [
            'notas'     => $consulta->paginate(30)->withQueryString(),
            'emitentes' => Emitente::orderBy('razao_social')->get(['id', 'razao_social']),
            'grupos'    => self::GRUPOS,
            'filtros'   => $request->only(['q', 'grupo', 'emitente', 'de', 'ate']),
            'ordem'     => $ordem,
            'dir'       => $dir,
            // Notas deste filtro que ainda não saíram: na fila, aguardando crédito ou com erro (não entram no total de emitidas)
            'totalPendentes' => self::filtrar(NotaFiscal::query(), $request)->whereIn('status', [...StatusNota::andamento(), ...StatusNota::atencao()])->sum('valor'),
            'emAndamento' => self::filtrar(NotaFiscal::query(), $request)->whereIn('status', StatusNota::andamento())->exists(),
            'totalEmitidas' => (float) self::filtrar(NotaFiscal::query(), $request)->where('status', StatusNota::Emitida)->sum('valor'),
        ]);
    }

    /** Filtros compartilhados com os relatórios. Rascunhos (lote ainda não enviado) ficam de fora: não são notas ainda. */
    public static function filtrar($consulta, Request $request)
    {
        $consulta->where('status', '!=', StatusNota::Rascunho);

        if (filled($q = trim((string) $request->query('q')))) {
            $digitos = preg_replace('/\D/', '', $q);
            $consulta->where(function ($w) use ($q, $digitos) {
                $w->where('tomador_nome', 'like', "%{$q}%")->orWhere('chave_acesso', 'like', "%{$q}%")->orWhere('numero_nfse', $q);
                if ($digitos !== '') {
                    $w->orWhere('tomador_documento', 'like', "%{$digitos}%");
                }
            });
        }

        match ($request->query('grupo')) {
            'emitida'   => $consulta->where('status', StatusNota::Emitida),
            'andamento' => $consulta->whereIn('status', StatusNota::andamento()),
            'atencao'   => $consulta->whereIn('status', StatusNota::atencao()),
            default     => null,
        };

        if ($request->filled('emitente')) {
            $consulta->where('emitente_id', $request->integer('emitente'));
        }
        if ($request->filled('de')) {
            $consulta->whereDate('data_competencia', '>=', $request->query('de'));
        }
        if ($request->filled('ate')) {
            $consulta->whereDate('data_competencia', '<=', $request->query('ate'));
        }

        return $consulta;
    }

    /** "Conferir na SEFIN": para nota com resultado incerto, pergunta à SEFIN pelo identificador original da DPS. */
    public function conciliar(Request $request, NotaFiscal $nota, ConciliacaoNfse $conciliacao)
    {
        $this->authorize('emitir');
        abort_unless(in_array($nota->status, [StatusNota::Falhou, StatusNota::Enviando, StatusNota::Pendente], true), 422, 'Só dá para conferir notas que não saíram ou estão sem resposta.');

        try {
            $resultado = $conciliacao->conciliar($nota);
        } catch (\Throwable $e) {
            report($e);
            $msg = 'Não conseguimos falar com a SEFIN agora. Tente de novo em alguns minutos.';

            return $request->expectsJson() ? response()->json(['message' => $msg], 502) : back()->withErrors(['conciliacao' => $msg]);
        }

        \App\Models\Auditoria::registrar('nota_conciliada', "Nota #{$nota->id} conferida na SEFIN: {$resultado}.", ['nota' => $nota->id, 'resultado' => $resultado]);

        $msg = match ($resultado) {
            ConciliacaoNfse::AUTORIZADA => 'A SEFIN já tinha autorizado esta nota. Ela foi marcada como emitida.',
            ConciliacaoNfse::NAO_ENCONTRADA => 'A SEFIN não tem esta nota. Ela não foi emitida e pode ser reenviada com segurança.',
            ConciliacaoNfse::SEM_ENVIO => 'Esta nota nunca chegou a ser enviada à SEFIN.',
            default => 'Esta nota já está emitida.',
        };

        return $request->expectsJson() ? response()->json(['ok' => true, 'resultado' => $resultado, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }
}
