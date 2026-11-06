<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Quem não está logado cai no login (o middleware 'auth' do /inicio redireciona)
Route::get('/', fn () => redirect()->route('inicio'));

// Pós-login: o middleware 'conta' manda quem não tem conta para a criação de conta
Route::get('/inicio', \App\Http\Controllers\InicioController::class)
    ->middleware(['auth', 'conta'])->name('inicio');
Route::get('/dashboard', fn () => redirect()->route('inicio'))->middleware(['auth', 'conta'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
require __DIR__.'/saas.php';
require __DIR__.'/master.php';
