<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use Illuminate\Http\Request;

/** Trilha de auditoria da conta (somente o dono). */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('gerenciar-conta');

        $consulta = Auditoria::with('user')->latest('id');
        if (array_key_exists((string) $request->query('acao'), Auditoria::ACOES)) {
            $consulta->where('acao', $request->query('acao'));
        }
        if (filled($q = trim((string) $request->query('q')))) {
            $consulta->where('descricao', 'like', "%{$q}%");
        }

        return view('auditoria.index', [
            'registros' => $consulta->paginate(40)->withQueryString(),
            'acoes'     => Auditoria::ACOES,
            'acao'      => $request->query('acao'),
            'busca'     => $request->query('q'),
        ]);
    }
}
