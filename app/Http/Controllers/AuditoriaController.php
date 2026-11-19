<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\User;
use App\Support\Tenancy\EmitenteAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Trilha de auditoria da conta (somente o dono), da empresa selecionada no topo + registros gerais da conta (usuários). */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('gerenciar-conta');

        $emitente = app(EmitenteAtual::class)->emitente();

        $consulta = Auditoria::with('user')->latest('id');

        // Só a empresa em uso (ou, sem empresa selecionada, tudo); ações da conta toda (usuários) valem para qualquer empresa
        // e, dela, só o ambiente em uso (teste e produção não se misturam)
        if ($emitente) {
            $consulta->where(fn ($q) => $q->where(fn ($e) => $e->where('emitente_id', $emitente->id)->where('ambiente', (int) $emitente->ambiente))
                ->orWhere(fn ($g) => $g->whereNull('emitente_id')->whereIn('acao', Auditoria::ACOES_DA_CONTA)));
        }
        if (array_key_exists((string) $request->query('acao'), Auditoria::ACOES)) {
            $consulta->where('acao', $request->query('acao'));
        }
        if (filled($q = trim((string) $request->query('q')))) {
            $consulta->where('descricao', 'like', "%{$q}%");
        }

        // Usuário ("sistema" = ações automáticas, sem usuário)
        $usuario = (string) $request->query('usuario');
        if ($usuario === 'sistema') {
            $consulta->whereNull('user_id');
        } elseif (ctype_digit($usuario)) {
            $consulta->where('user_id', (int) $usuario);
        }

        // Período, no horário de Brasília (as datas são inclusivas)
        $de = $this->data($request->query('de'));
        $ate = $this->data($request->query('ate'));
        if ($de) {
            $consulta->where('created_at', '>=', $de->copy()->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($ate) {
            $consulta->where('created_at', '<=', $ate->copy()->endOfDay()->setTimezone(config('app.timezone')));
        }

        $usuarios = User::whereIn('id', Auditoria::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']);

        return view('auditoria.index', [
            'registros' => $consulta->paginate(40)->withQueryString(),
            'acoes'     => Auditoria::ACOES,
            'usuarios'  => $usuarios,
            'emitente'  => $emitente,
            'acao'      => $request->query('acao'),
            'busca'     => $request->query('q'),
            'usuario'   => $usuario,
            'de'        => $de?->toDateString(),
            'ate'       => $ate?->toDateString(),
        ]);
    }

    private function data(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $valor, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }
    }
}
