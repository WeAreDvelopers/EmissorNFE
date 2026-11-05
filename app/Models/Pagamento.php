<?php

namespace App\Models;

use App\Enums\StatusPagamento;
use App\Models\Concerns\PertenceAConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pagamento extends Model
{
    use PertenceAConta;

    protected $fillable = [
        'conta_id', 'pacote_id', 'user_id', 'valor_centavos', 'creditos', 'validade_dias',
        'status', 'gateway', 'gateway_id', 'pix_copia_cola', 'pix_qr_base64', 'split',
        'expira_em', 'pago_em',
    ];

    protected $casts = [
        'status'    => StatusPagamento::class,
        'split'     => 'array',
        'expira_em' => 'datetime',
        'pago_em'   => 'datetime',
    ];

    protected $hidden = ['pix_qr_base64'];

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(Pacote::class);
    }
}
