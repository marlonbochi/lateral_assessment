<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObjectPayload;
use App\Enums\ContentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ContentFactory;
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
