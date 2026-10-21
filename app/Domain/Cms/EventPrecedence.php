<?php

declare(strict_types=1);

namespace App\Domain\Cms;

use App\Enums\ContentStatus;

/**
 * Decides whether a CMS event changes the stored state.
 *
 * The only rule is the version: an event is applied when there is no state
 * yet or its version is strictly higher than the latest accepted one. Equal
 * versions are duplicates, lower versions are stale. Timestamps are never
 * compared. Because a deletion keeps its version as a tombstone, older events
 * can never restore deleted content, while a newer one re-creates it.
 */
final class EventPrecedence
{
    public function decide(?ContentSnapshot $current, CmsEvent $event): EventDecision
    {
        if ($current === null || $event->version > $current->version) {
            return EventDecision::apply($event->type->targetStatus());
        }

        if ($event->version === $current->version) {
            return EventDecision::ignore(IgnoreReason::Duplicate);
        }

        return EventDecision::ignore(
            $current->status === ContentStatus::Deleted ? IgnoreReason::StaleAfterDelete : IgnoreReason::Stale,
        );
    }
}
