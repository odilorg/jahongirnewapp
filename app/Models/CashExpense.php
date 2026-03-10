<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashExpense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cashier_shift_id', 'expense_category_id', 'amount', 'currency',
        'description', 'receipt_photo_path', 'requires_approval',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'created_by', 'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function shift() { return $this->belongsTo(CashierShift::class, 'cashier_shift_id'); }
    public function category() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function isPending(): bool { return !\->approved_at && !\->rejected_at && \->requires_approval; }
}
