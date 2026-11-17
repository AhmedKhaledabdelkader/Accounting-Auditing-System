<?php

namespace App\Support\Access;

use App\Models\ClientMessage;
use App\Models\DocumentRequest;
use App\Models\User;

/** The side menu: its sections and links, each with the permission that shows it. The start page is the first link allowed. */
class Navigation
{
    private const ICONS = [
        'clients' => '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>',
        'portal' => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        'offices' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
        'reports' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'classifier' => '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
        'cache' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'synonyms' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
        'taxonomy' => '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
        'rules' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'api_docs' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'zakat' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'roles' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    ];

    /**
     * [section label key => [[href, active patterns, label key, icon, permission, hint key], …]]: the label and the hint (a line under
     * it in plain words) are `nav.items.<name>.0` and `.1`.
     */
    public static function sections(): array
    {
        $item = fn (string $href, array $on, string $name, string $permission) => [$href, $on, "nav.items.{$name}.0", self::ICONS[self::ICON_OF[$name] ?? $name], $permission, "nav.items.{$name}.1"];

        return [
            'nav.sections.work' => [
                // The clients first: the accountant's clients, and what waits for them. A client's own portal is their only page.
                $item('/clients', ['clients*'], 'clients', 'clients.view'),
                $item('/portal', ['portal*'], 'portal', 'portal.use'),
                // The work on a client: 4 to 7 (they ask for a client when none is open; the one opened last is remembered).
                $item('/work/trial-balance', ['work/trial-balance', 'clients/*/trial-balance', 'classifier'], 'trial_balance', 'clients.view'),
                $item('/work/prior-statements', ['work/prior-statements', 'clients/*/prior-statements'], 'prior', 'clients.view'),
                $item('/work/statements', ['work/statements', 'clients/*/statements', 'financial-reports*', 'financial-statements*'], 'statements', 'clients.view'),
                $item('/work/zakat', ['work/zakat', 'clients/*/zakat'], 'zakat', 'clients.view'),
                $item('/work/requirements', ['work/requirements', 'clients/*/requirements'], 'requirements', 'clients.view'),
                $item('/work/working-papers', ['work/working-papers', 'clients/*/working-papers'], 'papers', 'clients.view'),
                $item('/work/recalculation', ['work/recalculation', 'clients/*/recalculation'], 'recalc', 'clients.view'),
            ],
            'nav.sections.tuning' => [
                $item('/classification-cache', ['classification-cache*'], 'cache', 'cache.view'),
                $item('/classification-memory', ['classification-memory'], 'memory', 'cache.view'),
                $item('/classification-rules', ['classification-rules*'], 'rules', 'taxonomy.view'),
                $item('/taxonomy', ['taxonomy*'], 'taxonomy', 'taxonomy.view'),
                $item('/statement-synonyms', ['statement-synonyms*'], 'synonyms', 'synonyms.view'),
            ],
            'nav.sections.developers' => [
                $item('/api/docs', ['api/docs'], 'api_docs', 'api_docs.view'),
            ],
            'nav.sections.admin' => [
                $item('/admin/users', ['admin/users*'], 'users', 'users.manage'),
                $item('/admin/offices', ['admin/offices*'], 'offices', 'offices.manage'),
                $item('/admin/roles', ['admin/roles*'], 'roles', 'roles.manage'),
            ],
        ];
    }

    /** Links whose icon is another's. */
    private const ICON_OF = ['memory' => 'synonyms', 'trial_balance' => 'classifier', 'prior' => 'cache', 'statements' => 'reports', 'requirements' => 'users', 'papers' => 'reports', 'recalc' => 'rules', 'classifier' => 'classifier'];

    /** The link of the main side menu the current page belongs to: the most specific pattern that matches (a client's zakat page over the list of clients). */
    public static function activeHref(?User $user): ?string
    {
        $best = null;
        $score = 0;
        foreach (self::for($user) as $links) {
            foreach ($links as $link) {
                foreach ($link[1] as $pattern) {
                    if (request()->is($pattern) && strlen($pattern) > $score) {
                        [$best, $score] = [$link[0], strlen($pattern)];
                    }
                }
            }
        }

        return $best;
    }

    /** The client the work pages are about now (opened last), for the one who may see clients; null without one. */
    public static function currentClient(?User $user): ?array
    {
        $id = session('current_client');
        if (! $user?->can('clients.view') || ! $id || ! ($company = \App\Models\Company::find($id))) {
            return null;
        }

        return ['id' => $company->id, 'name' => $company->clientName(), 'company' => $company->name_ar];
    }
    /** The sections with only the links this user may open; empty sections dropped. */
    public static function for(?User $user): array
    {
        $out = [];
        foreach (self::sections() as $label => $links) {
            $allowed = array_values(array_filter($links, fn ($l) => $user?->can($l[4])));
            // Whoever may not see clients still has the classifier and the statements list, as before the work steps (the accountant's own role).
            if ($label === 'nav.sections.work' && ! $user?->can('clients.view')) {
                $item = fn (string $href, array $on, string $name, string $permission) => [$href, $on, "nav.items.{$name}.0", self::ICONS[self::ICON_OF[$name] ?? $name], $permission, "nav.items.{$name}.1"];
                $allowed = array_values(array_filter([...$allowed, $item('/classifier', ['classifier'], 'classifier', 'classify.view'),
                    $item('/financial-reports', ['financial-reports*', 'financial-statements*'], 'reports', 'reports.view')], fn ($l) => $user?->can($l[4])));
            }
            if ($allowed) {
                $out[$label] = $allowed;
            }
        }

        return $out;
    }

    /** Counts shown beside a link, by its href: accounts waiting for the admin's approval, and for the accountant what waits in the clients' portal (documents to review, new messages); for a client what is asked of them. */
    public static function counts(?User $user): array
    {
        $out = [];
        if ($user?->can('users.manage')) {
            $out['/admin/users'] = User::where('office_id', $user->office_id)->whereNull('company_id')->where('status', User::PENDING)->count();
        }
        if ($user?->can('clients.view')) {
            $out['/clients'] = DocumentRequest::where('status', DocumentRequest::UPLOADED)->count()
                + ClientMessage::where('from_client', true)->where('kind', ClientMessage::TEXT)->whereNull('read_at')->count();
        }
        if ($user?->can('portal.use') && $user->company_id) {
            $out['/portal'] = DocumentRequest::where('company_id', $user->company_id)->whereIn('status', DocumentRequest::OPEN)->count()
                + ClientMessage::where('company_id', $user->company_id)->where('from_client', false)->where('kind', ClientMessage::TEXT)->whereNull('read_at')->count();
        }

        return array_filter($out);
    }

    /** Where a signed-in user starts: the first page they may open, else their account page. */
    public static function home(User $user): string
    {
        foreach (self::for($user) as $links) {
            return $links[0][0];
        }

        return '/account';
    }
}
