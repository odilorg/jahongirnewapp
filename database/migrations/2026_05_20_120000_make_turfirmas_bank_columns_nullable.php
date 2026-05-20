<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make turfirmas bank/address columns nullable.
 *
 * The original schema enforced NOT NULL on account_number, bank_mfo,
 * bank_name and address_street. But the upstream TIN-lookup APIs (didox /
 * soliq) commonly return null for some of these (tour-firm records without
 * a published bank account, for instance), and there's no business reason
 * to block a Turfirma row from being created without them — the operator
 * can fill them in later if they ever matter for an invoice.
 *
 * Schema change unblocks contract creation for TINs whose upstream record
 * is partial (incident 2026-05-20, TIN 203360154).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('turfirmas', function (Blueprint $table) {
            $table->string('account_number')->nullable()->change();
            $table->string('bank_mfo')->nullable()->change();
            $table->string('bank_name')->nullable()->change();
            $table->string('address_street')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Roll back to NOT NULL with empty-string default so the migration
        // is reversible even if some rows have null values written under
        // the new schema (those rows would be coerced to '' on rollback).
        Schema::table('turfirmas', function (Blueprint $table) {
            $table->string('account_number')->nullable(false)->default('')->change();
            $table->string('bank_mfo')->nullable(false)->default('')->change();
            $table->string('bank_name')->nullable(false)->default('')->change();
            $table->string('address_street')->nullable(false)->default('')->change();
        });
    }
};
