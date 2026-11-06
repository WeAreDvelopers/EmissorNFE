<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valor total que a planilha declarava (célula E1): base da conciliação "planilha x emitido"
        Schema::table('importacoes', fn (Blueprint $t) => $t->decimal('total_planilha', 15, 2)->nullable());
        // Última vez que a nota foi conferida na SEFIN (nota com resultado incerto)
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->timestamp('conciliada_em')->nullable());
    }

    public function down(): void
    {
        Schema::table('importacoes', fn (Blueprint $t) => $t->dropColumn('total_planilha'));
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropColumn('conciliada_em'));
    }
};
