<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tributação municipal (ISSQN) padrão da empresa, como na tela "Tributação Municipal" do Emissor Nacional.
        // Cada nota pode mudar na nota completa personalizada.
        Schema::table('emitentes', function (Blueprint $table) {
            $table->unsignedTinyInteger('trib_issqn')->default(1)->after('regime_especial');          // 1 tributável, 2 imunidade, 4 não incidência
            $table->unsignedTinyInteger('tp_imunidade')->nullable()->after('trib_issqn');             // 1 a 5 (só com imunidade)
            $table->unsignedTinyInteger('retencao_issqn')->default(1)->after('tp_imunidade');         // 1 não retido, 2 retido pelo tomador
            $table->string('deducao_tipo', 10)->nullable()->after('retencao_issqn');                  // percentual | valor
            $table->decimal('deducao_valor', 15, 2)->nullable()->after('deducao_tipo');
            $table->string('codigo_municipio_prestacao', 7)->nullable()->after('deducao_valor');      // vazio = o município da empresa
        });
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->dropColumn(['trib_issqn', 'tp_imunidade', 'retencao_issqn', 'deducao_tipo', 'deducao_valor', 'codigo_municipio_prestacao']));
    }
};
