<?php

use App\Models\User;
use App\Services\GuideLibrary;
use App\Services\PrimaryNavigation;
use Database\Seeders\GuideAccessSeeder;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @param list<string> $permissions */
function guideReader(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('the guides index lists only the manuals the user may open', function () {
    $this->withoutVite();

    $this->actingAs(guideReader(['view viewer guides']))->get(route('guides.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guides/Index')
            ->has('guides', 1)
            ->where('guides.0.key', GuideLibrary::VIEWER));

    $this->actingAs(guideReader(['view agent guides', 'view viewer guides', 'view super admin guides']))
        ->get(route('guides.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('guides', 3)
            ->where('guides.0.key', GuideLibrary::AGENT)
            ->where('guides.1.key', GuideLibrary::VIEWER)
            ->where('guides.2.key', GuideLibrary::SUPER_ADMIN));
});

test('a role manual renders its markdown and section index', function () {
    $this->withoutVite();

    $this->actingAs(guideReader(['view viewer guides']))->get(route('guides.show', GuideLibrary::VIEWER))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guides/Show')
            ->where('guide.key', GuideLibrary::VIEWER)
            ->where('document.title', 'EXP Operations — Viewer Role Guide')
            ->has('document.headings')
            ->where('document.headings.0.title', '1. About this manual')
            ->where('others', []));
});

test('the super administrator manual renders and links to the other two', function () {
    $this->withoutVite();

    $this->actingAs(guideReader([
        'view agent guides',
        'view viewer guides',
        'view super admin guides',
    ]))->get(route('guides.show', GuideLibrary::SUPER_ADMIN))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guides/Show')
            ->where('guide.key', GuideLibrary::SUPER_ADMIN)
            ->where('guide.audience', 'Super Administrator')
            ->where('document.title', 'EXP Operations — Super Administrator Role Guide')
            ->where('document.headings.0.title', '1. About this manual')
            ->has('others', 2)
            ->where('others.0.key', GuideLibrary::AGENT)
            ->where('others.1.key', GuideLibrary::VIEWER));
});

test('a manual cannot be opened without its own guide permission', function () {
    $this->withoutVite();

    $agent = guideReader(['view agent guides']);

    $this->actingAs($agent)->get(route('guides.show', GuideLibrary::VIEWER))->assertForbidden();
    $this->actingAs($agent)->get(route('guides.show', GuideLibrary::SUPER_ADMIN))->assertForbidden();
    $this->actingAs($agent)->get(route('guides.show', GuideLibrary::AGENT))->assertOk();

    $this->actingAs($agent)->get('/guides/administrator')->assertNotFound();
});

test('the menu publishes one entry per readable role manual', function () {
    $menus = collect(app(PrimaryNavigation::class)->forUser(guideReader([
        'view viewer guides',
        'view agent guides',
        'view super admin guides',
    ])));
    $guides = $menus->firstWhere('key', 'guides');

    expect($guides)->not->toBeNull()
        ->and(collect($guides['children'])->pluck('key')->all())
        ->toBe(['guide-agent', 'guide-viewer', 'guide-super-admin'])
        ->and($guides['children'][0]['href'])->toBe(route('guides.show', GuideLibrary::AGENT))
        ->and($guides['children'][1]['href'])->toBe(route('guides.show', GuideLibrary::VIEWER))
        ->and($guides['children'][2]['href'])->toBe(route('guides.show', GuideLibrary::SUPER_ADMIN));

    $viewerMenus = collect(app(PrimaryNavigation::class)->forUser(guideReader(['view viewer guides'])));

    expect(collect($viewerMenus->firstWhere('key', 'guides')['children'])->pluck('key')->all())
        ->toBe(['guide-viewer']);
});

test('the guide seeder gives all three manuals to the super administrator only', function () {
    $this->seed(GuideAccessSeeder::class);

    $permissions = fn (string $role): array => Role::findByName($role, 'web')->permissions->pluck('name')->all();

    $superAdmin = $permissions('Super Administrator');
    $administrator = $permissions('Administrator');
    $agent = $permissions('Agent');
    $supervisor = $permissions('Supervisor');
    $viewer = $permissions('Viewer');

    expect($superAdmin)->toContain('view agent guides', 'view viewer guides', 'view super admin guides')
        ->and($administrator)->toContain('view agent guides', 'view viewer guides')
        ->and($administrator)->not->toContain('view super admin guides')
        ->and($agent)->toContain('view agent guides')
        ->and($agent)->not->toContain('view viewer guides', 'view super admin guides')
        ->and($supervisor)->toContain('view agent guides')
        ->and($supervisor)->not->toContain('view viewer guides', 'view super admin guides')
        ->and($viewer)->toContain(
            'view call history',
            'view all cases',
            'view reports',
            'view dentists',
            'view viewer guides',
        )
        ->and($viewer)->not->toContain('view cases', 'edit cases', 'view pbx', 'view agent guides', 'view super admin guides');
});

test('all three role manuals ship complete with the application', function () {
    $library = app(GuideLibrary::class);

    foreach ([GuideLibrary::AGENT, GuideLibrary::VIEWER, GuideLibrary::SUPER_ADMIN] as $key) {
        $meta = $library->meta($key);

        expect($meta['available'])->toBeTrue()
            ->and(File::exists(base_path('docs/'.$meta['file'])))->toBeTrue()
            ->and($meta['reading_minutes'])->toBeGreaterThanOrEqual(10);
    }

    $html = $library->document(GuideLibrary::VIEWER)['html'];

    expect($html)->toContain('id="1-about-this-manual"')
        ->and($html)->not->toContain('## Contents');

    $superAdminHtml = $library->document(GuideLibrary::SUPER_ADMIN)['html'];

    expect($superAdminHtml)->toContain('id="1-about-this-manual"')
        ->and($superAdminHtml)->toContain('id="27-quick-reference-card"')
        ->and($superAdminHtml)->not->toContain('## Contents');
});

test('a manual publishes its document facts and section links separately from the body', function () {
    $document = app(GuideLibrary::class)->document(GuideLibrary::AGENT);

    expect($document['facts'])->toHaveCount(4)
        ->and($document['facts'][0])->toBe([
            'label' => 'Audience',
            'value' => 'Call-centre agents (role: <strong>Agent</strong>) working the EXP dental line',
        ])
        ->and($document['facts'][3]['label'])->toBe('Document version')
        ->and($document['sections'])->toBe(25)
        ->and($document['html'])->toContain('<h2 id="1-about-this-manual">')
        ->and($document['html'])->toContain('class="guide-anchor"')
        ->and($document['html'])->toContain('<table class="guide-table">')
        ->and($document['html'])->not->toContain('<strong>Audience:</strong>');
});
