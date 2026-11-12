<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Auditoria;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Tomador;
use App\Services\Nfse\DetectorDuplicidade;
use App\Services\Nfse\EnvioDeNotas;
use App\Support\ListaServicoNacional;
use App\Support\MunicipiosIbge;
use App\Support\TabelasDps;
use App\Support\Tenancy\ContaAtual;
use App\Support\ValidadorLinha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Emissão manual em dois passos: preencher (com os padrões da empresa) → conferir e confirmar → emitir.
 * A nota só é criada no segundo passo, depois do "confirmo".
 */
class NotaManualController extends Controller
{
    /** Campos que viajam do formulário para a conferência e de volta. */
    private const CAMPOS_FORM = [
        'emitente_id', 'sem_tomador', 'tomador_documento', 'tomador_nome', 'tomador_email',
        'usar_padrao', 'codigo_servico', 'descricao', 'codigo_nbs', 'valor', 'data_competencia', 'avancado', 'ajustes',
    ];

    public function criar()
    {
        $this->authorize('emitir');

        $emitentes = Emitente::where('ativo', true)->orderBy('razao_social')->get();

        return view('notas.manual', [
            'emitentes' => $emitentes,
            'padroes'   => $emitentes->mapWithKeys(fn (Emitente $e) => [$e->id => [
                'codigo'    => (string) $e->codigo_servico_padrao,
                'nbs'       => (string) $e->codigo_nbs_padrao,
                'descricao' => trim((string) $e->descricao_servico_padrao),
                'teste'     => $e->ehHomologacao(),
                'sem_custo' => $e->semCobranca(),
                'fiscal'    => $this->fiscalPadrao($e),
            ]]),
        ]);
    }

    /** Passo 2: valida e mostra o resumo. Nada é gravado aqui. */
    public function conferir(Request $request)
    {
        $this->authorize('emitir');

        $emitente = Emitente::where('ativo', true)->findOrFail($request->input('emitente_id'));
        $dados = $this->normalizar($request, $emitente);

        $validador = ValidadorLinha::validar($dados, $emitente);
        [, $linhasAjuste, $validadorAjustes] = $this->ajustes($request, $emitente, $dados);
        $erros = $validador->errors();
        if ($validadorAjustes) {
            $erros->merge($validadorAjustes->errors()->toArray());
        }
        if ($erros->isNotEmpty()) {
            return redirect()->route('notas.manual')->withInput($request->only(self::CAMPOS_FORM))->withErrors($erros);
        }

        $parecida = null;
        if (! $dados['sem_tomador']) {
            $parecida = NotaFiscal::where('emitente_id', $emitente->id)
                ->where('tomador_documento', $dados['tomador_documento'])
                ->where('valor', $dados['valor'])
                ->whereDate('data_competencia', $dados['data_competencia'])
                ->whereIn('status', [StatusNota::Emitida, StatusNota::Pendente, StatusNota::Enviando, StatusNota::AguardandoCredito])
                ->latest('id')->first();
        }

        return view('notas.manual-conferir', [
            'emitente'   => $emitente,
            'dados'      => $dados,
            'campos'     => $request->only(self::CAMPOS_FORM) + ['usar_padrao' => ''],
            'servico'    => ListaServicoNacional::descricao($dados['codigo_servico']),
            'cliente'    => $dados['sem_tomador'] ? null : Tomador::where('documento', $dados['tomador_documento'])->first(),
            'padrao'     => [
                'codigo'    => $dados['codigo_servico'] === $emitente->codigo_servico_padrao,
                'descricao' => $dados['descricao'] === trim((string) $emitente->descricao_servico_padrao),
            ],
            'parecida'   => $parecida,
            'ajustes'    => $linhasAjuste,
        ]);
    }

    /** "Alterar": volta ao formulário com tudo o que foi digitado. */
    public function voltar(Request $request)
    {
        $this->authorize('emitir');

        return redirect()->route('notas.manual')->withInput($request->only(self::CAMPOS_FORM));
    }

