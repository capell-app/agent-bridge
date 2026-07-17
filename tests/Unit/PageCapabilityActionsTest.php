<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\Pages\ClonePageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\CreateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\DisablePageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\InspectPageCapabilityAction;
use Capell\AgentBridge\Actions\Pages\InspectPagePublishingReadinessCapabilityAction;
use Capell\AgentBridge\Actions\Pages\ListPageAuthoringOptionsCapabilityAction;
use Capell\AgentBridge\Actions\Pages\OpenPageEditorCapabilityAction;
use Capell\AgentBridge\Actions\Pages\SearchPagesCapabilityAction;
use Capell\AgentBridge\Actions\Pages\TransitionPagePublicationCapabilityAction;
use Capell\AgentBridge\Actions\Pages\UpdateDraftPageCapabilityAction;
use Capell\AgentBridge\Actions\Sites\InspectSiteCapabilityAction;
use Capell\AgentBridge\Actions\Sites\OpenSiteEditorCapabilityAction;
use Capell\AgentBridge\Actions\Sites\SearchSitesCapabilityAction;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('previews draft page creation with the validated payload', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Preview site', 1);
    $blueprintId = createAgentBridgeCapabilityBlueprint('Preview page', 'preview-page', 'page');
    $layoutId = DB::table('layouts')->insertGetId([
        'name' => 'Preview layout',
        'key' => 'preview-layout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = [
        'name' => 'Campaign landing page',
        'site_id' => $siteId,
        'blueprint_id' => $blueprintId,
        'layout_id' => $layoutId,
        'meta' => ['hidden' => true],
    ];

    $result = (new CreateDraftPageCapabilityAction)->preview(pageInvocation($payload));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('A new unpublished page record will be created.')
        ->and($result->data['page'])->toBe($payload);
});

it('rejects draft page creation payloads without required page fields', function (): void {
    (new CreateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Missing page relationships',
    ]));
})->throws(ValidationException::class);

it('rejects draft page creation without an authenticated user', function (): void {
    (new CreateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Campaign landing page',
        'site_id' => 1,
        'blueprint_id' => 2,
        'layout_id' => 3,
    ], user: false));
})->throws(ValidationException::class);

it('rejects draft page creation payloads that try to set admin metadata', function (): void {
    (new CreateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Campaign landing page',
        'site_id' => 1,
        'blueprint_id' => 2,
        'layout_id' => 3,
        'admin' => ['configurator' => 'PrivilegedConfigurator'],
    ]));
})->throws(ValidationException::class);

it('rejects draft page creation for unassigned sites', function (): void {
    (new CreateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Campaign landing page',
        'site_id' => 99,
        'blueprint_id' => 2,
        'layout_id' => 3,
    ], pageCapabilityUser(collect([1]))));
})->throws(ValidationException::class);

