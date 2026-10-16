<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
final class ContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->cms_id,
            'version' => $this->version,
            // stdClass keeps {} as an object in the JSON response.
            'content' => $this->payload ?? (object) [],
            'cms_updated_at' => $this->cms_timestamp->toISOString(),
            'synced_at' => $this->synced_at->toISOString(),
        ];
    }
}
