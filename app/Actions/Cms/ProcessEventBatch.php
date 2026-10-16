<?php

declare(strict_types=1);

namespace App\Actions\Cms;

use App\Domain\Cms\BatchResult;
use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\ContentSnapshot;
use App\Domain\Cms\EventDecision;
use App\Domain\Cms\EventOutcome;
use App\Domain\Cms\EventPrecedence;
use App\Domain\Cms\IgnoreReason;
use App\Enums\BatchStatus;
use App\Enums\ContentStatus;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

/**
 * Applies one batch atomically and in the order received (research R4-R6).
 *
 * Concurrency: transaction-scoped advisory locks are taken on every content id
 * of the batch, in sorted order, before reading anything. They also lock ids
 * that do not exist yet, and the deterministic order prevents deadlocks
 * between batches touching the same ids in different orders. The upsert is
 * additionally guarded by `version < incoming` as defense in depth.
 */
final class ProcessEventBatch
{
    public function __construct(private readonly EventPrecedence $precedence) {}

    public function handle(string $batchId): BatchResult
    {
        return DB::transaction(function () use ($batchId): BatchResult {
            /** @var EventBatch|null $batch */
            $batch = EventBatch::query()->lockForUpdate()->find($batchId);

            // Idempotent: queues deliver at least once.
            if ($batch === null || $batch->status === BatchStatus::Processed) {
                return BatchResult::alreadyProcessed();
            }

            $events = array_map(CmsEvent::fromValidated(...), $batch->events);

            $this->lockContentIds($events);

            $outcomes = [];
            foreach ($events as $position => $event) {
                $outcomes[] = $this->apply($batch->id, $position, $event);
            }

            $result = new BatchResult($outcomes);

            $batch->forceFill([
                'status' => BatchStatus::Processed,
                'applied_count' => $result->applied(),
                'ignored_count' => $result->ignored(),
                'processed_at' => now(),
                'last_error' => null,
            ])->save();

            return $result;
        }, attempts: 3);
    }

    /**
     * @param  list<CmsEvent>  $events
     */
    private function lockContentIds(array $events): void
    {
        $ids = array_values(array_unique(array_map(fn (CmsEvent $e): string => $e->id, $events)));
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$id]);
        }
    }

    private function apply(string $batchId, int $position, CmsEvent $event): EventOutcome
    {
        $previous = $this->snapshot($event->id);
        $decision = $this->precedence->decide($previous, $event);

        if ($decision->apply && $decision->newStatus !== null && ! $this->write($batchId, $position, $event, $decision->newStatus)) {
            // Only reachable if a newer version was written without the lock.
            $decision = EventDecision::ignore(IgnoreReason::Stale);
        }

        return new EventOutcome($position, $event, $previous, $decision);
    }

    private function snapshot(string $cmsId): ?ContentSnapshot
    {
        $row = DB::table('contents')->where('cms_id', $cmsId)->first(['version', 'status']);

        return $row === null ? null : new ContentSnapshot((int) $row->version, ContentStatus::from((string) $row->status));
    }

    /**
     * Writes only CMS-owned columns. The payload is copied inside PostgreSQL
     * from the inbox row, so it never round-trips through PHP. The local
     * override columns are never touched (they keep their value on conflict).
     */
    private function write(string $batchId, int $position, CmsEvent $event, ContentStatus $status): bool
    {
        $affected = DB::affectingStatement(<<<'SQL'
            INSERT INTO contents (cms_id, version, status, payload, cms_timestamp, synced_at, is_disabled, created_at, updated_at)
            SELECT
                CAST(? AS varchar), CAST(? AS bigint), CAST(? AS varchar),
                CASE WHEN CAST(? AS boolean) THEN NULL ELSE b.events -> CAST(? AS integer) -> 'payload' END,
                CAST(? AS timestamptz), now(), false, now(), now()
            FROM event_batches b
            WHERE b.id = CAST(? AS uuid)
            ON CONFLICT (cms_id) DO UPDATE SET
                version = EXCLUDED.version,
                status = EXCLUDED.status,
                payload = EXCLUDED.payload,
                cms_timestamp = EXCLUDED.cms_timestamp,
                synced_at = EXCLUDED.synced_at,
                updated_at = EXCLUDED.updated_at
            WHERE contents.version < EXCLUDED.version
            SQL, [
            $event->id,
            $event->version,
            $status->value,
            $status === ContentStatus::Deleted,
            $position,
            $event->timestamp->format('Y-m-d H:i:s.uP'),
            $batchId,
        ]);

        return $affected === 1;
    }
}
