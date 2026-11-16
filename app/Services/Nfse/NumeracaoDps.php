<?php

namespace App\Services\Nfse;

use App\Models\Emitente;
use App\Models\NotaFiscal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Numeração da DPS (nDPS). Quem escolhe é o app; o número da NFS-e quem dá é o ambiente nacional.
 *
 * - Um contador por empresa + série + ambiente (teste e produção não se misturam), com trava de linha no banco.
 * - O número é dado uma vez só por nota: reenvio ou falha de comunicação reaproveitam o mesmo
 *   (assim a SEFIN reconhece a DPS original e nunca nasce uma segunda identificação).
 * - Índice único no banco (empresa + série + ambiente + número) como segunda barreira.
 */
class NumeracaoDps
{
    public function reservar(NotaFiscal $nota): int
    {
        if ($nota->numero_dps) {
            return $nota->numero_dps;
        }

        return DB::transaction(function () use ($nota) {
            $ambiente = (int) Emitente::semIsolamento()->whereKey($nota->emitente_id)->value('ambiente') ?: 2;

            $this->garantirLinha($nota->emitente_id, $nota->serie_dps, $ambiente);
            $seq = DB::table('sequencias_dps')
                ->where(['emitente_id' => $nota->emitente_id, 'serie' => $nota->serie_dps, 'ambiente' => $ambiente])
                ->lockForUpdate()->first();

            $proximo = (int) $seq->ultimo_numero + 1;
            DB::table('sequencias_dps')->where('id', $seq->id)->update(['ultimo_numero' => $proximo, 'updated_at' => now()]);

            $nota->forceFill(['numero_dps' => $proximo, 'ambiente' => $ambiente])->save();

            return $proximo;
        });
    }

    /** Esse número já foi usado nesta empresa, série e ambiente? */
    public function existe(int $emitenteId, string $serie, int $ambiente, int $numero): bool
    {
        return NotaFiscal::semIsolamento()->where(['emitente_id' => $emitenteId, 'serie_dps' => $serie, 'ambiente' => $ambiente, 'numero_dps' => $numero])->exists();
    }

    /** Próximo número automático da série (o que o app daria se ninguém informasse nada). */
    public function proximoDe(int $emitenteId, string $serie, int $ambiente): int
    {
        return max((int) DB::table('sequencias_dps')->where(['emitente_id' => $emitenteId, 'serie' => $serie, 'ambiente' => $ambiente])->value('ultimo_numero'), $this->maiorUsado($emitenteId, $serie, $ambiente)) + 1;
    }

    /**
     * Número da DPS informado pela pessoa na nota manual ("Informar série e número da DPS").
     * A série já está na nota. Se o número passar do contador, o contador acompanha, para a numeração
     * automática seguinte nunca repetir esse número.
     */
    public function reservarInformado(NotaFiscal $nota, int $numero): int
    {
        return DB::transaction(function () use ($nota, $numero) {
            $ambiente = (int) Emitente::semIsolamento()->whereKey($nota->emitente_id)->value('ambiente') ?: 2;

            $this->garantirLinha($nota->emitente_id, $nota->serie_dps, $ambiente);
            $seq = DB::table('sequencias_dps')
                ->where(['emitente_id' => $nota->emitente_id, 'serie' => $nota->serie_dps, 'ambiente' => $ambiente])
                ->lockForUpdate()->first();

            if ($this->existe($nota->emitente_id, $nota->serie_dps, $ambiente, $numero)) {
                throw ValidationException::withMessages(['dps_numero' => "O número {$numero} já foi usado na série {$nota->serie_dps}."]);
            }
            if ($numero > (int) $seq->ultimo_numero) {
                DB::table('sequencias_dps')->where('id', $seq->id)->update(['ultimo_numero' => $numero, 'updated_at' => now()]);
            }
            $nota->forceFill(['numero_dps' => $numero, 'ambiente' => $ambiente])->save();

            return $numero;
        });
    }

