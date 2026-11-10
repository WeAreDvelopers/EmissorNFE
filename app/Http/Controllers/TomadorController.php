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

        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, ['cliente' => 'nome', 'cidade' => 'municipio', 'emails' => 'emails', 'situacao' => 'pendente']);

        return view('tomadores.index', [
            'ordem' => $ordem, 'dir' => $dir,
            'tomadores'  => $consulta->paginate(25)->withQueryString(),
            'busca'      => $busca,
            'soPendentes' => $soPendentes,
            'totalPendentes' => Tomador::where('pendente', true)->count(),
        ]);
    }

    /** Planilha modelo para cadastrar clientes em lote. */
    public function modelo()
    {
        $this->authorize('consultar');

        $livro = (new ImportadorClientes())->modelo();
        $arquivo = tempnam(sys_get_temp_dir(), 'cli').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($livro))->save($arquivo);

        return response()->download($arquivo, 'modelo-clientes.xlsx')->deleteFileAfterSend(true);
    }

    /** Importa a planilha de clientes: cria os novos e atualiza só o que veio preenchido nos já cadastrados. */
    public function importar(Request $request, ImportadorClientes $importador)
    {
        $this->authorize('emitir');
        $request->validate(['planilha' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']], [
            'planilha.required' => 'Escolha a planilha de clientes.',
            'planilha.mimes'    => 'Envie um arquivo .xlsx ou .csv (use a planilha modelo).',
            'planilha.max'      => 'O arquivo passou de 5 MB.',
        ]);

        $arquivo = $request->file('planilha');
        try {
            $r = $importador->importar($arquivo->getRealPath(), $request->user()?->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['planilha' => $e->getMessage()]);
        }

        \App\Models\Auditoria::registrar('clientes_importados', "Planilha de clientes {$arquivo->getClientOriginalName()} importada.", [
            'criados' => $r['criados'], 'atualizados' => $r['atualizados'], 'sem_mudanca' => $r['sem_mudanca'], 'com_erro' => count($r['erros']),
        ]);

        return redirect()->route('tomadores.index')->with('importacao_clientes', $r + ['arquivo' => $arquivo->getClientOriginalName()]);
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
        $dados['codigo_municipio'] = MunicipiosIbge::codigo($dados['municipio'] ?? null, $dados['uf'] ?? null);

        $tomador = new Tomador($dados + ['documento' => $documento]);
        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        \App\Models\Auditoria::registrar('cliente_cadastrado', "Cliente {$tomador->nome} cadastrado pela tela.", ['tomador' => $tomador->id]);

        $aviso = $tomador->pendente ? ' O endereço está incompleto: as notas saem sem o endereço dele até você completar.' : '';

        return redirect()->route('tomadores.index')->with('ok', "Cliente “{$tomador->nome}” cadastrado.".$aviso);
    }

    /** Valida e limpa os campos do formulário de cliente (cadastro e edição): texto aparado, CEP só números, UF maiúscula, e-mails em lista. */
    private function camposDoFormulario(Request $request): array
    {
        $dados = $request->validate([
            'nome'          => ['required', 'string', 'max:300'],
            'nome_fantasia' => ['nullable', 'string', 'max:300'],
            'logradouro'    => ['nullable', 'string', 'max:255'],
            'numero'        => ['nullable', 'string', 'max:60'],
            'complemento'   => ['nullable', 'string', 'max:156'],
            'bairro'        => ['nullable', 'string', 'max:156'],
            'municipio'     => ['nullable', 'string', 'max:120'],
            'uf'            => ['nullable', 'string', 'size:2'],
            'cep'           => ['nullable', 'string', 'max:12'],
            'emails'        => ['nullable', 'string', 'max:600'],
            'observacoes'   => ['nullable', 'string', 'max:2000'],
        ], [
            'nome.required' => 'Falta o nome ou razão social do cliente.',
            'uf.size'       => 'A UF tem 2 letras (ex.: SP).',
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

        // Mudou município ou UF: procura o código IBGE de novo
        if (($novos['municipio'] ?? null) !== $tomador->municipio || ($novos['uf'] ?? null) !== $tomador->uf) {
            $novos['codigo_municipio'] = MunicipiosIbge::codigo($novos['municipio'] ?? null, $novos['uf'] ?? null);
        }

        $historico = [];
        foreach ($novos as $campo => $valor) {
            $antes = $tomador->{$campo};
            if ($antes !== $valor && ! (blank($antes) && blank($valor))) {
                $historico[$campo] = [$antes, $valor];
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

        return redirect()->route('tomadores.index')->with('ok', "Cliente “{$tomador->nome}” atualizado.");
    }

    /** Exclui o cadastro. Só quando não há nota emitida (nem cancelada depois) para o cliente; notas em andamento ou com erro ficam com o nome e o documento que já guardam. */
    public function excluir(Request $request, Tomador $tomador)
    {
        $this->authorize('emitir');

        $emitidas = $tomador->notasEmitidas();
        if ($emitidas > 0) {
            $msg = 'Não dá para excluir “'.$tomador->nome.'”: há '.$emitidas.($emitidas === 1 ? ' nota emitida' : ' notas emitidas').' para este cliente.';

            return $request->expectsJson() ? response()->json(['message' => $msg], 422) : back()->withErrors(['cliente' => $msg]);
        }

        \App\Models\Auditoria::registrar('cliente_excluido', "Cliente {$tomador->nome} excluído.", ['documento' => $tomador->documento]);
        $tomador->delete();

        $msg = "Cliente “{$tomador->nome}” excluído.";

        return $request->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : redirect()->route('tomadores.index')->with('ok', $msg);
    }
}
