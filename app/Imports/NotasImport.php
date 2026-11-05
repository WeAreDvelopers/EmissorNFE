<?php

namespace App\Imports;

use App\Enums\StatusNota;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Support\ValidadorLinha;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lê a planilha em blocos de 500 linhas e grava cada linha como nota:
 * válida → Rascunho (sem custo, espera o usuário conferir e confirmar); inválida → Invalida.
 * NÃO reserva crédito nem enfileira: isso é feito no passo "Enviar" (Services\Nfse\EnvioDeNotas).
 *
 * Cabeçalho esperado (1ª linha):
 * tomador_documento | tomador_nome | tomador_email | codigo_servico | codigo_nbs | descricao | valor | data_competencia
 */
class NotasImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    private int $linhaAtual = 1; // linha 1 = cabeçalho

    public function __construct(private Importacao $importacao) {}

    public function chunkSize(): int
    {
        return 500;
    }

    public function collection(Collection $linhas): void
    {
        foreach ($linhas as $linha) {
            $this->linhaAtual++;
            $dados = ValidadorLinha::normalizar($linha->toArray());

            // Pula linhas totalmente vazias
            if (! array_filter($dados)) {
                continue;
            }

            $validador = ValidadorLinha::validar($dados, $this->importacao->emitente);
            $invalida = $validador->fails();

            NotaFiscal::create([
                ...ValidadorLinha::paraGravar($dados),
                'conta_id'       => $this->importacao->conta_id,
                'emitente_id'    => $this->importacao->emitente_id,
                'importacao_id'  => $this->importacao->id,
                'origem'         => 'planilha',
                'linha_planilha' => $this->linhaAtual,
                'serie_dps'      => $this->importacao->emitente->serie_dps,
                'status'         => $invalida ? StatusNota::Invalida : StatusNota::Rascunho,
                'erros'          => $invalida ? $validador->errors()->all() : null,
            ]);

            $this->importacao->increment('total_linhas');

            if ($invalida) {
                $this->importacao->increment('linhas_invalidas');
            }
        }
    }
}
