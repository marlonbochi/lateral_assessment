<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Cms\RetryEventBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEventBatchesRequest;
use App\Http\Resources\EventBatchCollection;
use App\Http\Resources\EventBatchDetailResource;
use App\Http\Resources\EventBatchResource;
use App\Models\EventBatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrator inspection and recovery of ingested batches.
 */
final class EventBatchController extends Controller
{
    public function index(ListEventBatchesRequest $request): EventBatchCollection
    {
        $this->authorize('viewAny', EventBatch::class);

        $batches = EventBatch::query()
            ->when($request->statusFilter(), fn ($q, $status) => $q->where('status', $status->value))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return new EventBatchCollection($batches->withQueryString());
    }

    public function show(EventBatch $batch): EventBatchDetailResource
    {
        $this->authorize('view', $batch);

        return new EventBatchDetailResource($batch);
    }

    public function retry(Request $request, EventBatch $batch, RetryEventBatch $retry): JsonResponse
    {
        $this->authorize('retry', $batch);

        /** @var User $admin */
        $admin = $request->user();

        return (new EventBatchResource($retry->handle($batch, $admin)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_ACCEPTED);
    }
}
