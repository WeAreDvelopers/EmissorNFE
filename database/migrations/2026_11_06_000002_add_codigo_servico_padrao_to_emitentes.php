<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            // cTribNac usado quando a planilha vem sem código de serviço
            $table->string('codigo_servico_padrao', 6)->nullable()->after('codigo_municipio');
        });
    }

    public function down(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            $table->dropColumn('codigo_servico_padrao');
        });
    }
};
