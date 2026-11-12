<?php

namespace App\Support;

use App\Enums\StatusNota;
use App\Models\NotaFiscal;

/**
 * Traduz o motivo de uma nota que "precisa de você" para: o que aconteceu, como resolver
 * e quais campos o usuário pode corrigir ali mesmo. O texto técnico original fica em `tecnico`.
 */
class ExplicacaoErro
{
    /** Campos editáveis na correção, com rótulo. */
    public const ROTULOS = [
        'tomador_documento' => 'CPF ou CNPJ do cliente',
        'tomador_nome'      => 'Nome ou razão social do cliente',
        'tomador_email'     => 'E-mail do cliente',
        'codigo_servico'    => 'Código de serviço (6 dígitos)',
        'codigo_nbs'        => 'Código NBS (9 dígitos, opcional)',
        'descricao'         => 'Descrição do serviço',
        'valor'             => 'Valor (R$)',
        'data_competencia'  => 'Data de competência',
    ];

    /** @return array{titulo:string, o_que_aconteceu:string, como_resolver:string, campos:array<int,string>, reenviar_direto:bool, tecnico:array<int,string>} */
    public static function para(NotaFiscal $nota): array
    {
        $tecnico = array_values((array) $nota->erros);

        return match ($nota->status) {
            StatusNota::Invalida => self::invalida($nota, $tecnico),
            StatusNota::Rejeitada => self::rejeitada($nota, $tecnico),
            StatusNota::Falhou => [
                'titulo'          => 'Não saiu por instabilidade',
                'o_que_aconteceu' => 'A prefeitura não respondeu depois de várias tentativas. Seus dados estão certos.',
                'como_resolver'   => 'É só reenviar. Se acontecer de novo, tente mais tarde.',
                'campos'          => [],
                'reenviar_direto' => true,
                'tecnico'         => $tecnico,
            ],
            default => [
                'titulo' => $nota->status->label(), 'o_que_aconteceu' => '', 'como_resolver' => '',
                'campos' => [], 'reenviar_direto' => false, 'tecnico' => $tecnico,
            ],
        };
    }

    private static function invalida(NotaFiscal $nota, array $tecnico): array
    {
        if (str_starts_with((string) ($tecnico[0] ?? ''), \App\Services\Nfse\EnvioDeNotas::CADASTRO_INCOMPLETO)) {
            return [
                'titulo'          => 'Cadastro do cliente incompleto',
                'o_que_aconteceu' => $tecnico[0].' Não emitimos nota para cliente sem endereço completo e ao menos 1 e-mail.',
                'como_resolver'   => 'Complete o cadastro em Clientes (ou informe os dados na planilha) e clique em Reenviar. '.(app(\App\Support\Tenancy\EmitenteAtual::class)->semCobranca() ? '' : 'Esta nota ainda não gastou crédito.'),
                'campos'          => [],
                'reenviar_direto' => true,
                'tecnico'         => $tecnico,
            ];
        }

        // Revalida para saber exatamente quais campos estão errados
        $dados = ValidadorLinha::normalizar($nota->only(ValidadorLinha::CAMPOS) + ['data_competencia' => $nota->data_competencia?->toDateString()], $nota->emitente);
        $campos = array_keys(ValidadorLinha::validar($dados, $nota->emitente)->errors()->messages());

        return [
            'titulo'          => 'Dado inválido na planilha',
            'o_que_aconteceu' => implode(' ', $tecnico) ?: 'Algum dado desta linha não passou na conferência.',
            'como_resolver'   => 'Corrija o campo abaixo e confirme. '.(app(\App\Support\Tenancy\EmitenteAtual::class)->semCobranca() ? '' : 'Esta nota ainda não gastou crédito.'),
            'campos'          => $campos ?: array_keys(self::ROTULOS),
            'reenviar_direto' => false,
            'tecnico'         => $tecnico,
        ];
    }

    private static function rejeitada(NotaFiscal $nota, array $tecnico): array
    {
        $texto = implode(' | ', $tecnico);

        $mapa = [
            '/E0312/'                        => [['codigo_servico'], 'A prefeitura da empresa não administra o código de serviço %codigo% na data de competência desta nota. Esse código existe na lista nacional, mas cada prefeitura escolhe quais ela aceita.', 'Peça ao seu contador (ou à prefeitura) o código de serviço nacional aceito para esta atividade e corrija aqui. Para descobrir qual ela aceita, veja a lista de serviços no emissor público nacional da sua prefeitura.'],
            '/E0310/'                        => [['codigo_servico'], 'O código de serviço %codigo% não existe na lista nacional de serviços.', 'Use um código de 6 dígitos da lista nacional (ex.: 010701 para manutenção de programas de computação) ou o que seu contador indicou.'],
            '/E0316/'                        => [['codigo_nbs'], 'O código NBS informado não existe ou não combina com o serviço.', 'Corrija o código NBS ou deixe o campo vazio.'],
            '/E0015/'                        => [['data_competencia'], 'A data de competência não é aceita.', 'Informe uma data de competência que não esteja no futuro.'],
            '/E0080|E0188|E0206/'            => [['tomador_documento'], 'O CPF/CNPJ do cliente foi recusado.', 'Confira o documento do cliente e corrija.'],
            '/E0247/'                        => [['tomador_email'], 'O e-mail do cliente foi recusado.', 'Corrija o e-mail ou deixe o campo vazio.'],
        ];

        foreach ($mapa as $padrao => [$campos, $quando, $resolver]) {
            if (preg_match($padrao, $texto)) {
                return [
                    'titulo'          => 'Recusada pela prefeitura',
                    'o_que_aconteceu' => str_replace('%codigo%', $nota->codigo_servico, $quando),
                    'como_resolver'   => $resolver,
                    'campos'          => $campos,
                    'reenviar_direto' => false,
                    'tecnico'         => $tecnico,
                ];
            }
        }

        return [
            'titulo'          => 'Recusada pela prefeitura',
            'o_que_aconteceu' => 'A prefeitura recusou esta nota. Veja o motivo em "Detalhes técnicos".',
            'como_resolver'   => 'Corrija o dado indicado ou fale com seu contador, depois reenvie.',
            'campos'          => array_keys(self::ROTULOS),
            'reenviar_direto' => false,
            'tecnico'         => $tecnico,
        ];
    }

    /** Grupo de "Precisa de você" em que a nota aparece. */
    public static function grupo(StatusNota $status): ?string
    {
        return match ($status) {
            StatusNota::Rejeitada => 'recusadas',
            StatusNota::Invalida  => 'invalidas',
            StatusNota::Falhou    => 'instabilidade',
            default               => null,
        };
    }

    public static function statusDoGrupo(string $grupo): ?StatusNota
    {
        return match ($grupo) {
            'recusadas'     => StatusNota::Rejeitada,
            'invalidas'     => StatusNota::Invalida,
            'instabilidade' => StatusNota::Falhou,
            default         => null,
        };
    }
}
