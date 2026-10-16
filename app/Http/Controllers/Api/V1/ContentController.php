<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListContentsRequest;
use App\Http\Resources\ContentCollection;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Models\User;
use Illuminate\Http\Request;

final class ContentController extends Controller
{
    public function index(ListContentsRequest $request): ContentCollection
    {
        $query = Content::query()->visibleTo($this->consumer($request))->feedOrder();

        $paginator = $request->usesPageNumbers()
            ? $query->paginate($request->perPage())
            : $query->cursorPaginate($request->perPage());

        return new ContentCollection($paginator->withQueryString());
    }

    /**
     * Resolved through the visibility scope (no implicit model binding), so
     * hidden content is indistinguishable from unknown content: both are 404.
     */
    public function show(Request $request, string $contentId): ContentResource
    {
        $content = Content::query()
            ->visibleTo($this->consumer($request))
            ->where('cms_id', $contentId)
            ->firstOrFail();

        return new ContentResource($content);
    }

    private function consumer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
