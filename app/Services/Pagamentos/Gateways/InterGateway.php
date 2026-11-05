<?php

namespace App\Services\Pagamentos\Gateways;

use App\Enums\StatusPagamento;
use App\Models\Conta;
use App\Models\Pagamento;
use App\Services\Pagamentos\CobrancaPix;
use App\Services\Pagamentos\Contracts\GatewayPagamento;
use App\Services\Pagamentos\EventoPagamento;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pix direto no Banco Inter PJ (API Pix v2, segue o padrão do Banco Central).
 *
 * - Autenticação: mTLS com o certificado da integração (.crt + .key baixados no Internet Banking)
 *   + OAuth2 client_credentials. Token dura 60 min e só pode ser gerado 5x/min → fica em cache.
 * - Cobrança: PUT /pix/v2/cob/{txid} com txid NOSSO (idempotente: repetir não cria outra).
 * - Webhook: um por chave Pix; corpo = array na raiz; sem assinatura (mTLS) → tratamos como aviso
 *   e confirmamos com GET /cob/{txid} antes de creditar (PagamentoService::processarEvento).
 * - Sem split e sem cadastro de cliente.
 *
 * ⚠️ CONFERIR em developers.inter.co antes de produção: hosts, nomes de escopo, formato do
 *    callback e do objeto de devolução.
 */
class InterGateway implements GatewayPagamento
{
    private const ESCOPOS = 'cob.write cob.read pix.read pix.write webhook.write webhook.read';

    public function nome(): string
    {
        return 'inter';
    }

    public function suportaSplit(): bool
    {
        return false;
    }

    public function garantirCliente(Conta $conta): ?string
    {
        return null; // a API Pix não tem cadastro de cliente; o devedor vai na própria cobrança
    }

    public function criarCobrancaPix(Pagamento $pagamento, ?string $clienteId): CobrancaPix
    {
        $txid = self::txid($pagamento);
        $conta = $pagamento->conta;
        $validadeSeg = config('saas.pix_validade_minutos') * 60;

        $corpo = [
            'calendario'         => ['expiracao' => $validadeSeg],
            'valor'              => ['original' => number_format($pagamento->valor_centavos / 100, 2, '.', '')],
            'chave'              => config('services.inter.chave_pix'),
            'solicitacaoPagador' => "Créditos NFS-e: {$pagamento->creditos} notas",
        ];

        // Devedor opcional; com CPF/CNPJ o comprovante sai identificado
        if ($conta?->documento) {
            $tipo = strlen($conta->documento) === 11 ? 'cpf' : 'cnpj';
            $corpo['devedor'] = [$tipo => $conta->documento, 'nome' => mb_substr($conta->nome, 0, 200)];
        }

        $cob = $this->http()->put("/pix/v2/cob/{$txid}", $corpo)->throw()->json();

        return new CobrancaPix(
            gatewayId: $txid,
            copiaCola: $cob['pixCopiaECola'],
            qrBase64:  null, // o Inter não devolve imagem: a tela gera o QR a partir do copia-e-cola
            expiraEm:  now()->addSeconds($validadeSeg),
        );
    }

    public function consultar(string $gatewayId): EventoPagamento
    {
        $cob = $this->http()->get("/pix/v2/cob/{$gatewayId}")->throw()->json();

        $devolvido = collect($cob['pix'] ?? [])
            ->flatMap(fn ($p) => self::lista($p['devolucoes'] ?? []))
            ->contains(fn ($d) => ($d['status'] ?? null) === 'DEVOLVIDO');

        $criacao = isset($cob['calendario']['criacao']) ? Carbon::parse($cob['calendario']['criacao']) : null;
        $vencida = $criacao && $criacao->addSeconds((int) ($cob['calendario']['expiracao'] ?? 0))->isPast();

        $status = match (true) {
            $devolvido                              => StatusPagamento::Devolvido,
            ($cob['status'] ?? null) === 'CONCLUIDA' => StatusPagamento::Pago,
            str_starts_with((string) ($cob['status'] ?? ''), 'REMOVIDA') => StatusPagamento::Expirado,
            $vencida                                => StatusPagamento::Expirado,
            default                                 => null, // ATIVA e dentro do prazo
        };

        return new EventoPagamento('consulta-'.$gatewayId, 'CONSULTA', $gatewayId, $status, $cob);
    }

