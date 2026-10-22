<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * The PHP releases this service may run on: the first patched release of each
 * supported branch. It is the single source of truth for composer.json, the
 * Docker image, CI and the `security:php` command (tests keep them in sync).
 *
 * Raise a minimum whenever a PHP security release fixes a critical issue, and
 * add a branch only after reviewing it. Current baseline: CVE-2026-109618 and
 * CVE-2026-109619 ("php2shell", unauthenticated RCE), fixed in 8.3.36, 8.4.27
 * and 8.5.12 on 2026-10-21. PHP 8.2 is excluded because Laravel 13 needs 8.3+.
 */
final class PhpRuntimePolicy
{
    /** @var array<string, string> branch => first patched release */
    public const array MINIMUM_PATCHED = [
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** @var list<string> */
    public const array ADVISORIES = ['CVE-2026-109618', 'CVE-2026-109619'];

    public static function isPatched(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        $minimum = self::MINIMUM_PATCHED["{$m[1]}.{$m[2]}"] ?? null;

        return $minimum !== null && version_compare("{$m[1]}.{$m[2]}.{$m[3]}", $minimum, '>=');
    }

    /**
     * Each branch from its first patched release up to (not including) the next branch.
     */
    public static function composerConstraint(): string
    {
        return implode(' || ', array_map(fn (string $minimum): string => "~{$minimum}", array_values(self::MINIMUM_PATCHED)));
    }
}
