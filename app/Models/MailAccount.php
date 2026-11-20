<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The mailbox a user sends emails from: SMTP host and an app password (kept encrypted, never shown again). */
class MailAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'from_address', 'from_name', 'host', 'port', 'security', 'username', 'password', 'last_test_ok', 'last_tested_at', 'last_error', 'imap_host', 'imap_port', 'imap_enabled', 'imap_last_uid', 'imap_checked_at', 'imap_error'];

    protected $hidden = ['password'];

    protected $attributes = ['imap_enabled' => true, 'imap_port' => 993];

    protected $casts = ['password' => 'encrypted', 'port' => 'integer', 'last_test_ok' => 'boolean', 'last_tested_at' => 'datetime', 'imap_port' => 'integer', 'imap_enabled' => 'boolean', 'imap_last_uid' => 'integer', 'imap_checked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
