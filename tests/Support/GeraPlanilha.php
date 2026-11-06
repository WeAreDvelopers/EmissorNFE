<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Monta planilhas no formato "Notas ERP" para os testes.
 * Cada linha: ['doc' => '529.982.247-25', 'valor' => 100, 'data' => '2026-11-01', 'atualizar' => true, 'nome_ref' => '…', ...]
 */
class GeraPlanilha
{
    public const CABECALHOS = ['Data de Competência', 'Nome Fantasia (ref.)', 'Razão Social/Nome (ref.)', 'CNPJ/CPF', 'Valor (R$)', 'Imposto IBPT', 'Atualizar',
        'Nome Fantasia (novo)', 'Razão Social/Nome (novo)', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Município', 'UF', 'CEP', 'E-mail',
        'Descrição dos Serviços (Opcional)', 'Código de Serviço (Opcional)', 'NBS (Opcional)'];

    public static function xlsx(string $cnpj, array $linhas, array $opcoes = []): UploadedFile
    {
        $livro = new Spreadsheet();
        $aba = $livro->getActiveSheet()->setTitle($opcoes['aba'] ?? 'Novembro');
        $aba->setCellValueExplicit('A1', $cnpj, 's');

        foreach ($opcoes['cabecalhos'] ?? self::CABECALHOS as $i => $t) {
            $aba->setCellValue([$i + 1, 2], $t);
        }

        $soma = 0;
        foreach (array_values($linhas) as $n => $l) {
            $r = $n + 3;
            $soma += (float) ($l['valor'] ?? 0);
            $valores = [
                1 => $l['data'] ?? now()->subDay()->toDateString(),
                2 => $l['fantasia_ref'] ?? null, 3 => $l['nome_ref'] ?? null,
                4 => $l['doc'] ?? null, 5 => $l['valor'] ?? null, 6 => null,
                7 => ($l['atualizar'] ?? false) ? 'x' : null,
                8 => $l['fantasia'] ?? null, 9 => $l['nome'] ?? null, 10 => $l['logradouro'] ?? null, 11 => $l['numero'] ?? null,
                12 => $l['complemento'] ?? null, 13 => $l['bairro'] ?? null, 14 => $l['municipio'] ?? null, 15 => $l['uf'] ?? null,
                16 => $l['cep'] ?? null, 17 => $l['emails'] ?? null, 18 => $l['descricao'] ?? null,
                19 => $l['codigo'] ?? null, 20 => $l['nbs'] ?? null,
            ];
            foreach ($valores as $c => $v) {
                if ($v !== null) {
                    $aba->setCellValueExplicit([$c, $r], $v, is_numeric($v) && $c === 5 ? 'n' : 's');
                }
            }
        }

        $aba->setCellValue('E1', $opcoes['total'] ?? $soma);
        $aba->setCellValue('F1', 0);

        if (isset($opcoes['aba_extra'])) {
            $extra = $livro->createSheet()->setTitle($opcoes['aba_extra']);
            $extra->setCellValue('A1', 'tomador_documento')->setCellValue('B1', 'valor');
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        (new Xlsx($livro))->save($arquivo);

        return new UploadedFile($arquivo, $opcoes['nome'] ?? 'lote.xlsx', null, null, true);
    }
}
