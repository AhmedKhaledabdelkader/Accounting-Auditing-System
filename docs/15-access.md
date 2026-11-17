# 15. Users, roles and permissions

Every page and every API endpoint needs a signed-in, **active** user and the **permission** for it.

## Pieces

| Piece | Where |
|---|---|
| Permission list (one key per feature, by group) | `config/permissions.php`; names in `lang/{ar,en}/permissions.php` (`keys.<group>.<action>`) |
| Roles (made by the admin) | `roles` (`name_ar`, `name_en`, `is_admin`, `open_for_signup`) + `role_permissions` (`role_id`, `permission`) — `App\Models\Role` |
| Users | `users.username` (sign-in name: 3–30 of `A-Z a-z 0-9 . - _`, stored lowercase, unique; `email` is optional and unused), `role_id`, `requested_role_id`, `status` (`pending` / `active` / `disabled`), `locale` |
| The check | `Gate::before` in `AppServiceProvider`: a catalogue key is allowed when the user is active and the role has it (an `is_admin` role has all). Routes use `can:<key>`; `reports.finalize` is checked in `FinancialReportController::storeVersion` (only `kind=final`) |
| Pending / disabled | `EnsureUserIsActive` (`active` middleware): pages sign the user out, the API answers 403 |
| Side menu | `App\Support\Access\Navigation`: each link with its permission; `/` goes to the first allowed page. The taxonomy page (`taxonomy.view`; changes need `taxonomy.manage`) is under «النظام» |

## Flows

- **Sign-up** (`/register`): name, username, password (8+, letters and numbers), and a role among those with `open_for_signup` (never an admin role). The account is `pending`; it can't sign in.
- **Approval** (`/admin/users`, `users.manage`): the admin picks the role (the asked one is preselected) and sets the status to active. Also: change role, disable, set a new password (revokes the user's API keys).
- **Roles** (`/admin/roles`, `roles.manage`): name in Arabic and English, «admin» (every permission), shown on sign-up, and the permissions by group. A role with users can't be deleted.
- **Always one admin:** any change that would leave no active user with an admin role is refused (`KeepAnAdmin`). An admin can't disable themselves or remove their own admin role.
- **API keys** (`/account`, `api_tokens.manage`): Sanctum personal access tokens, `Authorization: Bearer <key>`, with the account's permissions.
- **Pages → API:** the session (Sanctum stateful: `statefulApi()`, stateful host = the request's host). `public/js/http.js` adds `X-XSRF-TOKEN` to every same-origin change and sends a 401 back to `/login`.

## First admin

```bash
php artisan users:create-admin admin --name="الاسم"   # username; asks for the password; --office=<code> (default main)
```

Sign-in takes the **office code** (`main` for the first office), the username and the password; offices and the client portal are in [18](18-offices-and-client-portal.md). Makes the admin role («مدير النظام» / Administrator) if none exists. From then on everything is done in the app.

## Adding a feature

1. Add its key to `config/permissions.php` and its names to `lang/ar/permissions.php` and `lang/en/permissions.php`.
2. Put `->middleware('can:<key>')` on its routes (page and API). `AccessTest` fails for a named route without one.
3. A page link: add it to `Navigation::sections()` with the key.

## Tests

`Tests\TestCase` signs every test in as an unsaved administrator; `signOut()` for visitor tests. Browser tests (`DuskTestCase`) sign their browser in as a saved admin at the start of each test, and the tests' own requests use the same user. `tests/Feature/AccessTest.php` covers sign-in, sign-up, roles, the last admin, API keys and the permission on every route.
