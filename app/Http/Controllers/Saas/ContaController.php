<?php

namespace App\Http\Controllers\Saas;

use App\Enums\Papel;
use App\Enums\TipoMovimento;
use App\Http\Controllers\Controller;
use App\Models\Conta;
use App\Models\Revenda;
use App\Services\Creditos\CarteiraService;
use App\Support\Documento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContaController extends Controller
{
    public function criar()
    {
        return view('contas.criar');
    }

    /** Autoatendimento: cria a conta na revenda do domínio, o usuário vira Dono, ganha créditos de teste. */
    public function store(Request $request, CarteiraService $creditos)
    {
        $dados = $request->validate([
            'nome'             => ['required', 'string', 'max:150'],
            'documento'        => ['required', 'string', fn ($a, $v, $falha) => Documento::valido($v) ?: $falha('CPF/CNPJ inválido.')],
            'email_financeiro' => ['required', 'email'],
        ]);

        $conta = DB::transaction(function () use ($dados, $request, $creditos) {
            $conta = Conta::create([
                ...$dados,
                'documento'  => preg_replace('/\D/', '', $dados['documento']),
                'revenda_id' => app(Revenda::class)->id,   // definida pelo domínio (IdentificarRevenda)
            ]);

            $conta->usuarios()->attach($request->user()->id, ['papel' => Papel::Dono->value]);

            if ($bonus = (int) config('saas.bonus_cadastro')) {
                $creditos->creditar($conta->id, $bonus, TipoMovimento::Bonus, validadeDias: 30, descricao: 'Créditos de teste', userId: $request->user()->id);
            } else {
                $creditos->saldo($conta->id);
            }

            return $conta;
        });

        $request->user()->trocarConta($conta->id);

        return redirect()->route('emitentes.criar')->with('ok', 'Conta criada. Agora cadastre o CNPJ que vai emitir as notas.');
    }

    /** Troca de conta no topo da tela (contador com várias empresas). */
    public function trocar(Request $request, int $conta)
    {
        $request->user()->trocarConta($conta);

        return redirect()->route('importacoes.index');
    }
}
