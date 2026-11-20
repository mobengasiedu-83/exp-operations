<?php

namespace App\Services;

use App\Models\User;

/**
 * Builds the simplified shell menu for the signed-in user.
 *
 * Dashboard is the post-login landing page, so it is always available. Every
 * other menu is filtered by an explicit permission so the navigation can never
 * expose a screen the user cannot open.
 */
class PrimaryNavigation
{
    public function __construct(private readonly GuideLibrary $guides) {}

    /**
     * @return list<array{key: string, title: string, href: string, icon: string, children: list<array{key: string, title: string, href: string, icon: string}>}>
     */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $canReadCalls = $user->canAny(['view call history', 'view pbx']);
        $guideMenus = array_map(
            fn (array $guide): array => [...$guide, 'visible' => true],
            $this->guides->navigation($user),
        );

        /** @var list<array{key: string, title: string, href: string, icon: string, visible: bool, children?: list<array{key: string, title: string, href: string, icon: string, visible: bool}>}> $menus */
        $menus = [
            ['key' => 'dashboard', 'title' => 'Dashboard', 'href' => route('dashboard'), 'icon' => 'layout-dashboard', 'visible' => true],
            ['key' => 'workspace', 'title' => 'Agent Workspace', 'href' => route('workspace.index'), 'icon' => 'headphones', 'visible' => $user->can('view cases')],
            [
                'key' => 'call-history', 'title' => 'Calls', 'href' => route('calls.index'), 'icon' => 'phone', 'visible' => $canReadCalls,
                'children' => [
                    ['key' => 'agent-calls', 'title' => 'Agent calls', 'href' => route('calls.agent'), 'icon' => 'inbox', 'visible' => $user->canAny(['view call history', 'view cases'])],
                    ['key' => 'did-calls', 'title' => 'DID journeys', 'href' => route('calls.did'), 'icon' => 'route', 'visible' => $canReadCalls],
                    ['key' => 'all-calls', 'title' => 'All calls', 'href' => route('calls.index'), 'icon' => 'list', 'visible' => $canReadCalls],
                ],
            ],
            ['key' => 'reports', 'title' => 'Reports', 'href' => route('reports.index'), 'icon' => 'chart-column', 'visible' => $user->can('view reports')],
            ['key' => 'dentists', 'title' => 'Dentists', 'href' => route('dentists.index'), 'icon' => 'stethoscope', 'visible' => $user->can('view dentists')],
            [
                'key' => 'guides', 'title' => 'User guides', 'href' => route('guides.index'), 'icon' => 'book-open', 'visible' => $guideMenus !== [],
                'children' => $guideMenus,
            ],
            ['key' => 'administration', 'title' => 'Administration', 'href' => route('administration.index'), 'icon' => 'settings', 'visible' => $this->canAdminister($user)],
        ];

        return array_values(array_map(function (array $menu): array {
            $children = array_values(array_map(
                fn (array $child): array => ['key' => $child['key'], 'title' => $child['title'], 'href' => $child['href'], 'icon' => $child['icon']],
                array_filter($menu['children'] ?? [], fn (array $child): bool => $child['visible']),
            ));

            return [
                'key' => $menu['key'],
                'title' => $menu['title'],
                'href' => $menu['href'],
                'icon' => $menu['icon'],
                'children' => $children,
            ];
        }, array_values(array_filter($menus, fn (array $menu): bool => $menu['visible']))));
    }

    public function canAdminister(User $user): bool
    {
        return $user->canAny([
            'view users',
            'view roles',
            'view permissions',
            'view settings',
            'view sessions',
            'view audit',
            'view pbx',
            'manage screening protocols',
        ]);
    }
}
