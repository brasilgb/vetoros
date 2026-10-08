<?php

namespace App\Models\App;

use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Snapshot de item de uma versão de orçamento (copiado de order_items no momento da versão).
 * Itens de versão enviada não mudam; os de rascunho são substituídos junto com o rascunho.
 */
class OrderBudgetItem extends Model
{
    use Tenantable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Itens de orçamento são snapshots imutáveis.'));
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(OrderBudget::class, 'order_budget_id');
    }
}
