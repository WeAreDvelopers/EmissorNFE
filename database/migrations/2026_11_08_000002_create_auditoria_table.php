<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trilha de auditoria da conta: alterações fiscais, envios, exclusões, correções, usuários. Só se acrescenta.
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acao', 60);
            $table->string('descricao', 300);
            $table->json('detalhes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conta_id', 'created_at']);
            $table->index(['conta_id', 'acao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
