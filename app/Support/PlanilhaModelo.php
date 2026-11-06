<?php

namespace App\Support;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lê a planilha de notas dos ERPs (modelo "Notas ERP"):
 *   A1 = CNPJ da empresa que emite · E1/F1 = totais de conferência · linha 2 = cabeçalhos · dados a partir da linha 3.
 *   A data · B/C referência (ignoradas) · D CNPJ/CPF do cliente (ou "sem destinatário informado") · E valor · F IBPT
 *   G "Atualizar" (x) · H–Q dados novos do cliente (só valem na linha com x) · R descrição do serviço (opcional).
 *   Opcional, em qualquer coluna: "Código de Serviço" e "NBS" (vazio = padrão da empresa).
 * A aba certa é a que tem os cabeçalhos "CNPJ/CPF" e "Atualizar" (o nome muda a cada mês); as outras são ignoradas.
 */
class PlanilhaModelo
{
    public const SEM_DESTINATARIO = 'sem destinatario informado';

    /** Colunas pelo começo do nome do cabeçalho (sem acento/maiúscula), para sobreviver a pequenas variações. */
    private const COLUNAS = [
        'data'        => 'data_de_competencia',
        'documento'   => 'cnpj_cpf',
        'ref_fantasia' => 'nome_fantasia_ref',
        'ref_nome'    => 'razao_social_nome_ref',
        'valor'       => 'valor',
        'ibpt'        => 'imposto_ibpt',
        'atualizar'   => 'atualizar',
        'nome_fantasia' => 'nome_fantasia_novo',
        'nome'        => 'razao_social_nome_novo',
        'logradouro'  => 'logradouro',
        'numero'      => 'numero',
        'complemento' => 'complemento',
        'bairro'      => 'bairro',
        'municipio'   => 'municipio',
        'uf'          => 'uf',
        'cep'         => 'cep',
        'emails'      => 'e_mail',
        'descricao'   => 'descricao_dos_servicos',
        // Opcionais: para empresas com vários serviços. Vazio = código/NBS padrão da empresa.
        'codigo_servico' => ['codigo_de_servico', 'codigo_servico', 'cod_servico', 'ctribnac', 'codigo_de_tributacao'],
        'codigo_nbs'     => ['codigo_nbs', 'cnbs', 'nbs'],
    ];

    /**
     * @return array{aba:string, cnpj:string, total_valor:?float, total_ibpt:?float, linhas:array<int,array>}
     * @throws \RuntimeException com mensagem pronta para o usuário
     */
    public static function ler(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReaderForFile($caminho);
            $leitor->setReadDataOnly(true);
            $planilha = $leitor->load($caminho);
        } catch (\Throwable) {
            throw new \RuntimeException('Não conseguimos abrir este arquivo. Confira se é uma planilha válida (.xlsx) no modelo de notas dos ERPs.');
        }

        foreach ($planilha->getAllSheets() as $aba) {
            $cabecalho = self::cabecalho($aba);
            if (isset($cabecalho['documento'], $cabecalho['atualizar'], $cabecalho['valor'], $cabecalho['data'])) {
                return self::linhas($aba, $cabecalho);
            }
        }

