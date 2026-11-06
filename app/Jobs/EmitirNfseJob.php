<?php

namespace App\Jobs;

use App\Enums\StatusNota;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;
use App\Services\Nfse\AssinadorXml;
use App\Services\Nfse\Certificado;
use App\Services\Nfse\DpsBuilder;
use App\Services\Nfse\FinalizadorNota;
use App\Services\Nfse\RejeicaoSefinException;
use App\Services\Nfse\SefinNacionalClient;
use App\Services\Nfse\ValidadorXsd;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Emite UMA NFS-e. A nota chega aqui já com 1 crédito RESERVADO.
 *
 * - Autorizada                         → consome o crédito
 * - Rejeitada (XSD local ou SEFIN)     → estorna o crédito, não tenta de novo
 * - Erro técnico (timeout, 5xx, rede)  → tenta de novo; esgotou → estorna o crédito
 */
class EmitirNfseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // O RateLimited devolve o job para a fila e isso conta como tentativa.
    // Por isso limitamos por EXCEÇÕES (erros reais) e por prazo, não por $tries.
    public int $maxExceptions = 5;
    public int $timeout = 60;

    public function __construct(public NotaFiscal $nota)
    {
        $this->onQueue(config('nfse.fila_emissao'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** Espera entre tentativas: 30s, 1min, 5min, 15min */
    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function middleware(): array
    {
        return [
            new RateLimited('sefin'),                                   // limite de envios/minuto (por emitente)
            (new WithoutOverlapping($this->nota->id))->expireAfter(120), // mesma nota nunca em 2 workers
        ];
    }

    public function handle(DpsBuilder $builder, AssinadorXml $assinador, CarteiraService $creditos, ValidadorXsd $xsd): void
    {
        // Job roda fora de requisição: entra explicitamente na conta da nota
        app(ContaAtual::class)->como($this->nota->conta_id, function () use ($builder, $assinador, $creditos, $xsd) {
            $nota = NotaFiscal::with('emitente')->findOrFail($this->nota->id);

            // Idempotência: se já foi emitida (ex.: job duplicado), não faz nada
            if ($nota->status === StatusNota::Emitida) {
                return;
            }

            $nota->update(['status' => StatusNota::Enviando, 'tentativas' => $nota->tentativas + 1]);
            $nota->reservarNumeroDps(); // reservado uma vez; em reenvio usa o mesmo

            $sefin = SefinNacionalClient::para($nota->emitente);

            // Reenvio após timeout: a SEFIN pode ter autorizado e a resposta se perdeu.
            if ($nota->tentativas > 1 && $existente = $sefin->consultarPorDps($builder->idDps($nota))) {
                $this->marcarEmitida($nota, $existente, $creditos);

                return;
            }

            $xml = $assinador->assinar($builder->montar($nota), Certificado::doEmitente($nota->emitente));
            $nota->update(['xml_dps' => $xml]);

            // Valida contra o XSD oficial antes de gastar uma chamada à SEFIN (evita E1235)
            if ($errosXsd = $xsd->erros($xml)) {
                $this->rejeitar($nota, array_map(fn ($e) => "XSD: {$e}", $errosXsd), $creditos);

                return;
            }

            try {
                $retorno = $sefin->emitir($xml);
            } catch (RejeicaoSefinException $e) {
                $this->rejeitar($nota, $e->erros, $creditos);

                return; // rejeição não se resolve tentando de novo
            }

            $this->marcarEmitida($nota, $retorno, $creditos);
        });
    }

    /** Esgotou as tentativas por erro técnico: devolve o crédito reservado. */
    public function failed(Throwable $e): void
    {
        app(ContaAtual::class)->como($this->nota->conta_id, function () use ($e) {
            $nota = NotaFiscal::findOrFail($this->nota->id);
            $nota->update(['status' => StatusNota::Falhou, 'erros' => [$e->getMessage()]]);
            app(CarteiraService::class)->estornar($nota);
        });
    }

    private function rejeitar(NotaFiscal $nota, array $erros, CarteiraService $creditos): void
    {
        $nota->update(['status' => StatusNota::Rejeitada, 'erros' => $erros]);
        $creditos->estornar($nota);
    }

    private function marcarEmitida(NotaFiscal $nota, array $retorno, CarteiraService $creditos): void
    {
        app(FinalizadorNota::class)->emitida($nota, $retorno);
    }
}
