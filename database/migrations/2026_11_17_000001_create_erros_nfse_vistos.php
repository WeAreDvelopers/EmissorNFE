<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Códigos de erro da prefeitura que já apareceram, com a contagem. Alimenta o painel "Erros sem tradução" (master). */
    public function up(): void
    {
        Schema::create('erros_nfse_vistos', function (Blueprint $table) {
            $table->string('codigo', 10)->primary();
            $table->unsignedInteger('ocorrencias')->default(0);
            $table->boolean('sem_traducao')->default(false);   // código que o catálogo não conhece
            $table->unsignedBigInteger('ultima_nota_id')->nullable();
            $table->timestamp('ultima_vez')->nullable();
            $table->text('ultimo_texto')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erros_nfse_vistos');
    }
};
