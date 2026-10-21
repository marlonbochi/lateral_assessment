<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EventBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Batch metadata only: event payloads are never exposed.
 *
 * @mixin EventBatch
 */
class EventBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'event_count' => $this->event_count,
            'attempts' => $this->attempts,
            'applied_count' => $this->applied_count,
            'ignored_count' => $this->ignored_count,
            'last_error' => $this->last_error,
            'received_at' => $this->received_at->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'retried_at' => $this->retried_at?->toISOString(),
        ];
    }
}
