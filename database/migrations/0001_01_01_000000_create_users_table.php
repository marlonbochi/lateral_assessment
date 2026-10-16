<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumer accounts (users and administrators) of the REST API.
 *
 * No password, remember token, password resets or sessions: consumers
 * authenticate with operator-issued Sanctum bearer tokens only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role', 16)->default('user');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('user', 'admin'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
