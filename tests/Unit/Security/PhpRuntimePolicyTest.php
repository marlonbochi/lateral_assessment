<?php

declare(strict_types=1);

use App\Support\Security\PhpRuntimePolicy;

it('accepts only patched releases of the supported branches', function (string $version, bool $patched): void {
    expect(PhpRuntimePolicy::isPatched($version))->toBe($patched);
})->with([
    // CVE-2026-109618 / CVE-2026-109619 ("php2shell"), fixed on 2026-10-21.
    '8.3 before the fix' => ['8.3.35', false],
    '8.3 fixed' => ['8.3.36', true],
    '8.4 before the fix' => ['8.4.26', false],
    '8.4 fixed' => ['8.4.27', true],
    '8.4 later patch' => ['8.4.30', true],
    '8.5 before the fix' => ['8.5.11', false],
    '8.5 fixed' => ['8.5.12', true],
    'unsupported branch (Laravel 13 needs 8.3+)' => ['8.2.35', false],
    'unreviewed future branch' => ['8.6.0', false],
    'build suffix is ignored' => ['8.4.27-dev', true],
]);

it('derives the Composer constraint from the minimum patched versions', function (): void {
    expect(PhpRuntimePolicy::composerConstraint())->toBe('~8.3.36 || ~8.4.27 || ~8.5.12');
});

it('names the advisories it guards against', function (): void {
    expect(PhpRuntimePolicy::ADVISORIES)->toContain('CVE-2026-109618', 'CVE-2026-109619');
});
