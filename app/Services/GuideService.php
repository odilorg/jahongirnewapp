<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Guide;
use App\Models\StaffAuditLog;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD and lifecycle management for Guide records.
 *
 * All mutations are persisted to staff_audit_logs.
 * Callers are responsible for authorization before invoking mutating methods.
 */
class GuideService
{
    /**
     * Create a new guide record.
     *
     * lang_spoken accepts either an array or a comma-separated string ("EN, RU, UZ").
     * Throws if another guide already has the same normalized phone01.
     *
     * @param  array{first_name: string, last_name: string, phone01: string, email: string, lang_spoken?: array|string, phone02?: string|null}  $data
     * @throws \RuntimeException  on duplicate phone
     */
    public function create(array $data, string $actor): Guide
    {
        $this->assertNoDuplicatePhone(phone: trim($data['phone01']), exceptId: null);

        $guide = Guide::create([
            'first_name'  => trim($data['first_name']),
            'last_name'   => trim($data['last_name']),
            'phone01'     => trim($data['phone01']),
            'email'       => trim($data['email']),
            'lang_spoken' => $this->normalizeLangs($data['lang_spoken'] ?? []),
            'phone02'     => isset($data['phone02']) ? trim($data['phone02']) : null,
            'guide_image' => $data['guide_image'] ?? null,
            'is_active'   => true,
        ]);

        StaffAuditLog::record(
            entityType: 'guide',
            entityId:   $guide->id,
            action:     'created',
            changes:    [
                'first_name'  => $guide->first_name,
                'last_name'   => $guide->last_name,
                'phone01'     => $guide->phone01,
                'email'       => $guide->email,
                'lang_spoken' => $guide->lang_spoken,
            ],
            actor:      $actor,
        );

        return $guide;
    }

    /**
     * Update one or more editable fields on an existing guide.
     * Only fields present in $data are touched; unchanged values are skipped.
     *
     * Throws if the new phone01 value is already used by a different guide.
     *
     * @param  array<string, mixed>  $data  Keys from: first_name, last_name, phone01, phone02, email, lang_spoken
     * @throws \RuntimeException  on duplicate phone
     */
    public function update(Guide $guide, array $data, string $actor): void
    {
        if (array_key_exists('phone01', $data) && $data['phone01'] !== null) {
            $this->assertNoDuplicatePhone(phone: trim((string) $data['phone01']), exceptId: $guide->id);
        }

        $scalar  = ['first_name', 'last_name', 'phone01', 'phone02', 'email'];
        $changes = [];

        foreach ($scalar as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $new = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            if ($new !== $guide->$field) {
                $changes[$field] = ['old' => $guide->$field, 'new' => $new];
            }
        }

        if (array_key_exists('lang_spoken', $data)) {
            $new = $this->normalizeLangs($data['lang_spoken']);
            // Compare as sorted arrays to avoid spurious changes
            $oldSorted = $guide->lang_spoken ?? [];
            $newSorted = $new;
            sort($oldSorted);
            sort($newSorted);
            if ($oldSorted !== $newSorted) {
                $changes['lang_spoken'] = ['old' => $guide->lang_spoken, 'new' => $new];
            }
        }

        if (empty($changes)) {
            return;
        }

        $guide->update(array_map(fn ($v) => $v['new'], $changes));

        StaffAuditLog::record(
            entityType: 'guide',
            entityId:   $guide->id,
            action:     'updated',
            changes:    $changes,
            actor:      $actor,
        );
    }

    /**
     * Activate or deactivate a guide.
     * Deactivated guides are hidden from the booking assignment UI.
     */
    public function setActive(Guide $guide, bool $active, string $actor): void
    {
        if ($guide->is_active === $active) {
            return;
        }

        $guide->update(['is_active' => $active]);

        StaffAuditLog::record(
            entityType: 'guide',
            entityId:   $guide->id,
            action:     $active ? 'activated' : 'deactivated',
            changes:    null,
            actor:      $actor,
        );
    }

    /**
     * Hard-delete a guide.
     *
     * Only allowed when the guide has no booking references.
     * Intended for admin use only — not exposed in the Telegram bot.
     *
     * @throws \RuntimeException  if the guide is referenced by bookings
     */
    public function delete(Guide $guide, string $actor): void
    {
        if ($guide->bookings()->exists()) {
            throw new \RuntimeException(
                "Guide #{$guide->id} cannot be deleted: they are referenced by one or more bookings."
            );
        }

        $snapshot = [
            'first_name'  => $guide->first_name,
            'last_name'   => $guide->last_name,
            'phone01'     => $guide->phone01,
            'email'       => $guide->email,
            'lang_spoken' => $guide->lang_spoken,
            'is_active'   => $guide->is_active,
        ];

        $guideId = $guide->id;
        $guide->delete();

        StaffAuditLog::record(
            entityType: 'guide',
            entityId:   $guideId,
            action:     'deleted',
            changes:    $snapshot,
            actor:      $actor,
        );
    }

    /**
     * List guides, optionally filtered to active-only.
     * Results are ordered by first_name.
     */
    public function list(bool $onlyActive = false): Collection
    {
        $query = Guide::orderBy('first_name');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Search guides by normalized phone or partial name (case-insensitive).
     * Returns active and inactive records.
     */
    public function search(string $query): Collection
    {
        $normalizedQuery = PhoneNormalizer::normalize($query);

        return Guide::orderBy('first_name')
            ->where(function ($q) use ($query, $normalizedQuery) {
                $q->where('first_name', 'LIKE', "%{$query}%")
                  ->orWhere('last_name', 'LIKE', "%{$query}%");

                if ($normalizedQuery !== '') {
                    $q->orWhereRaw(
                        "REGEXP_REPLACE(phone01, '[^0-9]', '') LIKE ?",
                        ["%{$normalizedQuery}%"]
                    );
                }
            })
            ->get();
    }

    /**
     * Find a guide by ID or throw if not found.
     *
     * @throws \RuntimeException
     */
    public function find(int $id): Guide
    {
        $guide = Guide::find($id);

        if (! $guide) {
            throw new \RuntimeException("Guide #{$id} not found.");
        }

        return $guide;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Normalize lang_spoken to a clean array of uppercase strings.
     * Accepts either an array or a comma-separated string ("EN, RU, UZ").
     *
     * @param  string|array  $value
     * @return array<string>
     */
    private function normalizeLangs(string|array $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(
            array_map(fn ($s) => strtoupper(trim((string) $s)), $value)
        ));
    }

    /**
     * Throw if another guide already has the same normalized phone.
     *
     * @param  int|null  $exceptId  When updating, the current guide's own ID — skip self-match
     * @throws \RuntimeException
     */
    private function assertNoDuplicatePhone(string $phone, ?int $exceptId): void
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === '') {
            return; // empty/invalid phone — let model validation handle it
        }

        $existing = Guide::all(['id', 'phone01'])
            ->first(function (Guide $g) use ($normalized, $exceptId) {
                return $g->id !== $exceptId
                    && PhoneNormalizer::normalize($g->phone01) === $normalized;
            });

        if ($existing) {
            throw new \RuntimeException(
                "A guide with phone number {$phone} already exists (Guide #{$existing->id})."
            );
        }
    }
}
