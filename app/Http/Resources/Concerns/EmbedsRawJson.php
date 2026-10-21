<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Support\Json\RawJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes RawJson values (CMS payloads) into the response exactly as stored.
 */
trait EmbedsRawJson
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        $response = parent::toResponse($request);

        return $response->setJson(RawJson::embed((string) $response->getContent()));
    }
}
