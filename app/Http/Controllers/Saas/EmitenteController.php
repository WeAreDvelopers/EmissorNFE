<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\Emitente;
use App\Services\Nfse\Certificado;
use App\Services\Nfse\NumeracaoDps;
use App\Support\Documento;
use App\Support\ListaServicoNacional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmitenteController extends Controller
{
    public function index()
    {
        $this->authorize('consultar');

        return view('emitentes.index', ['emitentes' => Emitente::orderBy('razao_social')->get()]);
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

        $dados = $request->validate([
            'cnpj'                => ['required', fn ($a, $v, $f) => Documento::valido($v) && strlen(preg_replace('/\D/', '', $v)) === 14 ?: $f('CNPJ inválido.')],
            'razao_social'        => ['required', 'string', 'max:255'],
            'inscricao_municipal' => ['nullable', 'string', 'max:15'],
            'codigo_municipio'    => ['required', 'digits:7'],
            'enviar_email_automatico' => ['nullable', 'boolean'],
            'codigo_servico_padrao' => ['nullable', 'digits:6', fn ($a, $v, $f) => ListaServicoNacional::existe((string) $v) ?: $f('Esse código não existe na lista nacional de serviços.')],
            'descricao_servico_padrao' => ['nullable', 'string', 'max:2000'],
            'codigo_nbs_padrao'   => ['nullable', 'digits:9'],
            'op_simples_nacional' => ['required', 'in:1,2,3'],
            'regime_apuracao_sn'  => ['nullable', 'required_if:op_simples_nacional,3', 'in:1,2,3'],
            'regime_especial'     => ['required', 'in:0,1,2,3,4,5,6,9'],
            'aliquota_simples'    => ['nullable', 'required_if:op_simples_nacional,3', 'numeric', 'between:0,100'],
            'tributos_aprox'           => ['nullable', 'array'],
            'tributos_aprox.federal'   => ['nullable', 'required_if:op_simples_nacional,1', 'numeric', 'between:0,100'],
            'tributos_aprox.estadual'  => ['nullable', 'required_if:op_simples_nacional,1', 'numeric', 'between:0,100'],
            'tributos_aprox.municipal' => ['nullable', 'required_if:op_simples_nacional,1', 'numeric', 'between:0,100'],
            'serie_dps'           => ['required', 'regex:/^\d{1,5}$/', fn ($a, $v, $f) => ((int) $v >= 1 && (int) $v <= 49999) ?: $f('Série deve estar entre 00001 e 49999 (E0010).')],
            'ambiente'            => ['required', 'in:1,2'],
            'logo'                => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remover_logo'        => ['nullable', 'boolean'],
            'certificado'         => [$novo ? 'required' : 'nullable', 'file', 'max:100'],
            'certificado_senha'   => ['required_with:certificado', 'nullable', 'string'],
        ]);

        $dados['cnpj'] = preg_replace('/\D/', '', $dados['cnpj']);
        // Só guarda o que vale para o regime escolhido (os campos escondidos na tela também são enviados, vazios)
        if ((int) $dados['op_simples_nacional'] !== 1) {
            $dados['tributos_aprox'] = null;
        }
        if ((int) $dados['op_simples_nacional'] !== 3) {
            $dados['aliquota_simples'] = null;
            $dados['regime_apuracao_sn'] = null;
        }
        $dados['serie_dps'] = str_pad($dados['serie_dps'], 5, '0', STR_PAD_LEFT);

        // Produção só depois de ter emitido em homologação (evita nota fiscal real por engano)
        if ((int) $dados['ambiente'] === 1 && ! $emitente->notas()->where('status', 'emitida')->exists()) {
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
            'op_simples_nacional', 'regime_apuracao_sn', 'regime_especial', 'aliquota_simples', 'tributos_aprox', 'serie_dps', 'ambiente', 'enviar_email_automatico'];
        $antes = $novo ? [] : $emitente->only($campos);

        DB::transaction(function () use ($emitente, $dados, $pfx, $cert) {
            $emitente->fill(collect($dados)->except(['certificado', 'certificado_senha', 'logo', 'remover_logo'])->all());
            $emitente->save();

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
        if ($novo || $mudancas) {
            \App\Models\Auditoria::registrar($novo ? 'empresa_criada' : 'empresa_alterada', ($novo ? 'Empresa cadastrada: ' : 'Empresa alterada: ').$emitente->razao_social.'.', $novo ? ['cnpj' => $emitente->cnpj, 'ambiente' => (int) $emitente->ambiente] : $mudancas);
        }

        if ($logoAntiga && ($novaLogo || $request->boolean('remover_logo'))) {
            Storage::disk('public')->delete($logoAntiga);
        }

        return $novo
            ? redirect()->route('inicio')->with('ok', 'Empresa cadastrada. Agora é só importar a sua primeira planilha.')
            : redirect()->route('emitentes.index')->with('ok', 'Empresa atualizada.');
    }
}
