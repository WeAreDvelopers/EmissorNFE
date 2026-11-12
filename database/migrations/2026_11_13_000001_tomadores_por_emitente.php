<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tomadores (clientes) passam a ser de uma empresa (emitente): o mesmo CPF/CNPJ pode existir em empresas diferentes.
        // Pode ser reexecutada: no MySQL cada ALTER é independente e uma falha no meio deixa o que já rodou.
        if (! Schema::hasColumn('tomadores', 'emitente_id')) {
            Schema::table('tomadores', function (Blueprint $table) {
                $table->foreignId('emitente_id')->nullable()->after('conta_id')->constrained('emitentes')->nullOnDelete();
            });
        }
        if (Schema::hasIndex('tomadores', 'tomadores_conta_id_documento_unique')) {
            Schema::table('tomadores', function (Blueprint $table) {
                // a chave estrangeira de conta_id usa o índice único; sem outro índice o MySQL não deixa apagá-lo
                if (! Schema::hasIndex('tomadores', 'tomadores_conta_id_index')) {
                    $table->index('conta_id');
                }
            });
            Schema::table('tomadores', function (Blueprint $table) {
                $table->dropUnique(['conta_id', 'documento']);
            });
        }

        foreach (DB::table('tomadores')->orderBy('id')->get() as $t) {
            // empresas que já emitiram para este tomador, da mais usada para a menos usada
            $usos = DB::table('notas_fiscais')->where('tomador_id', $t->id)->whereNotNull('emitente_id')
                ->select('emitente_id', DB::raw('count(*) as total'))->groupBy('emitente_id')->orderByDesc('total')->pluck('emitente_id')->all();

            if (! $usos) {
                $usos = [DB::table('emitentes')->where('conta_id', $t->conta_id)->orderBy('id')->value('id')];
            }
            if (! $usos[0]) {
                continue;   // conta sem empresa: fica sem emitente (não aparece em tela nenhuma)
            }

            DB::table('tomadores')->where('id', $t->id)->update(['emitente_id' => $usos[0]]);

            // usado por mais de uma empresa: cada uma passa a ter a sua cópia, e as notas apontam para a cópia da própria empresa
            foreach (array_slice($usos, 1) as $outra) {
                $copia = (array) $t;
                unset($copia['id']);
                $copia['emitente_id'] = $outra;
                $novoId = DB::table('tomadores')->insertGetId($copia);
                DB::table('notas_fiscais')->where('tomador_id', $t->id)->where('emitente_id', $outra)->update(['tomador_id' => $novoId]);
            }
        }

        if (! Schema::hasIndex('tomadores', 'tomadores_emitente_id_documento_unique')) {
            Schema::table('tomadores', function (Blueprint $table) {
                $table->unique(['emitente_id', 'documento']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('tomadores', function (Blueprint $table) {
            $table->dropUnique(['emitente_id', 'documento']);
        });
        // junta de volta: mantém um tomador por documento na conta
        $vistos = [];
        foreach (DB::table('tomadores')->orderBy('id')->get() as $t) {
            $k = $t->conta_id.'|'.$t->documento;
            if (isset($vistos[$k])) {
                DB::table('notas_fiscais')->where('tomador_id', $t->id)->update(['tomador_id' => $vistos[$k]]);
                DB::table('tomador_alteracoes')->where('tomador_id', $t->id)->delete();
                DB::table('tomadores')->where('id', $t->id)->delete();
            } else {
                $vistos[$k] = $t->id;
            }
        }
        Schema::table('tomadores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('emitente_id');
            $table->unique(['conta_id', 'documento']);
            $table->dropIndex(['conta_id']);
        });
    }
};
