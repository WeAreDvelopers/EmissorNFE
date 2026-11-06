<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Models\Auditoria;
use App\Models\NotaFiscal;
use App\Services\Nfse\CancelamentoNfse;
use App\Services\Nfse\RejeicaoSefinException;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Cancelamento de NFS-e emitida. Ato fiscal sem volta: só o dono, com motivo e confirmação explícita. */
class NotaCancelamentoController extends Controller
{
    public function form(NotaFiscal $nota)
    {
        $this->authorize('cancelar-nota');
        abort_unless($nota->status === StatusNota::Emitida, 404);

        return view('notas.cancelar', ['nota' => $nota->load('emitente'), 'motivos' => CancelamentoNfse::MOTIVOS]);
    }

    public function store(Request $request, NotaFiscal $nota, CancelamentoNfse $cancelamento)
    {
        $this->authorize('cancelar-nota');
        abort_unless($nota->status === StatusNota::Emitida, 404);

        $dados = $request->validate([
            'motivo_codigo' => ['required', 'in:1,2,9'],
            'motivo_texto'  => ['required', 'string', 'min:15', 'max:255'],
            'confirmo'      => ['accepted'],
        ], [
            'motivo_codigo.required' => 'Escolha o motivo do cancelamento.',
            'motivo_texto.required'  => 'Explique o motivo do cancelamento.',
            'motivo_texto.min'       => 'Explique o motivo com pelo menos 15 caracteres.',
            'motivo_texto.max'       => 'O motivo passou de 255 caracteres.',
            'confirmo.accepted'      => 'Marque a confirmação para cancelar.',
        ]);

        try {
            $cancelamento->cancelar($nota, (int) $dados['motivo_codigo'], $dados['motivo_texto']);
        } catch (RejeicaoSefinException $e) {
            return back()->withInput()->withErrors(['cancelamento' => 'A SEFIN recusou o cancelamento: '.implode(' | ', $e->erros)]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['cancelamento' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['cancelamento' => 'Não conseguimos confirmar o cancelamento com a SEFIN. Antes de tentar de novo, confira em "Notas" se ela já aparece como cancelada.']);
        }

        Auditoria::registrar('nota_cancelada', "NFS-e nº {$nota->numero_nfse} cancelada.", [
            'nota' => $nota->id, 'motivo' => CancelamentoNfse::MOTIVOS[(int) $dados['motivo_codigo']], 'justificativa' => $dados['motivo_texto'],
        ]);

        return redirect()->route('notas.index', ['q' => $nota->numero_nfse])->with('ok', "NFS-e nº {$nota->numero_nfse} cancelada.");
    }
}
