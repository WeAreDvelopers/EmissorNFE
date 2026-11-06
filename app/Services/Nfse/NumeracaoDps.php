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
