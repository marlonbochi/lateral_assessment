<?php

declare(strict_types=1);

use App\Models\EventBatch;
use Illuminate\Support\Facades\Schedule;

// Processed inbox batches are pruned after the retention period (failed ones are kept).
Schedule::command('model:prune', ['--model' => [EventBatch::class]])->daily()->withoutOverlapping();

// Remove consumer tokens that expired more than a day ago.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
