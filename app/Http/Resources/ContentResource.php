<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\EmbedsRawJson;
use App\Models\Content;
use App\Support\Json\RawJson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
final class ContentResource extends JsonResource
{
    use EmbedsRawJson;

    /**
     * The int key holds the conditional MergeValue that Laravel flattens into
     * the administrator fields.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->cms_id,
            'version' => $this->version,
            // Served exactly as stored: no PHP decoding, so {} stays {} and
            // numbers keep their full precision.
            'content' => $this->payload ?? new RawJson('{}'),
            'cms_updated_at' => $this->cms_timestamp->toISOString(),
            'synced_at' => $this->synced_at->toISOString(),
            // Administrator view: role decided through the `admin` gate.
            $this->mergeWhen((bool) $request->user()?->can('admin'), fn (): array => [
                'status' => $this->status->value,
                'local_override' => [
                    'disabled' => $this->is_disabled,
                    'changed_at' => $this->disabled_at?->toISOString(),
                    'changed_by' => $this->disabled_by,
                ],
            ]),
        ];
    }
}
