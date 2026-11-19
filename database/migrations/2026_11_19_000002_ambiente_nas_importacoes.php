<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importações (lotes) também separam teste de produção. Cada lote guarda o ambiente em que a empresa estava ao importar.
     * Lotes antigos: o ambiente das notas do lote, se houver; senão o que a empresa usa hoje (não existe histórico de trocas).
     */
    public function up(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('ambiente')->nullable()->after('emitente_id')->index();
        });

        DB::table('importacoes')->orderBy('id')->each(function ($i) {
            $ambiente = DB::table('notas_fiscais')->where('importacao_id', $i->id)->whereNotNull('ambiente')->value('ambiente')
                ?? DB::table('emitentes')->where('id', $i->emitente_id)->value('ambiente');
            DB::table('importacoes')->where('id', $i->id)->update(['ambiente' => $ambiente ?: 2]);
        });
    }

    public function down(): void
    {
        Schema::table('importacoes', fn (Blueprint $table) => $table->dropColumn('ambiente'));
    }
};
