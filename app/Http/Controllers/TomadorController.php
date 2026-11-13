<?php

namespace App\Http\Controllers;

use App\Services\Tomadores\ImportadorClientes;
use App\Support\Ordenacao;
use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Enums\StatusNota;
use App\Support\Documento;
use App\Support\MunicipiosIbge;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Cadastro de clientes (tomadores) da conta: consultar, editar e ver o histórico das alterações. */
class TomadorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('consultar');

        $busca = trim((string) $request->query('q', ''));
        $soPendentes = $request->boolean('pendentes');
        $tipo = in_array($request->query('tipo'), ['pf', 'pj'], true) ? $request->query('tipo') : '';

        $consulta = Tomador::query()->orderBy('nome')
            ->withCount(['notas as notas_emitidas' => fn ($q) => $q->whereIn('status', [StatusNota::Emitida->value, StatusNota::Cancelada->value])]);
        if ($busca !== '') {
            $digitos = preg_replace('/\D/', '', $busca);
            $consulta->where(function ($q) use ($busca, $digitos) {
                $q->where('nome', 'like', "%{$busca}%")->orWhere('nome_fantasia', 'like', "%{$busca}%")->orWhere('emails', 'like', "%{$busca}%");
                if ($digitos !== '') {
                    $q->orWhere('documento', 'like', "%{$digitos}%");
                }
            });
        }
        if ($soPendentes) {
            $consulta->where('pendente', true);
        }
        if ($tipo !== '') {
            $consulta->whereRaw('length(documento) = ?', [$tipo === 'pf' ? 11 : 14]);   // CPF = 11 números, CNPJ = 14
        }

        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, ['cliente' => 'nome', 'cidade' => 'municipio', 'emails' => 'emails', 'situacao' => 'pendente']);

        return view('tomadores.index', [
            'ordem' => $ordem, 'dir' => $dir,
            'tomadores'  => $consulta->paginate(25)->withQueryString(),
            'busca'      => $busca,
            'soPendentes' => $soPendentes,
            'tipo'       => $tipo,
            'totalPendentes' => Tomador::where('pendente', true)->count(),
        ]);
    }

    /** Planilha modelo para cadastrar clientes em lote. */
    public function modelo(Request $request)
    {
        $this->authorize('consultar');

        // A linha 1 do modelo traz o CNPJ e a razão social do emitente escolhido (ou o primeiro ativo)
        // Sempre a empresa que está sendo administrada (não dá para baixar o modelo de outra)
        $emitente = app(\App\Support\Tenancy\EmitenteAtual::class)->emitente();
        $livro = (new ImportadorClientes())->modelo($emitente);
        $arquivo = tempnam(sys_get_temp_dir(), 'cli').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($livro))->save($arquivo);

        return response()->download($arquivo, 'modelo-clientes.xlsx')->deleteFileAfterSend(true);
    }

    /** Tela de importação da planilha de tomadores (mesmo padrão da importação de notas). */
    public function telaImportar()
    {
        $this->authorize('emitir');

        return view('tomadores.importar', [
            'empresa'   => app(\App\Support\Tenancy\EmitenteAtual::class)->emitente(),
            'limite'    => ImportadorClientes::limite(),
            'temVideo'  => is_file(public_path('videos/tutorial-planilha-tomadores.mp4')),
        ]);
    }

    /** Etapa 1: recebe a planilha e vai para a prévia. Nada é gravado aqui. */
    public function importar(Request $request, ImportadorClientes $importador)
    {
        $this->authorize('emitir');
        $request->validate(['planilha' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']], [
            'planilha.required' => 'Escolha a planilha de clientes.',
            'planilha.mimes'    => 'Envie um arquivo .xlsx ou .csv (use a planilha modelo).',
            'planilha.max'      => 'O arquivo passou de 5 MB.',
        ]);

        $arquivo = $request->file('planilha');
        $token = \Illuminate\Support\Str::random(40);
        $caminho = \Illuminate\Support\Facades\Storage::disk('local')->putFileAs('importacoes-clientes', $arquivo, $token.'.'.strtolower($arquivo->getClientOriginalExtension() ?: 'xlsx'));

        try {
            $importador->analisar(\Illuminate\Support\Facades\Storage::disk('local')->path($caminho));   // só para barrar arquivo ilegível ou grande demais
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($caminho);

            return back()->withErrors(['planilha' => $e->getMessage()]);
        }

        \Illuminate\Support\Facades\Cache::put("importacao-clientes:{$token}", [
            'caminho' => $caminho, 'arquivo' => $arquivo->getClientOriginalName(),
            'conta' => app(\App\Support\Tenancy\ContaAtual::class)->id(), 'emitente' => app(\App\Support\Tenancy\EmitenteAtual::class)->id(), 'user' => $request->user()?->id,
        ], now()->addHours(2));

        return redirect()->route('tomadores.importar.conferir', $token);
    }

    /** Dados da importação em andamento, só para quem a iniciou (mesma conta, empresa e usuário). */
    private function importacaoEmAndamento(Request $request, string $token): array
    {
        $dados = \Illuminate\Support\Facades\Cache::get("importacao-clientes:{$token}");
        abort_unless(
            $dados && $dados['conta'] === app(\App\Support\Tenancy\ContaAtual::class)->id()
                && $dados['emitente'] === app(\App\Support\Tenancy\EmitenteAtual::class)->id() && $dados['user'] === $request->user()?->id
                && \Illuminate\Support\Facades\Storage::disk('local')->exists($dados['caminho']),
            404
        );

        return $dados;
    }

    /** Etapa 2: prévia completa. Mostra tudo o que a planilha trouxe, o que vai acontecer em cada linha e o que está errado. */
    public function conferirImportacao(Request $request, string $token, ImportadorClientes $importador)
    {
        $this->authorize('emitir');
        $dados = $this->importacaoEmAndamento($request, $token);
        $linhas = collect($importador->analisar(\Illuminate\Support\Facades\Storage::disk('local')->path($dados['caminho'])));

        return view('tomadores.importar-conferir', [
            'token'   => $token,
            'arquivo' => $dados['arquivo'],
            'empresa' => app(\App\Support\Tenancy\EmitenteAtual::class)->emitente(),
            'linhas'  => $linhas,
            'total'   => $linhas->count(),
            'validas' => $linhas->filter(fn ($l) => ! $l['erros'])->count(),
            'comErro' => $linhas->filter(fn ($l) => $l['erros'] && ! $l['bloqueada'])->count(),     // dá para importar com o campo errado de fora
            'bloqueadas' => $linhas->filter(fn ($l) => $l['bloqueada'])->count(),                 // não entram de jeito nenhum
            'novos'      => $linhas->where('acao', 'novo')->count(),
            'atualizar'  => $linhas->where('acao', 'atualizar')->count(),
            'colunas'    => ImportadorClientes::COLUNAS,
        ]);
    }

    /** Etapa 3: grava, só as válidas ou também as com erro, conforme a escolha. */
    public function confirmarImportacao(Request $request, string $token, ImportadorClientes $importador)
    {
        $this->authorize('emitir');
        $dados = $this->importacaoEmAndamento($request, $token);
        $modo = $request->validate(['modo' => ['required', 'in:validos,todos']], ['modo.required' => 'Escolha o que importar.'])['modo'];

        try {
            $r = $importador->importar(\Illuminate\Support\Facades\Storage::disk('local')->path($dados['caminho']), $request->user()?->id, $modo);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);   // erro de banco nunca vai para a tela

            return back()->withErrors(['planilha' => 'Não foi possível importar esta planilha agora. Confira os dados e tente de novo; se continuar, avise o suporte.']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['planilha' => $e->getMessage()]);
        }

        \Illuminate\Support\Facades\Storage::disk('local')->delete($dados['caminho']);
        \Illuminate\Support\Facades\Cache::forget("importacao-clientes:{$token}");

        \App\Models\Auditoria::registrar('clientes_importados', "Planilha de clientes {$dados['arquivo']} importada ({$modo}).", [
            'modo' => $modo, 'criados' => $r['criados'], 'atualizados' => $r['atualizados'], 'sem_mudanca' => $r['sem_mudanca'], 'com_erro' => count($r['erros']),
        ]);

        return redirect()->route('tomadores.index')->with('importacao_clientes', $r + ['arquivo' => $dados['arquivo']]);
    }

    /** Desiste da importação: apaga o arquivo guardado. */
    public function cancelarImportacao(Request $request, string $token)
    {
        $this->authorize('emitir');
        $dados = \Illuminate\Support\Facades\Cache::pull("importacao-clientes:{$token}");
        if ($dados && ($dados['user'] ?? null) === $request->user()?->id) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($dados['caminho']);
        }

        return redirect()->route('tomadores.index');
    }

    /** Tela de cadastro de um cliente novo. */
    public function criar()
    {
        $this->authorize('emitir');

        return view('tomadores.criar', ['tomador' => new Tomador]);
    }

    public function store(Request $request)
    {
        $this->authorize('emitir');

        $documento = preg_replace('/\D/', '', (string) $request->input('documento'));
        $request->merge(['documento' => $documento]);
        $request->validate([
            'documento' => ['required', 'regex:/^(\d{11}|\d{14})$/', function ($campo, $valor, $falhar) {
                if (! Documento::valido($valor)) {
                    $falhar('CPF ou CNPJ inválido: confira os números (a prefeitura recusa dígito verificador errado).');
                }
            }],
        ], [
            'documento.required' => 'Informe o CPF ou CNPJ do cliente.',
            'documento.regex'    => 'O CPF tem 11 números e o CNPJ tem 14.',
        ]);

        if ($existente = Tomador::where('documento', $documento)->first()) {
            return back()->withInput()->withErrors(['documento' => "Este documento já está cadastrado como “{$existente->nome}”. Abra o cadastro dele para alterar."]);
        }

        $dados = $this->camposDoFormulario($request);
        $dados['codigo_municipio'] = $dados['codigo_municipio'] ?? Tomador::descobrirCodigoMunicipio($dados['municipio'] ?? null, $dados['uf'] ?? null, $dados['cep'] ?? null);

        $tomador = new Tomador($dados + ['documento' => $documento]);
        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        \App\Models\Auditoria::registrar('cliente_cadastrado', "Cliente {$tomador->nome} cadastrado pela tela.", ['tomador' => $tomador->id]);

        $aviso = $tomador->pendente ? ' O endereço está incompleto: as notas saem sem o endereço dele até você completar.' : '';

        return redirect()->route('tomadores.index')->with('ok', "Cliente “{$tomador->nome}” cadastrado.".$aviso)
            ->with('confirmacao', "Cadastro de “{$tomador->nome}” criado com sucesso.".$aviso);
    }

    /** Valida e limpa os campos do formulário de cliente (cadastro e edição): texto aparado, CEP só números, UF maiúscula, e-mails em lista. */
    private function camposDoFormulario(Request $request): array
    {
        $dados = $request->validate([
            'nome'          => ['required', 'string', 'max:300'],
            'nome_fantasia' => ['nullable', 'string', 'max:300'],
            'logradouro'    => ['required', 'string', 'max:255'],
            'numero'        => ['required', 'string', 'max:60'],
            'complemento'   => ['nullable', 'string', 'max:156'],
            'bairro'        => ['required', 'string', 'max:156'],
            'municipio'     => ['required', 'string', 'max:120'],
            'uf'            => ['required', 'string', 'size:2'],
            'cep'           => ['required', 'string', 'max:12', fn ($a, $v, $f) => strlen(preg_replace('/\D/', '', (string) $v)) === 8 ?: $f('O CEP tem 8 números (ex.: 01310-100).')],
            'ativo'         => ['nullable', 'boolean'],
            'codigo_municipio' => ['nullable', 'digits:7'],
            'emails'        => ['nullable', 'string', 'max:600'],
            'observacoes'   => ['nullable', 'string', 'max:2000'],
        ], [
            'nome.required' => 'Falta o nome ou razão social do cliente.',
            'uf.size'       => 'A UF tem 2 letras (ex.: SP).',
            'cep.required'        => 'Informe o CEP do cliente.',
            'logradouro.required' => 'Informe o logradouro (rua, avenida…).',
            'numero.required'     => 'Informe o número do endereço (use S/N se não tiver).',
            'bairro.required'     => 'Informe o bairro.',
            'municipio.required'  => 'Informe o município.',
            'uf.required'         => 'Informe a UF.',
        ]);

        $emails = collect(preg_split('/[;,\s]+/', (string) ($dados['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($e) => strtolower(trim($e)))->unique()->values();
        $invalidos = $emails->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($invalidos->isNotEmpty()) {
            throw ValidationException::withMessages(['emails' => 'E-mail inválido: '.$invalidos->implode(', ').'.']);
        }

        $novos = collect($dados)->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)->all();
        $novos['nome'] = $dados['nome'];
        $novos['uf'] = isset($novos['uf']) ? strtoupper($novos['uf']) : null;
        $novos['cep'] = isset($novos['cep']) ? (preg_replace('/\D/', '', $novos['cep']) ?: null) : null;
        $novos['emails'] = $emails->all() ?: null;
        $novos['ativo'] = $request->boolean('ativo', true);

        return $novos;
    }

    public function editar(Tomador $tomador)
    {
        $this->authorize('consultar');
        $tomador->resolverMunicipio();

        return view('tomadores.editar', [
            'tomador'    => $tomador,
            'alteracoes' => $tomador->alteracoes()->with('user')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, Tomador $tomador)
    {
        $this->authorize('emitir');

        $novos = $this->camposDoFormulario($request);

        // Mudou município, UF ou CEP, ou o cadastro ainda está sem código IBGE: procura de novo (nome + UF; se não casar, pelo CEP)
        if (blank($novos['codigo_municipio'] ?? null)) {
            unset($novos['codigo_municipio']);   // sem código vindo da tela: mantém o do cadastro
        }
        if (isset($novos['codigo_municipio'])) {
            // código escolhido na tela (tabela do IBGE): vale como está
        } elseif (($novos['municipio'] ?? null) !== $tomador->municipio || ($novos['uf'] ?? null) !== $tomador->uf || ($novos['cep'] ?? null) !== $tomador->cep || blank($tomador->codigo_municipio)) {
            $novos['codigo_municipio'] = Tomador::descobrirCodigoMunicipio($novos['municipio'] ?? null, $novos['uf'] ?? null, $novos['cep'] ?? null);
        }

        $historico = [];
        foreach ($novos as $campo => $valor) {
            $antes = $tomador->{$campo};
            if ($antes !== $valor && ! (blank($antes) && blank($valor))) {
                $historico[$campo] = $campo === 'ativo' ? [$antes ? 'Ativo' : 'Inativo', $valor ? 'Ativo' : 'Inativo'] : [$antes, $valor];
            }
        }

        $tomador->fill($novos);
        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        if ($historico) {
            TomadorAlteracao::create([
                'conta_id' => $tomador->conta_id, 'tomador_id' => $tomador->id, 'user_id' => $request->user()?->id,
                'origem' => 'manual', 'campos' => $historico,
            ]);
        }

        // Fica na tela do tomador com o pop-up de confirmação; o OK leva de volta para a lista
        return redirect()->route('tomadores.editar', $tomador)
            ->with('confirmacao', "Cadastro de “{$tomador->nome}” atualizado com sucesso.")
            ->with('confirmacao_destino', route('tomadores.index'));
    }

    /** Liga/desliga o cadastro. Tomador não se exclui: inativo some das buscas na emissão, mas o histórico e as notas ficam. */
    public function situacao(Request $request, Tomador $tomador)
    {
        $this->authorize('emitir');

        $ativar = ! $tomador->ativo;
        $tomador->forceFill(['ativo' => $ativar])->save();

        TomadorAlteracao::create([
            'conta_id' => $tomador->conta_id, 'tomador_id' => $tomador->id, 'user_id' => $request->user()?->id,
            'origem' => 'manual', 'campos' => ['ativo' => [$ativar ? 'Inativo' : 'Ativo', $ativar ? 'Ativo' : 'Inativo']],
        ]);
        \App\Models\Auditoria::registrar($ativar ? 'cliente_ativado' : 'cliente_inativado', "Cadastro de {$tomador->nome} ".($ativar ? 'ativado' : 'inativado').'.', ['tomador' => $tomador->id]);

        return back()->with('ok', "Cadastro de “{$tomador->nome}” ".($ativar ? 'ativado.' : 'inativado.'));
    }
}
