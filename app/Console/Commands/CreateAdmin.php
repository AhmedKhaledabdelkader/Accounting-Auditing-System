<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The first administrator (or another one) of an office: an active user with the admin role, made when there is none. Run
 * once after installing; from then on the admin approves sign-ups and manages roles in the app. The office is the main one
 * unless --office names another (its code).
 */
class CreateAdmin extends Command
{
    protected $signature = 'users:create-admin {username} {--name= : the name shown in the app} {--password= : asked for when left out} {--office=main : the code of the office}';

    protected $description = 'Create an active administrator of an office (and the admin role when there is none)';

    public function handle(): int
    {
        $username = mb_strtolower(trim($this->argument('username')));
        $name = $this->option('name') ?: $username;
        $office = Office::byCode($this->option('office'));
        if (! $office) {
            $this->error("No office has the code {$this->option('office')}. Make it first: php artisan office:create.");

            return self::FAILURE;
        }
        $password = $this->option('password') ?? $this->secret('Password');

        $v = Validator::make(compact('username', 'name', 'password'), [
            'username' => ['required', User::USERNAME_RULE, Rule::unique('users', 'username')->where('office_id', $office->id)],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::where('is_admin', true)->where('is_client', false)->first()
            ?? Role::create(['name_ar' => 'مدير النظام', 'name_en' => 'Administrator', 'is_admin' => true, 'open_for_signup' => false]);
        User::create(['office_id' => $office->id, 'name' => $name, 'username' => $username, 'password' => $password, 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $this->info("Administrator {$username} created in the office {$office->code}.");

        return self::SUCCESS;
    }
}
