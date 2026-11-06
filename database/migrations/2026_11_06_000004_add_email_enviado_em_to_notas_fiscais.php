<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->timestamp('email_enviado_em')->nullable()->after('emitida_em');
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn('email_enviado_em');
        });
    }
};
