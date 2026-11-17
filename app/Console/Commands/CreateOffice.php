<?php

namespace App\Console\Commands;

use App\Models\Office;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** A new accounting office with its code; then `users:create-admin <username> --office=<code>` makes its first admin. */
class CreateOffice extends Command
{
    protected $signature = 'office:create {code : 2-20 lowercase letters, digits or dashes, typed at sign-in} {name : the office\'s name in Arabic} {--name-en= : its name in English}';

    protected $description = 'Create an accounting office';

    public function handle(): int
    {
        $code = mb_strtolower(trim($this->argument('code')));
        $v = Validator::make(['code' => $code, 'name' => $this->argument('name')], ['code' => ['required', Office::CODE_RULE, 'unique:offices,code'], 'name' => ['required', 'string', 'max:255']]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        Office::create(['code' => $code, 'name_ar' => $this->argument('name'), 'name_en' => $this->option('name-en') ?: null, 'status' => Office::ACTIVE]);
        $this->info("Office {$code} created. Add its admin: php artisan users:create-admin <username> --office={$code}");

        return self::SUCCESS;
    }
}
