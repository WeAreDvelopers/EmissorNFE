<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico da empresa (mesmo conceito do histórico de clientes): quem mudou o quê, de qual valor para qual.
        Schema::create('emitente_alteracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('emitente_id')->constrained('emitentes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origem', 20);                   // cadastro | edicao
            $table->json('campos');                         // {campo: [de, para]}
            $table->timestamp('created_at')->useCurrent();
            $table->index(['emitente_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emitente_alteracoes');
    }
};
