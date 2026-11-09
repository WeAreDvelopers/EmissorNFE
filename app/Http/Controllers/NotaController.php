<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Auditoria;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Nfse\EnvioDeNotas;
use App\Support\ExplicacaoErro;
use App\Support\ValidadorLinha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $dados = ValidadorLinha::normalizar([...$atuais, ...$recebidos], $nota->emitente);
        $validador = ValidadorLinha::validar($dados, $nota->emitente);

        if ($validador->fails()) {
            throw ValidationException::withMessages($validador->errors()->messages());
        }

        // "Aplicar nas outras": o mesmo valor, no mesmo campo, nas notas do mesmo lote que tinham o mesmo problema
        $campoAplicar = $request->input('campo_aplicar', 'codigo_servico');
        $campoAplicar = in_array($campoAplicar, ValidadorLinha::CAMPOS, true) ? $campoAplicar : 'codigo_servico';
        $valorAntigo = $nota->getRawOriginal($campoAplicar);
        $statusInicial = $nota->status;

        $antes = $nota->only(ValidadorLinha::CAMPOS);
        $nota->update(ValidadorLinha::paraGravar($dados));
        $mudancas = collect($nota->only(ValidadorLinha::CAMPOS))->map(fn ($novo, $campo) => ['de' => $antes[$campo] ?? null, 'para' => $novo])
            ->filter(fn ($m) => (string) $m['de'] !== (string) $m['para'])->all();
        Auditoria::registrar('nota_corrigida', "{$nota->origem_rotulo} (nota #{$nota->id}) corrigida.", $mudancas + ['aplicado_em_outras' => $request->boolean('aplicar_em_todas')]);

        $afetadas = collect([$nota]);
        $importacao = $nota->importacao;
        $antesDoEnvio = $importacao && $importacao->status === 'conferir';

        if ($request->boolean('aplicar_em_todas') && $nota->importacao_id && (string) $valorAntigo !== (string) $dados[$campoAplicar]
            && in_array($statusInicial, [StatusNota::Rejeitada, StatusNota::Invalida], true)) {
            NotaFiscal::with('emitente')->where('importacao_id', $nota->importacao_id)
                ->where('status', $statusInicial)
                ->where($campoAplicar, $valorAntigo)
                ->whereKeyNot($nota->id)
                ->each(function (NotaFiscal $outra) use ($dados, $campoAplicar, $afetadas, $statusInicial) {
                    $outra->update([$campoAplicar => $dados[$campoAplicar]]);

                    if ($statusInicial === StatusNota::Invalida) {
                        // Só vira pronta se a linha inteira passar na conferência com o valor novo
                        $d = ValidadorLinha::normalizar([...$outra->only(ValidadorLinha::CAMPOS), 'data_competencia' => $outra->data_competencia?->toDateString()], $outra->emitente);
                        if (ValidadorLinha::validar($d, $outra->emitente)->fails()) {
                            return;
                        }
                        $outra->update(ValidadorLinha::paraGravar($d));
                    }
                    $afetadas->push($outra);
                });
        }

        foreach ($afetadas as $n) {
            if ($antesDoEnvio) {
                $n->update(['status' => StatusNota::Rascunho, 'erros' => null]);
            } else {
                $envio->enviarNota($n);
            }
        }

        $importacao?->recontarInvalidas();

        $msg = $antesDoEnvio
            ? ($afetadas->count() > 1 ? "{$afetadas->count()} linhas corrigidas." : "{$nota->origem_rotulo} corrigida.")
            : ($afetadas->count() > 1 ? "{$afetadas->count()} notas corrigidas e reenviadas." : "{$nota->origem_rotulo} corrigida e reenviada.");

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'mensagem' => $msg, 'notas' => $afetadas->pluck('id')->all()]);
        }

        // Antes do envio volta para a mesma página/filtro da conferência (e à linha editada)
        $destino = $antesDoEnvio ? url()->previous(route('importacoes.conferir', $importacao)) : url()->previous();

        return redirect()->to(strtok($destino, '#').($antesDoEnvio ? "#linha-{$nota->id}" : ''))->with('ok', $msg);
    }

    /** "É outra cobrança": o usuário confirma que a linha parecida com uma nota existente deve ser emitida. */
    public function aprovarDuplicidade(Request $request, NotaFiscal $nota)
    {
        $this->authorize('emitir');

        abort_unless($nota->importacao?->status === 'conferir' && $nota->status === StatusNota::Rascunho && $nota->duplicidade_de_id, 422, 'Esta linha não está aguardando decisão.');

        $nota->forceFill(['duplicidade_aprovada' => true])->save();
        Auditoria::registrar('duplicidade_aprovada', "Linha {$nota->linha_planilha} mantida como outra cobrança (parecida com a nota #{$nota->duplicidade_de_id}).", ['nota' => $nota->id, 'parecida_com' => $nota->duplicidade_de_id]);
        $msg = "Linha {$nota->linha_planilha} mantida: será emitida como outra cobrança.";

        return $request->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }

    /** Tira uma linha do lote antes do envio (nunca gastou crédito). */
    public function excluir(Request $request, NotaFiscal $nota)
    {
        $this->authorize('emitir');

        $importacao = $nota->importacao;
        abort_unless($importacao && $importacao->status === 'conferir'
            && in_array($nota->status, [StatusNota::Invalida, StatusNota::Rascunho], true), 422, 'Só dá para excluir linhas de um lote que ainda não foi enviado.');

        $linha = $nota->linha_planilha;
        Auditoria::registrar('nota_excluida', "Linha {$linha} excluída do lote {$importacao->arquivo_nome}.", ['cliente' => $nota->tomador_rotulo, 'valor' => (string) $nota->valor, 'lote' => $importacao->id]);
        DB::table('movimentos_credito')->where('nota_fiscal_id', $nota->id)->update(['nota_fiscal_id' => null]);
        $nota->delete();
        $importacao->update(['total_linhas' => $importacao->notas()->count()]);
        $importacao->recontarInvalidas();

        $msg = "Linha {$linha} excluída do lote.";

        return $request->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }
}
