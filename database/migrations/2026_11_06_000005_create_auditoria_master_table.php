<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem da equipe master consultou o quê (a área master enxerga todos os clientes).
        Schema::create('auditoria_master', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acao', 60);
            $table->foreignId('conta_id')->nullable()->constrained('contas')->nullOnDelete();
            $table->json('detalhes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['acao', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_master');
    }
};
