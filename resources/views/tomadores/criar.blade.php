<x-app-layout titulo="Novo cliente">
    <div class="max-w-[820px] mx-auto px-4 sm:px-8 py-8 space-y-6">
        <div class="space-y-2">
            <a href="{{ route('tomadores.index') }}" class="link text-sm">← Clientes</a>
            <h1 class="text-3xl font-semibold">Novo cliente</h1>
            <p class="text-[15px] text-ink-700">Só o CPF/CNPJ e o nome são obrigatórios. Com o endereço completo (comece pelo CEP), a nota já sai com o endereço do cliente.</p>
        </div>

        <form method="POST" action="{{ route('tomadores.store') }}" class="card p-5 sm:p-6 space-y-4">
            @csrf
            @include('tomadores._campos', ['tomador' => $tomador, 'podeEditar' => true, 'criando' => true])
            @if ($errors->any())<div class="rounded-lg bg-warn-50 border border-warn-200 px-4 py-3 text-sm text-warn-900" role="alert">{{ $errors->first() }}</div>@endif
            <div class="flex flex-wrap gap-3">
                <button class="btn btn-primary btn-lg">Cadastrar cliente</button>
                <a href="{{ route('tomadores.index') }}" class="btn btn-lg">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
