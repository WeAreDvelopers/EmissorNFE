<?php

namespace App\Enums;

enum StatusNota: string
{
    case Rascunho   = 'rascunho';    // válida e conferida, mas o usuário ainda não confirmou o envio (sem crédito reservado)
    case Invalida   = 'invalida';    // linha da planilha não passou na validação
    case AguardandoCredito = 'aguardando_credito'; // válida, mas sem saldo; volta à fila após recarga
    case Pendente   = 'pendente';    // aguardando na fila
    case Enviando   = 'enviando';    // job em execução
    case Emitida    = 'emitida';     // NFS-e autorizada
    case Rejeitada  = 'rejeitada';   // SEFIN recusou (erro de negócio — corrigir dados)
    case Falhou     = 'falhou';      // erro técnico após todas as tentativas
    case Cancelada  = 'cancelada';   // NFS-e emitida e depois cancelada (evento 101101); não conta como emitida

    /** Texto para o usuário (sem jargão). */
    public function label(): string
    {
        return match ($this) {
            self::Rascunho  => 'Pronta para enviar',
            self::Invalida  => 'Dado inválido',
            self::AguardandoCredito => 'Aguardando crédito',
            self::Pendente  => 'Na fila',
            self::Enviando  => 'Enviando',
            self::Emitida   => 'Emitida',
            self::Rejeitada => 'Recusada pela prefeitura',
            self::Falhou    => 'Não saiu: instabilidade',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Os 3 grupos que o usuário enxerga: emitida | andamento | atencao (precisa de ação). */
    public function grupo(): string
    {
        return match ($this) {
            self::Emitida, self::Cancelada => 'emitida',   // ciclo concluído (a cancelada só muda o selo)
            self::Invalida, self::Rejeitada, self::Falhou => 'atencao',
            default => 'andamento',
        };
    }

    /** Estados em que o usuário precisa agir. */
    public static function atencao(): array
    {
        return [self::Invalida, self::Rejeitada, self::Falhou];
    }

    /** Estados "em andamento" para contagem (Rascunho não conta: ainda nem foi enviado). */
    public static function andamento(): array
    {
        return [self::AguardandoCredito, self::Pendente, self::Enviando];
    }

    /** Classes do selo (cores com contraste AA). */
    public function selo(): string
    {
        if ($this === self::Cancelada) {
            return 'bg-perigo-600 text-white';
        }

        return match ($this->grupo()) {
            'emitida' => 'bg-ok-100 text-ok-800',
            'atencao' => 'bg-warn-100 text-warn-800',
            default   => $this === self::AguardandoCredito ? 'bg-amber-100 text-amber-900' : 'bg-info-100 text-info-800',
        };
    }
}
