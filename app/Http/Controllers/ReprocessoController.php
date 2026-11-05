<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Support\ValidadorLinha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Reprocessar: repete um lote já enviado SEM precisar da planilha.
 * Cria um lote novo com as linhas copiadas, abre um editor em formato de planilha
 * (trocar dados, adicionar e remover linhas) e leva para a conferência de sempre.
 * O lote original e as notas já emitidas não são tocados.
 */
class ReprocessoController extends Controller
{
    private const LIMITE_LINHAS = 5000;

    /** Copia as linhas do lote enviado para um lote novo, em edição. */
    public function reprocessar(Importacao $importacao)
    {
        $this->authorize('emitir');
        abort_unless($importacao->foiEnviada(), 422, 'Só dá para reprocessar um lote que já foi enviado.');

        $nova = DB::transaction(function () use ($importacao) {
            $emitente = $importacao->emitente;

            $nova = Importacao::create([
                'emitente_id'        => $importacao->emitente_id,
                'user_id'            => auth()->id(),
                'arquivo_nome'       => 'Reprocesso de '.$importacao->arquivo_nome,
                'arquivo_caminho'    => $importacao->arquivo_caminho,
                'arquivo_hash'       => null,            // não conta como "mesma planilha enviada de novo"
                'status'             => 'conferir',
                'reprocessada_de_id' => $importacao->id,
            ]);

            $linha = 1;
            $invalidas = 0;

            $importacao->notas()->orderBy('linha_planilha')->orderBy('id')->chunk(500, function ($notas) use ($nova, $emitente, &$linha, &$invalidas) {
                foreach ($notas as $original) {
                    $dados = ValidadorLinha::normalizar([
                        ...$original->only(ValidadorLinha::CAMPOS),
                        'data_competencia' => $original->data_competencia?->toDateString(),
                    ], $emitente);
                    $validador = ValidadorLinha::validar($dados, $emitente);
                    $invalida = $validador->fails();
                    $invalidas += (int) $invalida;

                    NotaFiscal::create([
                        ...ValidadorLinha::paraGravar($dados),
                        'conta_id'       => $nova->conta_id,
                        'emitente_id'    => $nova->emitente_id,
                        'importacao_id'  => $nova->id,
                        'origem'         => 'planilha',
                        'linha_planilha' => ++$linha,
                        'serie_dps'      => $emitente->serie_dps,
                        'status'         => $invalida ? StatusNota::Invalida : StatusNota::Rascunho,
                        'erros'          => $invalida ? $validador->errors()->all() : null,
                    ]);
                }
            });

            $nova->update(['total_linhas' => $linha - 1, 'linhas_invalidas' => $invalidas]);

            return $nova;
        });

        return redirect()->route('importacoes.editar', $nova)
            ->with('ok', 'Lote copiado. Ajuste os dados na tabela e, quando terminar, clique em "Salvar e revisar".');
    }

    /** Editor em formato de planilha. */
    public function editar(Importacao $importacao)
    {
        $this->authorize('emitir');
        abort_unless($importacao->status === 'conferir', 422, 'Este lote não está mais em edição.');

        return view('importacoes.editar', [
            'importacao' => $importacao->load('emitente', 'reprocessadaDe'),
            'linhas'     => $this->linhasParaTela($importacao),
        ]);
    }

    /**
     * Grava a tabela inteira de uma vez (JSON: não esbarra no limite de campos de formulário).
     * Linha existente (com id) é atualizada, sem id é criada, e a que sumiu da tabela é removida.
     * Linha com dado inválido é gravada mesmo assim, marcada com o motivo: o usuário vê e corrige.
     */
    public function salvar(Request $request, Importacao $importacao): JsonResponse
    {
        $this->authorize('emitir');
        abort_unless($importacao->status === 'conferir', 422, 'Este lote não está mais em edição.');

        $request->validate([
            'linhas'   => ['present', 'array', 'max:'.self::LIMITE_LINHAS],
            'linhas.*' => ['array'],
        ], ['linhas.max' => 'Cada lote aceita até '.self::LIMITE_LINHAS.' linhas.']);

        $emitente = $importacao->emitente;

        DB::transaction(function () use ($request, $importacao, $emitente) {
            $existentes = $importacao->notas()->get()->keyBy('id');
            $maxLinha = (int) $existentes->max('linha_planilha');
            $mantidos = [];

            foreach ($request->input('linhas') as $recebida) {
                $campos = Arr::only($recebida, ValidadorLinha::CAMPOS);

                // Linha totalmente em branco (ex.: "Adicionar linha" e não preencheu): ignora
                if (collect($campos)->every(fn ($v) => trim((string) $v) === '')) {
                    continue;
                }

                $dados = ValidadorLinha::normalizar($campos, $emitente);
                $validador = ValidadorLinha::validar($dados, $emitente);
                $invalida = $validador->fails();

                $atributos = [
                    ...ValidadorLinha::paraGravar($dados),
                    'status' => $invalida ? StatusNota::Invalida : StatusNota::Rascunho,
                    'erros'  => $invalida ? $validador->errors()->all() : null,
                ];

                $nota = isset($recebida['id']) ? $existentes->get((int) $recebida['id']) : null;

                if ($nota) {
                    $nota->update($atributos);
                    $mantidos[] = $nota->id;
                } else {
                    $criada = NotaFiscal::create([
                        ...$atributos,
                        'conta_id'       => $importacao->conta_id,
                        'emitente_id'    => $importacao->emitente_id,
                        'importacao_id'  => $importacao->id,
                        'origem'         => 'planilha',
                        'linha_planilha' => ++$maxLinha,
                        'serie_dps'      => $emitente->serie_dps,
                    ]);
                    $mantidos[] = $criada->id;
                }
            }

            // Sumiu da tabela = o usuário removeu a linha
            $importacao->notas()->whereNotIn('id', $mantidos ?: [0])->delete();

            $importacao->update([
                'total_linhas'     => count($mantidos),
                'linhas_invalidas' => $importacao->notas()->where('status', StatusNota::Invalida)->count(),
            ]);
        });

        $importacao->refresh();

        return response()->json([
            'ok'        => true,
            'total'     => (int) $importacao->total_linhas,
            'invalidas' => (int) $importacao->linhas_invalidas,
            'prontas'   => (int) $importacao->total_linhas - (int) $importacao->linhas_invalidas,
            'linhas'    => $this->linhasParaTela($importacao),
        ]);
    }

    /** Linhas no formato que a tabela da tela usa (texto como a pessoa digita: R$ com vírgula, data dd/mm/aaaa). */
    private function linhasParaTela(Importacao $importacao): array
    {
        return $importacao->notas()->orderBy('linha_planilha')->get()->map(fn (NotaFiscal $n) => [
            'id'                => $n->id,
            'tomador_documento' => \App\Support\Documento::formatar($n->tomador_documento),
            'tomador_nome'      => $n->tomador_nome,
            'tomador_email'     => $n->tomador_email,
            'codigo_servico'    => $n->codigo_servico,
            'codigo_nbs'        => $n->codigo_nbs,
            'descricao'         => $n->descricao,
            'valor'             => $n->valor > 0 ? number_format($n->valor, 2, ',', '') : '',
            'data_competencia'  => $n->data_competencia?->format('d/m/Y'),
            'erros'             => $n->status === StatusNota::Invalida ? (array) $n->erros : [],
        ])->all();
    }
}
