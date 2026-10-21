<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A consumer account of the REST API. Authenticates only with operator-issued
 * Sanctum bearer tokens (no password, no sessions).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 */
#[Fillable(['name', 'email', 'role'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /**
     * Raw role check. Call it only from the `admin` gate; everything else
     * must ask the gate (`$user->can('admin')`).
     */
    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
