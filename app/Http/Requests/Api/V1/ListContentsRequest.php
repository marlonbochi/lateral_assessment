<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Http\Requests\Api\V1\Concerns\ValidatesPagination;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ListContentsRequest extends FormRequest
{
    use ValidatesPagination;

    /**
     * Filtering by CMS status or override flag is administrator-only.
     */
    public function authorize(): bool|Response
    {
        if (! $this->hasAny(['status', 'disabled'])) {
            return true;
        }

        return Gate::inspect('filter-contents');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'status' => ['sometimes', Rule::in([ContentStatus::Published->value, ContentStatus::Unpublished->value])],
            'disabled' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
        ];
    }

    public function statusFilter(): ?ContentStatus
    {
        return $this->filled('status') ? ContentStatus::from($this->string('status')->toString()) : null;
    }

    public function disabledFilter(): ?bool
    {
        return $this->filled('disabled') ? $this->boolean('disabled') : null;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [$this->cursorValidator(['synced_at', 'cms_id'])];
    }
}