    /** Autocomplete dos clientes já cadastrados na conta. */
    public function clientes(Request $request)
    {
        $this->authorize('consultar');

        $busca = trim((string) $request->query('q', ''));
        if (mb_strlen($busca) < 2) {
            return response()->json([]);
        }
        $digitos = preg_replace('/\D/', '', $busca);

        return response()->json(
            Tomador::where('ativo', true)->where(function ($q) use ($busca, $digitos) {
                $q->where('nome', 'like', "%{$busca}%")->orWhere('nome_fantasia', 'like', "%{$busca}%");
                if ($digitos !== '') {
                    $q->orWhere('documento', 'like', "%{$digitos}%");
                }
            })->orderBy('nome')->limit(8)->get()->map(fn (Tomador $t) => [
                'documento' => $t->documento,
                'nome'      => $t->nome,
                'email'     => $t->emailPrincipal(),
                'cidade'    => $t->municipio ? "{$t->municipio}/{$t->uf}" : null,
            ])
        );
    }

    /** Passo 3: só com o "confirmo" marcado a nota é criada e enviada. */
    public function store(Request $request, EnvioDeNotas $envio, DetectorDuplicidade $detector)
    {
        $this->authorize('emitir');

        $emitente = Emitente::where('ativo', true)->findOrFail($request->input('emitente_id'));
        $volta = fn () => redirect()->route('notas.manual')->withInput($request->only(self::CAMPOS_FORM));

        if (! $request->boolean('confirmo')) {
            return $volta()->withErrors(['confirmo' => 'Confira os dados e marque a confirmação para emitir.']);
        }

        $dados = $this->normalizar($request, $emitente);
        $validador = ValidadorLinha::validar($dados, $emitente);
        [$parametros, , $validadorAjustes] = $this->ajustes($request, $emitente, $dados);
        $erros = $validador->errors();
        if ($validadorAjustes) {
            $erros->merge($validadorAjustes->errors()->toArray());
        }
        if ($erros->isNotEmpty()) {
            return $volta()->withErrors($erros);
        }

        $nota = null;
        try {
            DB::transaction(function () use (&$nota, $dados, $emitente, $detector, $request, $parametros) {
                $tomadorId = $dados['sem_tomador'] ? null : $this->cliente($dados)->id;

                $nota = NotaFiscal::create([
                    ...ValidadorLinha::paraGravar($dados),
                    'conta_id'    => $emitente->conta_id,
                    'emitente_id' => $emitente->id,
                    'tomador_id'  => $tomadorId,
                    'origem'      => 'web',
                    'serie_dps'   => $emitente->serie_dps,
                    'parametros_dps' => $parametros ?: null,
                    'status'      => StatusNota::Rascunho,
                ]);

                // Parecida com uma nota já emitida ou na fila: só segue se a pessoa confirmou que é outra cobrança.
                if ($detector->marcar($nota) && ! $request->boolean('confirmar_duplicidade')) {
                    $parecida = $nota->duplicadaDe;
                    throw new \DomainException("Já existe uma nota parecida (mesmo cliente, valor e data): nota #{$parecida->id}, {$parecida->status->label()}.");
                }
                if ($nota->duplicidade_de_id) {
                    $nota->forceFill(['duplicidade_aprovada' => true])->save();
                }
            });
        } catch (\DomainException $e) {
            return $volta()->with('duplicidade', $e->getMessage());
        }

        Auditoria::registrar('nota_manual', "Nota manual #{$nota->id} para {$nota->tomador_rotulo}.", ['nota' => $nota->id, 'valor' => (string) $nota->valor]);
        $envio->enviarNota($nota);

        $msg = $nota->fresh()->status === StatusNota::AguardandoCredito
            ? 'Nota criada. Ela vai para a fila assim que houver crédito.'
            : 'Nota enviada para emissão. O resultado aparece em Notas.';

        return redirect()->route('notas.index')->with('ok', $msg);
    }

