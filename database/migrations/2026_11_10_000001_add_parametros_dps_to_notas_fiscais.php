<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustes fiscais só desta nota (emissão manual com "todos os campos"): só o que difere do cadastro da empresa
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->json('parametros_dps')->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', fn (Blueprint $table) => $table->dropColumn('parametros_dps'));
    }
};