it('previews safe draft page updates without exposing the page id as a change', function (): void {
    $page = createAgentBridgeCapabilityPage();

    $result = (new UpdateDraftPageCapabilityAction)->preview(pageInvocation([
        'page_id' => $page->getKey(),
        'name' => 'Updated campaign page',
        'meta' => ['hidden' => false],
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('The selected page will be updated with safe editable fields.')
        ->and($result->data['page_id'])->toBe($page->getKey())
        ->and($result->data['changes'])->toBe([
            'name' => 'Updated campaign page',
            'meta' => ['hidden' => false],
        ]);
});

it('rejects draft page update payloads without a page id', function (): void {
    (new UpdateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Missing page id',
    ]));
})->throws(ValidationException::class);

it('rejects draft page update payloads that try to set admin metadata', function (): void {
    $page = createAgentBridgeCapabilityPage();

    (new UpdateDraftPageCapabilityAction)->preview(pageInvocation([
        'page_id' => $page->getKey(),
        'admin' => ['configurator' => 'PrivilegedConfigurator'],
    ]));
})->throws(ValidationException::class);

it('previews disabling a page by ending its visibility window', function (): void {
    $page = createAgentBridgeCapabilityPage();

    $result = (new DisablePageCapabilityAction)->preview(pageInvocation([
        'page_id' => $page->getKey(),
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('The page visibility window will be ended immediately.')
        ->and($result->data['page_id'])->toBe($page->getKey())
        ->and($result->data['visible_until'])->toBeString();
});

it('rejects disable page payloads without a page id', function (): void {
    (new DisablePageCapabilityAction)->preview(pageInvocation([]));
})->throws(ValidationException::class);

it('rejects readiness inspection payloads without a page id before querying pages', function (): void {
    (new InspectPagePublishingReadinessCapabilityAction)->preview(pageInvocation([]));
})->throws(ValidationException::class);

it('rejects page capabilities for pages outside the actor assigned sites', function (): void {
    $page = createAgentBridgeCapabilityPage(siteId: 2);
    $user = pageCapabilityUser(collect([1]));

    expect(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->preview(pageInvocation([
        'page_id' => $page->getKey(),
        'name' => 'Forbidden update',
    ], $user)))->toThrow(ValidationException::class)
        ->and(fn (): CapabilityResultData => (new DisablePageCapabilityAction)->preview(pageInvocation([
            'page_id' => $page->getKey(),
        ], $user)))->toThrow(ValidationException::class)
        ->and(fn (): CapabilityResultData => (new InspectPagePublishingReadinessCapabilityAction)->preview(pageInvocation([
            'page_id' => $page->getKey(),
        ], $user)))->toThrow(ValidationException::class);
});

it('executes page capability lifecycle changes against persisted draft pages', function (): void {
    createAgentBridgePageCapabilityTables();

    $siteId = createAgentBridgeCapabilitySite('Agent Site');
    $blueprintId = DB::table('blueprints')->insertGetId([
        'name' => 'Page',
        'key' => 'page',
        'type' => 'page',
        'default' => true,
        'admin' => json_encode(['configurator' => 'Default']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $layoutId = DB::table('layouts')->insertGetId([
        'name' => 'Agent Layout',
        'key' => 'agent-layout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $created = Page::withoutEvents(fn (): CapabilityResultData => (new CreateDraftPageCapabilityAction)->execute(pageInvocation([
        'name' => 'Agent-created landing page',
        'site_id' => $siteId,
        'blueprint_id' => $blueprintId,
        'layout_id' => $layoutId,
        'meta' => ['source' => 'agent'],
    ], pageCapabilityUser(collect(), isGlobalAdmin: true))));

    $page = findAgentBridgeCapabilityPage($created->data['page_id']);
    $page->pageUrls()->firstOrCreate([
        'site_id' => $siteId,
        'language_id' => 1,
        'url' => '/agent-created-landing-page',
    ], ['status' => true]);

    $updated = Page::withoutEvents(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'name' => 'Agent-updated landing page',
        'meta' => ['source' => 'agent', 'updated' => true],
    ], pageCapabilityUser(collect([(int) $siteId])))));
    $disabled = Page::withoutEvents(fn (): CapabilityResultData => (new DisablePageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
    ], pageCapabilityUser(collect([(int) $siteId])))));
    $readiness = (new InspectPagePublishingReadinessCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
    ], pageCapabilityUser(collect([(int) $siteId]))));

    expect($created->ok)->toBeTrue()
        ->and($created->data['name'])->toBe('Agent-created landing page')
        ->and($updated->data['updated'])->toContain('name', 'meta')
        ->and($page->refresh()->name)->toBe('Agent-updated landing page')
        ->and($disabled->ok)->toBeTrue()
        ->and($disabled->data['visible_until'])->toBeString()
        ->and($readiness->ok)->toBeTrue()
        ->and($readiness->data['checks'])->toMatchArray([
            'has_site' => true,
            'has_type' => true,
            'has_layout' => true,
            'has_page_urls' => true,
        ]);
});

