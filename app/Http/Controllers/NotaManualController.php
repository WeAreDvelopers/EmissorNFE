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
use App\Support\Tenancy\ContaAtual;
use App\Support\ValidadorLinha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Emissão manual em dois passos: preencher (com os padrões da empresa) → conferir e confirmar → emitir.
 * A nota só é criada no segundo passo, depois do "confirmo".
 */
class NotaManualController extends Controller
{
    /** Campos que viajam do formulário para a conferência e de volta. */
    private const CAMPOS_FORM = [
        'emitente_id', 'sem_tomador', 'tomador_documento', 'tomador_nome', 'tomador_email',
        'usar_padrao', 'codigo_servico', 'descricao', 'codigo_nbs', 'valor', 'data_competencia',
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
        if ($validador->fails()) {
            return redirect()->route('notas.manual')->withInput($request->only(self::CAMPOS_FORM))->withErrors($validador);
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
            Tomador::where(function ($q) use ($busca, $digitos) {
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
        if ($validador->fails()) {
            return $volta()->withErrors($validador);
        }

        $nota = null;
        try {
            DB::transaction(function () use (&$nota, $dados, $emitente, $detector, $request) {
                $tomadorId = $dados['sem_tomador'] ? null : $this->cliente($dados)->id;

                $nota = NotaFiscal::create([
                    ...ValidadorLinha::paraGravar($dados),
                    'conta_id'    => $emitente->conta_id,
                    'emitente_id' => $emitente->id,
                    'tomador_id'  => $tomadorId,
                    'origem'      => 'web',
                    'serie_dps'   => $emitente->serie_dps,
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
