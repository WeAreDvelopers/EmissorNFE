<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\MovimentoCredito;
use App\Models\Pacote;
use App\Models\Pagamento;
use App\Services\Creditos\CarteiraService;
use App\Services\Pagamentos\PagamentoService;
use App\Support\Ordenacao;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Http\Request;
use Throwable;

class CreditoController extends Controller
{
    public function __construct(private ContaAtual $contaAtual) {}

    /** Saldo, extrato e loja de pacotes da revenda da conta. */
    public function index(Request $request, CarteiraService $creditos)
    {
        $this->authorize('consultar');
        $conta = $this->contaAtual->conta();
        $consulta = MovimentoCredito::latest('id');
        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, ['data' => 'created_at', 'movimento' => 'tipo', 'quantidade' => 'quantidade', 'saldo' => 'disponivel_apos']);

        return view('creditos.index', [
            'carteira'   => $creditos->saldo($conta->id),
            'pacotes'    => $conta->revenda->pacotes,
            'ordem' => $ordem, 'dir' => $dir,
            'extrato'    => $consulta->paginate(30)->withQueryString(),
            'podeComprar'=> request()->user()->pode('comprar-creditos'),
        ]);
    }

    public function comprar(Request $request, Pacote $pacote, PagamentoService $pagamentos)
    {
        $this->authorize('comprar-creditos');

        try {
            $pagamento = $pagamentos->iniciarCompra($this->contaAtual->conta(), $pacote, $request->user()->id);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['pacote' => 'Não foi possível gerar o Pix agora. Tente de novo em instantes.']);
        }

        return redirect()->route('pagamentos.show', $pagamento);
    }

    /** Tela do QR Code. */
    public function pagamento(Pagamento $pagamento)
    {
        $this->authorize('comprar-creditos');

        return view('creditos.pix', ['pagamento' => $pagamento->makeVisible('pix_qr_base64')]);
    }

    /** Polling da tela do Pix. Só lê o banco; quem credita é o webhook. */
    public function status(Pagamento $pagamento)
    {
        return response()->json(['status' => $pagamento->status->value]);
    }
}
