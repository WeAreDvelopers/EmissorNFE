<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Nfse\EnvioDeNotas;
use App\Support\ExplicacaoErro;
use App\Support\ValidadorLinha;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotaController extends Controller
{
    /** "Precisa de você": notas que exigem ação, de todas as importações, filtráveis por causa. */
    public function pendencias(Request $request)
    {
        $this->authorize('consultar');

        $grupo = $request->query('grupo');
        $status = $grupo ? ExplicacaoErro::statusDoGrupo($grupo) : null;

        $notas = NotaFiscal::with('importacao')
            ->whereIn('status', $status ? [$status] : StatusNota::atencao())
            ->orderBy('importacao_id')->orderBy('linha_planilha')
            ->paginate(25)->withQueryString();

        $contagem = NotaFiscal::whereIn('status', StatusNota::atencao())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $selecionada = $request->integer('nota')
            ? NotaFiscal::with('emitente')->find($request->integer('nota'))
            : null;

        return view('notas.pendencias', compact('notas', 'grupo', 'contagem', 'selecionada'));
    }

    /**
     * Corrige os dados de uma nota e a põe de volta no caminho:
     * - antes do envio (importação em "conferir"): vira rascunho pronto, sem custo;
     * - depois do envio: reserva crédito de novo e reenvia (mesmo número de DPS).
     */
    public function corrigir(Request $request, NotaFiscal $nota, EnvioDeNotas $envio)
    {
        $this->authorize('emitir');
        abort_unless(in_array($nota->status, [StatusNota::Invalida, StatusNota::Rascunho, StatusNota::Rejeitada, StatusNota::Falhou], true), 422);

        $recebidos = $request->only(ValidadorLinha::CAMPOS);
        $atuais = $nota->only(ValidadorLinha::CAMPOS);
        $atuais['data_competencia'] = $nota->data_competencia?->toDateString();

        $dados = ValidadorLinha::normalizar([...$atuais, ...$recebidos]);
        $validador = ValidadorLinha::validar($dados, $nota->emitente);

        if ($validador->fails()) {
            throw ValidationException::withMessages($validador->errors()->messages());
        }

        $codigoAntigo = $nota->codigo_servico;
        $nota->update(ValidadorLinha::paraGravar($dados));

        $afetadas = collect([$nota]);

        // "Aplicar nas N": mesma troca de código de serviço nas outras notas recusadas do mesmo lote
        if ($request->boolean('aplicar_em_todas') && $codigoAntigo !== $dados['codigo_servico'] && $nota->importacao_id) {
            NotaFiscal::where('importacao_id', $nota->importacao_id)
                ->where('status', StatusNota::Rejeitada)
                ->where('codigo_servico', $codigoAntigo)
                ->whereKeyNot($nota->id)
                ->each(function (NotaFiscal $outra) use ($dados, $afetadas) {
                    $outra->update(['codigo_servico' => $dados['codigo_servico']]);
                    $afetadas->push($outra);
                });
        }

        $importacao = $nota->importacao;
        $antesDoEnvio = $importacao && $importacao->status === 'conferir';

        foreach ($afetadas as $n) {
            if ($antesDoEnvio) {
                $n->update(['status' => StatusNota::Rascunho, 'erros' => null]);
            } else {
                $envio->enviarNota($n);
            }
        }

        $importacao?->recontarInvalidas();

        $msg = $antesDoEnvio
            ? "Linha {$nota->linha_planilha} corrigida."
            : ($afetadas->count() > 1 ? "{$afetadas->count()} notas corrigidas e reenviadas." : "Linha {$nota->linha_planilha} corrigida e reenviada.");

        // Antes do envio volta para a mesma página/filtro da conferência (e à linha editada)
        $destino = $antesDoEnvio ? url()->previous(route('importacoes.conferir', $importacao)) : url()->previous();

        return redirect()->to(strtok($destino, '#').($antesDoEnvio ? "#linha-{$nota->id}" : ''))->with('ok', $msg);
    }
}