it('finds pages by internal name title slug or URL within the actor site scope', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $page->forceFill(['name' => 'About record'])->saveQuietly();
    $languageId = createAgentBridgeCapabilityLanguage();
    Page::withoutEvents(fn (): mixed => $page->translations()->create([
        'language_id' => $languageId,
        'title' => 'About Capell',
        'content' => 'About content',
        'meta' => ['slug' => 'about'],
    ]));
    Page::withoutEvents(fn (): mixed => $page->pageUrls()->create([
        'site_id' => 1,
        'language_id' => $languageId,
        'url' => '/about',
        'status' => true,
    ]));

    $hidden = createAgentBridgeCapabilityPage(siteId: 2);
    $hidden->forceFill(['name' => 'About hidden site'])->saveQuietly();

    $action = new SearchPagesCapabilityAction;
    $user = pageCapabilityUser(collect([1]));

    foreach (['About record', 'About Capell', 'about', '/about'] as $query) {
        $result = $action->execute(pageInvocation(['query' => $query], $user));
        $matches = agentBridgeCapabilityList($result->data['matches']);
        $match = agentBridgeCapabilityArray($matches[0] ?? null);

        expect($matches)
            ->toHaveCount(1)
            ->and($match)
            ->toMatchArray([
                'id' => agentBridgeCapabilityInteger($page->getKey()),
                'name' => 'About record',
                'urls' => ['/about'],
            ]);
    }
});

it('inspects a page by human reference without returning content unless requested', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $page->forceFill(['name' => 'Services'])->saveQuietly();
    $languageId = createAgentBridgeCapabilityLanguage();
    Page::withoutEvents(fn (): mixed => $page->translations()->create([
        'language_id' => $languageId,
        'title' => 'Our services',
        'content' => 'Private draft copy',
        'meta' => ['slug' => 'services'],
    ]));

    $action = new InspectPageCapabilityAction;
    $user = pageCapabilityUser(collect([1]));
    $summary = $action->execute(pageInvocation(['page' => 'Our services'], $user));
    $withContent = $action->execute(pageInvocation([
        'page' => 'Our services',
        'include_content' => true,
    ], $user));
    $summaryPage = agentBridgeCapabilityArray($summary->data['page']);
    $summaryTranslations = agentBridgeCapabilityList($summaryPage['translations'] ?? null);
    $summaryTranslation = agentBridgeCapabilityArray($summaryTranslations[0] ?? null);
    $contentPage = agentBridgeCapabilityArray($withContent->data['page']);
    $contentTranslations = agentBridgeCapabilityList($contentPage['translations'] ?? null);
    $contentTranslation = agentBridgeCapabilityArray($contentTranslations[0] ?? null);

    expect($summaryTranslation)
        ->not->toHaveKey('content')
        ->and($contentTranslation['content'] ?? null)
        ->toBe('Private draft copy');
});

it('returns a client navigation instruction for opening a page editor by name', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $page->forceFill(['name' => 'Contact'])->saveQuietly();

    if (! Route::has('filament.admin.resources.pages.edit')) {
        Route::get('/admin/pages/{record}/edit', static fn (): string => 'edit')
            ->name('filament.admin.resources.pages.edit');
    }

    $result = (new OpenPageEditorCapabilityAction)->execute(pageInvocation([
        'page' => 'Contact',
    ], pageCapabilityUser(collect([1]))));

    expect($result->ok)->toBeTrue()
        ->and($result->data)
        ->toMatchArray([
            'action' => 'open_url',
            'page_id' => agentBridgeCapabilityInteger($page->getKey()),
            'name' => 'Contact',
        ])
        ->and(agentBridgeCapabilityString($result->data['url']))->not->toBeEmpty();
});

