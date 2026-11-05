<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Lê só a 1ª linha da planilha (nomes das colunas) para barrar arquivo fora do modelo antes de importar. */
class CabecalhoPlanilha
{
    public const OBRIGATORIAS = ['tomador_documento', 'tomador_nome', 'descricao', 'valor', 'data_competencia'];

    /** @return string[]|null nomes normalizados como o importador faz (slug com _); null se não deu para ler */
    public static function ler(UploadedFile $arquivo): ?array
    {
        $ext = strtolower($arquivo->getClientOriginalExtension());

        try {
            $bruto = $ext === 'csv' ? self::lerCsv($arquivo->getRealPath()) : self::lerPlanilha($arquivo->getRealPath());
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($h) => Str::slug((string) $h, '_'), $bruto), fn ($h) => $h !== ''));
    }

    /** Colunas obrigatórias que faltam. $codigoServicoObrigatorio: false quando a empresa tem código padrão. */
    public static function faltando(array $cabecalho, bool $codigoServicoObrigatorio = true): array
    {
        $exigidas = $codigoServicoObrigatorio ? [...self::OBRIGATORIAS, 'codigo_servico'] : self::OBRIGATORIAS;

        return array_values(array_diff($exigidas, $cabecalho));
    }

    private static function lerCsv(string $caminho): array
    {
        $f = fopen($caminho, 'rb');
        $linha = (string) fgets($f);
        fclose($f);

        $linha = preg_replace('/^\xEF\xBB\xBF/', '', $linha);
        if (! mb_check_encoding($linha, 'UTF-8')) {
            $linha = mb_convert_encoding($linha, 'UTF-8', 'Windows-1252');
        }
        $sep = collect([';', ',', "\t"])->sortByDesc(fn ($s) => substr_count($linha, $s))->first();

        return str_getcsv(rtrim($linha, "\r\n"), $sep);
    }

    private static function lerPlanilha(string $caminho): array
    {
        $leitor = IOFactory::createReaderForFile($caminho);
        $leitor->setReadDataOnly(true);
        $leitor->setReadFilter(new class implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });
        $planilha = $leitor->load($caminho);

        return $planilha->getSheet(0)->rangeToArray('A1:'.$planilha->getSheet(0)->getHighestColumn().'1', null, true, false)[0] ?? [];
    }
}
