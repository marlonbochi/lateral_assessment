<?php

declare(strict_types=1);

use App\Rules\JsonObject;
use Illuminate\Support\Facades\Validator;

it('accepts JSON objects, including the empty object', function (object $value): void {
    expect(Validator::make(['payload' => $value], ['payload' => [new JsonObject]])->passes())->toBeTrue()
        ->and(JsonObject::passes($value))->toBeTrue();
})->with([
    'empty object' => [new stdClass],
    'object' => [(object) ['a' => 1]],
]);

it('rejects lists, scalars and null', function (array|string|null $value): void {
    $validator = Validator::make(['payload' => $value], ['payload' => ['present', new JsonObject]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('payload'))->toBe('The payload must be a JSON object.')
        ->and(JsonObject::passes($value))->toBeFalse();
})->with([
    'empty list' => [[]],
    'list' => [[1, 2]],
    'string' => ['text'],
    'null' => [null],
]);
