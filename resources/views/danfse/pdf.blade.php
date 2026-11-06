<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>DANFSe {{ $d['numero'] }}</title>
<style>
    @page { margin: 22px 24px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6px; color: #16201B; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .caixa { border: 0.8px solid #7b857f; }
    .topo td { padding: 7px 9px; }
    .titulo { text-align: center; font-size: 13px; font-weight: bold; line-height: 1.25; }
    .sub { font-size: 7.6px; color: #4D5A52; }
    .secao { background: #E9F0EC; font-weight: bold; font-size: 8.4px; padding: 4px 8px; border: 0.8px solid #7b857f; border-bottom: 0; }
    .campo { padding: 4px 8px 5px; border: 0.8px solid #cdd4cf; }
    .rotulo { font-size: 7px; color: #4D5A52; letter-spacing: .2px; display: block; margin-bottom: 1px; }
    .valor { font-size: 9px; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .forte { font-weight: bold; }
    .chave { font-size: 10px; letter-spacing: .6px; word-break: break-all; }
    .descricao { padding: 6px 8px; border: 0.8px solid #cdd4cf; line-height: 1.45; min-height: 44px; }
    .totais td { border: 0.8px solid #cdd4cf; padding: 6px 8px; }
    .totais .grande { font-size: 12px; font-weight: bold; }
    .aviso { margin-top: 10px; padding: 7px 9px; border: 1px solid #C62828; color: #A61E1E; font-weight: bold; text-align: center; font-size: 9px; }
    .rodape { margin-top: 10px; font-size: 7px; color: #4D5A52; text-align: center; }
    .espaco { height: 7px; }
</style>
</head>
<body>

<table class="caixa topo">
    <tr>
        <td style="width: 24%;"><div class="forte" style="font-size: 16px; color: #0B6B4D;">NFS-e</div><div class="sub">Nota Fiscal de Serviço eletrônica</div></td>
        <td class="titulo">DANFSe v2.0<br><span style="font-size: 10px; font-weight: normal;">Documento Auxiliar da NFS-e</span></td>
        <td style="width: 24%; text-align: right;" class="sub">Município: <b>{{ $d['municipio_emissor'] ?? '-' }}</b><br>Ambiente gerador: {{ $d['ambiente'] }}<br>Tipo de ambiente: {{ $d['ambiente'] === 1 ? 'Produção' : 'Homologação' }}</td>
    </tr>
</table>

<table class="caixa" style="margin-top: 6px;">
    <tr>
        <td class="campo" style="width: 78%;">
            <span class="rotulo">Chave de acesso da NFS-e</span>
            <span class="valor mono chave">{{ $d['chave'] }}</span>
            <div class="sub" style="margin-top: 5px;">A autenticidade desta NFS-e pode ser verificada pela leitura do QR Code ao lado ou pela consulta da chave de acesso no portal nacional da NFS-e.</div>
        </td>
        <td class="campo" style="text-align: center;"><img src="{{ $qr }}" alt="QR Code" style="width: 84px; height: 84px;"></td>
    </tr>
</table>

<table style="margin-top: 6px;">
    <tr>
        <td class="campo" style="width: 22%;"><span class="rotulo">Número da NFS-e</span><span class="valor forte">{{ $d['numero'] ?? '-' }}</span></td>
        <td class="campo" style="width: 26%;"><span class="rotulo">Competência da NFS-e</span><span class="valor">{{ $rotulos->data($d['dps']['competencia']) }}</span></td>
        <td class="campo"><span class="rotulo">Data e hora da emissão da NFS-e</span><span class="valor">{{ $rotulos->dataHora($d['processada_em']) }}</span></td>
    </tr>
    <tr>
        <td class="campo"><span class="rotulo">Número da DPS</span><span class="valor">{{ $d['dps']['numero'] ?? '-' }}</span></td>
        <td class="campo"><span class="rotulo">Série da DPS</span><span class="valor">{{ $d['dps']['serie'] ?? '-' }}</span></td>
        <td class="campo"><span class="rotulo">Data e hora da emissão da DPS</span><span class="valor">{{ $rotulos->dataHora($d['dps']['emitida_em']) }}</span></td>
    </tr>
    <tr>
        <td class="campo"><span class="rotulo">Emitente da NFS-e</span><span class="valor">{{ $rotulos->emitente($d['dps']['emitente']) }}</span></td>
        <td class="campo"><span class="rotulo">Situação da NFS-e</span><span class="valor">{{ $rotulos->situacao($d['situacao_codigo']) }}</span></td>
        <td class="campo"><span class="rotulo">Finalidade</span><span class="valor">NFS-e regular</span></td>
    </tr>
</table>

<div class="espaco"></div>
<div class="secao">Prestador / Fornecedor</div>
<table>
    <tr>
        <td class="campo" style="width: 28%;"><span class="rotulo">CNPJ / CPF</span><span class="valor mono">{{ $rotulos->documento($d['prestador']['documento']) }}</span></td>
        <td class="campo" style="width: 42%;"><span class="rotulo">Nome / Nome empresarial</span><span class="valor forte">{{ $d['prestador']['nome'] ?? '-' }}</span></td>
        <td class="campo"><span class="rotulo">Município / UF</span><span class="valor">{{ $d['municipio_emissor'] ?? '-' }} / {{ $d['prestador']['uf'] ?? '-' }}</span></td>
    </tr>
    <tr>
        <td class="campo" colspan="2"><span class="rotulo">Endereço</span><span class="valor">{{ collect([$d['prestador']['logradouro'], $d['prestador']['numero'], $d['prestador']['complemento'], $d['prestador']['bairro']])->filter()->implode(', ') ?: '-' }}</span></td>
        <td class="campo"><span class="rotulo">CEP</span><span class="valor mono">{{ $rotulos->cep($d['prestador']['cep']) }}</span></td>
    </tr>
    <tr>
        <td class="campo" colspan="2"><span class="rotulo">Simples Nacional na data de competência</span><span class="valor">{{ $rotulos->simples($d['prestador']['simples']) }}</span></td>
        <td class="campo"><span class="rotulo">E-mail / Telefone</span><span class="valor">{{ collect([$d['prestador']['email'], $d['prestador']['telefone']])->filter()->implode(' · ') ?: '-' }}</span></td>
    </tr>
    <tr>
        <td class="campo" colspan="3"><span class="rotulo">Regime de apuração tributária pelo Simples Nacional</span><span class="valor">{{ $rotulos->apuracao($d['prestador']['apuracao_sn']) }}</span></td>
    </tr>
</table>

<div class="espaco"></div>
<div class="secao">Tomador / Adquirente</div>
<table>
    <tr>
        <td class="campo" style="width: 28%;"><span class="rotulo">CNPJ / CPF</span><span class="valor mono">{{ $rotulos->documento($d['tomador']['documento']) }}</span></td>
        <td class="campo" style="width: 42%;"><span class="rotulo">Nome / Nome empresarial</span><span class="valor forte">{{ $d['tomador']['nome'] ?? '-' }}</span></td>
        <td class="campo"><span class="rotulo">E-mail</span><span class="valor">{{ $d['tomador']['email'] ?? '-' }}</span></td>
    </tr>
</table>
<div class="sub" style="padding: 3px 8px; border: 0.8px solid #cdd4cf; border-top: 0;">Destinatário e intermediário da operação não identificados na NFS-e.</div>

<div class="espaco"></div>
<div class="secao">Serviço prestado</div>
<table>
    <tr>
        <td class="campo" style="width: 34%;"><span class="rotulo">Código de tributação nacional / municipal</span><span class="valor mono">{{ $rotulos->codigoServico($d['servico']['codigo_nacional']) }} / {{ $d['servico']['codigo_municipal'] ?? '-' }}</span></td>
        <td class="campo" style="width: 26%;"><span class="rotulo">Código da NBS</span><span class="valor mono">{{ $rotulos->nbs($d['servico']['nbs']) }}</span></td>
        <td class="campo"><span class="rotulo">Local da prestação</span><span class="valor">{{ $d['local_prestacao'] ?? '-' }}</span></td>
    </tr>
    <tr>
        <td class="campo" colspan="3"><span class="valor">{{ $d['trib_nac_descricao'] ?? '-' }}</span></td>
    </tr>
</table>
<div class="rotulo" style="margin: 6px 0 2px 2px;">Descrição do serviço</div>
<div class="descricao valor">{{ $d['servico']['descricao'] ?? '-' }}</div>

<div class="espaco"></div>
<div class="secao">Tributação municipal (ISSQN)</div>
<table>
    <tr>
        <td class="campo" style="width: 34%;"><span class="rotulo">Tipo de tributação do ISSQN</span><span class="valor">{{ $rotulos->tipoTributacao($d['tributacao']['tipo']) }}</span></td>
        <td class="campo"><span class="rotulo">Município de incidência do ISSQN</span><span class="valor">{{ $d['municipio_incidencia'] ?? '-' }}</span></td>
        <td class="campo" style="width: 26%;"><span class="rotulo">Retenção do ISSQN</span><span class="valor">{{ $rotulos->retencao($d['tributacao']['retencao']) }}</span></td>
    </tr>
    <tr>
        <td class="campo"><span class="rotulo">Base de cálculo do ISSQN</span><span class="valor">{{ $rotulos->dinheiro($d['tributacao']['base']) }}</span></td>
        <td class="campo"><span class="rotulo">Alíquota aplicada</span><span class="valor">{{ $d['tributacao']['aliquota'] ? number_format((float) $d['tributacao']['aliquota'], 2, ',', '.').' %' : '-' }}</span></td>
        <td class="campo"><span class="rotulo">ISSQN apurado</span><span class="valor">{{ $rotulos->dinheiro($d['tributacao']['issqn']) }}</span></td>
    </tr>
</table>

<div class="espaco"></div>
<div class="secao">Valor total da NFS-e</div>
<table class="totais">
    <tr>
        <td><span class="rotulo">Valor do serviço</span><span class="valor">{{ $rotulos->dinheiro($d['valores']['servico']) }}</span></td>
        <td><span class="rotulo">Desconto incondicionado</span><span class="valor">{{ $rotulos->dinheiro($d['valores']['desconto']) }}</span></td>
        <td style="background: #F4F6F3;"><span class="rotulo">Valor líquido da NFS-e</span><span class="grande">{{ $rotulos->dinheiro($d['valores']['liquido'] ?? $d['valores']['servico']) }}</span></td>
    </tr>
</table>

@if ($teste)
    <div class="aviso">DOCUMENTO EMITIDO EM AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div>
@endif

<div class="rodape">DANFSe gerado a partir do XML da NFS-e autorizada · Chave de acesso {{ $d['chave'] }}</div>

</body>
</html>
