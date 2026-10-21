<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Content\SetContentDisabled;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The local override as a sub-resource: PUT disables, DELETE re-enables.
 */
final class ContentDisabledController extends Controller
{
    public function __construct(private readonly SetContentDisabled $setDisabled) {}

    public function update(Request $request, string $contentId): ContentResource
    {
        return $this->set($request, $contentId, true);
    }

    public function destroy(Request $request, string $contentId): ContentResource
    {
        return $this->set($request, $contentId, false);
    }

    private function set(Request $request, string $contentId, bool $disabled): ContentResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $content = Content::query()->visibleTo($admin)->where('cms_id', $contentId)->firstOrFail();

        $this->authorize('manageOverride', $content);

        return new ContentResource($this->setDisabled->handle($content, $disabled, $admin));
    }
}
