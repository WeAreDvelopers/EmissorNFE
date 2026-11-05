<?php

namespace App\Http\Controllers\Saas;

use App\Enums\Papel;
use App\Http\Controllers\Controller;
use App\Mail\ConviteConta;
use App\Models\Convite;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function __construct(private ContaAtual $contaAtual) {}

    public function index()
    {
        $this->authorize('gerenciar-usuarios');

        return view('usuarios.index', [
            'usuarios' => $this->contaAtual->conta()->usuarios()->orderBy('name')->get(),
            'convites' => Convite::whereNull('aceito_em')->where('expira_em', '>', now())->get(),
        ]);
    }

    public function convidar(Request $request)
    {
        $this->authorize('gerenciar-usuarios');

        $dados = $request->validate([
            'email' => ['required', 'email'],
            'papel' => ['required', Rule::enum(Papel::class)],
        ]);

        $convite = Convite::create([
            ...$dados,
            'convidado_por' => $request->user()->id,
            'token'         => Str::random(64),
            'expira_em'     => now()->addDays(7),
        ]);

        Mail::to($convite->email)->queue(new ConviteConta($convite));

        return back()->with('ok', "Convite enviado para {$convite->email}.");
    }

    /** Link do e-mail. Exige login (ou cadastro) com o MESMO e-mail convidado. */
    public function aceitar(Request $request, string $token)
    {
        $convite = Convite::semIsolamento()->where('token', $token)->firstOrFail();

        abort_unless($convite->valido(), 410, 'Convite expirado ou já usado.');
        abort_unless(strcasecmp($request->user()->email, $convite->email) === 0, 403, 'Este convite é para outro e-mail.');

        DB::transaction(function () use ($convite, $request) {
            $request->user()->contas()->syncWithoutDetaching([$convite->conta_id => ['papel' => $convite->papel->value]]);
            $convite->update(['aceito_em' => now()]);
        });

        $request->user()->trocarConta($convite->conta_id);

        return redirect()->route('importacoes.index')->with('ok', 'Você entrou na conta.');
    }

    public function alterarPapel(Request $request, int $user)
    {
        $this->authorize('gerenciar-usuarios');
        $papel = Papel::from($request->validate(['papel' => ['required', Rule::enum(Papel::class)]])['papel']);

        $conta = $this->contaAtual->conta();
        $this->garantirOutroDono($conta, $user, $papel);
        $conta->usuarios()->updateExistingPivot($user, ['papel' => $papel->value]);

        return back()->with('ok', 'Papel atualizado.');
    }

    public function remover(int $user)
    {
        $this->authorize('gerenciar-usuarios');

        $conta = $this->contaAtual->conta();
        $this->garantirOutroDono($conta, $user, null);
        $conta->usuarios()->detach($user);

        return back()->with('ok', 'Usuário removido da conta.');
    }

    /** A conta nunca pode ficar sem Dono. */
    private function garantirOutroDono($conta, int $userId, ?Papel $novoPapel): void
    {
        $ehDono = $conta->donos()->whereKey($userId)->exists();
        if ($ehDono && $novoPapel !== Papel::Dono) {
            abort_if($conta->donos()->count() <= 1, 422, 'A conta precisa de pelo menos um Dono.');
        }
    }
}
