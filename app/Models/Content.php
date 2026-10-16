<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObjectPayload;
use App\Enums\ContentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * Local replica of one CMS entity.
 *
 * CMS-owned columns are written only by event processing; the local override
 * columns only by administrators (see data-model.md, "Field ownership").
 *
 * @property int $id
 * @property string $cms_id
 * @property int $version
 * @property ContentStatus $status
 * @property stdClass|null $payload
 * @property CarbonImmutable $cms_timestamp
 * @property CarbonImmutable $synced_at
 * @property bool $is_disabled
 * @property CarbonImmutable|null $disabled_at
 * @property int|null $disabled_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory;

    /**
     * Nothing is mass assignable: actions write explicit attributes so CMS and
     * override columns can never be mixed up through user input.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'cms_id';
    }

    /**
     * Rows the given consumer may see. The role decision goes through the
     * `admin` gate (single source of role truth): administrators see every
     * non-deleted item, users only published items that are not disabled.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can('admin')) {
            $query->where('status', '<>', ContentStatus::Deleted->value);

            return;
        }

        $query->where('status', ContentStatus::Published->value)->where('is_disabled', false);
    }

    /**
     * Feed order: most recently synchronized first, CMS id as tie-breaker.
     * Matches the (synced_at DESC, cms_id DESC) partial indexes.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function feedOrder(Builder $query): void
    {
        $query->orderByDesc('synced_at')->orderByDesc('cms_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ContentStatus::class,
            'payload' => JsonObjectPayload::class,
            'cms_timestamp' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'is_disabled' => 'boolean',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