it('creates a translated page draft from human-readable authoring references', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Main website');
    $blueprintId = createAgentBridgeCapabilityBlueprint('Landing page', 'landing-page', 'page');
    $layoutId = DB::table('layouts')->insertGetId([
        'name' => 'Marketing layout',
        'key' => 'marketing-layout',
        'site_id' => $siteId,
        'default' => true,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = Page::withoutEvents(fn (): CapabilityResultData => (new CreateDraftPageCapabilityAction)->execute(pageInvocation([
        'name' => 'Summer campaign',
        'site' => 'Main website',
        'page_type' => 'landing-page',
        'layout' => 'Marketing layout',
        'title' => 'The summer campaign',
        'slug' => 'summer',
        'content' => 'Campaign draft copy',
    ], pageCapabilityUser(collect([(int) $siteId])))));

    $page = findAgentBridgeCapabilityPage($result->data['page_id'], ['translations', 'pageUrls']);
    $translation = $page->translations->first();

    $this->assertInstanceOf(Translation::class, $translation);

    expect($page->site_id)->toBe($siteId)
        ->and($page->blueprint_id)->toBe($blueprintId)
        ->and($page->layout_id)->toBe($layoutId)
        ->and(PublishSentinel::isDraftValue($page->visible_from))->toBeTrue()
        ->and($page->translations)->toHaveCount(1)
        ->and($translation->title)->toBe('The summer campaign')
        ->and($translation->content)->toBe('Campaign draft copy')
        ->and($translation->slug)->toBe('summer')
        ->and($page->pageUrls->pluck('url')->all())->toBe(['/summer']);
});

it('clones an existing page by name as a distinct disabled draft with copied content', function (): void {
    $source = createAgentBridgeCapabilityPage();
    $source->forceFill(['name' => 'Original services'])->saveQuietly();
    $languageId = createAgentBridgeCapabilityLanguage();
    Page::withoutEvents(fn (): mixed => $source->translations()->create([
        'language_id' => $languageId,
        'title' => 'Services',
        'content' => 'Original service copy',
        'meta' => ['slug' => 'services'],
    ]));
    Page::withoutEvents(fn (): mixed => $source->pageUrls()->create([
        'site_id' => 1,
        'language_id' => $languageId,
        'url' => '/services',
        'status' => true,
    ]));

    $result = Page::withoutEvents(fn (): CapabilityResultData => (new ClonePageCapabilityAction)->execute(pageInvocation([
        'page' => 'Original services',
        'name' => 'Consulting services',
        'title' => 'Consulting',
        'slug' => 'consulting',
    ], pageCapabilityUser(collect([1])))));

    $clone = findAgentBridgeCapabilityPage($result->data['page_id'], ['translations', 'pageUrls']);
    $cloneTranslation = $clone->translations->first();

    $this->assertInstanceOf(Translation::class, $cloneTranslation);

    expect($clone->getKey())->not->toBe($source->getKey())
        ->and($clone->uuid)->not->toBe($source->uuid)
        ->and($clone->name)->toBe('Consulting services')
        ->and(PublishSentinel::isDraftValue($clone->visible_from))->toBeTrue()
        ->and($clone->translations)->toHaveCount(1)
        ->and($cloneTranslation->title)->toBe('Consulting')
        ->and($cloneTranslation->content)->toBe('Original service copy')
        ->and($cloneTranslation->slug)->toBe('consulting')
        ->and($clone->pageUrls->pluck('url')->all())->toBe(['/consulting']);
});

it('updates page and translated content fields by URL reference', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $languageId = createAgentBridgeCapabilityLanguage();
    $translation = Page::withoutEvents(fn (): mixed => $page->translations()->create([
        'language_id' => $languageId,
        'title' => 'Old title',
        'content' => 'Old copy',
        'meta' => ['slug' => 'old-page'],
    ]));
    $this->assertInstanceOf(Translation::class, $translation);
    Page::withoutEvents(fn (): mixed => $page->pageUrls()->create([
        'site_id' => 1,
        'language_id' => $languageId,
        'url' => '/old-page',
        'status' => true,
    ]));

    $result = Page::withoutEvents(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page' => '/old-page',
        'name' => 'Updated page record',
        'title' => 'New title',
        'content' => 'New copy',
        'slug' => 'new-page',
    ], pageCapabilityUser(collect([1])))));

    expect($result->data['updated'])->toContain('name', 'title', 'content', 'slug')
        ->and($page->refresh()->name)->toBe('Updated page record')
        ->and($translation->refresh()->title)->toBe('New title')
        ->and($translation->content)->toBe('New copy')
        ->and($translation->slug)->toBe('new-page')
        ->and($page->pageUrls()->first()?->url)->toBe('/new-page');
});

