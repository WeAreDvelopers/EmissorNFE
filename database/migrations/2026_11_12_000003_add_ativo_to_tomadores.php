<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tomador não se exclui: fica ativo ou inativo (não tem relação com o CNPJ/CPF).
        Schema::table('tomadores', function (Blueprint $table) {
            $table->boolean('ativo')->default(true)->after('pendente');
        });
    }

    public function down(): void
    {
        Schema::table('tomadores', fn (Blueprint $table) => $table->dropColumn('ativo'));
    }
};
