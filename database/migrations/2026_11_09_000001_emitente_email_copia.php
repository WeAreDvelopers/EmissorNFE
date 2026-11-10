<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // E-mail da empresa que emite: recebe cópia de toda NFS-e enviada ao cliente (para ciência)
        Schema::table('emitentes', fn (Blueprint $t) => $t->string('email_copia')->nullable());
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $t) => $t->dropColumn('email_copia'));
    }
};
