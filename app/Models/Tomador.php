<?php

namespace App\Models;

use App\Enums\StatusNota;
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
        'ativo'         => 'Cadastro',
    ];

    protected $fillable = [
        'conta_id', 'documento', 'nome', 'nome_fantasia', 'logradouro', 'numero', 'complemento', 'bairro',
        'municipio', 'codigo_municipio', 'uf', 'cep', 'emails', 'observacoes', 'pendente', 'ativo',
    ];

    protected $casts = ['emails' => 'array', 'pendente' => 'boolean', 'ativo' => 'boolean'];

    public function notas(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }

    /** Notas já emitidas (inclui as depois canceladas) deste cliente: com elas o cadastro não pode ser excluído. */
    public function notasEmitidas(): int
    {
        return $this->notas()->whereIn('status', [StatusNota::Emitida->value, StatusNota::Cancelada->value])->count();
    }

    public function alteracoes(): HasMany
    {
        return $this->hasMany(TomadorAlteracao::class)->latest('id');
    }

    /** PF (CPF, 11 números) ou PJ (CNPJ, 14). Vem só do documento, nunca do nome: uma empresa pode ter nome de pessoa. */
    public function tipoPessoa(): string
    {
        return strlen(preg_replace('/\D/', '', (string) $this->documento)) === 11 ? 'PF' : 'PJ';
    }

    /** E-mail que vai no XML da DPS (o leiaute aceita só um). */
    public function emailPrincipal(): ?string
    {
        return ($this->emails ?? [])[0] ?? null;
    }

    /**
     * Código IBGE do município: primeiro pelo nome + UF (tabela do IBGE); se não casar (grafia diferente, IBGE fora do ar),
     * usa o código que o CEP já traz (ViaCEP/BrasilAPI), desde que seja da mesma UF.
     */
    public static function descobrirCodigoMunicipio(?string $municipio, ?string $uf, ?string $cep): ?string
    {
        if ($codigo = \App\Support\MunicipiosIbge::codigo($municipio, $uf)) {
            return $codigo;
        }

        $dados = strlen((string) preg_replace('/\D/', '', (string) $cep)) === 8 ? app(\App\Services\ConsultaCep::class)->buscar((string) $cep) : null;
        if ($dados && ! empty($dados['codigo_municipio']) && strtoupper((string) ($dados['uf'] ?? '')) === strtoupper(trim((string) $uf))) {
            return (string) $dados['codigo_municipio'];
        }

        return null;
    }

    /** Município e UF preenchidos mas sem código IBGE (a busca falhou na importação): tenta de novo e grava se achar. */
    public function resolverMunicipio(): void
    {
        if ($this->codigo_municipio || blank($this->municipio) || blank($this->uf)) {
            return;
        }

        if ($codigo = self::descobrirCodigoMunicipio($this->municipio, $this->uf, $this->cep)) {
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
