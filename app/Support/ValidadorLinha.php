<?php

namespace App\Support;

use App\Models\Emitente;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Normaliza e valida UMA linha de nota (planilha ou correção na tela), com mensagens em português.
 * Regras alinhadas ao XSD v1.01 e às rejeições do Anexo I.
 */
class ValidadorLinha
{
    public const CAMPOS = [
        'tomador_documento', 'tomador_nome', 'tomador_email', 'codigo_servico',
        'codigo_nbs', 'descricao', 'valor', 'data_competencia',
    ];

    /** Limpa formatação comum de planilha (pontos, traços, R$, vírgula decimal, data do Excel). */
    public static function normalizar(array $l): array
    {
        $soDigitos = fn ($v) => preg_replace('/\D/', '', (string) $v);

        $valor = trim((string) ($l['valor'] ?? ''));
        $valor = str_replace(['R$', ' '], '', $valor);
        if (str_contains($valor, ',')) {               // 1.234,56 -> 1234.56
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        }

        $data = $l['data_competencia'] ?? null;
        if (is_numeric($data)) {                        // célula de data do Excel
            $data = ExcelDate::excelToDateTimeObject($data)->format('Y-m-d');
        } elseif ($data && preg_match('#^\d{2}/\d{2}/\d{4}$#', (string) $data)) {
            $data = Carbon::createFromFormat('d/m/Y', $data)->toDateString();
        }

        return [
            'tomador_documento' => $soDigitos($l['tomador_documento'] ?? ''),
            'tomador_nome'      => trim((string) ($l['tomador_nome'] ?? '')),
            'tomador_email'     => trim((string) ($l['tomador_email'] ?? '')) ?: null,
            'codigo_servico'    => str_pad($soDigitos($l['codigo_servico'] ?? ''), 6, '0', STR_PAD_LEFT),
            'codigo_nbs'        => $soDigitos($l['codigo_nbs'] ?? '') ?: null,
            'descricao'         => trim((string) ($l['descricao'] ?? '')),
            'valor'             => $valor,
            'data_competencia'  => $data,
        ];
    }

    public static function validar(array $dados, Emitente $emitente): ValidatorContract
    {
        return Validator::make($dados, [
            'tomador_documento' => [
                'bail', 'required', 'regex:/^(\d{11}|\d{14})$/',   // bail: uma mensagem só por campo, a mais útil
                fn ($a, $v, $falha) => Documento::valido((string) $v) ?: $falha('O CPF/CNPJ do cliente não é válido (dígito verificador não confere).'),
                fn ($a, $v, $falha) => $v !== $emitente->cnpj ?: $falha('O cliente não pode ser a própria empresa que emite a nota.'),
            ],
            'tomador_nome'      => ['required', 'string', 'max:300'],
            'tomador_email'     => ['nullable', 'email', 'max:80'],
            'codigo_servico'    => ['required', 'digits:6'],
            'codigo_nbs'        => ['nullable', 'digits:9'],
            'descricao'         => ['required', 'string', 'max:2000'],
            'valor'             => ['required', 'numeric', 'gt:0', 'max:999999999999999.99'],
            'data_competencia'  => ['required', 'date', 'before_or_equal:today'],
        ], [
            'tomador_documento.required'  => 'Falta o CPF ou CNPJ do cliente.',
            'tomador_documento.regex'     => 'O CPF/CNPJ do cliente tem o tamanho errado: precisa de 11 dígitos (CPF) ou 14 (CNPJ).',
            'tomador_nome.required'       => 'Falta o nome ou razão social do cliente.',
            'tomador_nome.max'            => 'O nome do cliente passou de 300 caracteres.',
            'tomador_email.email'         => 'O e-mail do cliente não parece válido.',
            'tomador_email.max'           => 'O e-mail do cliente passou de 80 caracteres.',
            'codigo_servico.required'     => 'Falta o código de serviço.',
            'codigo_servico.digits'       => 'O código de serviço precisa ter 6 dígitos.',
            'codigo_nbs.digits'           => 'O código NBS precisa ter 9 dígitos (ou ficar vazio).',
            'descricao.required'          => 'Falta a descrição do serviço.',
            'descricao.max'               => 'A descrição passou de 2000 caracteres.',
            'valor.required'              => 'Falta o valor do serviço.',
            'valor.numeric'               => 'O valor do serviço não é um número válido.',
            'valor.gt'                    => 'O valor do serviço precisa ser maior que zero.',
            'valor.max'                   => 'O valor do serviço é grande demais.',
            'data_competencia.required'   => 'Falta a data de competência.',
            'data_competencia.date'       => 'A data de competência não é uma data válida (use dd/mm/aaaa).',
            'data_competencia.before_or_equal' => 'A data de competência não pode estar no futuro.',
        ]);
    }

    /** Valores seguros para gravar mesmo quando a linha é inválida (sem estourar colunas). */
    public static function paraGravar(array $d): array
    {
        return [
            ...$d,
            'tomador_documento' => substr($d['tomador_documento'], 0, 14),
            'tomador_nome'      => mb_substr($d['tomador_nome'], 0, 300),
            'codigo_servico'    => substr($d['codigo_servico'], 0, 6),
            'codigo_nbs'        => $d['codigo_nbs'] ? substr($d['codigo_nbs'], 0, 9) : null,
            'valor'             => is_numeric($d['valor']) ? $d['valor'] : 0,
            'data_competencia'  => strtotime((string) $d['data_competencia']) ? $d['data_competencia'] : now()->toDateString(),
        ];
    }
}
