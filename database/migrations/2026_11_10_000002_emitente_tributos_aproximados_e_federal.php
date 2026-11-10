<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            // Valor aproximado dos tributos: percentual | monetario | simples | nenhum (vazio = padrão do regime)
            $table->string('modo_tributos_aprox', 12)->nullable()->after('tributos_aprox');
            // Tributação federal padrão (não optante): situação do PIS/COFINS e tipo de retenção, como no Emissor Nacional
            $table->string('pis_cofins_cst', 2)->default('00')->after('modo_tributos_aprox');
            $table->unsignedTinyInteger('tp_ret_pis_cofins')->default(0)->after('pis_cofins_cst');
            $table->decimal('aliquota_pis', 5, 2)->nullable()->after('tp_ret_pis_cofins');
            $table->decimal('aliquota_cofins', 5, 2)->nullable()->after('aliquota_pis');
        });
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->dropColumn(['modo_tributos_aprox', 'pis_cofins_cst', 'tp_ret_pis_cofins', 'aliquota_pis', 'aliquota_cofins']));
    }
};
