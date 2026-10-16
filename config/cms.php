<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | CMS webhook
    |--------------------------------------------------------------------------
    |
    | Credentials for the HTTP Basic authentication used by the CMS when it
    | delivers event batches. They are separate from consumer credentials and
    | must only ever come from the environment. Empty values fail closed.
    |
    */

    'webhook' => [
        'username' => env('CMS_WEBHOOK_USERNAME'),
        'password' => env('CMS_WEBHOOK_PASSWORD'),
        'max_body_bytes' => (int) env('CMS_WEBHOOK_MAX_BODY_BYTES', 1048576),
        'rate_limit_per_minute' => (int) env('CMS_WEBHOOK_RATE_LIMIT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer API
    |--------------------------------------------------------------------------
    */

    'api' => [
        'rate_limit_per_minute' => (int) env('API_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch processing queue
    |--------------------------------------------------------------------------
    |
    | The connection must live in the same PostgreSQL database as the data so
    | the inbox row and its job are committed in one transaction (see
    | specs/001-cms-content-sync/research.md, R2).
    |
    */

    'queue' => [
        'connection' => env('CMS_QUEUE_CONNECTION', 'database'),
        'name' => env('CMS_QUEUE_NAME', 'cms-events'),
    ],

    'processing' => [
        'tries' => 5,
        'backoff' => [5, 30, 120, 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox retention
    |--------------------------------------------------------------------------
    |
    | Processed batches are pruned after this many days. Failed batches are
    | never pruned automatically.
    |
    */

    'batches' => [
        'retention_days' => (int) env('CMS_BATCH_RETENTION_DAYS', 30),
    ],

];
