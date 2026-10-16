<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObjectList;
use App\Enums\BatchStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EventBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * An acknowledged CMS delivery in the durable inbox.
 *
 * @property string $id
 * @property BatchStatus $status
 * @property list<stdClass> $events
 * @property int $event_count
 * @property int $attempts
 * @property int|null $applied_count
 * @property int|null $ignored_count
 * @property string|null $last_error
 * @property string|null $request_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $retried_at
 * @property int|null $retried_by
 */
class EventBatch extends Model
{
    /** @use HasFactory<EventBatchFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function retriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }

    /**
     * `events` uses an object-preserving cast; the `array` cast would turn
     * empty JSON objects ({}) into lists ([]).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'events' => JsonObjectList::class,
            'event_count' => 'integer',
            'attempts' => 'integer',
            'applied_count' => 'integer',
            'ignored_count' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'retried_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
