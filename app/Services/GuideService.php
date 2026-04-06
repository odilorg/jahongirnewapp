<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Guide;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * CRUD and lifecycle management for Guide records.
 *
 * All mutations are logged to the application log channel with actor context.
 * Callers are responsible for authorization before invoking these methods.
 */
class GuideService
{
    /**
     * Create a new guide record.
     *
     * lang_spoken accepts either an array or a comma-separated string ("EN, RU, UZ").
     *
     * @param  array{first_name: string, last_name: string, phone01: string, email: string, lang_spoken?: array|string, phone02?: string|null}  $data
     */
    public function create(array $data, string $actor): Guide
    {
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

        Log::info('GuideService: guide created', [
            'guide_id' => $guide->id,
            'name'     => $guide->full_name,
            'actor'    => $actor,
        ]);

        return $guide;
    }

    /**
     * Update one or more editable fields on an existing guide.
     * Only fields present in $data are touched; unchanged values are skipped.
     *
     * @param  array<string, mixed>  $data  Keys from: first_name, last_name, phone01, phone02, email, lang_spoken
     */
    public function update(Guide $guide, array $data, string $actor): void
    {
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

        Log::info('GuideService: guide updated', [
            'guide_id' => $guide->id,
            'actor'    => $actor,
            'changes'  => $changes,
        ]);
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

        Log::info('GuideService: guide ' . ($active ? 'activated' : 'deactivated'), [
            'guide_id' => $guide->id,
            'name'     => $guide->full_name,
            'actor'    => $actor,
        ]);
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
}
