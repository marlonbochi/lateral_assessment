<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BatchStatus;
use App\Http\Requests\Api\V1\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListEventBatchesRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        // Authorized by the role middleware and EventBatchPolicy::viewAny in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'status' => ['sometimes', Rule::enum(BatchStatus::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [$this->cursorValidator(['received_at', 'id'])];
    }

    public function statusFilter(): ?BatchStatus
    {
        return $this->filled('status') ? BatchStatus::from($this->string('status')->toString()) : null;
    }
}
