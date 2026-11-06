<!DOCTYPE html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#F4F6F3;font-family:Arial,Helvetica,sans-serif;color:#16201B;">
<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #D9DFD9;border-radius:12px;padding:28px;">
    @if ($teste)
        <p style="margin:0 0 16px;padding:10px 12px;background:#FDECEC;border:1px solid #F5B5B5;border-radius:8px;color:#A61E1E;font-size:13px;font-weight:bold;">
            Documento de teste: emitido em ambiente de homologação, sem valor fiscal.
        </p>
    @endif

    <p style="margin:0 0 14px;font-size:16px;">Olá, {{ $nota->tomador_nome }}.</p>
    <p style="margin:0 0 14px;font-size:15px;line-height:1.5;">
        <b>{{ $empresa->razao_social }}</b> emitiu uma nota fiscal de serviço para você.
    </p>

    <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;">
        <tr><td style="padding:6px 0;color:#4D5A52;">NFS-e nº</td><td style="padding:6px 0;text-align:right;"><b>{{ $nota->numero_nfse }}</b></td></tr>
        <tr><td style="padding:6px 0;color:#4D5A52;border-top:1px solid #E5E9E5;">Valor</td><td style="padding:6px 0;text-align:right;border-top:1px solid #E5E9E5;"><b>R$ {{ number_format($nota->valor, 2, ',', '.') }}</b></td></tr>
        <tr><td style="padding:6px 0;color:#4D5A52;border-top:1px solid #E5E9E5;">Competência</td><td style="padding:6px 0;text-align:right;border-top:1px solid #E5E9E5;">{{ $nota->data_competencia?->format('d/m/Y') }}</td></tr>
        <tr><td style="padding:6px 0;color:#4D5A52;border-top:1px solid #E5E9E5;">Serviço</td><td style="padding:6px 0;text-align:right;border-top:1px solid #E5E9E5;">{{ \Illuminate\Support\Str::limit($nota->descricao, 80) }}</td></tr>
    </table>

    <p style="margin:0 0 14px;font-size:14px;line-height:1.5;">
        Seguem em anexo o <b>DANFSe (PDF)</b>, para leitura e impressão, e o <b>arquivo XML</b> da nota, que você pode repassar ao seu contador.
    </p>

    <p style="margin:0 0 4px;font-size:12px;color:#4D5A52;">Chave de acesso</p>
    <p style="margin:0 0 16px;font-size:12px;font-family:Consolas,monospace;word-break:break-all;">{{ $nota->chave_acesso }}</p>

    <p style="margin:0;font-size:12px;color:#4D5A52;line-height:1.5;">Você pode conferir a autenticidade da nota no portal nacional da NFS-e, informando a chave de acesso.</p>
</div>
</body>
</html>
