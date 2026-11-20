<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The mailbox an accountant sends their clients' emails from (SMTP, an app password): one per user, the password encrypted.
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20)->default('custom');
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('security', 4)->default('tls');      // tls (STARTTLS, 587) | ssl (465)
            $table->string('username');
            $table->text('password');                           // encrypted (the model's cast)
            $table->boolean('last_test_ok')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
