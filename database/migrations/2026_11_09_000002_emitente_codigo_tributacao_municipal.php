<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código de tributação municipal (cTribMun, 3 dígitos): o portal nacional pede em alguns municípios; proibido para MEI
        Schema::table('emitentes', fn (Blueprint $t) => $t->string('codigo_tributacao_municipal', 3)->nullable());
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $t) => $t->dropColumn('codigo_tributacao_municipal'));
    }
};