    /** Valores do cadastro da empresa que a nota completa personalizada abre para ajuste só numa nota. */
    private function fiscalPadrao(Emitente $e): array
    {
        $t = $e->tributos_aprox ?? [];
        $rotulo = MunicipiosIbge::rotulo($e->codigo_municipio);
        $rotuloPrestacao = MunicipiosIbge::rotulo($e->codigoMunicipioPrestacao());
        [$cidade, $uf] = $rotuloPrestacao && str_contains($rotuloPrestacao, '/') ? explode('/', $rotuloPrestacao, 2) : ['', ''];
        $op = (int) $e->op_simples_nacional;

        return [
            'op'               => $op,
            // dedução/redução da base: não optante, ou ME/EPP com o ISSQN apurado pela NFS-e (E0436, E0441, E0442)
            'deducao_ok'       => $e->deducaoPermitida(),
            'cod_prest'        => $e->codigoMunicipioPrestacao(),
            'cod_trib_mun'     => (string) $e->codigo_tributacao_municipal,
            'codigos'          => $e->listaCodigosMunicipais(),
            'regime_especial'  => (string) (int) $e->regime_especial,
            'aliquota_simples' => $e->aliquota_simples !== null ? (string) (float) $e->aliquota_simples : '',
            'trib_federal'     => isset($t['federal']) ? (string) (float) $t['federal'] : '',
            'trib_estadual'    => isset($t['estadual']) ? (string) (float) $t['estadual'] : '',
            'trib_municipal'   => isset($t['municipal']) ? (string) (float) $t['municipal'] : '',
            'retencao'         => (string) (int) ($e->retencao_issqn ?: 1),
            'mun_prestacao'    => $cidade,
            'uf_prestacao'     => $uf,
            'trib_issqn'       => (string) (int) ($e->trib_issqn ?: 1),
            'tp_imunidade'     => $e->tp_imunidade ? (string) (int) $e->tp_imunidade : '',
            'deducao_tipo'     => $e->deducao_tipo ?: 'none',
            'deducao_valor'    => $e->deducao_valor !== null ? (string) (float) $e->deducao_valor : '',
            'modo'             => $e->modoTributosAprox(),
            'modos'            => Emitente::modosPermitidos($op),
            'val_federal'      => '',
            'val_estadual'     => '',
            'val_municipal'    => '',
            'pis_cst'          => $e->pis_cofins_cst ?: '00',
            'pis_ret'          => (string) (int) $e->tp_ret_pis_cofins,
            'pis_bc'           => '',
            'pis_aliq_pis'     => $e->aliquota_pis !== null ? (string) (float) $e->aliquota_pis : '',
            'pis_aliq_cofins'  => $e->aliquota_cofins !== null ? (string) (float) $e->aliquota_cofins : '',
            'ret_irrf'         => '',
            'ret_csll'         => '',
            'ret_cp'           => '',
            'fixos'            => $this->camposDeLeitura($e, $rotulo),
        ];
    }

    /** Campos que a nota completa só mostra: identidade da empresa e o que o Emissor Nacional bloqueia. */
    private function camposDeLeitura(Emitente $e, ?string $cidade): array
    {
        $op = (int) $e->op_simples_nacional;

        $prestador = [
            ['CNPJ', \App\Support\Documento::formatar($e->cnpj)],
            ['Inscrição municipal', filled($e->inscricao_municipal) ? $e->inscricao_municipal : 'não informada'],
            ['Razão social', (string) $e->razao_social],
            ['Município do emissor', $cidade ?: 'código IBGE '.$e->codigo_municipio],
            ['Situação no Simples Nacional', [1 => 'Não optante', 2 => 'MEI', 3 => 'ME/EPP optante'][$op] ?? '—'],
            ['Regime especial de tributação', Emitente::REGIMES_ESPECIAIS[(int) $e->regime_especial] ?? 'Nenhum'],
        ];
        if ($op === 3) {
            $prestador[] = ['Regime de apuração do Simples', (int) $e->regime_apuracao_sn === 2 ? 'Tributos federais pelo Simples e ISSQN pela NFS-e' : 'Federais e municipal pelo Simples Nacional'];
        }
        $prestador[] = ['Série da DPS', (string) ($e->serie_dps ?: '00001')];
        $prestador[] = ['Ambiente', (int) $e->ambiente === 1 ? 'Produção' : 'Teste (homologação)'];

        $bloqueados = [
            ['Exigibilidade do ISSQN suspensa', 'Não (bloqueado no Emissor Nacional)'],
            ['Benefício municipal', 'Não (bloqueado no Emissor Nacional)'],
            ['Código indicador da operação', 'bloqueado no Emissor Nacional'],
            ['IBS/CBS (situação e classificação tributária)', 'bloqueado no Emissor Nacional'],
        ];

        return compact('prestador', 'bloqueados');
    }

