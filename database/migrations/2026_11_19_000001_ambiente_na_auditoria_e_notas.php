<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ao trocar a empresa entre teste (2, homologação) e produção (1), Auditoria, Notas e Relatórios só mostram o ambiente em uso.
     * Para isso a auditoria passa a guardar o ambiente de cada registro, e toda nota passa a ter ambiente desde que nasce
     * (antes só era preenchido ao reservar o número da DPS: rascunhos, inválidas e na fila ficavam sem).
     * Registros antigos: o ambiente da nota citada; senão o que a empresa usa hoje (o histórico de trocas não existe).
     */
    public function up(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            $table->unsignedTinyInteger('ambiente')->nullable()->after('emitente_id')->index();
        });

        // Notas ainda sem ambiente (nunca receberam número): ambiente atual da empresa
        DB::statement('update notas_fiscais set ambiente = coalesce((select ambiente from emitentes where emitentes.id = notas_fiscais.emitente_id), 2) where ambiente is null');

        // Auditoria: da nota citada, quando houver
        DB::table('auditoria')->whereNull('ambiente')->whereNotNull('emitente_id')->orderBy('id')->each(function ($r) {
            $d = json_decode((string) $r->detalhes, true) ?: [];
            $ambiente = isset($d['nota']) ? DB::table('notas_fiscais')->where('id', $d['nota'])->value('ambiente') : null;
            $ambiente ??= DB::table('emitentes')->where('id', $r->emitente_id)->value('ambiente');
            if ($ambiente) {
                DB::table('auditoria')->where('id', $r->id)->update(['ambiente' => $ambiente]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('auditoria', fn (Blueprint $table) => $table->dropColumn('ambiente'));
    }
};
