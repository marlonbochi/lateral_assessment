<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CMS webhook
|--------------------------------------------------------------------------
|
| Authenticated with HTTP Basic credentials from the environment only. It
| runs outside the `api` middleware group: no Sanctum, no consumer tokens.
| Rate limiting (throttle:cms-webhook) is applied once, in bootstrap/app.php.
|
*/
