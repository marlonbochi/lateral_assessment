<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('declares strict types in every application class', function (): void {
    expect('App')->toUseStrictTypes();
});

it('keeps the domain layer framework-free', function (): void {
    expect('App\Domain')->not->toUse(['Illuminate', 'App\Models', 'App\Http', 'App\Actions', 'App\Jobs']);
});

it('never lets controllers talk to the database directly', function (): void {
    expect('App\Http\Controllers')->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\DatabaseManager']);
});

it('uses backed enums for closed sets', function (): void {
    expect('App\Enums')->toBeEnums();
});

it('keeps actions final and single-purpose', function (): void {
    expect('App\Actions')->toBeFinal()->toHaveMethod('handle');
});

it('leaves no debugging helpers behind', function (): void {
    expect(['dd', 'dump', 'var_dump', 'ray', 'die'])->not->toBeUsed();
});

/*
 * Pest arch expectations work on class/namespace dependencies, not method
 * calls, so this rule is a plain scan: the raw role check may only be used by
 * the `admin` gate; everything else must ask the gate (constitution, II).
 */
it('only lets the admin gate call User::isAdmin()', function (): void {
    $offenders = collect((new Finder)->files()->in(app_path())->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), '->isAdmin('))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe(['app/Providers/AppServiceProvider.php']);
});
