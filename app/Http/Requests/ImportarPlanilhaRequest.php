<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarPlanilhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajuste para sua política de acesso
    }

    public function rules(): array
    {
        return [
            'planilha'    => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'], // 10 MB
            // Emitente precisa ser da conta ativa (o global scope de Emitente já filtra)
            'emitente_id' => ['required', 'integer', function ($a, $v, $falha) {
                \App\Models\Emitente::whereKey($v)->where('ativo', true)->exists() ?: $falha('Emitente inválido.');
            }],
        ];
    }

    /** Arquivo fora do padrão: extensão errada ou sem as colunas do modelo. */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $v) {
            $arquivo = $this->file('planilha');
            if ($v->errors()->isNotEmpty() || ! $arquivo) {
                return;
            }

            $ext = strtolower($arquivo->getClientOriginalExtension());
            if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                $v->errors()->add('planilha', 'Arquivo fora do padrão: envie uma planilha .xlsx, .xls ou .csv (o arquivo enviado é .'.($ext ?: 'sem extensão').').');

                return;
            }

            $cabecalho = \App\Support\CabecalhoPlanilha::ler($arquivo);
            if ($cabecalho === null) {
                $v->errors()->add('planilha', 'Não conseguimos abrir este arquivo. Confira se é uma planilha válida e baixe o modelo para usar como base.');

                return;
            }

            $emitente = \App\Models\Emitente::find($this->input('emitente_id'));
            $faltam = \App\Support\CabecalhoPlanilha::faltando($cabecalho, ! $emitente?->codigo_servico_padrao);
            if ($faltam) {
                $v->errors()->add('planilha', 'Arquivo fora do padrão. Faltam as colunas: '.implode(', ', $faltam).'. A primeira linha precisa ter os nomes do modelo; baixe o modelo ao lado e copie seus dados para ele.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'planilha.mimes' => 'Envie um arquivo .xlsx, .xls ou .csv.',
        ];
    }
}
