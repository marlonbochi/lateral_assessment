<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Security\PhpRuntimePolicy;
use Illuminate\Console\Command;

/**
 * Fails (exit 1) when the PHP runtime is affected by a known critical
 * vulnerability. The Docker build runs it, so a vulnerable image cannot be built.
 */
final class CheckPhpSecurity extends Command
{
    protected $signature = 'security:php
        {--php-version= : Check this version instead of the running one}';

    protected $description = 'Check that the PHP runtime has the latest critical security fixes';

    public function handle(): int
    {
        $option = $this->option('php-version');
        $version = is_string($option) && $option !== '' ? $option : PHP_VERSION;

        if (! PhpRuntimePolicy::isPatched($version)) {
            $this->components->error(sprintf(
                'PHP %s is affected by %s or is not a supported branch. Minimum patched releases: %s.',
                $version,
                implode(', ', PhpRuntimePolicy::ADVISORIES),
                implode(', ', PhpRuntimePolicy::MINIMUM_PATCHED),
            ));

            return self::FAILURE;
        }

        $this->components->info("PHP {$version} is patched against ".implode(', ', PhpRuntimePolicy::ADVISORIES).'.');

        return self::SUCCESS;
    }
}
