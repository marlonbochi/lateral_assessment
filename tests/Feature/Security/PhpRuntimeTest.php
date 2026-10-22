<?php

declare(strict_types=1);

use App\Support\Security\PhpRuntimePolicy;
use Illuminate\Support\Facades\Artisan;

it('runs on a PHP release without known critical vulnerabilities', function (): void {
    expect(PhpRuntimePolicy::isPatched(PHP_VERSION))
        ->toBeTrue('PHP '.PHP_VERSION.' is affected by '.implode(', ', PhpRuntimePolicy::ADVISORIES).'; upgrade it.');
});

it('declares the patched versions in composer.json', function (): void {
    /** @var array{require: array{php: string}, config: array{platform: array{php: string}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['require']['php'])->toBe(PhpRuntimePolicy::composerConstraint())
        ->and(PhpRuntimePolicy::isPatched($composer['config']['platform']['php']))->toBeTrue();
});

it('builds every Docker stage from a pinned, patched PHP image', function (): void {
    preg_match_all('/^FROM php:(\S+?)-fpm-alpine\b/m', (string) file_get_contents(base_path('docker/php/Dockerfile')), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $tag) {
        expect($tag)->toMatch('/^\d+\.\d+\.\d+$/', "php:{$tag} must pin an exact patch release")
            ->and(PhpRuntimePolicy::isPatched($tag))->toBeTrue("php:{$tag} is not patched");
    }
});

it('hardens php.ini against the request-parsing attack paths', function (string $directive, string $value): void {
    expect((string) file_get_contents(base_path('docker/php/php.ini')))
        ->toMatch('/^'.preg_quote($directive, '/').'\s*=\s*'.preg_quote($value, '/').'\s*$/m');
})->with([
    // The API only reads JSON from php://input, so PHP never has to parse form or multipart bodies.
    'no automatic POST/multipart parsing' => ['enable_post_data_reading', 'Off'],
    'no file uploads' => ['file_uploads', 'Off'],
    'no session upload progress' => ['session.upload_progress.enabled', 'Off'],
]);

it('reports the runtime status through security:php', function (string $version, int $exitCode, string $message): void {
    expect(Artisan::call('security:php', ['--php-version' => $version]))->toBe($exitCode)
        ->and(Artisan::output())->toContain($message);
})->with([
    'patched' => ['8.4.27', 0, 'PHP 8.4.27 is patched'],
    'vulnerable' => ['8.4.26', 1, 'PHP 8.4.26 is affected by CVE-2026-109618, CVE-2026-109619'],
]);

it('checks the running PHP version by default', function (): void {
    Artisan::call('security:php');

    expect(Artisan::output())->toContain('PHP '.PHP_VERSION);
});
