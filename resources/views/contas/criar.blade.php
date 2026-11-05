<x-app-layout titulo="Criar conta">
    <div class="max-w-xl mx-auto px-4 sm:px-6 py-10 space-y-5">
        <div>
            <h1 class="text-3xl font-semibold">Vamos criar a sua conta</h1>
            <p class="mt-2 text-[15px] text-ink-700">A conta é quem paga e compartilha os créditos. Depois você cadastra a empresa que vai emitir as notas.
                Você já começa com <b>{{ (int) config('saas.bonus_cadastro') }} créditos de teste</b>.</p>
        </div>

        <form method="POST" action="{{ route('contas.store') }}" class="card p-6 space-y-4">
            @csrf
            <x-campo nome="nome" rotulo="Nome da conta (empresa ou escritório)" required />
            <x-campo nome="documento" rotulo="CPF ou CNPJ de quem paga" mono required />
            <x-campo nome="email_financeiro" rotulo="E-mail financeiro" tipo="email" :valor="auth()->user()->email" ajuda="Recibos e avisos de saldo vão para este e-mail." required />
            <button class="btn btn-primary btn-lg">Criar conta</button>
        </form>
    </div>
</x-app-layout>
