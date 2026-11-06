<?php

namespace App\Services\Tomadores;

use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Support\MunicipiosIbge;
use Illuminate\Support\Facades\DB;

/**
 * Regras do cadastro de clientes na importação:
 *  - CPF/CNPJ novo → cria o cliente com o que a planilha traz;
 *  - CPF/CNPJ existente → só muda o que a linha marcada com "x" trouxe preenchido; campo vazio preserva o que já existe;
 *  - nada é gravado na leitura: a prévia fica na importação e só é aplicada quando o usuário confirma o envio.
 */
class CadastroTomadores
{
    /** @var array<string,array> documento => ['existente' => ?Tomador, 'base' => array, 'estado' => array, 'linhas' => int[]] */
    private array $docs = [];

    /**
     * Registra uma linha da planilha e devolve os dados do cliente como ficarão depois de confirmar
     * (cadastro atual + o que a linha traz), para validar e gravar na nota.
     *
     * @return array{nome:string, email:?string}
     */
    public function registrar(string $documento, array $linha): array
    {
        $d = &$this->docs[$documento];

        if (! isset($d)) {
            $existente = Tomador::where('documento', $documento)->first();
            $base = $existente ? $this->campos($existente) : [];
            $d = [
                'existente' => $existente,
                'base'      => $base,
                // Cliente novo: usa o nome de referência (colunas B/C) até alguém informar o dado novo
                'estado'    => $existente ? $base : array_filter([
                    'nome'          => $linha['ref_nome'] ?? '',
                    'nome_fantasia' => ($linha['ref_fantasia'] ?? '') !== ($linha['ref_nome'] ?? '') ? ($linha['ref_fantasia'] ?? '') : '',
                ], fn ($v) => $v !== ''),
                'linhas'    => [],
            ];
        }

        $d['linhas'][] = $linha['linha'];

        if ($linha['atualizar']) {
            foreach ($linha['novo'] as $campo => $valor) {
                $d['estado'][$campo] = $valor;
            }
        }

        return [
            'nome'  => (string) ($d['estado']['nome'] ?? ''),
            'email' => ($d['estado']['emails'] ?? [])[0] ?? null,
        ];
    }

    /** Prévia para a tela de conferência: só quem é novo ou muda alguma coisa. Resolve o código IBGE do município. */
    public function previa(): array
    {
        $saida = [];

        foreach ($this->docs as $documento => $d) {
            $estado = $d['estado'];
            if (isset($estado['municipio'], $estado['uf']) && ($estado['municipio'] !== ($d['base']['municipio'] ?? null) || $estado['uf'] !== ($d['base']['uf'] ?? null))) {
                $estado['codigo_municipio'] = MunicipiosIbge::codigo($estado['municipio'], $estado['uf']);
            }

            $mudancas = [];
            foreach ($estado as $campo => $novo) {
                $antes = $d['base'][$campo] ?? null;
                if ($novo !== $antes && ! (blank($novo) && blank($antes))) {
                    $mudancas[$campo] = [$antes, $novo];
                }
            }

            $novoCliente = $d['existente'] === null;
            if ($mudancas === [] && ! $novoCliente) {
                continue;
            }

            $saida[] = [
                'documento' => $documento,
                'nome'      => $estado['nome'] ?? '',
                'novo'      => $novoCliente,
                'campos'    => $mudancas,
                'linhas'    => $d['linhas'],
            ];
        }

        return $saida;
    }

    /**
     * Aplica a prévia guardada na importação (uma vez só), escreve o histórico e liga cada nota ao seu cliente.
     * Aplica somente os campos que a prévia mudou, para não pisar numa edição feita depois da leitura.
     */
    public function aplicar(Importacao $importacao, ?int $userId): void
    {
        DB::transaction(function () use ($importacao, $userId) {
            $importacao = Importacao::whereKey($importacao->id)->lockForUpdate()->first();
            if ($importacao->cadastro_aplicado_em === null) {
                foreach ($importacao->atualizacoes ?? [] as $item) {
                    $this->aplicarItem($importacao, $item, $userId);
                }
                $importacao->forceFill(['cadastro_aplicado_em' => now()])->save();
            }

            $this->ligarNotas($importacao);
        });
    }

    private function aplicarItem(Importacao $importacao, array $item, ?int $userId): void
    {
        $tomador = Tomador::firstOrNew(['documento' => $item['documento']], ['conta_id' => $importacao->conta_id]);
        $tomador->conta_id = $importacao->conta_id;

        $historico = [];
        foreach ($item['campos'] as $campo => [$antesPrevisto, $novo]) {
            $atual = $tomador->{$campo};
            if ($atual === $novo || (blank($atual) && blank($novo))) {
                continue;
            }
            $historico[$campo] = [$atual, $novo];
            $tomador->{$campo} = $novo;
        }
        if (blank($tomador->nome)) {
            $tomador->nome = $item['nome'] ?: 'Cliente '.$item['documento'];
        }

        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        if ($historico !== []) {
            TomadorAlteracao::create([
                'conta_id' => $importacao->conta_id, 'tomador_id' => $tomador->id, 'user_id' => $userId,
                'importacao_id' => $importacao->id, 'origem' => 'importacao', 'campos' => $historico,
            ]);
        }
    }

    private function ligarNotas(Importacao $importacao): void
    {
        $mapa = Tomador::whereIn('documento', NotaFiscal::where('importacao_id', $importacao->id)->where('sem_tomador', false)->whereNull('tomador_id')->pluck('tomador_documento')->unique())
            ->pluck('id', 'documento');

        foreach ($mapa as $documento => $id) {
            NotaFiscal::where('importacao_id', $importacao->id)->where('tomador_documento', $documento)->whereNull('tomador_id')->update(['tomador_id' => $id]);
        }
    }

    private function campos(Tomador $t): array
    {
        return array_filter(
            collect(array_keys(Tomador::CAMPOS))->mapWithKeys(fn ($c) => [$c => $t->{$c}])->all() + ['codigo_municipio' => $t->codigo_municipio],
            fn ($v) => $v !== null && $v !== '' && $v !== []
        );
    }
}
