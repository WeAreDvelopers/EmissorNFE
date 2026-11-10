{{-- Campos do cliente, na ordem em que se preenche: dados, CEP (busca o endereço), endereço, e-mails e observações.
     Usado no cadastro novo ($criando) e na edição. --}}
@props(['tomador', 'podeEditar' => true, 'criando' => false])
<div class="grid grid-cols-1 sm:grid-cols-6 gap-4" x-data="enderecoCep(@js(route('cep.buscar', '__CEP__')))">
    @if ($criando)
        <x-campo nome="documento" rotulo="CPF ou CNPJ" :valor="$tomador->documento" mono required inputmode="numeric" maxlength="18" autocomplete="off" placeholder="000.000.000-00 ou 00.000.000/0000-00" x-mascara="doc" class="sm:col-span-3" ajuda="Não dá para trocar depois. Cada CPF/CNPJ só pode ser cadastrado uma vez." />
        <div class="hidden sm:block sm:col-span-3"></div>
    @endif

    <x-campo nome="nome" rotulo="Razão social / nome" :valor="$tomador->nome" required :disabled="! $podeEditar" class="sm:col-span-3" />
    <x-campo nome="nome_fantasia" rotulo="Nome fantasia" :valor="$tomador->nome_fantasia" :disabled="! $podeEditar" class="sm:col-span-3" />

    <div class="sm:col-span-2">
        <x-campo nome="cep" rotulo="CEP" :valor="$tomador->cep" mono inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000" x-mascara="cep" :disabled="! $podeEditar"
                 x-on:input="buscar($event.target.value)" />
        <p class="campo-ajuda" x-show="estado" x-cloak aria-live="polite">
            <span x-show="estado === 'buscando'">Buscando o endereço…</span>
            <span x-show="estado === 'ok'" class="text-ok-800">Endereço preenchido. Confira e informe o número.</span>
            <span x-show="estado === 'erro'" class="text-warn-900">CEP não encontrado. Preencha o endereço à mão.</span>
        </p>
    </div>
    <x-campo nome="logradouro" rotulo="Logradouro" :valor="$tomador->logradouro" class="sm:col-span-4" :disabled="! $podeEditar" />
    <x-campo nome="numero" rotulo="Número" :valor="$tomador->numero" class="sm:col-span-2" :disabled="! $podeEditar" />
    <x-campo nome="complemento" rotulo="Complemento" :valor="$tomador->complemento" class="sm:col-span-4" :disabled="! $podeEditar" />
    <x-campo nome="bairro" rotulo="Bairro" :valor="$tomador->bairro" class="sm:col-span-2" :disabled="! $podeEditar" />
    <x-campo nome="municipio" rotulo="Município" :valor="$tomador->municipio" class="sm:col-span-3" :disabled="! $podeEditar"
             :ajuda="$tomador->codigo_municipio ? 'Código IBGE '.$tomador->codigo_municipio : ($tomador->municipio ? 'Município não reconhecido: confira o nome e a UF.' : null)" />
    <x-campo nome="uf" rotulo="UF" :valor="$tomador->uf" maxlength="2" mono class="sm:col-span-1" :disabled="! $podeEditar" />

    <x-campo nome="emails" rotulo="E-mails" :valor="implode(', ', $tomador->emails ?? [])" class="sm:col-span-6" :disabled="! $podeEditar" ajuda="Separe por vírgula. O primeiro é o principal e vai no XML da nota; o aviso de nota emitida vai para todos." />
    <div class="sm:col-span-6">
        <label for="observacoes" class="campo-rotulo">Observações</label>
        <textarea id="observacoes" name="observacoes" rows="3" class="campo-entrada" @disabled(! $podeEditar)>{{ old('observacoes', $tomador->observacoes) }}</textarea>
    </div>
</div>
