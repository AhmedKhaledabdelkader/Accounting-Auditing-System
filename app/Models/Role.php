<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A set of permissions the admin gives to users; an admin role has every permission. */
class Role extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'is_admin', 'open_for_signup', 'is_client'];

    protected $casts = ['is_admin' => 'boolean', 'open_for_signup' => 'boolean', 'is_client' => 'boolean'];

    /** The role given to a client's portal user (its only permission is portal.use). */
    public static function client(): ?self
    {
        return self::where('is_client', true)->orderBy('id')->first();
    }

    /** Every permission key the app knows (config/permissions.php). */
    public static function catalogue(): array
    {
        return array_merge(...array_values(config('permissions')));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The role's permission keys; all of them for an admin role. */
    public function permissions(): array
    {
        if ($this->is_admin) {
            return self::catalogue();
        }

        return $this->permissionCache ??= DB::table('role_permissions')->where('role_id', $this->id)->pluck('permission')->all();
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Replaces the role's permissions; unknown keys are dropped. */
    public function syncPermissions(array $keys): void
    {
        $keys = array_values(array_intersect(self::catalogue(), $keys));
        DB::transaction(function () use ($keys) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($k) => ['role_id' => $this->id, 'permission' => $k], $keys));
        });
        $this->permissionCache = null;
    }

    /** The role's name in the page's language. */
    public function name(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    private ?array $permissionCache = null;
}
