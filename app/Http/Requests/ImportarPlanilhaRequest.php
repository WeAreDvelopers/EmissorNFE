<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarPlanilhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajuste para sua política de acesso
    }

    /** A importação é sempre da empresa que está sendo administrada: não dá para importar para outra. */
    protected function prepareForValidation(): void
    {
        if ($id = app(\App\Support\Tenancy\EmitenteAtual::class)->id()) {
            $this->merge(['emitente_id' => $id]);
        }
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

    /** Arquivo fora do padrão: extensão errada, sem a aba de notas do modelo ou de outra empresa. */
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

            // Formato único: planilha do modelo "Notas ERP" (aba de notas, CNPJ em A1, cabeçalhos na linha 2)
            try {
                $planilha = \App\Support\PlanilhaModelo::ler($arquivo->getRealPath());
            } catch (\RuntimeException $e) {
                $v->errors()->add('planilha', $e->getMessage());

                return;
            }

            $emitente = \App\Models\Emitente::find($this->input('emitente_id'));
            if ($emitente && $planilha['cnpj'] !== $emitente->cnpj) {
                $v->errors()->add('planilha', 'O CNPJ da célula A1 da planilha ('.\App\Support\Documento::formatar($planilha['cnpj']).') não é o da empresa escolhida ('.\App\Support\Documento::formatar($emitente->cnpj).'). Escolha a empresa certa ou use a planilha dela.');

                return;
            }

            if ($planilha['linhas'] === []) {
                $v->errors()->add('planilha', 'A planilha não tem nenhuma nota: os dados começam na linha 3.');
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
