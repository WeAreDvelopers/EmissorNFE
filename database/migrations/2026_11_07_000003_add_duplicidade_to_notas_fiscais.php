<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Possível duplicidade: mesma empresa, cliente, valor e data de competência de uma nota que já existe.
        // Nunca presumimos: o usuário vê as duas e decide (aprovar a linha ou excluí-la).
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->foreignId('duplicidade_de_id')->nullable()->after('tomador_id')->constrained('notas_fiscais')->nullOnDelete();
            $table->boolean('duplicidade_aprovada')->default(false)->after('duplicidade_de_id');
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicidade_de_id');
            $table->dropColumn('duplicidade_aprovada');
        });
    }
};
