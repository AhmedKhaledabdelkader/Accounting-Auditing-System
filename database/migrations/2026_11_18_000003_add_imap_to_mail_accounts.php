<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reading the replies from the same mailbox (IMAP): where, whether, how far it has read and the last problem.
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('imap_host')->nullable()->after('security');
            $table->unsignedSmallInteger('imap_port')->default(993)->after('imap_host');
            $table->boolean('imap_enabled')->default(true)->after('imap_port');
            $table->unsignedBigInteger('imap_last_uid')->nullable()->after('imap_enabled');
            $table->timestamp('imap_checked_at')->nullable()->after('imap_last_uid');
            $table->string('imap_error')->nullable()->after('imap_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_enabled', 'imap_last_uid', 'imap_checked_at', 'imap_error']);
        });
    }
};
