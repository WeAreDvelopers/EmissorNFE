<?php

namespace App\Http\Controllers;

use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Support\MunicipiosIbge;
use Illuminate\Http\Request;

/** Cadastro de clientes (tomadores) da conta: consultar, editar e ver o histórico das alterações. */
class TomadorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('consultar');

        $busca = trim((string) $request->query('q', ''));
        $soPendentes = $request->boolean('pendentes');

        $consulta = Tomador::query()->orderBy('nome');
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

        return view('tomadores.index', [
            'tomadores'  => $consulta->paginate(25)->withQueryString(),
            'busca'      => $busca,
            'soPendentes' => $soPendentes,
            'totalPendentes' => Tomador::where('pendente', true)->count(),
        ]);
    }

    public function editar(Tomador $tomador)
    {
        $this->authorize('consultar');

        return view('tomadores.editar', [
            'tomador'    => $tomador,
            'alteracoes' => $tomador->alteracoes()->with('user')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, Tomador $tomador)
    {
        $this->authorize('emitir');

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
            return back()->withInput()->withErrors(['emails' => 'E-mail inválido: '.$invalidos->implode(', ').'.']);
        }

        $novos = collect($dados)->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)->all();
        $novos['nome'] = $dados['nome'];
        $novos['uf'] = isset($novos['uf']) ? strtoupper($novos['uf']) : null;
        $novos['cep'] = isset($novos['cep']) ? (preg_replace('/\D/', '', $novos['cep']) ?: null) : null;
        $novos['emails'] = $emails->all() ?: null;

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
}