    /**
     * Callback do Inter: array de Pix recebidos (cada um com txid, endToEndId e, se houver, devoluções).
     * Autenticação: token secreto no caminho da URL cadastrada (o mTLS do callback fica no servidor web).
     */
    public function lerWebhook(Request $request): ?array
    {
        $token = (string) config('services.inter.webhook_token');
        if ($token === '' || ! hash_equals($token, (string) $request->route('token'))) {
            return null;
        }

        $itens = $request->json()->all();
        $itens = array_is_list($itens) ? $itens : ($itens['pix'] ?? []); // aceita também o formato BACEN

        return collect($itens)
            ->filter(fn ($p) => ! empty($p['txid']))
            ->flatMap(function ($p) {
                $eventos = [new EventoPagamento($p['endToEndId'] ?? $p['txid'], 'PIX_RECEBIDO', $p['txid'], StatusPagamento::Pago, $p)];

                foreach (self::lista($p['devolucoes'] ?? []) as $d) {
                    $eventos[] = new EventoPagamento(
                        ($p['endToEndId'] ?? $p['txid']).'-dev-'.($d['id'] ?? $d['rtrId'] ?? 'x'),
                        'PIX_DEVOLUCAO', $p['txid'], StatusPagamento::Devolvido, $d,
                    );
                }

                return $eventos;
            })
            ->values()
            ->all();
    }

    /** Cadastra a URL do webhook na chave Pix (rodar uma vez: php artisan inter:webhook). */
    public function registrarWebhook(string $url): void
    {
        $chave = rawurlencode((string) config('services.inter.chave_pix'));
        $this->http()->put("/pix/v2/webhook/{$chave}", ['webhookUrl' => $url])->throw();
    }

    /** txid: 26–35 caracteres alfanuméricos, único por cobrança. */
    public static function txid(Pagamento $pagamento): string
    {
        return 'nfse'.str_pad((string) $pagamento->id, 26, '0', STR_PAD_LEFT);
    }

    /** A doc do Inter mostra "devolucoes" ora como objeto, ora como array. */
    private static function lista(mixed $v): array
    {
        return is_array($v) && ! array_is_list($v) ? [$v] : (array) $v;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.inter.url'))
            ->withOptions($this->mtls())
            ->withToken($this->token())
            ->withHeaders(array_filter(['x-conta-corrente' => config('services.inter.conta_corrente')]))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
    }

    private function token(): string
    {
        // 55 min: renova antes de vencer; o Inter limita a 5 tokens por minuto
        return Cache::remember('inter:oauth:token', now()->addMinutes(55), fn () =>
            Http::baseUrl(config('services.inter.url'))
                ->withOptions($this->mtls())
                ->asForm()
                ->post('/oauth/v2/token', [
                    'client_id'     => config('services.inter.client_id'),
                    'client_secret' => config('services.inter.client_secret'),
                    'grant_type'    => 'client_credentials',
                    'scope'         => self::ESCOPOS,
                ])->throw()->json('access_token')
        );
    }

    private function mtls(): array
    {
        $opcoes = ['cert' => config('services.inter.cert'), 'ssl_key' => config('services.inter.key')];

        // Chave cifrada: a senha vai em ssl_key (em cert o Guzzle mandaria para o .crt)
        if ($senha = config('services.inter.key_senha')) {
            $opcoes['ssl_key'] = [config('services.inter.key'), $senha];
        }

        return $opcoes;
    }
}
