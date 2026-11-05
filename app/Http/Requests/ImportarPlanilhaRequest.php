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

    public function messages(): array
    {
        return [
            'planilha.mimes' => 'Envie um arquivo .xlsx, .xls ou .csv.',
        ];
    }
}
