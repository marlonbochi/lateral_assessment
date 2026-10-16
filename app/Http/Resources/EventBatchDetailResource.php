<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use stdClass;

/**
 * Batch metadata plus an event summary (position, id, type, version,
 * timestamp). Payloads are deliberately left out.
 */
final class EventBatchDetailResource extends EventBatchResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'events' => array_map(fn (int $position, stdClass $event): array => [
                'position' => $position,
                'id' => $event->id ?? null,
                'type' => $event->type ?? null,
                'version' => $event->version ?? null,
                'timestamp' => $event->timestamp ?? null,
            ], array_keys($this->events), $this->events),
        ];
    }
}