    /**
     * Ajustes fiscais só desta nota (nota fiscal completa personalizada).
     * Valida segundo o XSD e o Anexo I e devolve só o que difere do padrão da empresa, no formato que o DpsBuilder lê em parametros_dps.
     *
     * @return array{0: array, 1: list<array{0:string,1:string,2:string}>, 2: ?\Illuminate\Contracts\Validation\Validator} [parametros, linhas (rótulo, de, para), validador com erros]
     */
    private function ajustes(Request $request, Emitente $e, array $dados): array
    {
        if (! $request->boolean('avancado')) {
            return [[], [], null];
        }

        $base = $this->fiscalPadrao($e);
        $op = $base['op'];
        $valorNota = is_numeric($dados['valor'] ?? null) ? (float) $dados['valor'] : 0.0;
        $in = (array) $request->input('ajustes', []);
        foreach (['aliquota_simples', 'trib_federal', 'trib_estadual', 'trib_municipal', 'deducao_valor', 'val_federal', 'val_estadual', 'val_municipal', 'pis_bc', 'pis_aliq_pis', 'pis_aliq_cofins', 'ret_irrf', 'ret_csll', 'ret_cp'] as $k) {
            if (isset($in[$k]) && is_string($in[$k])) {
                $in[$k] = str_replace(',', '.', trim($in[$k]));
            }
        }
        $trib = (string) ($in['trib_issqn'] ?? '1');
        $tributavel = $trib === '1';
        $deducao = (string) ($in['deducao_tipo'] ?? 'none');
        $pisRet = (int) ($in['pis_ret'] ?? $base['pis_ret']);
        $cst = (string) ($in['pis_cst'] ?? $base['pis_cst']);
        $modo = (string) ($in['modo_tributos_aprox'] ?? $base['modo']);
        $pct = ['required', 'numeric', 'between:0,100'];
        $dinheiro = ['nullable', 'numeric', 'gt:0', 'lt:'.max($valorNota, 0.01)];

        $regras = [
            'ajustes.trib_issqn'      => ['required', 'in:1,2,4'],
            'ajustes.regime_especial' => ['nullable', 'in:0,1,2,3,4,5,6,9'],   // só leitura na tela (vem do cadastro, como no portal)
            'ajustes.modo_tributos_aprox' => ['nullable', Rule::in($base['modos'])],
            'ajustes.retencao'        => ['required', 'in:1,2'],
            'ajustes.mun_prestacao'   => ['nullable', 'string', 'max:120'],
            'ajustes.uf_prestacao'    => ['nullable', 'string', 'size:2'],
            'ajustes.deducao_tipo'    => ['required', 'in:none,percentual,valor'],
        ];
        if ($trib === '2') {
            $regras['ajustes.tp_imunidade'] = ['required', 'in:1,2,3,4,5'];
        }
        if ($deducao === 'percentual') {
            $regras['ajustes.deducao_valor'] = ['required', 'numeric', 'gt:0', 'lte:100'];
        } elseif ($deducao === 'valor') {
            $regras['ajustes.deducao_valor'] = ['required', 'numeric', 'gt:0', 'lt:'.max($valorNota, 0.01)];
        }
        if ($op !== 2) {
            $regras['ajustes.cod_trib_mun'] = ['nullable', 'regex:/^\d{1,3}$/'];
        }
        if ($modo === 'simples') {
            $regras['ajustes.aliquota_simples'] = $pct;
        }
        if ($modo === 'percentual') {
            foreach (['federal', 'estadual', 'municipal'] as $k) {
                $regras["ajustes.trib_{$k}"] = $pct;
            }
        }
        if ($modo === 'monetario') {   // em branco = calcula pelo percentual da empresa
            foreach (['federal', 'estadual', 'municipal'] as $k) {
                $regras["ajustes.val_{$k}"] = ['nullable', 'numeric', 'gte:0', 'lte:'.max($valorNota, 0.01)];   // E0702 a E0704
            }
        }
        if ($op === 1) {
            $regras['ajustes.pis_cst'] = ['required', Rule::in(array_keys(TabelasDps::CST_PIS_COFINS))];
            $regras['ajustes.pis_ret'] = ['required', 'in:0,1,2,3,4,5,6,7,8,9'];
            $regras['ajustes.ret_irrf'] = $dinheiro;
            $regras['ajustes.ret_cp'] = $dinheiro;
            $regras['ajustes.ret_csll'] = $dinheiro;
            if ($cst !== '00') {
                $regras['ajustes.pis_bc'] = ['nullable', 'numeric', 'gt:0', 'lte:'.max($valorNota, 0.01)];   // E0677; em branco = valor do serviço
                $regras['ajustes.pis_aliq_pis'] = $pct;
                $regras['ajustes.pis_aliq_cofins'] = $pct;
            }
        }

        $codigoPrestacao = null;
        $validador = Validator::make(['ajustes' => $in], $regras, [
            'ajustes.cod_trib_mun.regex'    => 'O código complementar municipal tem até 3 números (ex.: 001).',
            'ajustes.*.between'             => 'Informe um percentual entre 0 e 100.',
            'ajustes.*.numeric'             => 'Informe só números (ex.: 13,45).',
            'ajustes.*.required'            => 'Preencha este campo ou volte aos padrões da empresa.',
            'ajustes.*.gt'                  => 'Informe um valor maior que zero.',
            'ajustes.*.lt'                  => 'O valor precisa ser menor que o valor do serviço.',
            'ajustes.*.lte'                 => 'O valor passou do permitido.',
            'ajustes.uf_prestacao.size'     => 'A UF tem 2 letras (ex.: SP).',
            'ajustes.tp_imunidade.required' => 'Escolha o tipo de imunidade.',
        ]);
        $validador->after(function ($v) use ($in, $e, $op, $dados, $base, $trib, $tributavel, $deducao, $pisRet, $cst, &$codigoPrestacao) {
            $erro = fn ($campo, $msg) => $v->errors()->add("ajustes.{$campo}", $msg);

            if (! $tributavel) {   // imunidade ou não incidência: sem regime especial, retenção, dedução nem alíquota (E0172, E0580, E0435)
                if ((int) ($in['regime_especial'] ?? $e->regime_especial) !== 0) {
                    $erro('trib_issqn', 'Com imunidade ou não incidência a empresa não pode ter regime especial de tributação (ele vem do cadastro da empresa).');
                }
                if (($in['retencao'] ?? '1') !== '1') {
                    $erro('retencao', 'Com imunidade ou não incidência não pode haver retenção do ISSQN.');
                }
                if ($deducao !== 'none') {
                    $erro('deducao_tipo', 'Com imunidade ou não incidência não pode haver dedução/redução.');
                }
            }

            if ($deducao !== 'none') {
                if (! $base['deducao_ok']) {
                    $erro('deducao_tipo', $op === 2 ? 'MEI não pode ter dedução/redução da base de cálculo.' : 'Para ME/EPP com o ISSQN apurado pelo Simples Nacional não há dedução/redução da base de cálculo.');
                }
                if ((int) ($in['regime_especial'] ?? $e->regime_especial) !== 0) {
                    $erro('deducao_tipo', 'Com regime especial de tributação não pode haver dedução/redução.');
                }
            }

            if (($in['retencao'] ?? '1') === '2') {
                if ($op === 2) {
                    $erro('retencao', 'MEI não pode ter retenção do ISSQN pelo tomador.');
                }
                if ((int) ($in['regime_especial'] ?? $e->regime_especial) !== 0) {
                    $erro('retencao', 'Com regime especial de tributação não pode haver retenção do ISSQN pelo tomador.');
                }
                if ($dados['sem_tomador']) {
                    $erro('retencao', 'Para a retenção pelo tomador, informe o cliente da nota.');
                } elseif (! ($t = Tomador::where('documento', $dados['tomador_documento'])->first()) || ! $t->enderecoCompleto()) {
                    $erro('retencao', 'A retenção pelo tomador exige o endereço completo do cliente. Complete o cadastro dele em Clientes e volte.');
                }
            }

            if ($op === 1) {   // retenções federais (E0720, E0724)
                $csll = trim((string) ($in['ret_csll'] ?? ''));
                if (in_array($pisRet, TabelasDps::CSLL_PROIBIDA, true) && $csll !== '') {
                    $erro('ret_csll', 'Com o tipo de retenção "'.TabelasDps::RETENCAO_PIS_COFINS[0].'" a CSLL retida não pode ser informada.');
                }
                if (TabelasDps::csllObrigatoria($pisRet) && $csll === '') {
                    $erro('ret_csll', 'Com este tipo de retenção é obrigatório informar a CSLL retida.');
                }
            }

            $mun = trim((string) ($in['mun_prestacao'] ?? ''));
            $uf = trim((string) ($in['uf_prestacao'] ?? ''));
            if ($mun !== '' || $uf !== '') {
                $codigoPrestacao = MunicipiosIbge::codigo($mun, $uf);
                if (! $codigoPrestacao) {
                    $erro('mun_prestacao', 'Município da prestação não reconhecido: confira o nome e a UF.');
                }
            }
        });

        if ($validador->fails()) {
            return [[], [], $validador];
        }

        $parametros = [];
        $linhas = [];
        $num = fn ($v) => (float) $v;
        $pct2 = fn ($v) => number_format((float) $v, 2, ',', '.').'%';
        $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');

        if ($op !== 2) {
            $novo = preg_replace('/\D/', '', (string) ($in['cod_trib_mun'] ?? ''));
            if ($novo !== preg_replace('/\D/', '', $base['cod_trib_mun'])) {
                $parametros['codigo_tributacao_municipal'] = $novo === '' ? '' : str_pad($novo, 3, '0', STR_PAD_LEFT);
                $linhas[] = ['Código complementar municipal', $base['cod_trib_mun'] ?: 'não usado', $novo === '' ? 'não usado' : str_pad($novo, 3, '0', STR_PAD_LEFT)];
            }
        }
        if ($trib !== $base['trib_issqn'] || ($trib === '2' && (string) (int) ($in['tp_imunidade'] ?? 0) !== $base['tp_imunidade'])) {
            $parametros['trib_issqn'] = (int) $trib;
            $linhas[] = ['Tributação do ISSQN', TabelasDps::TRIBUTACAO_ISSQN[(int) $base['trib_issqn']] ?? '—', TabelasDps::TRIBUTACAO_ISSQN[(int) $trib]];
            if ($trib === '2') {
                $parametros['tp_imunidade'] = (int) $in['tp_imunidade'];
                $linhas[] = ['Tipo de imunidade', $base['tp_imunidade'] !== '' ? TabelasDps::IMUNIDADES[(int) $base['tp_imunidade']] : '—', TabelasDps::IMUNIDADES[(int) $in['tp_imunidade']]];
            }
        }
        if (isset($in['regime_especial']) && (int) $in['regime_especial'] !== (int) $base['regime_especial']) {
            $parametros['regime_especial'] = (int) $in['regime_especial'];
            $linhas[] = ['Regime especial de tributação', Emitente::REGIMES_ESPECIAIS[(int) $base['regime_especial']] ?? '—', Emitente::REGIMES_ESPECIAIS[(int) $in['regime_especial']] ?? '—'];
        }
        $textoDeducao = fn ($tipo, $v) => $tipo === 'none' ? 'nenhuma' : ($tipo === 'percentual' ? $pct2($v) : $brl($v));
        if ($deducao !== $base['deducao_tipo'] || ($deducao !== 'none' && $num($in['deducao_valor']) !== $num($base['deducao_valor']))) {
            $parametros['deducao'] = $deducao === 'none' ? ['tipo' => 'none'] : ['tipo' => $deducao, 'valor' => $num($in['deducao_valor'])];
            $linhas[] = ['Dedução/redução da base de cálculo', $textoDeducao($base['deducao_tipo'], $base['deducao_valor']), $textoDeducao($deducao, $in['deducao_valor'] ?? '')];
        }
        // Valor aproximado dos tributos: forma e valores só desta nota
        $mudouModo = $modo !== $base['modo'];
        if ($mudouModo) {
            $parametros['modo_tributos_aprox'] = $modo;
            $linhas[] = ['Valor aproximado dos tributos', Emitente::MODOS_TRIBUTOS_APROX[$base['modo']], Emitente::MODOS_TRIBUTOS_APROX[$modo]];
        }
        if ($modo === 'simples' && ($mudouModo || $num($in['aliquota_simples']) !== $num($base['aliquota_simples']))) {
            $parametros['aliquota_simples'] = $num($in['aliquota_simples']);
            $linhas[] = ['Alíquota do Simples Nacional', $pct2($base['aliquota_simples']), $pct2($in['aliquota_simples'])];
        }
        if ($modo === 'percentual') {
            $mudou = $mudouModo;
            foreach (['federal' => 'Federal', 'estadual' => 'Estadual', 'municipal' => 'Municipal'] as $k => $nome) {
                if ($num($in["trib_{$k}"]) !== $num($base["trib_{$k}"])) {
                    $mudou = true;
                    $linhas[] = ["Tributos aproximados · {$nome}", $pct2($base["trib_{$k}"]), $pct2($in["trib_{$k}"])];
                }
            }
            if ($mudou) {
                $parametros['tributos_aprox'] = ['federal' => $num($in['trib_federal']), 'estadual' => $num($in['trib_estadual']), 'municipal' => $num($in['trib_municipal'])];
            }
        }
        if ($modo === 'monetario') {
            $informados = array_filter(['federal', 'estadual', 'municipal'], fn ($k) => trim((string) ($in["val_{$k}"] ?? '')) !== '');
            if ($informados) {
                $calculado = fn ($k) => round($valorNota * $num($base["trib_{$k}"]) / 100, 2);
                $parametros['valores_tributos'] = [];
                foreach (['federal' => 'Federal', 'estadual' => 'Estadual', 'municipal' => 'Municipal'] as $k => $nome) {
                    $v = in_array($k, $informados, true) ? $num($in["val_{$k}"]) : $calculado($k);
                    $parametros['valores_tributos'][$k] = $v;
                    $linhas[] = ["Tributos aproximados · {$nome}", $brl($calculado($k)).' (calculado)', $brl($v)];
                }
            }
        }
        if ($op === 1) {
            $bc = trim((string) ($in['pis_bc'] ?? '')) !== '' ? $num($in['pis_bc']) : $valorNota;
            $mudouPis = $cst !== $base['pis_cst'] || $pisRet !== (int) $base['pis_ret']
                || ($cst !== '00' && (trim((string) ($in['pis_bc'] ?? '')) !== '' || $num($in['pis_aliq_pis']) !== $num($base['pis_aliq_pis']) || $num($in['pis_aliq_cofins']) !== $num($base['pis_aliq_cofins'])));
            if ($mudouPis) {
                $pc = ['cst' => $cst, 'tp_ret' => $pisRet];
                if ($cst !== '00') {
                    $pc += ['bc' => $bc, 'aliq_pis' => $num($in['pis_aliq_pis']), 'aliq_cofins' => $num($in['pis_aliq_cofins'])];
                }
                $parametros['pis_cofins'] = $pc;
                if ($cst !== $base['pis_cst'] || ($cst !== '00' && isset($pc['bc']))) {
                    $linhas[] = ['Situação tributária do PIS/COFINS', $base['pis_cst'].' - '.TabelasDps::CST_PIS_COFINS[$base['pis_cst']], $cst.' - '.TabelasDps::CST_PIS_COFINS[$cst].($cst !== '00' ? ' (base '.$brl($bc).', PIS '.$pct2($in['pis_aliq_pis']).', COFINS '.$pct2($in['pis_aliq_cofins']).')' : '')];
                }
                if ($pisRet !== (int) $base['pis_ret']) {
                    $linhas[] = ['Tipo de retenção do PIS/COFINS/CSLL', $base['pis_ret'].' - '.TabelasDps::RETENCAO_PIS_COFINS[(int) $base['pis_ret']], $pisRet.' - '.TabelasDps::RETENCAO_PIS_COFINS[$pisRet]];
                }
            }
            foreach (['ret_irrf' => 'IRRF', 'ret_csll' => 'Contribuições sociais retidas (CSLL)', 'ret_cp' => 'Contribuição previdenciária retida'] as $k => $nome) {
                if (trim((string) ($in[$k] ?? '')) !== '') {
                    $parametros[$k] = $num($in[$k]);
                    $linhas[] = [$nome, 'sem preenchimento', $brl($in[$k])];
                }
            }
        }
        if ($in['retencao'] !== $base['retencao']) {
            $parametros['retencao_issqn'] = (int) $in['retencao'];
            $linhas[] = ['Retenção do ISSQN pelo tomador', $base['retencao'] === '2' ? 'Sim' : 'Não', $in['retencao'] === '2' ? 'Sim' : 'Não'];
        }
        if ($codigoPrestacao && $codigoPrestacao !== $base['cod_prest']) {
            $parametros['cod_municipio_prestacao'] = $codigoPrestacao;
            $linhas[] = ['Município da prestação', ($base['mun_prestacao'] ?: $e->codigo_municipio).($base['uf_prestacao'] ? '/'.$base['uf_prestacao'] : ''), trim($in['mun_prestacao']).'/'.strtoupper(trim($in['uf_prestacao']))];
        }

        return [$parametros, $linhas, null];
    }

