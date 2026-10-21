<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable inbox of acknowledged CMS deliveries (data-model.md §2). A row and
 * its processing job are written in the same transaction, so no acknowledged
 * batch can be lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 16)->default('pending');
            $table->jsonb('events');
            $table->smallInteger('event_count');
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('applied_count')->nullable();
            $table->smallInteger('ignored_count')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('retried_at')->nullable();
            $table->foreignId('retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE event_batches ADD CONSTRAINT event_batches_status_valid CHECK (status IN ('pending', 'processed', 'failed'))");
        DB::statement('ALTER TABLE event_batches ADD CONSTRAINT event_batches_event_count_range CHECK (event_count BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE event_batches ADD CONSTRAINT event_batches_events_is_array CHECK (jsonb_typeof(events) = 'array')");
        DB::statement('CREATE INDEX event_batches_status_received_idx ON event_batches (status, received_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_batches');
    }
};
