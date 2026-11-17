<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'office_id',
        'company_id',
        'name',
        'username',
        'email',
        'password',
        'role_id',
        'requested_role_id',
        'status',
        'locale',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /** Usernames: 3–30 Latin letters, digits, dot, dash or underscore; stored lowercase. */
    public const USERNAME_RULE = 'regex:/^[A-Za-z0-9._-]{3,30}$/';

    protected function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** The role the user asked for on sign-up; the admin approves it or gives another. */
    public function requestedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'requested_role_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && (bool) $this->role?->is_admin;
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** The company a client's portal user belongs to; null for the office's own staff. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }

    /** A client's portal user: sees only their company's requests, files and chat. */
    public function isClient(): bool
    {
        return $this->company_id !== null;
    }

    /** Permissions about the whole site: only the platform office's users hold them (a user with no office is a legacy account). */
    public const PLATFORM_ONLY = ['roles.manage', 'offices.manage'];

    /** Whether the user may do this (an active user whose role has the permission). */
    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive() || ! $this->role?->allows($permission)) {
            return false;
        }
        if (in_array($permission, self::PLATFORM_ONLY, true) && $this->office_id !== null && ! $this->office?->is_platform) {
            return false;
        }

        return true;
    }
}