it('lists human-readable page authoring choices for an allowed site', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Authoring site');
    createAgentBridgeCapabilityBlueprint('Standard page', 'standard-page', 'page');
    DB::table('layouts')->insert([
        'name' => 'Standard layout',
        'key' => 'standard-layout',
        'site_id' => $siteId,
        'default' => true,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new ListPageAuthoringOptionsCapabilityAction)->execute(pageInvocation([
        'site' => 'Authoring site',
    ], pageCapabilityUser(collect([(int) $siteId]))));
    $site = agentBridgeCapabilityArray($result->data['site']);
    $pageTypes = agentBridgeCapabilityList($result->data['page_types']);
    $layouts = agentBridgeCapabilityList($result->data['layouts']);
    $languages = agentBridgeCapabilityList($result->data['languages']);

    expect($site)
        ->toMatchArray(['id' => (int) $siteId, 'name' => 'Authoring site'])
        ->and(array_column($pageTypes, 'key'))->toContain('standard-page')
        ->and(array_column($layouts, 'key'))->toContain('standard-layout')
        ->and(array_column($languages, 'code'))->toContain('en');
});

it('rejects ambiguous human page references and returns disambiguation guidance', function (): void {
    $first = createAgentBridgeCapabilityPage();
    $second = Page::withoutEvents(fn (): Page => Page::query()->create([
        'name' => 'Second page',
        'site_id' => $first->site_id,
        'blueprint_id' => $first->blueprint_id,
        'layout_id' => $first->layout_id,
    ]));
    $first->forceFill(['name' => 'Shared name'])->saveQuietly();
    $second->forceFill(['name' => 'Shared name'])->saveQuietly();

    expect(fn (): CapabilityResultData => (new InspectPageCapabilityAction)->execute(pageInvocation([
        'page' => 'Shared name',
    ], pageCapabilityUser(collect([1])))))
        ->toThrow(ValidationException::class, 'matched more than one page');
});

it('finds inspects and opens an allowed site by domain or human name', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Main website');
    $languageId = createAgentBridgeCapabilityLanguage();
    DB::table('site_domains')->insert([
        'site_id' => $siteId,
        'language_id' => $languageId,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => '/',
        'status' => true,
        'default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = pageCapabilityUser(collect([(int) $siteId]));

    $search = (new SearchSitesCapabilityAction)->execute(pageInvocation([
        'query' => 'example.test',
    ], $user));
    $inspect = (new InspectSiteCapabilityAction)->execute(pageInvocation([
        'site' => 'Main website',
    ], $user));
    $open = (new OpenSiteEditorCapabilityAction)->execute(pageInvocation([
        'site' => 'example.test',
    ], $user));
    $sites = agentBridgeCapabilityList($search->data['sites']);
    $site = agentBridgeCapabilityArray($sites[0] ?? null);
    $domains = agentBridgeCapabilityList($site['domains'] ?? null);
    $domain = agentBridgeCapabilityArray($domains[0] ?? null);
    $inspectedSite = agentBridgeCapabilityArray($inspect->data['site']);

    expect($sites)->toHaveCount(1)
        ->and($site)->toMatchArray([
            'id' => (int) $siteId,
            'name' => 'Main website',
        ])
        ->and($domain)->toMatchArray([
            'domain' => 'example.test',
            'url' => 'https://example.test/',
        ])
        ->and($inspectedSite['name'] ?? null)->toBe('Main website')
        ->and($open->data)->toMatchArray([
            'action' => 'open_url',
            'site_id' => (int) $siteId,
            'name' => 'Main website',
        ])
        ->and(agentBridgeCapabilityString($open->data['url']))->not->toBeEmpty();
});

it('previews and confirms native page publication transitions by human reference', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $page->forceFill([
        'name' => 'Launch page',
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ])->saveQuietly();
    $user = pageCapabilityUser(collect([1]));
    $action = new TransitionPagePublicationCapabilityAction;
    $invocation = pageInvocation(
        ['page' => 'Launch page'],
        $user,
        'capell.pages.publish_now',
    );

    $preview = $action->preview($invocation);

    expect($preview->ok)->toBeTrue()
        ->and($preview->data)->toMatchArray([
            'page_id' => agentBridgeCapabilityInteger($page->getKey()),
            'transition' => 'publish-now',
            'outcome' => 'changed',
            'before_state' => 'draft',
            'after_state' => 'published',
        ])
        ->and(PublishSentinel::isDraftValue($page->refresh()->visible_from))->toBeTrue();

    $result = Page::withoutEvents(fn (): CapabilityResultData => $action->execute($invocation));

    expect($result->ok)->toBeTrue()
        ->and($result->data['outcome'])->toBe('changed')
        ->and($page->refresh()->publishVisibilityState()->value)->toBe('published');
});

