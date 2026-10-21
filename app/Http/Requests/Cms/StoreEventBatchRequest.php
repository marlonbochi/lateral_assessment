<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\CmsEventType;
use App\Rules\JsonObject;
use App\Rules\Rfc3339DateTime;
use App\Rules\StrictPositiveInteger;
use App\Support\Json\JsonPayload;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;
use stdClass;

/**
 * Validates a whole CMS batch before anything is stored: one invalid event
 * rejects the batch.
 *
 * The raw body is decoded twice, because Laravel's validator only traverses
 * arrays: an associative decode drives the standard rules, and an object
 * decode is the only source used to check that payloads are JSON objects
 * (an associative decode cannot tell {} from []).
 */
final class StoreEventBatchRequest extends FormRequest
{
    public const int MAX_EVENTS = 100;

    private bool $malformed = false;

    /** @var list<stdClass> */
    private array $rawEvents = [];

    public function authorize(): bool
    {
        // Authentication is enforced by the AuthenticateCms middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->getContent();

        try {
            $assoc = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $objects = JsonPayload::decodeBody($body);
        } catch (JsonException) {
            $this->malformed = true;
            $this->replace([]);

            return;
        }

        if (is_array($objects) && array_is_list($objects)) {
            $this->rawEvents = array_values(array_filter($objects, fn (mixed $e): bool => $e instanceof stdClass));
        }

        $this->replace(['events' => $assoc]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->malformed) {
            return [];
        }

        return [
            'events' => ['required', 'list', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['array'],
            'events.*.id' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'events.*.version' => ['required', new StrictPositiveInteger],
            'events.*.timestamp' => ['required', new Rfc3339DateTime],
            'events.*.type' => ['required', 'string', Rule::enum(CmsEventType::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->malformed) {
                $validator->errors()->add('events', 'The request body must be valid JSON.');

                return;
            }

            $this->validatePayloads($validator);
        }];
    }

    /**
     * Log the rejection with field paths only (never values), then answer 422.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $requestId = $this->attributes->get('request_id');

        Log::channel('cms')->warning('cms.batch.rejected', [
            'fields' => array_keys($validator->errors()->messages()),
            'event_count' => count($this->rawEvents),
            'request_id' => is_string($requestId) ? $requestId : null,
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Raw events, decoded with JSON objects preserved.
     *
     * @return list<stdClass>
     */
    public function rawEvents(): array
    {
        return $this->rawEvents;
    }

    /**
     * @return list<CmsEvent>
     */
    public function validatedEvents(): array
    {
        return array_map(CmsEvent::fromValidated(...), $this->rawEvents);
    }

    private function validatePayloads(Validator $validator): void
    {
        foreach ($this->rawEvents as $index => $event) {
            $type = is_string($event->type ?? null) ? CmsEventType::tryFrom($event->type) : null;

            if ($type === null || ! $type->requiresPayload()) {
                continue;
            }

            $attribute = "events.{$index}.payload";

            if (! property_exists($event, 'payload')) {
                $validator->errors()->add($attribute, "The {$attribute} field is required for {$type->value} events.");

                continue;
            }

            if (! JsonObject::passes($event->payload)) {
                $validator->errors()->add($attribute, str_replace(':attribute', $attribute, JsonObject::MESSAGE));
            }
        }
    }
}
