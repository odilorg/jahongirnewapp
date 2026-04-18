<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use Symfony\Component\Finder\Finder;

/**
 * L-017 — scans PHP source files for ledger-discipline rule violations.
 *
 * Invoked by `php artisan ledger:guard`. Takes a list of rules (from
 * config/ledger-discipline.php), walks `scan_roots`, and returns a
 * flat list of violations with per-rule severity.
 *
 * Pure scan logic — no Laravel runtime deps beyond Symfony Finder.
 * Kept separate from the command so tests can drive the scanner with
 * a fixture directory.
 */
final class LedgerDisciplineScanner
{
    /**
     * @param  array<int, array{
     *     id: string,
     *     severity: 'strict'|'warn',
     *     description: string,
     *     pattern: string,
     *     allowed_path_prefixes?: list<string>,
     *     baseline_files?: list<string>,
     *     remediation?: string,
     *   }>  $rules
     * @param  list<string>  $scanRoots  Relative paths under $baseDir
     * @param  string        $baseDir    Absolute project root (no trailing slash)
     * @return array{
     *     violations: list<array{
     *         rule_id: string,
     *         severity: 'strict'|'warn',
     *         description: string,
     *         file: string,
     *         line: int,
     *         match: string,
     *         remediation: string|null,
     *     }>,
     *     files_scanned: int,
     * }
     */
    public function scan(array $rules, array $scanRoots, string $baseDir): array
    {
        $violations   = [];
        $filesScanned = 0;

        foreach ($scanRoots as $root) {
            $abs = rtrim($baseDir, '/') . '/' . $root;
            if (! is_dir($abs)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in($abs)
                ->name('*.php')
                ->ignoreUnreadableDirs();

            foreach ($finder as $file) {
                $filesScanned++;
                $absPath = $file->getRealPath();
                $relPath = self::relative($absPath, $baseDir);
                $raw     = $file->getContents();

                // Strip PHP comments but preserve line numbers so reported
                // lines still point at the original source. A `LedgerEntry::create`
                // mention inside a docblock is NOT a violation — only real
                // static-method calls in code should trip the rules.
                $content = self::stripCommentsPreservingLines($raw);

                foreach ($rules as $rule) {
                    if (self::isAllowed($relPath, $rule)) {
                        continue;
                    }

                    if (preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE) > 0) {
                        foreach ($matches[0] as $match) {
                            [$text, $offset] = $match;
                            $violations[] = [
                                'rule_id'     => $rule['id'],
                                'severity'    => $rule['severity'],
                                'description' => $rule['description'],
                                'file'        => $relPath,
                                'line'        => self::lineOfOffset($content, $offset),
                                'match'       => trim($text),
                                'remediation' => $rule['remediation'] ?? null,
                            ];
                        }
                    }
                }
            }
        }

        // Sort for deterministic output: by rule_id, then file, then line.
        usort($violations, function ($a, $b) {
            return [$a['rule_id'], $a['file'], $a['line']]
               <=> [$b['rule_id'], $b['file'], $b['line']];
        });

        return [
            'violations'    => $violations,
            'files_scanned' => $filesScanned,
        ];
    }

    /**
     * A file is allowed for a rule if it lives under one of the rule's
     * allowed path prefixes OR appears in the rule's baseline files.
     */
    private static function isAllowed(string $relPath, array $rule): bool
    {
        foreach ($rule['allowed_path_prefixes'] ?? [] as $prefix) {
            // Allow both directory prefixes ("app/Actions/Ledger/") and
            // explicit file paths ("app/Foo/Bar.php") in the same list.
            if (str_starts_with($relPath, $prefix)) {
                return true;
            }
        }
        foreach ($rule['baseline_files'] ?? [] as $allowedFile) {
            if ($relPath === $allowedFile) {
                return true;
            }
        }
        return false;
    }

    private static function relative(string $absPath, string $baseDir): string
    {
        $baseDir = rtrim($baseDir, '/') . '/';
        if (str_starts_with($absPath, $baseDir)) {
            return substr($absPath, strlen($baseDir));
        }
        return $absPath;
    }

    private static function lineOfOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    /**
     * Return $content with every PHP comment (T_COMMENT + T_DOC_COMMENT)
     * replaced by whitespace of equivalent length — newlines kept so
     * that line numbers are unchanged. Strings, heredocs and real code
     * tokens are untouched.
     *
     * Uses the PHP tokenizer so regex matches land only on real code.
     */
    private static function stripCommentsPreservingLines(string $content): string
    {
        // Handle files that accidentally omit the opening PHP tag. The
        // tokenizer only looks at content inside PHP tag boundaries.
        if (! str_contains($content, '<' . '?')) {
            return $content;
        }

        $tokens = @token_get_all($content);
        if ($tokens === false) {
            return $content;   // fallback: scan the raw content
        }

        $out = '';
        foreach ($tokens as $t) {
            if (is_array($t)) {
                [$id, $text] = $t;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    // Replace the comment with the same number of newlines
                    // so line numbers for subsequent tokens are preserved.
                    $newlines = substr_count($text, "\n");
                    $out .= str_repeat("\n", $newlines);
                    // Pad with spaces to preserve column offsets within
                    // the original comment's final line.
                    $lastNl = strrpos($text, "\n");
                    $trailing = $lastNl === false ? strlen($text) : strlen($text) - $lastNl - 1;
                    $out .= str_repeat(' ', $trailing);
                } else {
                    $out .= $text;
                }
            } else {
                $out .= $t;
            }
        }

        return $out;
    }
}