it('schedules page publication with a future date and rejects a missing schedule', function (): void {
    $page = createAgentBridgeCapabilityPage();
    $page->forceFill([
        'name' => 'Scheduled launch',
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ])->saveQuietly();
    $user = pageCapabilityUser(collect([1]));
    $action = new TransitionPagePublicationCapabilityAction;
    $publishAt = now()->addDay()->startOfMinute();

    $result = Page::withoutEvents(fn (): CapabilityResultData => $action->execute(pageInvocation([
        'page' => 'Scheduled launch',
        'publish_at' => $publishAt->toIso8601String(),
    ], $user, 'capell.pages.schedule_publish')));

    expect($result->ok)->toBeTrue()
        ->and($result->data['after_state'])->toBe('scheduled')
        ->and($page->refresh()->visible_from?->toIso8601String())->toBe($publishAt->toIso8601String());

    expect(fn (): CapabilityResultData => $action->preview(pageInvocation([
        'page' => 'Scheduled launch',
    ], $user, 'capell.pages.schedule_publish')))
        ->toThrow(ValidationException::class);
});

/**
 * @return array<array-key, mixed>
 */
function agentBridgeCapabilityArray(mixed $value): array
{
    throw_unless(is_array($value), UnexpectedValueException::class, 'Expected capability array data.');

    return $value;
}

/**
 * @return list<mixed>
 */
function agentBridgeCapabilityList(mixed $value): array
{
    throw_unless(is_array($value) && array_is_list($value), UnexpectedValueException::class, 'Expected capability list data.');

    return $value;
}

function agentBridgeCapabilityInteger(mixed $value): int
{
    throw_unless(is_int($value), UnexpectedValueException::class, 'Expected capability integer data.');

    return $value;
}

function agentBridgeCapabilityString(mixed $value): string
{
    throw_unless(is_string($value), UnexpectedValueException::class, 'Expected capability string data.');

    return $value;
}

/**
 * @param  list<string>  $relations
 */
function findAgentBridgeCapabilityPage(mixed $pageId, array $relations = []): Page
{
    throw_unless(is_int($pageId) || is_string($pageId), UnexpectedValueException::class, 'Expected a page identifier.');

    $page = Page::query()
        ->with($relations)
        ->whereKey($pageId)
        ->first();

    throw_unless($page instanceof Page, UnexpectedValueException::class, 'Expected a capability page.');

    return $page;
}

