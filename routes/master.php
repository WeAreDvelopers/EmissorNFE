<?php

use App\Http\Controllers\Master\MasterController;
use Illuminate\Support\Facades\Route;

/**
 * Área master (somente leitura): acompanhar clientes, notas, erros e créditos de toda a plataforma.
 * Sem o middleware 'conta': o master não está "dentro" de uma conta, e os dados são lidos sem isolamento.
 */
Route::middleware(['auth', 'master'])->prefix('master')->name('master.')->group(function () {
    Route::get('/', [MasterController::class, 'visao'])->name('visao');
    Route::get('/clientes', [MasterController::class, 'clientes'])->name('clientes');
    Route::get('/clientes/{conta}', [MasterController::class, 'cliente'])->name('cliente');
    // Única escrita da área master: ligar/desligar a cobrança de uma empresa (sempre auditada)
    Route::post('/empresas/{emitente}/cobranca', [MasterController::class, 'cobranca'])->name('empresa.cobranca');
});
