<?php

namespace App\Services\Nfse;

use App\Models\NotaFiscal;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * DANFSE: versão impressa (PDF) da NFS-e, montada a partir do XML autorizado pela SEFIN.
 * Layout próprio, seguindo os blocos do DANFSE oficial (chave, prestador, tomador, serviço, tributação, totais).
 */
class Danfse
{
    /** Consulta pública da NFS-e Nacional por chave de acesso (o QR Code aponta para ela). */
    public const CONSULTA = [
        1 => 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=',
        2 => 'https://www.producaorestrita.nfse.gov.br/ConsultaPublica/?tpc=1&chave=',
    ];

    public function nomeArquivo(NotaFiscal $nota, string $extensao): string
    {
        return 'NFSe-'.($nota->numero_nfse ?: $nota->id).'-'.substr((string) $nota->chave_acesso, -8).'.'.$extensao;
    }

    public function pdf(NotaFiscal $nota): string
    {
        if (! $nota->xml_nfse) {
            throw new RuntimeException('Esta nota ainda não tem o XML da NFS-e.');
        }

        $d = NfseXml::ler($nota->xml_nfse);
        $d['chave'] ??= $nota->chave_acesso;

        $html = view('danfse.pdf', [
            'd'        => $d,
            'qr'       => $this->qrCode(self::CONSULTA[$d['ambiente']] ?? self::CONSULTA[2], (string) $d['chave']),
            'rotulos'  => $this,
            'teste'    => $d['ambiente'] !== 1,
        ])->render();

        $opcoes = new Options();
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('defaultFont', 'DejaVu Sans');
        $opcoes->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($opcoes);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function qrCode(string $base, string $chave): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(180, 0), new SvgImageBackEnd())))->writeString($base.$chave);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    // ---------- formatadores usados pelo layout ----------

    public function documento(?string $d): string
    {
        $d = preg_replace('/\D/', '', (string) $d);

        return match (strlen($d)) {
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d),
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d),
            default => $d !== '' ? $d : '-',
        };
    }

    public function dinheiro(?string $v): string
    {
        return $v === null || $v === '' ? '-' : 'R$ '.number_format((float) $v, 2, ',', '.');
    }

    public function dataHora(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') : '-';
    }

    public function data(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->format('d/m/Y') : '-';
    }

    public function cep(?string $cep): string
    {
        return $cep && strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : ($cep ?: '-');
    }

    public function codigoServico(?string $c): string
    {
        return $c && strlen($c) === 6 ? substr($c, 0, 2).'.'.substr($c, 2, 2).'.'.substr($c, 4, 2) : ($c ?: '-');
    }

    public function nbs(?string $c): string
    {
        return $c && strlen($c) === 9 ? substr($c, 0, 1).'.'.substr($c, 1, 4).'.'.substr($c, 5, 2).'.'.substr($c, 7, 2) : ($c ?: '-');
    }

    public function simples(?string $v): string
    {
        return match ($v) {
            '1' => 'Não optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
            default => '-',
        };
    }

    public function apuracao(?string $v): string
    {
        return match ($v) {
            '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
            '2' => 'Tributos federais pelo Simples Nacional e ISSQN por fora do Simples',
            '3' => 'Tributos federais e municipal por fora do Simples Nacional',
            default => '-',
        };
    }

    public function tipoTributacao(?string $v): string
    {
        return match ($v) {
            '1' => 'Operação tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de serviço',
            '4' => 'Não incidência',
            default => '-',
        };
    }

    public function retencao(?string $v): string
    {
        return match ($v) {
            '1' => 'Não retido',
            '2' => 'Retido pelo tomador',
            '3' => 'Retido pelo intermediário',
            default => '-',
        };
    }

    public function emitente(?string $v): string
    {
        return match ($v) {
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
            default => '-',
        };
    }

    public function situacao(?string $cStat): string
    {
        return $cStat === '100' ? 'NFS-e gerada' : ($cStat ? 'Código '.$cStat : '-');
    }
}
