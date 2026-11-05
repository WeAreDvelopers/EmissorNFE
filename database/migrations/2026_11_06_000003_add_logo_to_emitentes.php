<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            $table->string('logo_arquivo')->nullable()->after('certificado_validade');
        });
    }

    public function down(): void
    {
        Schema::table('emitentes', function (Blueprint $table) {
            $table->dropColumn('logo_arquivo');
        });
    }
};