function createAgentBridgeCapabilityPage(int $siteId = 1): Page
{
    createAgentBridgePageCapabilityTables();

    createAgentBridgeCapabilitySite('Agent Site ' . $siteId, $siteId);
    $blueprintId = DB::table('blueprints')->insertGetId([
        'name' => 'Page',
        'key' => 'page-' . $siteId,
        'type' => 'page',
        'default' => true,
        'admin' => json_encode(['configurator' => 'Default']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $layoutId = DB::table('layouts')->insertGetId([
        'name' => 'Agent Layout ' . $siteId,
        'key' => 'agent-layout-' . $siteId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pageId = DB::table('pages')->insertGetId([
        'name' => 'Agent Page ' . $siteId,
        'site_id' => $siteId,
        'blueprint_id' => $blueprintId,
        'layout_id' => $layoutId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Page::query()->findOrFail($pageId);
}

function createAgentBridgeCapabilitySite(string $name, ?int $siteId = null): int
{
    $attributes = [
        'name' => $name,
        'language_id' => createAgentBridgeCapabilityLanguage(),
    ];

    if (Schema::hasColumn('sites', 'blueprint_id')) {
        $attributes['blueprint_id'] = createAgentBridgeCapabilityBlueprint('Site', 'agent-site', 'site');
    }

    if (Schema::hasColumn('sites', 'theme_id')) {
        $attributes['theme_id'] = createAgentBridgeCapabilityTheme();
    }

    if (Schema::hasColumn('sites', 'id') && $siteId !== null) {
        DB::table('sites')->updateOrInsert(['id' => $siteId], $attributes);

        return $siteId;
    }

    return (int) DB::table('sites')->insertGetId($attributes);
}

function agentBridgeInteger(mixed $value): int
{
    return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
}

function createAgentBridgeCapabilityTheme(): int
{
    if (! Schema::hasTable('themes')) {
        return 1;
    }

    $themeId = agentBridgeInteger(DB::table('themes')->where('key', 'agent-theme')->value('id'));

    if ($themeId > 0) {
        return $themeId;
    }

    return (int) DB::table('themes')->insertGetId([
        'name' => 'Agent Theme',
        'blueprint_id' => createAgentBridgeCapabilityBlueprint('Theme', 'agent-theme', 'theme'),
        'key' => 'agent-theme',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAgentBridgeCapabilityLanguage(): int
{
    if (! Schema::hasTable('languages')) {
        return 1;
    }

    $languageId = agentBridgeInteger(DB::table('languages')->where('code', 'en')->value('id'));

    if ($languageId > 0) {
        return $languageId;
    }

    return (int) DB::table('languages')->insertGetId([
        'name' => 'English',
        'code' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAgentBridgeCapabilityBlueprint(string $name, string $key, string $type): int
{
    $blueprintId = agentBridgeInteger(DB::table('blueprints')
        ->where('key', $key)
        ->where('type', $type)
        ->value('id'));

    if ($blueprintId > 0) {
        return $blueprintId;
    }

    return (int) DB::table('blueprints')->insertGetId([
        'name' => $name,
        'key' => $key,
        'type' => $type,
        'default' => true,
        'admin' => json_encode(['configurator' => 'Default'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAgentBridgePageCapabilityTables(): void
{
    if (! Schema::hasTable('sites')) {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('language_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('blueprints')) {
        Schema::create('blueprints', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->nullable();
            $table->string('type')->nullable();
            $table->boolean('default')->default(false);
            $table->string('group')->nullable();
            $table->json('admin')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('themes')) {
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('blueprint_id')->nullable();
            $table->string('key')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('layouts')) {
        Schema::create('layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('pages')) {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name');
            $table->foreignId('site_id')->nullable();
            $table->foreignId('blueprint_id')->nullable();
            $table->foreignId('layout_id')->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();
            $table->integer('order')->default(0);
            $table->integer('depth')->default(0);
            $table->integer('_lft')->default(0);
            $table->integer('_rgt')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('page_urls')) {
        Schema::create('page_urls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('language_id');
            $table->nullableMorphs('pageable');
            $table->string('url');
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function pageInvocation(
    array $payload,
    AuthenticatableContract|false|null $user = null,
    string $capabilityKey = 'capell.pages.test',
): CapabilityInvocationData {
    if ($user === null) {
        $user = pageCapabilityUser(collect([agentBridgeInteger($payload['site_id'] ?? 1)]));
    }

    return new CapabilityInvocationData(
        capability: new CapabilityData(
            key: $capabilityKey,
            name: 'Page test',
            description: 'Page test capability.',
            scope: 'capell.pages.test',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::High,
            actionClass: FakeCapabilityAction::class,
        ),
        payload: $payload,
        user: $user === false ? null : $user,
    );
}

/**
 * @param  Collection<int, int>  $assignedSiteIds
 */
function pageCapabilityUser(Collection $assignedSiteIds, bool $isGlobalAdmin = false): AuthenticatableContract
{
    return new readonly class($assignedSiteIds, $isGlobalAdmin) implements AuthenticatableContract
    {
        /**
         * @param  Collection<int, int>  $assignedSiteIds
         */
        public function __construct(
            private Collection $assignedSiteIds,
            private bool $isGlobalAdmin,
        ) {}

        /**
         * @return Collection<int, int>
         */
        public function getAssignedSiteIds(): Collection
        {
            return $this->assignedSiteIds;
        }

        public function hasRole(string $role): bool
        {
            return $this->isGlobalAdmin && $role === config('capell.roles.super_admin', 'super_admin');
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken(mixed $value): void
        {
            //
        }

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}
