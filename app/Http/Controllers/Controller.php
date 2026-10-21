<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Since Laravel 11 the base controller is empty; controllers authorize
    // per resource through policies with $this->authorize().
    use AuthorizesRequests;
}
