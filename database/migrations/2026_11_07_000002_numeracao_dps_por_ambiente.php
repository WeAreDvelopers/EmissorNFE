<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contador oficial da numeração: um por empresa + série + ambiente (teste e produção nunca se misturam).
        Schema::create('sequencias_dps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emitente_id')->constrained('emitentes');
            $table->string('serie', 5);
            $table->unsignedTinyInteger('ambiente');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestamps();
            $table->unique(['emitente_id', 'serie', 'ambiente']);
        });

        // Quem mexeu manualmente na sequência (só o dono pode)
        Schema::create('ajustes_numeracao_dps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('emitente_id')->constrained('emitentes');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serie', 5);
            $table->unsignedTinyInteger('ambiente');
            $table->unsignedBigInteger('de');
            $table->unsignedBigInteger('para');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->unsignedTinyInteger('ambiente')->nullable()->after('serie_dps');
        });

        // Notas já existentes: o ambiente é o da empresa no momento (até aqui só havia um por empresa)
        DB::statement('update notas_fiscais set ambiente = (select ambiente from emitentes where emitentes.id = notas_fiscais.emitente_id) where numero_dps is not null');

        // A unicidade passa a incluir o ambiente. Cria a nova antes de apagar a antiga (a chave estrangeira precisa de um índice).
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->unique(['emitente_id', 'serie_dps', 'ambiente', 'numero_dps'], 'notas_dps_unica'));
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropUnique(['emitente_id', 'serie_dps', 'numero_dps']));

        // Contadores iniciais = maior número já usado em cada grupo
        $grupos = DB::table('notas_fiscais')->whereNotNull('numero_dps')
            ->selectRaw('emitente_id, serie_dps, ambiente, max(numero_dps) as maximo')->groupBy('emitente_id', 'serie_dps', 'ambiente')->get();
        foreach ($grupos as $g) {
            DB::table('sequencias_dps')->insert([
                'emitente_id' => $g->emitente_id, 'serie' => $g->serie_dps, 'ambiente' => $g->ambiente ?? 2,
                'ultimo_numero' => $g->maximo, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->unique(['emitente_id', 'serie_dps', 'numero_dps']));
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropUnique('notas_dps_unica'));
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropColumn('ambiente'));
        Schema::dropIfExists('ajustes_numeracao_dps');
        Schema::dropIfExists('sequencias_dps');
    }
};