    /** Último número usado e próximo, por ambiente, para a tela da empresa. */
    public function situacao(Emitente $emitente): array
    {
        $serie = $emitente->serie_dps;
        $saida = [];
        foreach ([2 => 'Teste', 1 => 'Produção'] as $ambiente => $rotulo) {
            $ultimo = (int) DB::table('sequencias_dps')->where(['emitente_id' => $emitente->id, 'serie' => $serie, 'ambiente' => $ambiente])->value('ultimo_numero');
            $saida[$ambiente] = ['rotulo' => $rotulo, 'ultimo' => $ultimo, 'proximo' => $ultimo + 1, 'maior_usado' => $this->maiorUsado($emitente->id, $serie, $ambiente)];
        }

        return $saida;
    }

    /** Histórico dos números usados (os mais recentes primeiro). */
    public function historico(Emitente $emitente, int $limite = 15)
    {
        return NotaFiscal::semIsolamento()->where('emitente_id', $emitente->id)->where('serie_dps', $emitente->serie_dps)
            ->whereNotNull('numero_dps')->orderByDesc('id')->limit($limite)
            ->get(['id', 'ambiente', 'numero_dps', 'status', 'numero_nfse', 'emitida_em', 'updated_at']);
    }

    /**
     * Ajuste manual (só o dono, na tela). Não deixa voltar para um número que já existe:
     * o novo "próximo" precisa ser maior que o maior número já usado nesta série e ambiente.
     */
    public function ajustar(Emitente $emitente, int $ambiente, int $proximo, ?int $userId): void
    {
        DB::transaction(function () use ($emitente, $ambiente, $proximo, $userId) {
            $serie = $emitente->serie_dps;
            $this->garantirLinha($emitente->id, $serie, $ambiente);
            $seq = DB::table('sequencias_dps')->where(['emitente_id' => $emitente->id, 'serie' => $serie, 'ambiente' => $ambiente])->lockForUpdate()->first();

            $maior = $this->maiorUsado($emitente->id, $serie, $ambiente);
            if ($proximo <= $maior) {
                throw ValidationException::withMessages(['proximo' => "Já existem notas até o nº {$maior} nesta série. O próximo número precisa ser {$this->fmt($maior + 1)} ou maior."]);
            }
            if ($proximo > 999999999999999) {
                throw ValidationException::withMessages(['proximo' => 'Número grande demais.']);
            }

            if ((int) $seq->ultimo_numero === $proximo - 1) {
                return;
            }

            DB::table('sequencias_dps')->where('id', $seq->id)->update(['ultimo_numero' => $proximo - 1, 'updated_at' => now()]);
            DB::table('ajustes_numeracao_dps')->insert([
                'conta_id' => $emitente->conta_id, 'emitente_id' => $emitente->id, 'user_id' => $userId, 'serie' => $serie,
                'ambiente' => $ambiente, 'de' => (int) $seq->ultimo_numero + 1, 'para' => $proximo, 'created_at' => now(),
            ]);
        });
    }

    public function ajustes(Emitente $emitente, int $limite = 10)
    {
        return DB::table('ajustes_numeracao_dps')->where('emitente_id', $emitente->id)->orderByDesc('id')->limit($limite)->get();
    }

    private function maiorUsado(int $emitenteId, string $serie, int $ambiente): int
    {
        return (int) NotaFiscal::semIsolamento()->where(['emitente_id' => $emitenteId, 'serie_dps' => $serie, 'ambiente' => $ambiente])->max('numero_dps');
    }

    private function garantirLinha(int $emitenteId, string $serie, int $ambiente): void
    {
        DB::table('sequencias_dps')->insertOrIgnore([
            'emitente_id' => $emitenteId, 'serie' => $serie, 'ambiente' => $ambiente,
            'ultimo_numero' => $this->maiorUsado($emitenteId, $serie, $ambiente), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fmt(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
