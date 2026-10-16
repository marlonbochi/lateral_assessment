<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Locally disables or re-enables content. Writes only the override columns
 * (never CMS-owned data) and is idempotent: repeating it changes nothing.
 */
final class SetContentDisabled
{
    public function handle(Content $content, bool $disabled, User $actor): Content
    {
        $changed = false;

        $result = DB::transaction(function () use ($content, $disabled, $actor, &$changed): Content {
            /** @var Content $locked */
            $locked = Content::query()->lockForUpdate()->findOrFail($content->id);

            if ($locked->is_disabled === $disabled) {
                return $locked;
            }

            Content::query()->whereKey($locked->id)->update([
                'is_disabled' => $disabled,
                'disabled_at' => $disabled ? now() : null,
                'disabled_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $changed = true;

            return $locked->refresh();
        });

        if ($changed) {
            Log::channel('cms')->info('content.override.changed', [
                'actor_id' => $actor->id,
                'content_id' => $result->cms_id,
                'disabled' => $disabled,
            ]);
        }

        return $result;
    }
}