    private function normalizar(Request $request, Emitente $emitente): array
    {
        return ValidadorLinha::normalizar([
            'tomador_documento' => $request->input('tomador_documento'),
            'tomador_nome'      => $request->input('tomador_nome'),
            'tomador_email'     => $request->input('tomador_email'),
            'sem_tomador'       => $request->boolean('sem_tomador'),
            'codigo_servico'    => $request->input('codigo_servico'),
            'codigo_nbs'        => $request->input('codigo_nbs'),
            'descricao'         => $request->input('descricao'),
            'valor'             => $request->input('valor'),
            'data_competencia'  => $request->input('data_competencia'),
        ], $emitente);
    }

    /** Cliente da conta pelo CPF/CNPJ; se ainda não existe, é cadastrado com o que foi digitado (o endereço pode ser completado depois em Clientes). */
    private function cliente(array $dados): Tomador
    {
        $t = Tomador::firstOrNew(['documento' => $dados['tomador_documento']]);
        if (! $t->exists) {
            $t->fill([
                'conta_id' => app(ContaAtual::class)->id(),
                'nome'     => $dados['tomador_nome'],
                'emails'   => $dados['tomador_email'] ? [$dados['tomador_email']] : [],
            ]);
            $t->pendente = true;
            $t->save();
        }

        return $t;
    }
}
