<?php

namespace App\Services\Tomadores;

use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Support\Documento;
use App\Support\MunicipiosIbge;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cadastro de clientes por planilha (sem emitir nota):
 *  - CPF/CNPJ novo → cria o cliente com o que a planilha traz (nome é obrigatório);
 *  - CPF/CNPJ existente → muda só o que a linha trouxe preenchido; célula vazia preserva o que já existe;
 *  - cada mudança entra no histórico do cliente.
 */
class ImportadorClientes
{
    public const LIMITE_LINHAS = 2000;

    /** Cabeçalho do modelo => campo. A leitura aceita variações (sem acento, maiúsculas, "E-mail"/"E-mails"). */
    public const COLUNAS = [
        'documento'     => 'CNPJ/CPF',
        'nome'          => 'Razão Social/Nome',
        'nome_fantasia' => 'Nome Fantasia',
        'logradouro'    => 'Logradouro',
        'numero'        => 'Número',
        'complemento'   => 'Complemento',
        'bairro'        => 'Bairro',
        'municipio'     => 'Município',
        'uf'            => 'UF',
        'cep'           => 'CEP',
        'emails'        => 'E-mails',
        'observacoes'   => 'Observações',
    ];

    public function modelo(): Spreadsheet
    {
        $livro = new Spreadsheet();
        $aba = $livro->getActiveSheet()->setTitle('Clientes');

        $col = 1;
        foreach (self::COLUNAS as $titulo) {
            $aba->setCellValue([$col, 1], $titulo);
            $aba->getColumnDimensionByColumn($col)->setWidth(max(14, mb_strlen($titulo) + 6));
            $col++;
        }
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(self::COLUNAS));
        $aba->getStyle("A1:{$ultima}1")->getFont()->setBold(true);
        $aba->getStyle("A1:{$ultima}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEFE3');
        $aba->freezePane('A2');
        $aba->getColumnDimension('B')->setWidth(36);
        $aba->getColumnDimension('K')->setWidth(36);
        // CNPJ/CPF, número e CEP como texto: o Excel não pode comer os zeros da frente
        foreach (['A', 'E', 'J'] as $c) {
            $aba->getStyle("{$c}2:{$c}2000")->getNumberFormat()->setFormatCode('@');
        }

        $ajuda = $livro->createSheet()->setTitle('Como preencher');
        $linhas = [
            ['Como preencher a planilha de clientes'],
            [''],
            ['• Preencha a aba "Clientes", uma linha por cliente, a partir da linha 2. Não mude os cabeçalhos da linha 1.'],
            ['• CNPJ/CPF é obrigatório (com ou sem pontuação). Razão Social/Nome é obrigatório para cliente novo.'],
            ['• Cliente que já existe (mesmo CNPJ/CPF): só os campos preenchidos são atualizados. Célula vazia mantém o que já está cadastrado.'],
            ['• Para emitir nota o cliente precisa de endereço completo (logradouro, número, bairro, município, UF e CEP) e ao menos 1 e-mail.'],
            ['• E-mails: separe por vírgula ou ponto e vírgula. O primeiro é o principal e vai no XML da nota; o aviso de nota emitida vai para todos.'],
            ['• UF com 2 letras (ex.: SP). O código IBGE do município é descoberto sozinho pelo nome e pela UF.'],
            ['• Linhas com problema são listadas depois da importação; as demais são importadas normalmente.'],
            ['• Até '.self::LIMITE_LINHAS.' linhas por arquivo.'],
        ];
        foreach ($linhas as $i => $l) {
            $ajuda->setCellValue([1, $i + 1], $l[0]);
        }
        $ajuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ajuda->getColumnDimension('A')->setWidth(120);
        $livro->setActiveSheetIndex(0);

        return $livro;
    }