        throw new \RuntimeException('Arquivo fora do padrão: não encontramos a aba de notas. A linha 2 precisa ter os cabeçalhos do modelo (Data de Competência, CNPJ/CPF, Valor, Atualizar…). Baixe o modelo ao lado e copie os dados para ele.');
    }

    /** Só confere se o arquivo tem a aba de notas e devolve o CNPJ de A1 (leve, para barrar antes de importar). */
    public static function cnpjDoArquivo(string $caminho): string
    {
        return self::ler($caminho)['cnpj'];
    }

    /** @return array<string,int> campo => índice da coluna (1 = A) */
    private static function cabecalho(Worksheet $aba): array
    {
        $mapa = [];
        $maxima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($aba->getHighestColumn());
        for ($c = 1; $c <= $maxima; $c++) {
            $slug = self::chave((string) $aba->getCell([$c, 2])->getValue());
            if ($slug === '') {
                continue;
            }
            foreach (self::COLUNAS as $campo => $prefixos) {
                // "valor_r" → valor · prefixo exato ou seguido de "_" (para "numero" não casar com "numeroabc")
                foreach ((array) $prefixos as $prefixo) {
                    if (! isset($mapa[$campo]) && ($slug === $prefixo || str_starts_with($slug, $prefixo.'_'))) {
                        $mapa[$campo] = $c;
                    }
                }
            }
        }

        return $mapa;
    }

    /** "Razão Social/Nome (novo)" → razao_social_nome_novo (barra e parênteses viram separador, não somem). */
    private static function chave(string $titulo): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::of($titulo)->ascii()->lower()->toString()), '_');
    }

    private static function linhas(Worksheet $aba, array $col): array
    {
        $valor = fn (int $linha, string $campo) => isset($col[$campo]) ? $aba->getCell([$col[$campo], $linha])->getCalculatedValue() : null;
        $texto = fn (int $linha, string $campo) => trim((string) ($valor($linha, $campo) ?? ''));

        $linhas = [];
        for ($l = 3; $l <= $aba->getHighestDataRow(); $l++) {
            $doc = $texto($l, 'documento');
            $v = $valor($l, 'valor');
            if ($doc === '' && ($v === null || $v === '')) {
                continue;                                     // linha em branco
            }

            $data = $valor($l, 'data');
            if (is_numeric($data)) {
                $data = ExcelDate::excelToDateTimeObject($data)->format('Y-m-d');
            } elseif ($data && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim((string) $data), $m)) {
                $data = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $emails = collect(preg_split('/[;,\s]+/', $texto($l, 'emails'), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($e) => strtolower(trim($e)))->unique()->values()->all();

            $linhas[] = [
                'linha'         => $l,
                'data'          => $data,
                'documento'     => $doc,
                'sem_tomador'   => Str::of($doc)->ascii()->lower()->squish()->toString() === self::SEM_DESTINATARIO,
                'valor'         => $v,
                'ibpt'          => $valor($l, 'ibpt'),
                'ref_nome'      => $texto($l, 'ref_nome'),
                'ref_fantasia'  => $texto($l, 'ref_fantasia'),
                'atualizar'     => strtolower($texto($l, 'atualizar')) !== '' && ! in_array(strtolower($texto($l, 'atualizar')), ['0', 'nao', 'não', 'n'], true),
                'novo'          => array_filter([
                    'nome_fantasia' => $texto($l, 'nome_fantasia'),
                    'nome'          => $texto($l, 'nome'),
                    'logradouro'    => $texto($l, 'logradouro'),
                    'numero'        => $texto($l, 'numero'),
                    'complemento'   => $texto($l, 'complemento'),
                    'bairro'        => $texto($l, 'bairro'),
                    'municipio'     => $texto($l, 'municipio'),
                    'uf'            => strtoupper($texto($l, 'uf')),
                    'cep'           => preg_replace('/\D/', '', $texto($l, 'cep')),
                    'emails'        => $emails,
                ], fn ($v) => $v !== '' && $v !== []),
                'descricao'     => $texto($l, 'descricao'),
                'codigo_servico' => preg_replace('/\D/', '', $texto($l, 'codigo_servico')),
                'codigo_nbs'    => preg_replace('/\D/', '', $texto($l, 'codigo_nbs')),
            ];
        }

        return [
            'aba'         => $aba->getTitle(),
            'cnpj'        => preg_replace('/\D/', '', (string) $aba->getCell('A1')->getValue()),
            'total_valor' => is_numeric($t = $aba->getCell('E1')->getCalculatedValue()) ? (float) $t : null,
            'total_ibpt'  => is_numeric($t = $aba->getCell('F1')->getCalculatedValue()) ? (float) $t : null,
            'linhas'      => $linhas,
        ];
    }
}
