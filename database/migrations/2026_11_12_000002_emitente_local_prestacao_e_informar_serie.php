<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            // Onde o serviço é prestado: emitente | tomador (destinatário de cada nota) | outro (codigo_municipio_prestacao)
            $table->string('local_prestacao', 10)->default('emitente')->after('codigo_municipio_prestacao');
            // Como no Emissor Nacional: "Informar série e número da DPS". Empresas já cadastradas mantêm a série que usam.
            $table->boolean('informar_serie_dps')->default(true)->after('serie_dps');
        });
        DB::table('emitentes')->whereNotNull('codigo_municipio_prestacao')->update(['local_prestacao' => 'outro']);
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->dropColumn(['local_prestacao', 'informar_serie_dps']));
    }
};