    /**
     * @return array{criados:int, atualizados:int, sem_mudanca:int, erros:array<int,array{linha:int,mensagem:string}>}
     */
    public function importar(string $caminho, ?int $userId): array
    {
        $linhas = $this->ler($caminho);
        $r = ['criados' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => []];
        $vistos = [];

        foreach ($linhas as $numero => $l) {
            $documento = $this->documento((string) ($l['documento'] ?? ''));
            if ($documento === null) {
                $r['erros'][] = ['linha' => $numero, 'mensagem' => 'CNPJ/CPF vazio ou inválido.'];
                continue;
            }
            if (isset($vistos[$documento])) {
                $r['erros'][] = ['linha' => $numero, 'mensagem' => 'CNPJ/CPF repetido na planilha (já usado na linha '.$vistos[$documento].'). Esta linha foi ignorada.'];
                continue;
            }
            try {
                $dados = $this->normalizar($l);
            } catch (\InvalidArgumentException $e) {
                $r['erros'][] = ['linha' => $numero, 'mensagem' => $e->getMessage()];
                continue;
            }

            $tomador = Tomador::where('documento', $documento)->first();
            if (! $tomador && blank($dados['nome'] ?? null)) {
                $r['erros'][] = ['linha' => $numero, 'mensagem' => 'Cliente novo precisa de Razão Social/Nome.'];
                continue;
            }

            $vistos[$documento] = $numero;   // só conta como "visto" a linha que de fato entrou
            $resultado = $this->gravar($tomador, $documento, $dados, $userId);
            $r[$resultado]++;
        }

        return $r;
    }

    private function gravar(?Tomador $tomador, string $documento, array $dados, ?int $userId): string
    {
        $novo = $tomador === null;
        $tomador ??= new Tomador(['documento' => $documento]);

        // Mudou município ou UF: procura o código IBGE de novo
        $municipio = $dados['municipio'] ?? $tomador->municipio;
        $uf = $dados['uf'] ?? $tomador->uf;
        if (($municipio !== $tomador->municipio || $uf !== $tomador->uf || ! $tomador->codigo_municipio) && filled($municipio) && filled($uf)) {
            $dados['codigo_municipio'] = MunicipiosIbge::codigo($municipio, $uf);
        }

        $historico = [];
        foreach ($dados as $campo => $valor) {
            $antes = $tomador->{$campo};
            if ($antes === $valor || (blank($antes) && blank($valor))) {
                continue;
            }
            $historico[$campo] = [$antes, $valor];
            $tomador->{$campo} = $valor;
        }

        if ($historico === [] && ! $novo) {
            return 'sem_mudanca';
        }

        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        if ($historico !== []) {
            TomadorAlteracao::create([
                'conta_id' => $tomador->conta_id, 'tomador_id' => $tomador->id, 'user_id' => $userId,
                'origem' => 'planilha_clientes', 'campos' => $historico,
            ]);
        }

        return $novo ? 'criados' : 'atualizados';
    }

