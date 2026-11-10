{{-- Campos das telas do Emissor Nacional com os valores desta empresa (veja Emitente::camposFiscais). Só leitura, discreto. --}}
@props(['grupos', 'legenda' => true])
@php
    $dica = [
        'cadastro'  => 'Editável nesta tela',
        'fixo'      => 'Padrão do sistema',
        'bloqueado' => 'Bloqueado no portal',
        'por_nota'  => 'Pode mudar em cada nota',
    ];
@endphp
<div class="space-y-4">
    @if ($legenda)
        <p class="text-[13px] text-ink-700 leading-relaxed">Campos das telas do Emissor Nacional e como saem nas notas desta empresa.</p>
    @endif
    @foreach ($grupos as $titulo => $linhas)
        <div>
            <h3 class="text-[13px] font-semibold text-ink-900 mb-1">{{ $titulo }}</h3>
            <table class="w-full text-[13px]">
                <tbody>
                    @foreach ($linhas as $l)
                        <tr class="border-t border-gray-100 align-top">
                            <td class="py-1.5 pe-3 text-ink-600 w-[42%]">{{ $l['rotulo'] }}</td>
                            <td class="py-1.5 pe-3 text-ink-900 break-words">{{ $l['valor'] }}</td>
                            <td class="py-1.5 text-[12px] text-ink-600 text-right whitespace-nowrap hidden md:table-cell">{{ $dica[$l['origem']] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
