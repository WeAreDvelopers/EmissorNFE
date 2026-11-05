<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            // Lote criado a partir de outro ("Reprocessar"): guarda de qual veio
            $table->foreignId('reprocessada_de_id')->nullable()->after('arquivo_hash')->constrained('importacoes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reprocessada_de_id');
        });
    }
};
