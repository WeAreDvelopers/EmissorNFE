<?php

namespace App\Support;

use App\Models\NotaFiscal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo de erros da prefeitura: para cada código (E0116…), o que houve, como resolver e onde corrigir.
 *
 * - `erros-nfse-base.php`  gerado do Anexo I por docs/gerar-mapeamento-erros.py (todos os códigos, orientação conservadora).
 * - `erros-nfse.php`       textos próprios revisados (têm prioridade sobre a base).
 *
 * Ações: nota (campo editável no painel) · emitente / cliente (botão que abre o cadastro no campo certo) ·
 *        contador (depende da prefeitura, com mensagem pronta) · sistema (avisar o suporte) · nao_suportado (bug nosso).
 */
class CatalogoErros
{
    private static ?array $base = null;
    private static ?array $proprios = null;

    /** Rótulo curto da etiqueta na lista, por ação (nota mantém "Recusada pela prefeitura"). */
    private const ROTULOS = [
        'emitente' => 'Ajuste no cadastro da empresa',
        'cliente'  => 'Ajuste no cadastro do cliente',
        'contador' => 'Depende da prefeitura',
        'sistema'  => 'Erro no sistema',
        'nao_suportado' => 'Erro no sistema',
    ];

    /** @return list<string> códigos E0000 do texto técnico, sem repetir, na ordem em que aparecem */
    public static function codigos(array $tecnico): array
    {
        preg_match_all('/\bE\d{4}\b/', implode(' ', array_map('strval', $tecnico)), $m);

        return array_values(array_unique($m[0] ?? []));
    }

    /** Entrada do catálogo (base + texto próprio) ou null se o código é desconhecido. */
    public static function entrada(string $codigo): ?array
    {
        self::$base ??= require resource_path('data/erros-nfse-base.php');
        self::$proprios ??= require resource_path('data/erros-nfse.php');

        $base = self::$base[$codigo] ?? null;
        $proprio = self::$proprios[$codigo] ?? null;
        if (! $base && ! $proprio) {
            return null;
        }

        return array_replace(['acao' => 'sistema', 'campo' => ''], $base ?? [], $proprio ?? [], ['codigo' => $codigo, 'texto_proprio' => (bool) $proprio]);
    }

    /** Todos os códigos conhecidos (para testes e para o painel de erros sem tradução). */
    public static function todos(): array
    {
        self::$base ??= require resource_path('data/erros-nfse-base.php');
        self::$proprios ??= require resource_path('data/erros-nfse.php');

        return array_values(array_unique([...array_keys(self::$base), ...array_keys(self::$proprios)]));
    }

    /** Códigos do texto técnico que o catálogo não conhece (vão para a telemetria de "sem tradução"). */
    public static function desconhecidos(array $tecnico): array
    {
        return array_values(array_filter(self::codigos($tecnico), fn ($c) => self::entrada($c) === null));
    }

