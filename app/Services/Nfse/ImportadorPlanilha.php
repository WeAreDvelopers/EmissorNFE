<?php

namespace App\Services\Nfse;

use App\Enums\StatusNota;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Tomadores\CadastroTomadores;
use App\Support\PlanilhaModelo;
use App\Support\ValidadorLinha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Lê a planilha do modelo "Notas ERP" e grava cada linha como nota:
 * válida → Rascunho (sem custo, espera a conferência); inválida → Invalida.
 * Também monta a prévia das mudanças no cadastro de clientes (aplicada só quando o usuário confirma o envio)
 * e os avisos de conferência (totais de E1/F1 contra a soma das linhas).
 * NÃO reserva crédito nem enfileira: isso é feito no passo "Enviar" (EnvioDeNotas).
 */
class ImportadorPlanilha
{
    public function importar(Importacao $importacao): void
    {
        $emitente = $importacao->emitente;
        $planilha = PlanilhaModelo::ler(Storage::path($importacao->arquivo_caminho));

        if ($planilha['cnpj'] !== $emitente->cnpj) {
            throw new \RuntimeException('O CNPJ da célula A1 da planilha não é o da empresa escolhida.');
        }

        DB::transaction(function () use ($importacao, $emitente, $planilha) {
            $cadastro = new CadastroTomadores();
            $somaValor = 0.0;
            $somaIbpt = 0.0;

            foreach ($planilha['linhas'] as $l) {
                $semTomador = $l['sem_tomador'];
                $documento = preg_replace('/\D/', '', $l['documento']);
                $cliente = $semTomador ? ['nome' => '', 'email' => null] : $cadastro->registrar($documento, $l);

                $dados = ValidadorLinha::normalizar([
                    'tomador_documento' => $semTomador ? '' : $l['documento'],
                    'tomador_nome'      => $cliente['nome'],
                    'tomador_email'     => $cliente['email'],
                    'sem_tomador'       => $semTomador,
                    'codigo_servico'    => $l['codigo_servico'],   // coluna opcional; vazio = padrão da empresa
                    'codigo_nbs'        => $l['codigo_nbs'],
                    'descricao'         => $l['descricao'],     // vazio = descrição padrão da empresa
                    'valor'             => $l['valor'],
                    'data_competencia'  => $l['data'],
                ], $emitente);

                $validador = ValidadorLinha::validar($dados, $emitente);
                $invalida = $validador->fails();

                NotaFiscal::create([
                    ...ValidadorLinha::paraGravar($dados),
                    'conta_id'       => $importacao->conta_id,
                    'emitente_id'    => $importacao->emitente_id,
                    'importacao_id'  => $importacao->id,
                    'origem'         => 'planilha',
                    'linha_planilha' => $l['linha'],
                    'serie_dps'      => $emitente->serie_dps,
                    'status'         => $invalida ? StatusNota::Invalida : StatusNota::Rascunho,
                    'erros'          => $invalida ? $validador->errors()->all() : null,
                ]);

                $importacao->increment('total_linhas');
                if ($invalida) {
                    $importacao->increment('linhas_invalidas');
                }

                $somaValor += is_numeric($dados['valor']) ? (float) $dados['valor'] : 0;
                $somaIbpt += is_numeric($l['ibpt']) ? (float) $l['ibpt'] : 0;
            }

            $importacao->update([
                'atualizacoes' => $cadastro->previa(),
                'avisos'       => $this->avisos($planilha, $somaValor, $somaIbpt),
            ]);
        });
    }

    /** Totais de conferência da planilha (E1/F1) contra o que realmente está nas linhas. */
    private function avisos(array $planilha, float $somaValor, float $somaIbpt): array
    {
        $avisos = [];
        $brl = fn ($v) => 'R$ '.number_format($v, 2, ',', '.');

        if ($planilha['total_valor'] !== null && abs($planilha['total_valor'] - $somaValor) > 0.01) {
            $avisos[] = "O total de valores da planilha (E1) é {$brl($planilha['total_valor'])}, mas a soma das linhas é {$brl($somaValor)}. Confira se alguma linha foi alterada ou ficou de fora.";
        }
        if ($planilha['total_ibpt'] !== null && abs($planilha['total_ibpt'] - $somaIbpt) > 0.01) {
            $avisos[] = "O total do imposto IBPT da planilha (F1) é {$brl($planilha['total_ibpt'])}, mas a soma das linhas é {$brl($somaIbpt)}.";
        }

        return $avisos;
    }
}
