<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HR\StoreJobApplicationAction;
use App\Enums\HR\ExperienceLevel;
use App\Enums\HR\LanguageLevel;
use App\Enums\HR\Position;
use App\Http\Requests\JobApplication\StoreJobApplicationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public job application form (no auth).
 *
 *  GET  /jobs/apply           — render the form (preselect via ?position=...&source=...)
 *  POST /jobs/apply           — submit; delegates to StoreJobApplicationAction
 *  GET  /jobs/apply/success   — "спасибо" page (same page for real + duplicate + honeypot)
 *
 * Phase 1, 2026-05-11.
 */
class JobApplicationController extends Controller
{
    public function __construct(
        private readonly StoreJobApplicationAction $action,
    ) {}

    public function show(Request $request): View
    {
        $rawPosition = $request->query('position');
        $position = $this->resolvePosition(is_string($rawPosition) ? $rawPosition : null);

        return view('jobs.apply', [
            'positions' => Position::publicOptions(),
            'experienceLevels' => ExperienceLevel::publicOptions(),
            'languageLevels' => LanguageLevel::publicOptions(),
            'preselected' => [
                'position' => $position?->value,
                'source' => is_string($request->query('source')) ? $request->query('source') : null,
            ],
            // Schema map drives the conditional position-specific question
            // widget rendering in the Blade view (Alpine.js shows the right
            // input block based on selected position).
            'positionSchemas' => collect(Position::cases())->mapWithKeys(fn (Position $p) => [
                $p->value => [
                    'question' => $p->specificQuestion(),
                    'schema' => $p->answerSchema(),
                ],
            ])->toArray(),
        ]);
    }

    public function store(StoreJobApplicationRequest $request): RedirectResponse
    {
        // Honeypot — bots fill all inputs. Real users never see this
        // hidden field. Silent success (no DB write) so spam bots
        // can't learn the rejection signature.
        if (! empty($request->input('website'))) {
            Log::info('JobApplication: honeypot hit, silent success', [
                'ip' => $request->ip(),
            ]);

            return redirect()->route('jobs.apply.success');
        }

        $this->action->execute(
            $request->validated(),
            $request->file('cv'),
            ['ip' => $request->ip(), 'user_agent' => (string) $request->userAgent()],
        );

        return redirect()->route('jobs.apply.success');
    }

    public function success(): View
    {
        return view('jobs.apply-success');
    }

    private function resolvePosition(?string $raw): ?Position
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return Position::tryFrom($raw);
    }
}
