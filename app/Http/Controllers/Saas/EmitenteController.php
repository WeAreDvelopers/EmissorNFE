<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\CodigoMunicipal;
use App\Models\Emitente;
use App\Services\Nfse\Certificado;
use App\Services\Nfse\NumeracaoDps;
use App\Support\Documento;
use App\Support\ListaServicoNacional;
use App\Support\MunicipiosIbge;
use App\Support\TabelasDps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmitenteController extends Controller
{
    public function index()
    {
        $this->authorize('consultar');

        return view('emitentes.index', [
            'emitentes'      => Emitente::orderBy('razao_social')->get(),
            'empresaAtivaId' => app(\App\Support\Tenancy\EmitenteAtual::class)->id(),
        ]);
    }

    public function criar()
    {
        $this->authorize('gerenciar-emitentes');

        return view('emitentes.form', ['emitente' => new Emitente(['serie_dps' => '00001', 'ambiente' => 2])]);
    }

    /**
     * Lê o certificado enviado e devolve CNPJ, razão social e validade, para a tela preencher sozinha.
     * Não grava nada: o arquivo só é guardado quando o usuário salva o emitente.
     */
    public function lerCertificado(Request $request)
    {
        $this->authorize('gerenciar-emitentes');

        $request->validate(['certificado' => ['required', 'file', 'max:100'], 'certificado_senha' => ['required', 'string']], [
            'certificado.required' => 'Escolha o arquivo do certificado.',
            'certificado_senha.required' => 'Digite a senha do certificado.',
        ]);

        try {
            $cert = new Certificado($request->file('certificado')->get(), $request->input('certificado_senha'));
        } catch (RuntimeException) {
            return response()->json(['message' => 'Não conseguimos abrir o certificado. Confira a senha e se o arquivo é um certificado A1 (.pfx ou .p12).'], 422);
        }

        $valido = $cert->validoAte();

        return response()->json([
            'razao_social' => $cert->razaoSocialProvavel(),
            'cnpj'         => $cert->cnpjProvavel() ? Documento::formatar($cert->cnpjProvavel()) : null,
            'valido_ate'   => $valido?->format('d/m/Y'),
            'vencido'      => (bool) $valido?->isPast(),
        ]);
    }

    public function editar(Emitente $emitente)
    {
        $this->authorize('gerenciar-emitentes');

        $numeracao = app(NumeracaoDps::class);

        return view('emitentes.form', [
            'emitente'     => $emitente,
            'numeracao'    => $numeracao->situacao($emitente),
            'historicoDps' => $numeracao->historico($emitente),
            'ajustesDps'   => $numeracao->ajustes($emitente),
            'alteracoes'   => $emitente->alteracoes()->with('user')->get(),
        ]);
    }

    /** Ajuste manual do próximo número da DPS (somente o dono; não deixa repetir número já usado). */
    public function ajustarNumeracao(Request $request, Emitente $emitente, NumeracaoDps $numeracao)
    {
        $this->authorize('gerenciar-emitentes');

        $dados = $request->validate([
            'ambiente' => ['required', 'in:1,2'],
            'proximo'  => ['required', 'integer', 'min:1'],
        ], ['proximo.required' => 'Informe o próximo número.', 'proximo.integer' => 'O próximo número precisa ser um número inteiro.', 'proximo.min' => 'O próximo número precisa ser 1 ou maior.']);

        $numeracao->ajustar($emitente, (int) $dados['ambiente'], (int) $dados['proximo'], $request->user()?->id);
        \App\Models\Auditoria::registrar('numeracao_ajustada', "Próximo número da DPS de {$emitente->razao_social} ajustado para {$dados['proximo']} (".($dados['ambiente'] == 1 ? 'produção' : 'teste').').', ['emitente' => $emitente->id, 'serie' => $emitente->serie_dps, 'proximo' => (int) $dados['proximo']]);

        return back()->with('ok', 'Numeração ajustada. A próxima DPS será a nº '.number_format((int) $dados['proximo'], 0, ',', '.').'.');
    }

    /** Escolhe a empresa que o usuário vai administrar (menu lateral). Notas, importações e tomadores passam a ser só dela. */
    public function administrar(Request $request, Emitente $emitente)
    {
        abort_unless($emitente->ativo, 404);
        $request->user()->forceFill(['emitente_atual_id' => $emitente->id])->save();

        return redirect()->route('inicio')->with('ok', "Agora você está administrando {$emitente->razao_social}.");
    }

    public function store(Request $request)
    {
        $this->authorize('gerenciar-emitentes');

        return $this->salvar($request, new Emitente());
    }

    public function update(Request $request, Emitente $emitente)
    {
        $this->authorize('gerenciar-emitentes');

        return $this->salvar($request, $emitente);
    }

    private function salvar(Request $request, Emitente $emitente)
    {
        $novo = ! $emitente->exists;

        // Linhas em branco da lista de códigos municipais (adicionadas na tela e não preenchidas) são ignoradas
        $request->merge(['codigos' => array_values(array_filter((array) $request->input('codigos', []), fn ($c) => is_array($c) && (filled($c['codigo'] ?? null) || filled($c['descricao'] ?? null) || filled($c['servico'] ?? null))))]);

        // O que é obrigatório muda com o regime e com a forma de informar o valor aproximado dos tributos
        $op = (int) $request->input('op_simples_nacional');
        $permitidos = Emitente::modosPermitidos($op);
        $modo = in_array($request->input('modo_tributos_aprox'), $permitidos, true) ? $request->input('modo_tributos_aprox') : $permitidos[0];
        $comPercentuais = in_array($modo, ['percentual', 'monetario'], true);
        $federal = $op === 1;
        $pisComAliquota = $federal && $request->input('pis_cofins_cst', '00') !== '00';

        $dados = $request->validate([
            'cnpj'                => ['required', fn ($a, $v, $f) => Documento::valido($v) && strlen(preg_replace('/\D/', '', $v)) === 14 ?: $f('CNPJ inválido.')],
            'razao_social'        => ['required', 'string', 'max:255'],
            'inscricao_municipal' => ['nullable', 'string', 'max:15'],
            'codigo_municipio'    => ['required', 'digits:7'],
            'enviar_email_automatico' => ['nullable', 'boolean'],
            'email_copia'         => ['nullable', 'email', 'max:255'],
            'codigo_tributacao_municipal' => ['nullable', 'required_unless:op_simples_nacional,2', 'digits:3'],
            'codigo_servico_padrao' => ['nullable', 'digits:6', fn ($a, $v, $f) => ListaServicoNacional::existe((string) $v) ?: $f('Esse código não existe na lista nacional de serviços.')],
            'descricao_servico_padrao' => ['nullable', 'string', 'max:2000'],
            'codigo_nbs_padrao'   => ['nullable', 'digits:9'],
            'op_simples_nacional' => ['required', 'in:1,2,3'],
            'regime_apuracao_sn'  => ['nullable', 'required_if:op_simples_nacional,3', 'in:1,2,3'],
            'regime_especial'     => ['required', 'in:0,1,2,3,4,5,6,9'],
            'modo_tributos_aprox' => ['nullable', Rule::in($permitidos)],
            'codigos'             => ['nullable', 'array', 'max:300'],
            'codigos.*.codigo'    => ['required', 'digits:3'],
            'codigos.*.descricao' => ['nullable', 'string', 'max:500'],
            'codigos.*.servico'   => ['nullable', 'digits:6', fn ($a, $v, $f) => ListaServicoNacional::existe((string) $v) ?: $f('Esse serviço não existe na lista nacional de serviços.')],
            'trib_issqn'          => ['nullable', 'in:1,2,4'],
            'tp_imunidade'        => [$request->input('trib_issqn') === '2' ? 'required' : 'nullable', 'in:1,2,3,4,5'],
            'retencao_issqn'      => ['nullable', 'in:1,2'],
            'deducao_tipo'        => ['nullable', 'in:none,percentual,valor'],
            'deducao_valor'       => ['nullable', in_array($request->input('deducao_tipo'), ['percentual', 'valor'], true) ? 'required' : 'nullable', 'numeric', 'gt:0', $request->input('deducao_tipo') === 'percentual' ? 'lte:100' : 'lt:999999999999'],
            'local_prestacao'     => ['nullable', 'in:emitente,tomador,outro'],
            'informar_serie_dps'  => ['nullable', 'boolean'],
            'municipio_prestacao' => ['nullable', 'string', 'max:120'],
            'uf_prestacao'        => ['nullable', 'string', 'size:2'],
            'aliquota_simples'    => ['nullable', $modo === 'simples' ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'tributos_aprox'           => ['nullable', 'array'],
            'tributos_aprox.federal'   => ['nullable', $comPercentuais ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'tributos_aprox.estadual'  => ['nullable', $comPercentuais ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'tributos_aprox.municipal' => ['nullable', $comPercentuais ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'pis_cofins_cst'      => [$federal ? 'required' : 'nullable', Rule::in(array_keys(TabelasDps::CST_PIS_COFINS))],
            // no padrão da empresa só entram os tipos de retenção sem CSLL retida (os demais pedem o valor em cada nota)
            'tp_ret_pis_cofins'   => [$federal ? 'required' : 'nullable', 'in:0,2'],
            'aliquota_pis'        => ['nullable', $pisComAliquota ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'aliquota_cofins'     => ['nullable', $pisComAliquota ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'serie_dps'           => [$request->boolean('informar_serie_dps') ? 'required' : 'nullable', 'regex:/^\d{1,5}$/', fn ($a, $v, $f) => ((int) $v >= 1 && (int) $v <= 49999) ?: $f('Série deve estar entre 00001 e 49999 (E0010).')],
            'ambiente'            => ['required', 'in:1,2'],
            'logo'                => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remover_logo'        => ['nullable', 'boolean'],
            'certificado'         => ['nullable', 'file', 'max:100'],   // por ora a empresa pode ser salva sem certificado
            'certificado_senha'   => ['required_with:certificado', 'nullable', 'string'],
        ]);

        $dados['cnpj'] = preg_replace('/\D/', '', $dados['cnpj']);

        // Cada CNPJ só pode ser cadastrado uma vez por conta (índice único no banco): avisa em vez de dar erro 500
        if (Emitente::where('cnpj', $dados['cnpj'])->when($emitente->exists, fn ($q) => $q->whereKeyNot($emitente->id))->exists()) {
            throw ValidationException::withMessages(['cnpj' => 'Já existe uma empresa com este CNPJ nesta conta. Abra-a em Emitentes para editar.']);
        }

        $dados['trib_issqn'] ??= 1;
        $dados['retencao_issqn'] ??= 1;

        // Códigos complementares municipais: sem repetição, e o código padrão da empresa sempre consta na lista
        $codigos = collect($dados['codigos'] ?? [])->map(fn ($c) => ['codigo' => $c['codigo'], 'descricao' => trim((string) ($c['descricao'] ?? '')) ?: null, 'codigo_servico' => $c['servico'] ?? null ?: null]);
        if ($codigos->pluck('codigo')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['codigos' => 'O código '.$codigos->pluck('codigo')->duplicates()->first().' aparece mais de uma vez na lista de códigos municipais.']);
        }
        if (filled($dados['codigo_tributacao_municipal'] ?? null) && ! $codigos->contains('codigo', $dados['codigo_tributacao_municipal'])) {
            $codigos->push(['codigo' => $dados['codigo_tributacao_municipal'], 'descricao' => null, 'codigo_servico' => null]);
        }

        // Tributação municipal padrão: as mesmas travas da SEFIN valem para a empresa (E0172, E0435, E0436, E0438, E0441, E0580, E0583, E0588)
        $trib = (int) $dados['trib_issqn'];
        $deducao = in_array($dados['deducao_tipo'] ?? null, ['percentual', 'valor'], true) ? $dados['deducao_tipo'] : null;
        $semRegimeEspecial = (int) $dados['regime_especial'] === 0;
        $erros = [];
        if ($trib !== 1 && ! $semRegimeEspecial) {
            $erros['trib_issqn'] = 'Com imunidade ou não incidência o regime especial de tributação precisa ser "Nenhum".';
        }
        if ($trib !== 1 && (int) $dados['retencao_issqn'] === 2) {
            $erros['retencao_issqn'] = 'Com imunidade ou não incidência não pode haver retenção do ISSQN.';
        }
        if ((int) $dados['retencao_issqn'] === 2 && ($op === 2 || ! $semRegimeEspecial)) {
            $erros['retencao_issqn'] = $op === 2 ? 'MEI não pode ter retenção do ISSQN pelo tomador.' : 'Com regime especial de tributação não pode haver retenção do ISSQN.';
        }
        if ($deducao && ($trib !== 1 || ! $semRegimeEspecial || ! ($op === 1 || ($op === 3 && (int) ($dados['regime_apuracao_sn'] ?? 0) === 2)))) {
            $erros['deducao_tipo'] = $op === 2 ? 'MEI não pode ter dedução/redução da base de cálculo.' : 'Dedução/redução só vale para operação tributável, sem regime especial, de empresa não optante ou ME/EPP com o ISSQN apurado pela NFS-e.';
        }
        $codigoPrestacao = null;
        $dados['local_prestacao'] = $dados['local_prestacao'] ?? 'emitente';
        if ($dados['local_prestacao'] === 'outro' && ! filled($dados['municipio_prestacao'] ?? null)) {
            $erros['municipio_prestacao'] = 'Informe a UF e o município da prestação, ou escolha "do emitente" ou "do destinatário".';
        }
        if ($dados['local_prestacao'] === 'outro' && (filled($dados['municipio_prestacao'] ?? null) || filled($dados['uf_prestacao'] ?? null))) {
            $codigoPrestacao = MunicipiosIbge::codigo($dados['municipio_prestacao'] ?? null, $dados['uf_prestacao'] ?? null);
            if (! $codigoPrestacao) {
                $erros['municipio_prestacao'] = 'Município da prestação não reconhecido: confira o nome e a UF.';
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
        $dados['tp_imunidade'] = $trib === 2 ? (int) $dados['tp_imunidade'] : null;
        $dados['deducao_tipo'] = $deducao;
        $dados['deducao_valor'] = $deducao ? $dados['deducao_valor'] : null;
        $dados['codigo_municipio_prestacao'] = $codigoPrestacao && $codigoPrestacao !== $dados['codigo_municipio'] ? $codigoPrestacao : null;
        // Só guarda o que vale para o regime escolhido (os campos escondidos na tela também são enviados, vazios)
        $dados['modo_tributos_aprox'] = $modo;
        if (! $comPercentuais) {
            $dados['tributos_aprox'] = null;
        }
        if ($modo !== 'simples') {
            $dados['aliquota_simples'] = null;
        }
        if ($op !== 3) {
            $dados['regime_apuracao_sn'] = null;
        }
        if (! $federal) {   // tributação federal só existe para o não optante
            $dados['pis_cofins_cst'] = '00';
            $dados['tp_ret_pis_cofins'] = 0;
        }
        if (! $pisComAliquota) {
            $dados['aliquota_pis'] = $dados['aliquota_cofins'] = null;
        }
        // Sem "informar série e número", vale a série padrão (00001) e a numeração é toda automática
        $dados['informar_serie_dps'] = $request->boolean('informar_serie_dps');
        $dados['serie_dps'] = str_pad($dados['informar_serie_dps'] ? $dados['serie_dps'] : '1', 5, '0', STR_PAD_LEFT);

        // Sem certificado não há como assinar nem emitir: produção só com certificado cadastrado
        if ((int) $dados['ambiente'] === 1 && ! $request->hasFile('certificado') && ! $emitente->certificado_arquivo) {
            throw ValidationException::withMessages(['ambiente' => 'Cadastre o certificado digital da empresa antes de ativar produção.']);
        }

        // Produção só depois de ter emitido em homologação (evita nota fiscal real por engano)
        if ((int) $dados['ambiente'] === 1 && ! \App\Models\NotaFiscal::semIsolamento()->where('emitente_id', $emitente->id)->where('status', 'emitida')->exists()) {
            throw ValidationException::withMessages(['ambiente' => 'Emita ao menos uma nota em produção restrita antes de ativar produção.']);
        }

        $pfx = $request->file('certificado')?->get();
        $cert = null;

        if ($pfx) {
            try {
                $cert = new Certificado($pfx, $dados['certificado_senha']);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['certificado' => $e->getMessage()]);
            }

            if ($cert->validoAte()?->isPast()) {
                throw ValidationException::withMessages(['certificado' => 'Certificado vencido (E1203).']);
            }
            if (($cnpjCert = $cert->cnpjProvavel()) && $cnpjCert !== $dados['cnpj']) {
                throw ValidationException::withMessages(['certificado' => "O certificado é do CNPJ {$cnpjCert}, não do emitente."]);
            }
        }

        // Logo: troca (envia outra) ou remove; o arquivo antigo é apagado só depois de salvar
        $logoAntiga = $emitente->logo_arquivo;
        $novaLogo = $request->file('logo')?->store('logos', 'public');
        if ($novaLogo) {
            $dados['logo_arquivo'] = $novaLogo;
        } elseif ($request->boolean('remover_logo')) {
            $dados['logo_arquivo'] = null;
        }

        // Campos fiscais que entram na trilha de auditoria (nunca a senha nem o arquivo do certificado)
        $campos = ['razao_social', 'cnpj', 'inscricao_municipal', 'codigo_municipio', 'codigo_servico_padrao', 'descricao_servico_padrao', 'codigo_nbs_padrao',
            'op_simples_nacional', 'regime_apuracao_sn', 'regime_especial', 'aliquota_simples', 'tributos_aprox', 'serie_dps', 'ambiente', 'enviar_email_automatico', 'email_copia', 'codigo_tributacao_municipal',
            'modo_tributos_aprox', 'pis_cofins_cst', 'tp_ret_pis_cofins', 'aliquota_pis', 'aliquota_cofins',
            'trib_issqn', 'tp_imunidade', 'retencao_issqn', 'deducao_tipo', 'deducao_valor', 'codigo_municipio_prestacao', 'local_prestacao', 'informar_serie_dps'];
        $antes = $novo ? [] : $emitente->only($campos);
        if (! $novo) {   // empresa antiga, sem escolha gravada: o padrão do regime conta como valor anterior (não é mudança)
            $antes['modo_tributos_aprox'] = $emitente->modoTributosAprox();
            $antes['pis_cofins_cst'] = $emitente->pis_cofins_cst ?: '00';
            $antes['tp_ret_pis_cofins'] = (int) $emitente->tp_ret_pis_cofins;
            $antes['trib_issqn'] = (int) ($emitente->trib_issqn ?: 1);
            $antes['retencao_issqn'] = (int) ($emitente->retencao_issqn ?: 1);
        }

        DB::transaction(function () use ($emitente, $dados, $pfx, $cert, $codigos) {
            $emitente->fill(collect($dados)->except(['certificado', 'certificado_senha', 'logo', 'remover_logo', 'codigos'])->all());
            $emitente->save();

            CodigoMunicipal::where('emitente_id', $emitente->id)->delete();
            foreach ($codigos as $c) {
                CodigoMunicipal::create($c + ['conta_id' => $emitente->conta_id, 'emitente_id' => $emitente->id]);
            }

            if ($pfx) {
                $emitente->update([
                    'certificado_arquivo'  => Certificado::guardar($emitente, $cert->pfx()),
                    'certificado_senha'    => $dados['certificado_senha'],
                    'certificado_validade' => $cert->validoAte(),
                ]);
            }
        });

        $mudancas = [];
        foreach ($campos as $campo) {
            $de = $antes[$campo] ?? null;
            $para = $emitente->{$campo};
            if (json_encode($de) !== json_encode($para) && ! (blank($de) && blank($para))) {
                $mudancas[$campo] = ['de' => $de, 'para' => $para];
            }
        }
        if ($pfx) {
            $mudancas['certificado'] = ['de' => $novo ? null : 'anterior', 'para' => 'novo (válido até '.$cert->validoAte()?->format('d/m/Y').')'];
        }
        if ($mudancas) {
            \App\Models\EmitenteAlteracao::create([
                'conta_id' => $emitente->conta_id, 'emitente_id' => $emitente->id, 'user_id' => $request->user()?->id,
                'origem' => $novo ? 'cadastro' : 'edicao', 'campos' => $mudancas,
            ]);
        }
        if ($novo || $mudancas) {
            \App\Models\Auditoria::registrar($novo ? 'empresa_criada' : 'empresa_alterada', ($novo ? 'Empresa cadastrada: ' : 'Empresa alterada: ').$emitente->razao_social.'.', $novo ? ['cnpj' => $emitente->cnpj, 'ambiente' => (int) $emitente->ambiente] : $mudancas);
        }

        if ($logoAntiga && ($novaLogo || $request->boolean('remover_logo'))) {
            Storage::disk('public')->delete($logoAntiga);
        }

        $resposta = $novo
            ? redirect()->route('inicio')->with('ok', 'Empresa cadastrada. Agora é só importar a sua primeira planilha.')
            : redirect()->route('emitentes.editar', $emitente)->with('confirmacao', 'Empresa atualizada com sucesso.')->with('confirmacao_destino', route('emitentes.index'));

        // Salvou sem certificado: tudo bem por ora, mas avisa em destaque que ainda falta
        if (! $emitente->fresh()->certificado_arquivo) {
            $resposta->with('aviso', 'Os dados da empresa foram salvos, mas falta o certificado digital A1. Sem ele não é possível emitir notas nem ativar produção. Envie o certificado em Emitentes > Editar.');
        }

        return $resposta;
    }
}
