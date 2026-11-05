<x-mail::message>
# Você foi convidado

Você recebeu um convite para emitir notas fiscais de serviço em uma conta do {{ config('saas.nome_produto', 'Emissor NFS-e') }}.

<x-mail::button :url="$url">
Aceitar convite
</x-mail::button>

Entre (ou cadastre-se) com este mesmo e-mail. O convite vale por 7 dias.
</x-mail::message>
