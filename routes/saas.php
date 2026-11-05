<?php

/**
 * Inclua no routes/web.php:   require __DIR__.'/saas.php';
 * E registre os middlewares (bootstrap/app.php):
 *   $middleware->web(prepend: [\App\Http\Middleware\IdentificarRevenda::class]);
 *   $middleware->alias(['conta' => \App\Http\Middleware\DefinirContaAtual::class]);
 *   $middleware->validateCsrfTokens(except: ['webhooks/*']);
 */

use App\Http\Controllers\ImportacaoController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\ReprocessoController;
use App\Http\Controllers\Saas\ContaController;
use App\Http\Controllers\Saas\CreditoController;
use App\Http\Controllers\Saas\EmitenteController;
use App\Http\Controllers\Saas\UsuarioController;
use App\Http\Controllers\Webhooks\PagamentoWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook do Pix: sem login, sem CSRF. Token secreto no caminho + confirmação na fonte antes de creditar.
Route::post('/webhooks/pagamentos/{token}', PagamentoWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.pagamentos');

Route::middleware(['auth', 'conta'])->group(function () {
    // Conta
    Route::get('/contas/criar', [ContaController::class, 'criar'])->name('contas.criar');
    Route::post('/contas', [ContaController::class, 'store'])->name('contas.store');
    Route::post('/contas/{conta}/trocar', [ContaController::class, 'trocar'])->name('contas.trocar');

    // Convites (o convidado pode ainda não ter conta)
    Route::get('/convites/{token}', [UsuarioController::class, 'aceitar'])->name('convites.aceitar');

    // Usuários da conta
    Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('/usuarios/convites', [UsuarioController::class, 'convidar'])->name('usuarios.convidar');
    Route::patch('/usuarios/{user}', [UsuarioController::class, 'alterarPapel'])->name('usuarios.papel');
    Route::delete('/usuarios/{user}', [UsuarioController::class, 'remover'])->name('usuarios.remover');

    // Emitentes (CNPJs + certificado)
    Route::get('/emitentes', [EmitenteController::class, 'index'])->name('emitentes.index');
    Route::get('/emitentes/criar', [EmitenteController::class, 'criar'])->name('emitentes.criar');
    Route::post('/emitentes/certificado/ler', [EmitenteController::class, 'lerCertificado'])->name('emitentes.certificado.ler');
    Route::get('/emitentes/{emitente}/editar', [EmitenteController::class, 'editar'])->name('emitentes.editar');
    Route::post('/emitentes',[EmitenteController::class, 'store'])->name('emitentes.store');
    Route::put('/emitentes/{emitente}', [EmitenteController::class, 'update'])->name('emitentes.update');

    // Créditos e Pix
    Route::get('/creditos', [CreditoController::class, 'index'])->name('creditos.index');
    Route::post('/creditos/pacotes/{pacote}/comprar', [CreditoController::class, 'comprar'])->name('creditos.comprar');
    Route::get('/pagamentos/{pagamento}', [CreditoController::class, 'pagamento'])->name('pagamentos.show');
    Route::get('/pagamentos/{pagamento}/status', [CreditoController::class, 'status'])->name('pagamentos.status');

    // Importações: escolher arquivo → conferir → enviar → acompanhar
    Route::get('/importacoes', [ImportacaoController::class, 'index'])->name('importacoes.index');
    Route::get('/importacoes/nova', [ImportacaoController::class, 'criar'])->name('importacoes.criar');
    Route::get('/importacoes/modelo', [ImportacaoController::class, 'modelo'])->name('importacoes.modelo');
    Route::post('/importacoes', [ImportacaoController::class, 'store'])->name('importacoes.store');
    Route::get('/importacoes/{importacao}/conferir', [ImportacaoController::class, 'conferir'])->name('importacoes.conferir');
    Route::post('/importacoes/{importacao}/enviar', [ImportacaoController::class, 'enviar'])->name('importacoes.enviar');
    Route::get('/importacoes/{importacao}', [ImportacaoController::class, 'show'])->name('importacoes.show');
    Route::get('/importacoes/{importacao}/progresso', [ImportacaoController::class, 'progresso'])->name('importacoes.progresso');
    Route::post('/importacoes/{importacao}/reenviar-falhas', [ImportacaoController::class, 'reenviarFalhas'])->name('importacoes.reenviar-falhas');

    // Reprocessar: repete um lote enviado, editando os dados numa tabela (sem a planilha)
    Route::post('/importacoes/{importacao}/reprocessar', [ReprocessoController::class, 'reprocessar'])->name('importacoes.reprocessar');
    Route::get('/importacoes/{importacao}/editar', [ReprocessoController::class, 'editar'])->name('importacoes.editar');
    Route::put('/importacoes/{importacao}/linhas', [ReprocessoController::class, 'salvar'])->name('importacoes.linhas.salvar');

    // Notas que precisam de ação, correção e reenvio
    Route::get('/pendencias', [NotaController::class, 'pendencias'])->name('pendencias');
    Route::patch('/notas/{nota}', [NotaController::class, 'corrigir'])->name('notas.corrigir');
    Route::post('/notas/{nota}/reenviar', [ImportacaoController::class, 'reenviar'])->name('notas.reenviar');
});

// Só em desenvolvimento: simula o cliente pagando o Pix do gateway fake
if (app()->environment('local', 'testing')) {
    Route::middleware(['auth', 'conta'])->post('/dev/pix/{pagamento}/pagar', function (\App\Models\Pagamento $pagamento) {
        abort_unless(config('saas.gateway') === 'fake', 404);
        \App\Services\Pagamentos\Gateways\FakeGateway::pagar($pagamento->gateway_id);
        app(\App\Services\Pagamentos\PagamentoService::class)
            ->processarEvento(app(\App\Services\Pagamentos\Contracts\GatewayPagamento::class)->consultar($pagamento->gateway_id));

        return back()->with('ok', 'Pagamento simulado.');
    })->name('dev.pix.pagar');
}
