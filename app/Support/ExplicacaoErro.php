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

    /** Elementos do XML (XSD) que vêm direto de um campo que a pessoa digitou. */
    private const XSD_CAMPOS = [
        'xDescServ' => ['descricao', 'A descrição do serviço não está num formato aceito (tamanho ou caracteres).'],
        'xNome'     => ['tomador_nome', 'O nome ou razão social do cliente não está num formato aceito (tamanho ou caracteres).'],
        'cTribNac'  => ['codigo_servico', 'O código de serviço não está num formato aceito (são 6 números).'],
        'cNBS'      => ['codigo_nbs', 'O código NBS não está num formato aceito (são 9 números).'],
        'vServ'     => ['valor', 'O valor da nota não está num formato aceito.'],
        'CPF'       => ['tomador_documento', 'O CPF do cliente não está num formato aceito.'],
        'CNPJ'      => ['tomador_documento', 'O CNPJ do cliente não está num formato aceito.'],
        'email'     => ['tomador_email', 'O e-mail do cliente não está num formato aceito.'],
        'dCompet'   => ['data_competencia', 'A data de competência não está num formato aceito.'],
    ];

    /**
     * A nota nem saiu: o sistema conferiu o documento contra o padrão oficial (XSD) antes de enviar e ele não passou.
     * Não é recusa da prefeitura. Se o elemento é um campo digitado, a pessoa corrige; senão é problema do sistema.
     */
    private static function foraDoPadrao(array $tecnico): array
    {
        $campos = [];
        $quando = [];
        foreach ($tecnico as $linha) {
            if (preg_match("/Element '(?:\{[^}]*\})?(\w+)'/", (string) $linha, $m) && isset(self::XSD_CAMPOS[$m[1]])) {
                [$campo, $msg] = self::XSD_CAMPOS[$m[1]];
                $campos[$campo] = $campo;
                $quando[$msg] = $msg;
            }
        }

        if ($campos) {
            return [
                'titulo'          => 'Dado fora do formato aceito',
                'rotulo'          => 'Dado fora do formato',
                'o_que_aconteceu' => implode(' ', $quando).' A nota não foi enviada.',
                'como_resolver'   => 'Corrija o campo abaixo e reenvie.',
                'campos'          => array_values($campos),
                'reenviar_direto' => false,
                'tecnico'         => $tecnico,
            ];
        }

        return [
            'titulo'          => 'Não foi enviada: erro no sistema',
            'rotulo'          => 'Erro no sistema',
            'o_que_aconteceu' => 'A nota não chegou a ser enviada para a prefeitura. O sistema montou o documento fora do padrão da NFS-e. Não é problema nos dados do cliente nem da nota.',
            'como_resolver'   => 'Não há nada para corrigir aqui. Avise o suporte informando o número desta nota (#'.'%id%'.') e depois use Reenviar.',
            'campos'          => [],
            'reenviar_direto' => true,
            'tecnico'         => $tecnico,
            'acao'            => 'sistema',
            'codigos'         => ['XSD'],
        ];
    }

    private static function rejeitada(NotaFiscal $nota, array $tecnico): array
    {
        if ($tecnico && collect($tecnico)->every(fn ($t) => str_starts_with((string) $t, 'XSD:'))) {
            $r = self::foraDoPadrao($tecnico);
            $r['como_resolver'] = str_replace('%id%', (string) $nota->id, $r['como_resolver']);

            return $r;
        }

        $texto = implode(' | ', $tecnico);

        $mapa = [
            '/E0312/'                        => [['codigo_servico'], 'A prefeitura da empresa não administra o código de serviço %codigo% na data de competência desta nota. Esse código existe na lista nacional, mas cada prefeitura escolhe quais ela aceita.', 'Peça ao seu contador (ou à prefeitura) o código de serviço nacional aceito para esta atividade e corrija aqui. Para descobrir qual ela aceita, veja a lista de serviços no emissor público nacional da sua prefeitura.'],
            '/E0310/'                        => [['codigo_servico'], 'O código de serviço %codigo% não existe na lista nacional de serviços.', 'Use um código de 6 dígitos da lista nacional (ex.: 010701 para manutenção de programas de computação) ou o que seu contador indicou.'],
            '/E0316/'                        => [['codigo_nbs'], 'O código NBS informado não existe ou não combina com o serviço.', 'Corrija o código NBS ou deixe o campo vazio.'],
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

        // Catálogo de erros (Anexo I): ação, texto e destino de cada código
        if ($explicacao = CatalogoErros::para($tecnico, $nota)) {
            return $explicacao;
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