    /**
     * Explicação no formato de ExplicacaoErro::para() (mais: acao, rotulo, destino, contador, suporte, codigos),
     * ou null se nenhum código do erro está no catálogo.
     */
    public static function para(array $tecnico, NotaFiscal $nota): ?array
    {
        $entradas = array_values(array_filter(array_map(fn ($c) => self::entrada($c), self::codigos($tecnico))));
        if (! $entradas) {
            return null;
        }
        // Texto próprio primeiro; senão o primeiro código reconhecido
        usort($entradas, fn ($a, $b) => ($b['texto_proprio'] ? 1 : 0) <=> ($a['texto_proprio'] ? 1 : 0));
        $e = $entradas[0];
        $acao = $e['acao'];
        $campo = (string) ($e['campo'] ?? '');
        $oficial = (string) ($e['oficial'] ?? '');

        $titulo = $e['titulo'] ?? ($acao === 'nota' ? 'Recusada pela prefeitura' : 'Recusada: '.($e['onde'] ?? 'ajuste necessário'));
        $aconteceu = $e['aconteceu'] ?? ($oficial !== '' ? 'A prefeitura informou: '.$oficial : 'A prefeitura recusou esta nota.');
        $resolver = $e['como_resolver'] ?? self::resolverPadrao($acao, $e, $nota);

        $r = [
            'titulo'          => $titulo,
            'rotulo'          => self::ROTULOS[$acao] ?? null,
            'o_que_aconteceu' => $aconteceu,
            'como_resolver'   => $resolver,
            'campos'          => [],
            'reenviar_direto' => true,
            'tecnico'         => $tecnico,
            'acao'            => $acao,
            'codigos'         => array_column($entradas, 'codigo'),
            'destino'         => null,
            'contador'        => null,
            'suporte'         => null,
        ];

        if ($acao === 'nota') {
            $r['campos'] = isset(ExplicacaoErro::ROTULOS[$campo]) ? [$campo] : array_keys(ExplicacaoErro::ROTULOS);
            $r['reenviar_direto'] = false;
        } elseif ($acao === 'contador' && isset(ExplicacaoErro::ROTULOS[$campo])) {
            $r['campos'] = [$campo];   // depende da prefeitura, mas a pessoa também pode ajustar o dado da nota
            $r['reenviar_direto'] = false;
        } elseif ($acao === 'emitente' || ($acao === 'contador' && ! isset(ExplicacaoErro::ROTULOS[$campo]))) {
            $r['destino'] = ['url' => route('emitentes.editar', $nota->emitente_id).($campo ? '?destacar='.$campo : ''), 'rotulo' => 'Abrir o cadastro da empresa', 'campo' => $campo, 'permissao' => 'gerenciar-emitentes'];
        } elseif ($acao === 'cliente' && $nota->tomador_id) {
            $r['destino'] = ['url' => route('tomadores.editar', $nota->tomador_id).($campo ? '?destacar='.$campo : ''), 'rotulo' => 'Abrir o cadastro do cliente', 'campo' => $campo, 'permissao' => 'emitir'];
        }

        if (in_array($acao, ['contador', 'emitente', 'cliente'], true) && isset($e['contador'])) {
            $r['contador'] = $e['contador'];
        } elseif ($acao === 'contador') {
            $r['contador'] = "Preciso de ajuda com um cadastro na prefeitura para emitir NFS-e. A prefeitura recusou a nota com o erro {$e['codigo']}: {$oficial}";
        }
        if (in_array($acao, ['sistema', 'nao_suportado'], true)) {
            $r['suporte'] = "Nota #{$nota->id} recusada antes do envio ou pelo sistema. Erro {$e['codigo']}: ".($oficial ?: implode(' | ', $tecnico));
        }

        return $r;
    }

    private static function resolverPadrao(string $acao, array $e, NotaFiscal $nota): string
    {
        $orient = (string) ($e['orientacao'] ?? '');

        return match ($acao) {
            'nota'     => ($orient !== '' ? $orient.' ' : '').'Corrija o campo abaixo e reenvie.',
            'emitente' => ($orient !== '' ? $orient.' ' : '').'Abra o cadastro da empresa, ajuste o campo indicado e depois reenvie a nota.',
            'cliente'  => ($orient !== '' ? $orient.' ' : '').'Abra o cadastro do cliente, ajuste o campo indicado e depois reenvie a nota.',
            'contador' => ($orient !== '' ? $orient.' ' : '').'Isso depende de um cadastro na prefeitura: use o botão para copiar a mensagem e envie à contabilidade. Depois reenvie a nota.',
            default    => 'Não há nada para corrigir aqui. Avise o suporte informando o número desta nota (#'.$nota->id.') e depois use Reenviar.',
        };
    }

    /**
     * Telemetria: conta cada código que a prefeitura devolveu (e marca os que o catálogo não conhece).
     * Nunca pode atrapalhar o envio, por isso engole qualquer falha.
     */
    public static function registrar(array $erros, int $notaId): void
    {
        try {
            foreach (self::codigos($erros) as $codigo) {
                DB::table('erros_nfse_vistos')->upsert([[
                    'codigo' => $codigo, 'ocorrencias' => 1, 'sem_traducao' => self::entrada($codigo) === null, 'ultima_nota_id' => $notaId,
                    'ultima_vez' => now(), 'ultimo_texto' => Str::limit(implode(' | ', array_map('strval', $erros)), 600), 'created_at' => now(), 'updated_at' => now(),
                ]], ['codigo'], ['ocorrencias' => DB::raw('ocorrencias + 1'), 'sem_traducao', 'ultima_nota_id', 'ultima_vez', 'ultimo_texto', 'updated_at']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
