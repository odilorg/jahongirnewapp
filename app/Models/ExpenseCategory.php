<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two-level expense taxonomy (Phase 1.6.1):
 *   - Top-level "parent" rows have parent_id = null (slug = salaries,
 *     taxes, utilities, finance, tour_ops, repairs, office, kitchen,
 *     other).
 *   - Concrete spending categories are children of one of those.
 *
 * Doctrine: IDs of legacy categories never change. Reports and
 * cash_expenses keep referencing the same expense_category_id; the
 * new parent_id only adds a grouping dimension on top.
 */
class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'slug',
        'name',
        'display_name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** Convenience: prefer display_name when present, fall back to name. */
    public function getLabelAttribute(): string
    {
        return (string) ($this->display_name ?: $this->name);
    }
}
