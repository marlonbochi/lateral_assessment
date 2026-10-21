<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `cms_timestamp` was created as timestamptz(0), which rounds the fractional
 * seconds the CMS sends. The data model specifies a plain timestamptz
 * (microsecond precision), so the column is widened to match it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contents ALTER COLUMN cms_timestamp TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contents ALTER COLUMN cms_timestamp TYPE timestamptz(0)');
    }
};
