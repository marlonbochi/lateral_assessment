<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local replica of CMS entities (see specs/001-cms-content-sync/data-model.md §1).
 *
 * CMS-owned columns (cms_id, version, status, payload, cms_timestamp, synced_at)
 * are written only by event processing; override columns (is_disabled,
 * disabled_at, disabled_by) only by administrators. Deleted entities stay as
 * tombstones that keep their deletion version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table): void {
            $table->id();
            $table->string('cms_id', 255);
            $table->bigInteger('version');
            $table->string('status', 16);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('cms_timestamp');
            $table->timestampTz('synced_at');
            $table->boolean('is_disabled')->default(false);
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique('cms_id', 'contents_cms_id_unique');
        });

        DB::statement('ALTER TABLE contents ADD CONSTRAINT contents_version_positive CHECK (version > 0)');
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_status_valid CHECK (status IN ('published', 'unpublished', 'deleted'))");
        DB::statement(<<<'SQL'
            ALTER TABLE contents ADD CONSTRAINT contents_payload_matches_status CHECK (
                (status = 'deleted' AND payload IS NULL) OR (status <> 'deleted' AND payload IS NOT NULL)
            )
            SQL);
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_payload_is_object CHECK (payload IS NULL OR jsonb_typeof(payload) = 'object')");

        // Each consumer listing is an index scan in feed order, with no sort step.
        DB::statement("CREATE INDEX contents_user_feed_idx ON contents (synced_at DESC, cms_id DESC) WHERE status = 'published' AND is_disabled = false");
        DB::statement("CREATE INDEX contents_admin_feed_idx ON contents (synced_at DESC, cms_id DESC) WHERE status <> 'deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
