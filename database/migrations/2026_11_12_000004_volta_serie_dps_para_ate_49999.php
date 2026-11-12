<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A série da DPS vai de 00001 a 49999 (E0010). Empresas salvas com série 50000+ (regra errada, já revertida)
        // voltam para 00001, desde que nenhuma nota tenha sido criada com a série antiga.
        $emitentes = DB::table('emitentes')->whereRaw('CAST(serie_dps AS UNSIGNED) >= 50000')->get(['id', 'serie_dps']);
        foreach ($emitentes as $e) {
            $usada = DB::table('notas_fiscais')->where('emitente_id', $e->id)->where('serie_dps', $e->serie_dps)->exists();
            if (! $usada) {
                DB::table('emitentes')->where('id', $e->id)->update(['serie_dps' => '00001']);
            }
        }
    }

    public function down(): void
    {
        // dados corrigidos: nada a desfazer
    }
};
