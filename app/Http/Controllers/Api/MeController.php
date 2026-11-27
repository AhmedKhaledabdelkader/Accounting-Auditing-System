<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me: who is signed in. The client screen uses it to show the user's name and role, and to hide what the role
 * may not do (the server still checks every permission itself).
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $role ? ['id' => $role->id, 'name' => $role->name(), 'is_admin' => (bool) $role->is_admin] : null,
            'is_admin' => $user->isAdmin(),
            // Only the keys this user really holds (a platform-only key is dropped for an ordinary office's admin).
            'permissions' => array_values(array_filter($role?->permissions() ?? [], fn (string $p) => $user->hasPermission($p))),
            'office' => $user->office ? ['id' => $user->office->id, 'name' => $user->office->name()] : null,
            'is_client' => $user->isClient(),
        ]);
    }
}
