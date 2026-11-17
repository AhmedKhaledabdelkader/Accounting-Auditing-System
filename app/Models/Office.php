<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An accounting office. Its code is typed with the username and password on the sign-in page; its users, clients,
 * reports and classification runs are its own. The platform office runs the site (roles and offices are its to manage).
 */
class Office extends Model
{
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    /** 2–20 lowercase Latin letters, digits or dashes, starting with a letter or digit. */
    public const CODE_RULE = 'regex:/^[a-z0-9][a-z0-9-]{1,19}$/';

    protected $fillable = ['name_ar', 'name_en', 'code', 'is_platform', 'status'];

    protected $casts = ['is_platform' => 'boolean'];

    protected function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public static function byCode(?string $code): ?self
    {
        $code = mb_strtolower(trim((string) $code));

        return $code === '' ? null : self::where('code', $code)->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function name(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }
}
