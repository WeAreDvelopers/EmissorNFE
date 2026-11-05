<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('emitente_id')->constrained('emitentes');
            $table->string('arquivo_hash', 64)->nullable();   // detecta a mesma planilha enviada 2x
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('arquivo_nome');
            $table->string('arquivo_caminho');
            // pendente | lendo | concluida | falhou
            $table->string('status')->default('pendente')->index();
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('linhas_invalidas')->default(0);
            $table->text('erro')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes');
    }
};
