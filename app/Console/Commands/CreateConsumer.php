<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateConsumer extends Command
{
    protected $signature = 'consumer:create
        {name : Display name of the API client}
        {email : Unique contact e-mail of the API client}
        {--role=user : Role of the consumer (user or admin)}';

    protected $description = 'Create a consumer account for the REST API';

    public function handle(): int
    {
        $input = [
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'role' => $this->option('role'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, role: string} $data */
        $data = $validator->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => Role::from($data['role']),
        ]);

        $this->components->info(sprintf('Consumer #%d <%s> created with role [%s].', $user->id, $user->email, $user->role->value));

        return self::SUCCESS;
    }
}
