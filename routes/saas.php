<?php

/**
 * Inclua no routes/web.php:   require __DIR__.'/saas.php';
 * E registre os middlewares (bootstrap/app.php):
 *   $middleware->web(prepend: [\App\Http\Middleware\IdentificarRevenda::class]);
 *   $middleware->alias(['conta' => \App\Http\Middleware\DefinirContaAtual::class]);
 *   $middleware->validateCsrfTokens(except: ['webhooks/*']);
 */

use App\Http\Controllers\ImportacaoController;
use App\Http\Controllers\NotaConsultaController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\NotaDocumentoController;
use App\Http\Controllers\ReprocessoController;
use App\Http\Controllers\Saas\ContaController;
use App\Http\Controllers\Saas\CreditoController;
use App\Http\Controllers\Saas\EmitenteController;
use App\Http\Controllers\Saas\UsuarioController;
use App\Http\Controllers\TomadorController;
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
    Route::post('/emitentes/{emitente}/administrar', [EmitenteController::class, 'administrar'])->name('emitentes.administrar');
    Route::post('/emitentes/{emitente}/numeracao', [EmitenteController::class, 'ajustarNumeracao'])->name('emitentes.numeracao');

    // Busca na lista nacional de serviços (autocomplete do campo "código de serviço")
    Route::get('/servicos/buscar', fn (\Illuminate\Http\Request $r) => response()->json(
        \App\Support\ListaServicoNacional::buscar((string) $r->query('q', ''))
    ))->middleware('throttle:120,1')->name('servicos.buscar');

    // Busca na NBS 2.0 (lista suspensa do campo NBS)
    Route::get('/nbs/buscar', fn (\Illuminate\Http\Request $r) => response()->json(
        \App\Support\ListaNbs::buscar((string) $r->query('q', ''))
    ))->middleware('throttle:120,1')->name('nbs.buscar');

    // Consulta de CEP (endereço do cliente): 404 quando não existe ou os serviços estão fora do ar
    Route::get('/cep/{cep}', function (string $cep) {
        $d = app(\App\Services\ConsultaCep::class)->buscar($cep);

        return $d ? response()->json($d) : response()->json(['erro' => 'CEP não encontrado'], 404);
    })->where('cep', '[0-9\-]{8,9}')->middleware('throttle:60,1')->name('cep.buscar');

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
    Route::delete('/importacoes/{importacao}', [ImportacaoController::class, 'excluir'])->name('importacoes.excluir');
    Route::get('/importacoes/{importacao}/progresso', [ImportacaoController::class, 'progresso'])->name('importacoes.progresso');
    Route::post('/importacoes/{importacao}/reenviar-falhas', [ImportacaoController::class, 'reenviarFalhas'])->name('importacoes.reenviar-falhas');

    // Reprocessar: repete um lote enviado, editando os dados numa tabela (sem a planilha)
    Route::post('/importacoes/{importacao}/reprocessar', [ReprocessoController::class, 'reprocessar'])->name('importacoes.reprocessar');
    Route::get('/importacoes/{importacao}/editar', [ReprocessoController::class, 'editar'])->name('importacoes.editar');
    Route::put('/importacoes/{importacao}/linhas', [ReprocessoController::class, 'salvar'])->name('importacoes.linhas.salvar');

    // Emissão manual de uma nota (padrões da empresa já preenchidos) e busca de clientes para ela
    Route::get('/notas/nova', [\App\Http\Controllers\NotaManualController::class, 'criar'])->name('notas.manual');
    Route::post('/notas/manual/conferir', [\App\Http\Controllers\NotaManualController::class, 'conferir'])->name('notas.manual.conferir');
    Route::post('/notas/manual/voltar', [\App\Http\Controllers\NotaManualController::class, 'voltar'])->name('notas.manual.voltar');
    Route::post('/notas/manual', [\App\Http\Controllers\NotaManualController::class, 'store'])->name('notas.manual.store');
    Route::get('/clientes/buscar', [\App\Http\Controllers\NotaManualController::class, 'clientes'])->middleware('throttle:120,1')->name('tomadores.buscar');

    // Clientes (tomadores): cadastro por conta, com histórico de alterações
    Route::get('/clientes', [TomadorController::class, 'index'])->name('tomadores.index');
    Route::get('/clientes/modelo', [TomadorController::class, 'modelo'])->name('tomadores.modelo');
    Route::get('/clientes/importar', [TomadorController::class, 'telaImportar'])->name('tomadores.importar.tela');
    Route::post('/clientes/importar', [TomadorController::class, 'importar'])->name('tomadores.importar');
    Route::get('/clientes/importar/{token}', [TomadorController::class, 'conferirImportacao'])->name('tomadores.importar.conferir');
    Route::post('/clientes/importar/{token}', [TomadorController::class, 'confirmarImportacao'])->name('tomadores.importar.confirmar');
    Route::delete('/clientes/importar/{token}', [TomadorController::class, 'cancelarImportacao'])->name('tomadores.importar.cancelar');
    Route::get('/clientes/novo', [TomadorController::class, 'criar'])->name('tomadores.criar');
    Route::post('/clientes', [TomadorController::class, 'store'])->name('tomadores.store');
    Route::get('/clientes/{tomador}', [TomadorController::class, 'editar'])->name('tomadores.editar');
    Route::put('/clientes/{tomador}', [TomadorController::class, 'update'])->name('tomadores.update');
    Route::post('/clientes/{tomador}/situacao', [TomadorController::class, 'situacao'])->name('tomadores.situacao');

    // Cancelamento de NFS-e emitida (somente o dono)
    Route::get('/notas/{nota}/cancelar', [\App\Http\Controllers\NotaCancelamentoController::class, 'form'])->name('notas.cancelar.form');
    Route::post('/notas/{nota}/cancelar', [\App\Http\Controllers\NotaCancelamentoController::class, 'store'])->name('notas.cancelar');

    // Auditoria da conta (somente o dono)
    Route::get('/auditoria', [\App\Http\Controllers\AuditoriaController::class, 'index'])->name('auditoria.index');

    // Consulta de notas (todos os lotes), conferência na SEFIN e relatório mensal
    Route::get('/notas', [NotaConsultaController::class, 'index'])->name('notas.index');
    Route::post('/notas/{nota}/conciliar', [NotaConsultaController::class, 'conciliar'])->name('notas.conciliar');
    Route::get('/relatorios', [RelatorioController::class, 'index'])->name('relatorios.index');
    Route::get('/relatorios/csv', [RelatorioController::class, 'csv'])->name('relatorios.csv');

    // Notas que precisam de ação, correção e reenvio
    Route::get('/pendencias', [NotaController::class, 'pendencias'])->name('pendencias');
    Route::patch('/notas/{nota}', [NotaController::class, 'corrigir'])->name('notas.corrigir');
    Route::delete('/notas/{nota}', [NotaController::class, 'excluir'])->name('notas.excluir');
    Route::post('/notas/{nota}/duplicidade/aprovar', [NotaController::class, 'aprovarDuplicidade'])->name('notas.duplicidade.aprovar');

    // NFS-e emitida: baixar o XML, ver/baixar o DANFSE (PDF) e enviar por e-mail ao cliente
    Route::get('/notas/{nota}/xml', [NotaDocumentoController::class, 'xml'])->name('notas.xml');
    Route::get('/notas/{nota}/danfse', [NotaDocumentoController::class, 'danfse'])->name('notas.danfse');
    Route::post('/notas/{nota}/email', [NotaDocumentoController::class, 'email'])->name('notas.email');
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