    /** Só devolve o que a linha trouxe preenchido (vazio preserva o cadastro). */
    private function normalizar(array $l): array
    {
        $d = [];
        foreach (['nome', 'nome_fantasia', 'logradouro', 'numero', 'complemento', 'bairro', 'municipio', 'observacoes'] as $c) {
            if (filled($v = trim((string) ($l[$c] ?? '')))) {
                $d[$c] = $v;
            }
        }

        if (filled($uf = strtoupper(trim((string) ($l['uf'] ?? ''))))) {
            if (! preg_match('/^[A-Z]{2}$/', $uf)) {
                throw new \InvalidArgumentException("UF \"{$uf}\" inválida: use 2 letras (ex.: SP).");
            }
            $d['uf'] = $uf;
        }

        if (filled($cep = preg_replace('/\D/', '', (string) ($l['cep'] ?? '')))) {
            $cep = str_pad($cep, 8, '0', STR_PAD_LEFT);
            if (strlen($cep) !== 8) {
                throw new \InvalidArgumentException('CEP inválido: precisa ter 8 dígitos.');
            }
            $d['cep'] = $cep;
        }

        $emails = collect(preg_split('/[;,\s]+/', (string) ($l['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($e) => strtolower(trim($e)))->unique()->values();
        if ($emails->isNotEmpty()) {
            if ($invalido = $emails->first(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
                throw new \InvalidArgumentException("E-mail inválido: {$invalido}.");
            }
            $d['emails'] = $emails->all();
        }

        return $d;
    }

    /** CPF/CNPJ só com dígitos. O Excel come zeros da frente de número: completa antes de validar. */
    private function documento(string $valor): ?string
    {
        $d = preg_replace('/\D/', '', $valor);
        if ($d === '') {
            return null;
        }
        if (strlen($d) <= 11) {
            $d = str_pad($d, 11, '0', STR_PAD_LEFT);
        } elseif (strlen($d) < 14) {
            $d = str_pad($d, 14, '0', STR_PAD_LEFT);
        }

        return Documento::valido($d) ? $d : null;
    }

    /** @return array<int,array<string,string>> número da linha na planilha => campos */
    private function ler(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReaderForFile($caminho);
            $leitor->setReadDataOnly(true);
            $planilha = $leitor->load($caminho);
        } catch (\Throwable) {
            throw new \RuntimeException('Não conseguimos abrir este arquivo. Use a planilha modelo de clientes (.xlsx ou .csv).');
        }

        foreach ($planilha->getAllSheets() as $aba) {
            if (($cab = $this->cabecalho($aba)) && isset($cab['documento'])) {
                return $this->linhas($aba, $cab);
            }
        }

        throw new \RuntimeException('Arquivo fora do padrão: não encontramos a coluna "CNPJ/CPF" na linha 1. Baixe a planilha modelo de clientes e copie os dados para ela.');
    }

    /** @return array<string,int> campo => índice da coluna */
    private function cabecalho(Worksheet $aba): array
    {
        $apelidos = [
            'documento' => ['cnpjcpf', 'cpfcnpj', 'cnpj', 'cpf', 'documento'],
            'nome' => ['razaosocialnome', 'razaosocial', 'nome', 'razaosocialnomecompleto'],
            'nome_fantasia' => ['nomefantasia', 'fantasia'],
            'logradouro' => ['logradouro', 'endereco', 'rua'],
            'numero' => ['numero', 'n', 'nro'],
            'complemento' => ['complemento'],
            'bairro' => ['bairro'],
            'municipio' => ['municipio', 'cidade'],
            'uf' => ['uf', 'estado'],
            'cep' => ['cep'],
            'emails' => ['emails', 'email', 'emaildocliente'],
            'observacoes' => ['observacoes', 'observacao', 'obs'],
        ];

        $mapa = [];
        $max = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($aba->getHighestColumn());
        for ($c = 1; $c <= $max; $c++) {
            $chave = preg_replace('/[^a-z0-9]/', '', Str::of((string) $aba->getCell([$c, 1])->getValue())->ascii()->lower()->toString());
            foreach ($apelidos as $campo => $lista) {
                if (! isset($mapa[$campo]) && in_array($chave, $lista, true)) {
                    $mapa[$campo] = $c;
                }
            }
        }

        return $mapa;
    }

    private function linhas(Worksheet $aba, array $cab): array
    {
        $ultima = min($aba->getHighestRow(), self::LIMITE_LINHAS + 1);
        if ($aba->getHighestRow() > self::LIMITE_LINHAS + 1) {
            throw new \RuntimeException('A planilha passou de '.self::LIMITE_LINHAS.' linhas. Divida em mais de um arquivo.');
        }

        $saida = [];
        for ($r = 2; $r <= $ultima; $r++) {
            $linha = [];
            foreach ($cab as $campo => $c) {
                $v = $aba->getCell([$c, $r])->getValue();
                $linha[$campo] = is_float($v) && floor($v) === $v ? (string) (int) $v : trim((string) $v);
            }
            if (collect($linha)->filter(fn ($v) => $v !== '')->isEmpty()) {
                continue;   // linha em branco
            }
            $saida[$r] = $linha;
        }

        return $saida;
    }
}
