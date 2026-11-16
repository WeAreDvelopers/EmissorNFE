<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Informar série e número da DPS" passa a significar: a nota manual pergunta a série e o número (como no Emissor Nacional).
     * A série da empresa (serie_dps) vale sempre, com ou sem a opção. Quem já estava cadastrado continua sem perguntas.
     */
    public function up(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->boolean('informar_serie_dps')->default(false)->change());
        DB::table('emitentes')->update(['informar_serie_dps' => false]);
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->boolean('informar_serie_dps')->default(true)->change());
    }
};
