<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cliente (tomador do serviço) de uma conta. */
class Tomador extends Model
{
    use PertenceAConta;

    protected $table = 'tomadores';

    /** Campos que a planilha (colunas H–Q) e a tela podem alterar, com o nome em português para exibir. */
    public const CAMPOS = [
        'nome_fantasia' => 'Nome fantasia',
        'nome'          => 'Razão social / nome',
        'logradouro'    => 'Logradouro',
        'numero'        => 'Número',
        'complemento'   => 'Complemento',
        'bairro'        => 'Bairro',
        'municipio'     => 'Município',
        'uf'            => 'UF',
        'cep'           => 'CEP',
        'emails'        => 'E-mails',
        'observacoes'   => 'Observações',
    ];

    protected $fillable = [
        'conta_id', 'documento', 'nome', 'nome_fantasia', 'logradouro', 'numero', 'complemento', 'bairro',
        'municipio', 'codigo_municipio', 'uf', 'cep', 'emails', 'observacoes', 'pendente',
    ];

    protected $casts = ['emails' => 'array', 'pendente' => 'boolean'];

    public function alteracoes(): HasMany
    {
        return $this->hasMany(TomadorAlteracao::class)->latest('id');
    }

    /** E-mail que vai no XML da DPS (o leiaute aceita só um). */
    public function emailPrincipal(): ?string
    {
        return ($this->emails ?? [])[0] ?? null;
    }

    /** Município e UF preenchidos mas sem código IBGE (a busca falhou na importação): tenta de novo e grava se achar. */
    public function resolverMunicipio(): void
    {
        if ($this->codigo_municipio || blank($this->municipio) || blank($this->uf)) {
            return;
        }

        if ($codigo = \App\Support\MunicipiosIbge::codigo($this->municipio, $this->uf)) {
            // Grava o código primeiro e só então reavalia se o cadastro ainda tem pendência
            $this->forceFill(['codigo_municipio' => $codigo]);
            $this->pendente = ! $this->enderecoCompleto();   // mesma regra do cadastro e da edição
            $this->save();
        }
    }

    /** Endereço completo o bastante para ir na DPS? (município IBGE + CEP + logradouro + número + bairro) */
    public function enderecoCompleto(): bool
    {
        return $this->codigo_municipio && strlen((string) $this->cep) === 8
            && filled($this->logradouro) && filled($this->numero) && filled($this->bairro);
    }

    /** O que falta para o cadastro ficar completo (lista para a tela). */
    public function faltando(): array
    {
        $falta = [];
        foreach (['logradouro' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'municipio' => 'município', 'cep' => 'CEP'] as $campo => $rotulo) {
            if (blank($this->{$campo})) {
                $falta[] = $rotulo;
            }
        }
        if (! $this->codigo_municipio && filled($this->municipio)) {
            $falta[] = 'município não reconhecido (confira nome e UF)';
        }
        if (blank($this->emails)) {
            $falta[] = 'e-mail';
        }

        return $falta;
    }
}
