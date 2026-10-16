<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cms;

use App\Actions\Cms\AcceptEventBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\StoreEventBatchRequest;
use Illuminate\Http\JsonResponse;

final class EventBatchController extends Controller
{
    public function __invoke(StoreEventBatchRequest $request, AcceptEventBatch $accept): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');

        $batch = $accept->handle(
            $request->getContent(),
            count($request->rawEvents()),
            is_string($requestId) ? $requestId : null,
        );

        return new JsonResponse([
            'data' => [
                'batch_id' => $batch->id,
                'status' => $batch->status->value,
                'event_count' => $batch->event_count,
                'received_at' => $batch->received_at->utc()->toIso8601ZuluString(),
            ],
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
