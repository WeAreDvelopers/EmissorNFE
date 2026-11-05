<?php

namespace App\Models;

use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;

/** Um "pacote recebido": controla quanto resta e quando vence (consumo FIFO por vencimento). */
class LoteCredito extends Model
{
    use PertenceAConta;

    protected $table = 'lotes_credito';

    protected $fillable = ['conta_id', 'origem', 'pagamento_id', 'quantidade', 'restante', 'expira_em'];

    protected $casts = ['expira_em' => 'datetime'];
}
