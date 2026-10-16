<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('reports the application as up on the unauthenticated health route', function (): void {
    get('/up')->assertOk();
});
