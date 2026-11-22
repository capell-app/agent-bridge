<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\FilamentShield;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\AgentBridge\Actions\AdminTools\InvokeAdminAgentToolCapabilityAction;
use Capell\AgentBridge\Actions\AuditAgentBridgeCapabilityAction;
use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Actions\Media\UploadMediaCapabilityAction;
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
use Capell\AgentBridge\Actions\RevertAgentBridgeAuditEntryAction;
use Capell\AgentBridge\Actions\Sites\InspectSiteCapabilityAction;
use Capell\AgentBridge\Actions\Sites\OpenSiteEditorCapabilityAction;
use Capell\AgentBridge\Actions\Sites\SearchSitesCapabilityAction;
use Capell\AgentBridge\Capabilities\BuiltInAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConflictException;
use Capell\AgentBridge\Exceptions\AgentBridgeRevertRefusedException;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeAuditEntriesRelationManager;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AdminTools\AdminAgentToolCapabilities;
use Capell\AgentBridge\Support\AgentBridgeMcpV1Policy;
use Capell\AgentBridge\Support\AgentBridgePageRevertPoints;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Capell\AgentBridge\Tests\Fixtures\FakeCapabilityAction;
use Capell\AgentBridge\Tests\Fixtures\SiteAssignedUser;
use Capell\AgentBridge\Tests\Fixtures\User;
use Capell\AgentBridge\Tools\Site\InspectSiteStateTool;
use Capell\Core\Actions\Ai\HasUnreviewedAiContentAction;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

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

it('claims media upload idempotency atomically and rejects key reuse with different content', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Media site', 1);
    $key = (string) Str::uuid();
    $payload = [
        'site_id' => $siteId,
        'idempotency_key' => $key,
        'file_name' => 'pixel.png',
        'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ];
    $action = new UploadMediaCapabilityAction;

    $first = $action->execute(pageInvocation($payload, pageCapabilityUser(collect([1]))));
    $retry = $action->execute(pageInvocation($payload, pageCapabilityUser(collect([1]))));
    $sha256 = hash('sha256', (string) base64_decode($payload['content_base64'], true));
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);
    $capability = $registry->get('capell.media.upload');
    $policy = new AgentBridgeMcpV1Policy;
    $freshProjection = agentBridgeCapabilityArray($policy->projectOutput($capability, [
        'mode' => 'executed',
        'result' => $first->toPayload(),
    ])['result'] ?? null);
    $replayProjection = agentBridgeCapabilityArray($policy->projectOutput($capability, [
        'mode' => 'executed',
        'result' => $retry->toPayload(),
    ])['result'] ?? null);
    $freshData = agentBridgeCapabilityArray($freshProjection['data'] ?? null);
    $replayData = agentBridgeCapabilityArray($replayProjection['data'] ?? null);

    expect($first->data['replayed'])->toBeFalse()
        ->and($retry->data['replayed'])->toBeTrue()
        ->and($retry->data['media_id'])->toBe($first->data['media_id'])
        ->and($first->data['sha256'])->toBe($sha256)
        ->and($retry->data['sha256'])->toBe($sha256)
        ->and($freshData['sha256'])->toBe($sha256)
        ->and($freshData['replayed'])->toBeFalse()
        ->and($replayData['sha256'])->toBe($sha256)
        ->and($replayData['replayed'])->toBeTrue()
        ->and(DB::table('capell_agent_bridge_media_uploads')->count())->toBe(1);

    expect(fn (): CapabilityResultData => $action->execute(pageInvocation(array_replace($payload, [
        'file_name' => 'different.png',
        'content_base64' => base64_encode(base64_decode($payload['content_base64'], true) . 'different'),
    ]), pageCapabilityUser(collect([1])))))
        ->toThrow(AgentBridgeMcpV1ConflictException::class);
});

it('rejects draft page creation payloads without required page fields', function (): void {
    (new CreateDraftPageCapabilityAction)->preview(pageInvocation([
        'name' => 'Missing page relationships',
    ]));
})->throws(ValidationException::class);

it('canonicalises remotely supplied media upload names before storage', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Media filename site', 1);
    $payload = [
        'site_id' => $siteId,
        'idempotency_key' => (string) Str::uuid(),
        'file_name' => 'pixel.php.png',
        'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ];
    $action = new UploadMediaCapabilityAction;
    $user = pageCapabilityUser(collect([1]));

    expect($action->preview(pageInvocation($payload, $user))->data['file_name'])->toBe('pixel-php.png')
        ->and($action->execute(pageInvocation($payload, $user))->data['file_name'])->toBe('pixel-php.png');
});

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
        'expected_revision' => agentBridgeDraftRevision($page),
        'name' => 'Updated campaign page',
        'meta' => ['hidden' => false],
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('The selected page will be updated with safe editable fields.')
        ->and($result->data['page_id'])->toBe($page->getKey())
        ->and($result->data['changed_fields'])->toBe(['name', 'meta']);
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
        'expected_revision' => agentBridgeDraftRevision($page),
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
        'expected_revision' => agentBridgeDraftRevision($page),
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
    ensureAgentBridgeDraftRevision($page);
    $page->pageUrls()->firstOrCreate([
        'site_id' => $siteId,
        'language_id' => 1,
        'url' => '/agent-created-landing-page',
    ], ['status' => true]);

    $updated = Page::withoutEvents(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'expected_revision' => agentBridgeDraftRevision($page),
        'name' => 'Agent-updated landing page',
        'meta' => ['source' => 'agent', 'updated' => true],
    ], pageCapabilityUser(collect([$siteId])))));
    $disabled = Page::withoutEvents(fn (): CapabilityResultData => (new DisablePageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
    ], pageCapabilityUser(collect([$siteId])))));
    $readiness = (new InspectPagePublishingReadinessCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
    ], pageCapabilityUser(collect([$siteId]))));

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
    ], pageCapabilityUser(collect([$siteId])))));

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
    config()->set('activitylog', require dirname(__DIR__, 4) . '/vendor/spatie/laravel-activitylog/config/activitylog.php');
    resolve(EventSourcedRegistry::class)->register(Page::class, PageAggregate::class, PageStateSerializer::class);
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
    ensureAgentBridgeDraftRevision($page, revision: 0);

    $result = (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page' => '/old-page',
        'expected_revision' => 0,
        'name' => 'Updated page record',
        'title' => 'New title',
        'content' => 'New copy',
        'slug' => 'new-page',
    ], pageCapabilityUser(collect([1]))));

    expect($result->data['updated'])->toContain('name', 'title', 'content', 'slug')
        ->and($result->data['revision'])->toBe(1)
        ->and(agentBridgeDraftRevision($page))->toBe(1)
        ->and($page->refresh()->name)->toBe('Updated page record')
        ->and($translation->refresh()->title)->toBe('New title')
        ->and($translation->content)->toBe('New copy')
        ->and($translation->slug)->toBe('new-page')
        ->and($page->pageUrls()->first()?->url)->toBe('/new-page');

    expect(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'expected_revision' => 0,
        'title' => 'Stale overwrite',
    ], pageCapabilityUser(collect([1])))))
        ->toThrow(AgentBridgeMcpV1ConflictException::class);
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
    ], pageCapabilityUser(collect([$siteId]))));
    $site = agentBridgeCapabilityArray($result->data['site']);
    $pageTypes = agentBridgeCapabilityList($result->data['page_types']);
    $layouts = agentBridgeCapabilityList($result->data['layouts']);
    $languages = agentBridgeCapabilityList($result->data['languages']);

    expect($site)
        ->toMatchArray(['id' => $siteId, 'name' => 'Authoring site'])
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
    $user = pageCapabilityUser(collect([$siteId]));

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
            'id' => $siteId,
            'name' => 'Main website',
        ])
        ->and($domain)->toMatchArray([
            'domain' => 'example.test',
            'url' => 'https://example.test/',
        ])
        ->and($inspectedSite['name'] ?? null)->toBe('Main website')
        ->and($open->data)->toMatchArray([
            'action' => 'open_url',
            'site_id' => $siteId,
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
    $uuid = (string) Str::uuid();
    $pageId = DB::table('pages')->insertGetId([
        'uuid' => $uuid,
        'name' => 'Agent Page ' . $siteId,
        'site_id' => $siteId,
        'blueprint_id' => $blueprintId,
        'layout_id' => $layoutId,
        'visible_from' => PublishSentinel::draftValue(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = Page::query()->findOrFail($pageId);
    ensureAgentBridgeDraftRevision($page);

    return $page;
}

function ensureAgentBridgeDraftRevision(Page $page, PageWorkflowStatus $status = PageWorkflowStatus::Draft, int $revision = 1): void
{
    if (! is_string($page->uuid) || $page->uuid === '') {
        $page->forceFill(['uuid' => (string) Str::uuid()])->saveQuietly();
    }

    PageWorkflowState::query()->updateOrCreate(
        ['page_uuid' => $page->uuid],
        ['status' => $status, 'aggregate_version' => $revision],
    );
}

function agentBridgeDraftRevision(Page $page): int
{
    $state = PageWorkflowState::query()->where('page_uuid', $page->uuid)->firstOrFail();

    return $state->aggregate_version;
}

function createAgentBridgeCapabilitySite(string $name, ?int $siteId = null): int
{
    $attributes = [
        'name' => $name,
        'language_id' => createAgentBridgeCapabilityLanguage(),
    ];

    if (Schema::hasColumn('sites', 'uuid')) {
        $attributes['uuid'] = (string) Str::uuid();
    }

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
    $user ??= pageCapabilityUser(collect([agentBridgeInteger($payload['site_id'] ?? 1)]));

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

/**
 * A page with event-sourced history, as the editor leaves it, named $name at its latest version.
 */
function agentBridgeRevertablePage(string $name, int $siteId = 1): Page
{
    config()->set('activitylog', require dirname(__DIR__, 4) . '/vendor/spatie/laravel-activitylog/config/activitylog.php');
    resolve(EventSourcedRegistry::class)->register(Page::class, PageAggregate::class, PageStateSerializer::class);
    $page = createAgentBridgeCapabilityPage(siteId: $siteId);
    $page->forceFill(['name' => $name])->save();
    ensureAgentBridgeDraftRevision($page, revision: AgentBridgePageRevertPoints::currentVersion($page));

    return $page->refresh();
}

function agentBridgeAuditedUpdate(Page $page, string $name, Authenticatable $actor): CapellAgentBridgeAuditEntry
{
    $invocation = pageInvocation([
        'page_id' => $page->getKey(),
        'expected_revision' => agentBridgeDraftRevision($page),
        'name' => $name,
    ], $actor, 'capell.pages.update_draft');
    $result = (new UpdateDraftPageCapabilityAction)->execute($invocation);

    return AuditAgentBridgeCapabilityAction::run(
        event: 'capell_agent-bridge.pages.update_draft',
        capabilityKey: 'capell.pages.update_draft',
        scope: 'capell.pages.write',
        payload: $invocation->payload,
        result: $result,
        user: $actor,
    );
}

it('undoes an assistant page edit from its audit entry, once, and records who undid it', function (): void {
    $page = agentBridgeRevertablePage('Original name');
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-editor@example.test', 'password' => 'secret']);

    $entry = agentBridgeAuditedUpdate($page, 'Assistant name', $owner);

    expect($page->refresh()->name)->toBe('Assistant name')
        ->and($entry->revert_point)->toMatchArray(['kind' => 'page_rollback', 'page_id' => $page->getKey()])
        ->and($entry->result)->not->toHaveKey('revertPoint')
        ->and(json_encode($entry->result, JSON_THROW_ON_ERROR))->not->toContain('version_before')
        ->and(RevertAgentBridgeAuditEntryAction::allows($entry, $owner))->toBeTrue();

    RevertAgentBridgeAuditEntryAction::run($entry, $owner);

    expect($page->refresh()->name)->toBe('Original name')
        ->and($entry->refresh()->reverted_at)->not->toBeNull()
        ->and($entry->reverted_by_id)->toBe($owner->getKey())
        ->and(CapellAgentBridgeAuditEntry::query()->where('event', 'capell_agent-bridge.capability.reverted')->value('payload'))->toBe(['audit_entry_id' => $entry->getKey()])
        ->and(RevertAgentBridgeAuditEntryAction::allows($entry, $owner))->toBeFalse()
        ->and(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_unavailable'));
});

it('refuses to undo an assistant edit once someone has changed the page since', function (): void {
    $page = agentBridgeRevertablePage('Original name');
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-later@example.test', 'password' => 'secret']);
    $entry = agentBridgeAuditedUpdate($page, 'Assistant name', $owner);

    $page->refresh()->forceFill(['name' => 'Later human edit'])->save();

    expect(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_page_changed_since'))
        ->and($page->refresh()->name)->toBe('Later human edit')
        ->and($entry->refresh()->reverted_at)->toBeNull();
});

it('refuses to undo an assistant edit for someone who cannot reach the page site or roll it back', function (): void {
    $page = agentBridgeRevertablePage('Original name', siteId: 5);
    $editor = pageCapabilityUser(collect([5]));
    $entry = agentBridgeAuditedUpdate($page, 'Assistant name', $editor);
    $outsider = SiteAssignedUser::query()->create(['name' => 'Outsider', 'email' => 'revert-outsider@example.test', 'password' => 'secret']);

    expect(RevertAgentBridgeAuditEntryAction::allows($entry, $outsider))->toBeFalse()
        ->and(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry, $outsider))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_forbidden'))
        ->and($page->refresh()->name)->toBe('Assistant name');

    // Site access alone is not enough: undoing an edit needs the page rollback permission.
    $page2 = agentBridgeRevertablePage('Second original');
    $owner = SiteAssignedUser::query()->create(['name' => 'No rollback', 'email' => 'revert-norollback@example.test', 'password' => 'secret']);
    $entry2 = agentBridgeAuditedUpdate($page2, 'Assistant second', $owner);
    $gate = new AccessGate(app(), static fn (): mixed => null);
    $gate->before(static fn (mixed $user, string $ability): bool => $ability !== CapellPermission::RollbackPage->name());
    Gate::swap($gate);

    expect(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry2, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_forbidden'))
        ->and($page2->refresh()->name)->toBe('Assistant second');
});

it('undoes an assistant page creation by deleting the untouched draft, and refuses once it has changed', function (): void {
    config()->set('activitylog', require dirname(__DIR__, 4) . '/vendor/spatie/laravel-activitylog/config/activitylog.php');
    resolve(EventSourcedRegistry::class)->register(Page::class, PageAggregate::class, PageStateSerializer::class);
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Agent Site', 1);
    $blueprintId = createAgentBridgeCapabilityBlueprint('Page', 'page', 'page');
    $layoutId = DB::table('layouts')->insertGetId(['name' => 'Agent Layout', 'key' => 'agent-layout', 'created_at' => now(), 'updated_at' => now()]);
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-create@example.test', 'password' => 'secret']);
    $create = function (string $name) use ($siteId, $blueprintId, $layoutId, $owner): CapellAgentBridgeAuditEntry {
        $invocation = pageInvocation(['name' => $name, 'site_id' => $siteId, 'blueprint_id' => $blueprintId, 'layout_id' => $layoutId], $owner, 'capell.pages.create_draft');

        return AuditAgentBridgeCapabilityAction::run(
            event: 'capell_agent-bridge.pages.create_draft',
            capabilityKey: 'capell.pages.create_draft',
            scope: 'capell.pages.write',
            payload: $invocation->payload,
            result: (new CreateDraftPageCapabilityAction)->execute($invocation),
            user: $owner,
        );
    };

    $untouched = $create('Assistant draft');
    $untouchedId = agentBridgeCapabilityInteger(data_get($untouched->revert_point, 'page_id'));

    RevertAgentBridgeAuditEntryAction::run($untouched, $owner);

    $edited = $create('Assistant draft edited later');
    $editedPage = findAgentBridgeCapabilityPage(data_get($edited->revert_point, 'page_id'));
    $editedPage->forceFill(['name' => 'Kept by a person'])->save();

    expect($untouched->revert_point)->toMatchArray(['kind' => 'page_delete'])
        ->and(Page::query()->whereKey($untouchedId)->exists())->toBeFalse()
        ->and($untouched->refresh()->reverted_at)->not->toBeNull()
        ->and(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($edited, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_page_changed_since'))
        ->and(Page::query()->whereKey($editedPage->getKey())->exists())->toBeTrue();
});

it('refuses to undo an assistant page creation once a page has been added under the draft', function (): void {
    config()->set('activitylog', require dirname(__DIR__, 4) . '/vendor/spatie/laravel-activitylog/config/activitylog.php');
    resolve(EventSourcedRegistry::class)->register(Page::class, PageAggregate::class, PageStateSerializer::class);
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Agent Site', 1);
    $blueprintId = createAgentBridgeCapabilityBlueprint('Page', 'page', 'page');
    $layoutId = DB::table('layouts')->insertGetId(['name' => 'Agent Layout', 'key' => 'agent-layout', 'created_at' => now(), 'updated_at' => now()]);
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-child@example.test', 'password' => 'secret']);
    $invocation = pageInvocation(['name' => 'Assistant parent', 'site_id' => $siteId, 'blueprint_id' => $blueprintId, 'layout_id' => $layoutId], $owner, 'capell.pages.create_draft');
    $entry = AuditAgentBridgeCapabilityAction::run(
        event: 'capell_agent-bridge.pages.create_draft',
        capabilityKey: 'capell.pages.create_draft',
        scope: 'capell.pages.write',
        payload: $invocation->payload,
        result: (new CreateDraftPageCapabilityAction)->execute($invocation),
        user: $owner,
    );
    $draftId = agentBridgeCapabilityInteger(data_get($entry->revert_point, 'page_id'));

    // An editor adds a page under the draft. That changes the child, not the draft's version.
    $childId = DB::table('pages')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Editor child',
        'site_id' => $siteId,
        'blueprint_id' => $blueprintId,
        'layout_id' => $layoutId,
        'parent_id' => $draftId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_page_changed_since'))
        ->and(Page::query()->whereKey($draftId)->exists())->toBeTrue()
        ->and(Page::query()->whereKey($childId)->exists())->toBeTrue()
        ->and($entry->refresh()->reverted_at)->toBeNull();
});

it('refuses to undo an assistant edit whose earlier version is no longer stored, instead of failing', function (): void {
    $page = agentBridgeRevertablePage('Original name');
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-missing@example.test', 'password' => 'secret']);
    $entry = agentBridgeAuditedUpdate($page, 'Assistant name', $owner);
    $versionBefore = agentBridgeCapabilityInteger(data_get($entry->revert_point, 'version_before'));

    // The history up to the earlier version has been pruned from the event store.
    /** @var class-string<EloquentStoredEvent> $storedEventModel */
    $storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);
    $storedEventModel::query()->where('aggregate_uuid', $page->uuid)->where('aggregate_version', '<=', $versionBefore)->delete();

    expect(fn (): CapellAgentBridgeAuditEntry => RevertAgentBridgeAuditEntryAction::run($entry, $owner))
        ->toThrow(AgentBridgeRevertRefusedException::class, __('capell-agent-bridge::admin.revert_unavailable'))
        ->and($page->refresh()->name)->toBe('Assistant name')
        ->and($entry->refresh()->reverted_at)->toBeNull();
});

it('records no revert point for a change it could not tell apart from later work', function (): void {
    $page = createAgentBridgeCapabilityPage();
    ensureAgentBridgeDraftRevision($page, revision: 0);

    // Without event-sourced history there is no earlier version to return to.
    $result = Page::withoutEvents(fn (): CapabilityResultData => (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'expected_revision' => 0,
        'name' => 'No history',
    ], pageCapabilityUser(collect([1])))));

    expect($result->revertPoint)->toBeNull();
});

it('offers Undo change on the user page audit entries only while the change can be undone', function (): void {
    $page = agentBridgeRevertablePage('Original name');
    $owner = SiteAssignedUser::query()->create(['name' => 'Editor', 'email' => 'revert-admin@example.test', 'password' => 'secret']);
    $entry = agentBridgeAuditedUpdate($page, 'Assistant name', $owner);
    $readOnly = AuditAgentBridgeCapabilityAction::run(event: 'capell_agent-bridge.pages.search', capabilityKey: 'capell.pages.search', scope: 'capell.pages.read', user: $owner);
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $this->actingAs($owner);

    $pageLookups = 0;
    DB::listen(function (QueryExecuted $query) use (&$pageLookups): void {
        if (preg_match('/from [`"]?pages[`"]?\\s+where/i', $query->sql) === 1) {
            $pageLookups++;
        }
    });

    $table = Livewire::test(AgentBridgeAuditEntriesRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
        ->assertActionVisible(TestAction::make('revert')->table($entry))
        ->assertActionVisible(TestAction::make('revert')->table($entry));

    // The row's actions are asked about again and again; the page is looked up once.
    expect($pageLookups)->toBe(1);

    $table
        ->assertActionHidden(TestAction::make('revert')->table($readOnly))
        ->assertActionVisible(TestAction::make('revert')->table($entry))
        ->callAction(TestAction::make('revert')->table($entry))
        ->assertNotified(__('capell-agent-bridge::admin.revert_done'))
        ->assertActionHidden(TestAction::make('revert')->table($entry->refresh()));

    expect($page->refresh()->name)->toBe('Original name');
});

it('keeps every MCP v1 capability inside a token site limit, so a capability without one cannot join the list unnoticed', function (): void {
    $page = createAgentBridgeCapabilityPage(siteId: 1);
    createAgentBridgeCapabilityPage(siteId: 2);
    $blueprintId = agentBridgeInteger($page->blueprint_id);
    $layoutId = agentBridgeInteger($page->layout_id);
    $owner = User::query()->create(['name' => 'Limited owner', 'email' => 'limited-owner@example.test', 'password' => 'secret']);
    $owner->globalAdmin = true;

    $token = CreateAgentBridgeTokenAction::run($owner, 'Site two only', ['*'], null, null, [2])['token'];
    $actor = pageCapabilityUser(collect([1, 2]));

    // Each case reaches site 1, which the actor may use but the token may not. A
    // capability that is refused must throw; a search must leave site 1 out.
    $cases = [
        'capell.sites.search' => [SearchSitesCapabilityAction::class, 'execute', ['query' => 'Agent Site 1'], ['sites', 'Agent Site 1']],
        'capell.sites.inspect' => [InspectSiteCapabilityAction::class, 'execute', ['site_id' => 1], null],
        'capell.pages.search' => [SearchPagesCapabilityAction::class, 'execute', ['query' => 'Agent Page 1'], ['matches', 'Agent Page 1']],
        'capell.pages.inspect' => [InspectPageCapabilityAction::class, 'execute', ['page_id' => $page->getKey()], null],
        'capell.pages.authoring_options' => [ListPageAuthoringOptionsCapabilityAction::class, 'execute', ['site_id' => 1], null],
        'capell.pages.create_draft' => [CreateDraftPageCapabilityAction::class, 'preview', ['name' => 'Limited draft', 'site_id' => 1, 'blueprint_id' => $blueprintId, 'layout_id' => $layoutId], null],
        'capell.pages.update_draft' => [UpdateDraftPageCapabilityAction::class, 'preview', ['page_id' => $page->getKey(), 'expected_revision' => agentBridgeDraftRevision($page), 'name' => 'Limited update'], null],
        'capell.media.upload' => [UploadMediaCapabilityAction::class, 'preview', ['site_id' => 1, 'idempotency_key' => (string) Str::uuid(), 'file_name' => 'pixel.png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='], null],
    ];

    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);
    $mcpV1Keys = (new AgentBridgeMcpV1Policy)->allowedCapabilities($registry->all())->pluck('key')->sort()->values()->all();
    $caseKeys = array_keys($cases);
    sort($caseKeys);

    // A new MCP v1 capability needs a case here proving it keeps to the token's sites.
    expect($caseKeys)->toBe($mcpV1Keys);

    foreach ($cases as $key => [$actionClass, $method, $payload, $hidden]) {
        $run = fn (): CapabilityResultData => (new $actionClass)->{$method}(pageInvocation($payload, $actor, $key));

        // Without a token limit the actor reaches site 1.
        $unrestricted = $run();

        if ($hidden === null) {
            expect(fn (): mixed => AgentBridgeTokenSiteRestriction::within($token, $run))
                ->toThrow(ValidationException::class);

            continue;
        }

        [$resultsKey, $hiddenName] = $hidden;
        $restricted = AgentBridgeTokenSiteRestriction::within($token, $run);

        expect(json_encode($unrestricted->data[$resultsKey], JSON_THROW_ON_ERROR))->toContain($hiddenName)
            ->and(json_encode($restricted->data[$resultsKey], JSON_THROW_ON_ERROR))->not->toContain($hiddenName);
    }
});

it('keeps the site state counts to the sites a site-limited token may reach', function (): void {
    createAgentBridgeCapabilityPage(siteId: 1);
    createAgentBridgeCapabilityPage(siteId: 2);
    $owner = SiteAssignedUser::query()->create(['name' => 'State owner', 'email' => 'state-owner@example.test', 'password' => 'secret']);
    $token = CreateAgentBridgeTokenAction::run($owner, 'Site two only', ['*'], null, null, [2])['token'];
    $counts = static function (): array {
        $content = (new InspectSiteStateTool)->handle()->getStructuredContent();

        throw_unless(is_array($content) && is_array($content['counts'] ?? null), RuntimeException::class, 'Expected site state counts.');

        return $content['counts'];
    };

    $installation = $counts();

    app()->instance(CapellAgentBridgeToken::class, $token);
    $limited = $counts();

    $token->forceFill(['revoked_at' => now()])->save();
    $revoked = $counts();

    expect($installation['sites'])->toBe(2)
        ->and($installation['pages'])->toBe(2)
        ->and($limited)->toMatchArray(['sites' => 1, 'pages' => 1, 'languages' => null, 'blueprints' => null])
        ->and($revoked)->toMatchArray(['sites' => 0, 'pages' => 0, 'languages' => null, 'blueprints' => null]);
});

it('registers the Core Admin read tools and the draft save on the free Agent Bridge, and no live write tool', function (): void {
    $registry = new CapellAgentBridgeCapabilityRegistry;
    (new BuiltInAgentBridgeCapabilityProvider)->registerCapabilities($registry);
    $keys = $registry->all()->pluck('key')->all();

    expect($keys)->toContain(...AdminAgentToolCapabilities::freeKeys());

    foreach (AdminAgentToolCapabilities::proKeys() as $proKey) {
        expect($keys)->not->toContain($proKey);
    }

    $draft = $registry->get('capell.admin.page_draft.save');
    $read = $registry->get('capell.admin.blueprints.read');

    expect($draft->needsConfirmation())->toBeTrue()
        ->and($draft->risk)->toBe(CapabilityRiskEnum::Low)
        ->and($draft->scope)->toBe('capell.pages.write')
        ->and($read->risk)->toBe(CapabilityRiskEnum::Read)
        ->and($read->needsConfirmation())->toBeFalse()
        ->and($read->inputSchema['properties'] ?? [])->toHaveKey('site')
        ->and((new AgentBridgeMcpV1Policy)->allowedCapabilities($registry->all())->pluck('key')->intersect(AdminAgentToolCapabilities::freeKeys())->all())->toBe([]);
});

it('reads Core Admin blueprints through the capability for a site the user can reach', function (): void {
    config()->set('filament-shield.permissions.separator', ':');
    config()->set('filament-shield.permissions.case', 'pascal');
    app()->singleton('filament-shield', static fn (): FilamentShield => new FilamentShield);
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Admin tool site', 1);
    createAgentBridgeCapabilityBlueprint('Admin tool page', 'admin-tool-page', 'page');
    $action = resolve(InvokeAdminAgentToolCapabilityAction::class);
    $invocation = pageInvocation(['site_id' => $siteId, 'type' => 'page'], capabilityKey: 'capell.admin.blueprints.read');

    expect($action->preview($invocation)->data)->toBe(['tool' => 'admin.blueprint.read', 'site_id' => $siteId]);

    $result = $action->execute($invocation);

    expect($result->ok)->toBeTrue()
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->toContain('admin-tool-page');
});

it('refuses a Core Admin tool without a user, outside the user sites, or installation-wide under a site-limited token', function (): void {
    createAgentBridgePageCapabilityTables();
    createAgentBridgeCapabilitySite('Reachable site', 1);
    createAgentBridgeCapabilitySite('Other site', 2);
    $action = resolve(InvokeAdminAgentToolCapabilityAction::class);

    expect(fn (): CapabilityResultData => $action->preview(pageInvocation(['site_id' => 1], false, 'capell.admin.blueprints.read')))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): CapabilityResultData => $action->execute(pageInvocation(['site_id' => 2], pageCapabilityUser(collect([1])), 'capell.admin.blueprints.read')))
        ->toThrow(ValidationException::class)
        ->and(fn (): CapabilityResultData => $action->preview(pageInvocation(['site_id' => 1], capabilityKey: 'capell.admin.not_a_tool')))
        ->toThrow(AuthorizationException::class);

    $owner = User::query()->create(['name' => 'Admin tool owner', 'email' => 'admin-tool-owner@example.test', 'password' => 'secret']);
    $owner->globalAdmin = true;
    $token = CreateAgentBridgeTokenAction::run($owner, 'Site one only', ['*'], null, null, [1])['token'];
    $settings = pageInvocation(['site_id' => 1, 'group' => 'general', 'values' => []], capabilityKey: 'capell.admin.settings.write');

    expect(fn (): CapabilityResultData => AgentBridgeTokenSiteRestriction::within($token, fn (): CapabilityResultData => $action->preview($settings)))
        ->toThrow(AuthorizationException::class, (string) __('capell-agent-bridge::admin.admin_tool_site_limited_token'));
});

it('records every field an agent writes as unreviewed AI content, so the page cannot publish until a person reviews it', function (): void {
    config()->set('activitylog', require dirname(__DIR__, 4) . '/vendor/spatie/laravel-activitylog/config/activitylog.php');
    resolve(EventSourcedRegistry::class)->register(Page::class, PageAggregate::class, PageStateSerializer::class);
    $page = createAgentBridgeCapabilityPage();
    $languageId = createAgentBridgeCapabilityLanguage();
    Page::withoutEvents(fn (): mixed => $page->translations()->create([
        'language_id' => $languageId,
        'title' => 'Person-written title',
        'content' => 'Person-written copy',
        'meta' => ['slug' => 'reviewed-page'],
    ]));
    Page::withoutEvents(fn (): mixed => $page->pageUrls()->create([
        'site_id' => 1,
        'language_id' => $languageId,
        'url' => '/reviewed-page',
        'status' => true,
    ]));
    ensureAgentBridgeDraftRevision($page, revision: 0);
    $user = pageCapabilityUser(collect([1]));

    expect(HasUnreviewedAiContentAction::run($page))->toBeFalse();

    (new UpdateDraftPageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'expected_revision' => 0,
        'name' => 'Agent-named page',
        'content' => 'Agent-written copy',
        // An agent must not be able to forge the page's public disclosure summary.
        'meta' => [AiDisclosure::PAGE_META_KEY => AiDisclosure::None->value],
    ], $user, 'capell.pages.update_draft'));

    $rows = AiProvenance::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->orderBy('field')
        ->get();

    expect($rows->map(fn (AiProvenance $row): array => [$row->field, $row->language_id, $row->disclosure, $row->capability, $row->confirmed_at])->all())
        ->toBe([
            ['content', $languageId, AiDisclosure::Generated, 'capell.pages.update_draft', null],
            ['meta', null, AiDisclosure::Generated, 'capell.pages.update_draft', null],
            ['name', null, AiDisclosure::Generated, 'capell.pages.update_draft', null],
        ])
        ->and($rows->first()?->actor_id)->toBe(agentBridgeInteger($user->getAuthIdentifier()))
        ->and(HasUnreviewedAiContentAction::run($page))->toBeTrue()
        ->and($page->refresh()->meta[AiDisclosure::PAGE_META_KEY] ?? null)->toBe(AiDisclosure::Generated->value);

    // The revision the agent's change created holds its provenance, so rolling back to it
    // restores the AI content together with its unreviewed rows.
    /** @var class-string<EloquentStoredEvent> $storedEventModel */
    $storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);
    $revision = $storedEventModel::query()->where('aggregate_uuid', $page->aggregateUuid())->orderByDesc('aggregate_version')->firstOrFail();
    $revisionFields = collect(agentBridgeCapabilityList(data_get($revision->event_properties, 'state.aiProvenance')))
        ->map(fn (mixed $row): mixed => data_get($row, 'field'))
        ->sort()
        ->values()
        ->all();

    expect($revisionFields)->toBe(['content', 'meta', 'name']);
});

it('records the fields of an agent-created draft and the fields an agent chose for a clone as unreviewed AI content', function (): void {
    createAgentBridgePageCapabilityTables();
    $siteId = createAgentBridgeCapabilitySite('Main website');
    createAgentBridgeCapabilityBlueprint('Landing page', 'landing-page', 'page');
    DB::table('layouts')->insert([
        'name' => 'Marketing layout',
        'key' => 'marketing-layout',
        'site_id' => $siteId,
        'default' => true,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = pageCapabilityUser(collect([$siteId]));

    $created = Page::withoutEvents(fn (): CapabilityResultData => (new CreateDraftPageCapabilityAction)->execute(pageInvocation([
        'name' => 'Summer campaign',
        'site' => 'Main website',
        'page_type' => 'landing-page',
        'layout' => 'Marketing layout',
        'content' => 'Campaign draft copy',
    ], $user, 'capell.pages.create_draft')));
    $page = findAgentBridgeCapabilityPage($created->data['page_id']);

    expect(AiProvenance::query()->where('pageable_id', $page->getKey())->orderBy('field')->pluck('field')->all())
        ->toBe(['content', 'name', 'slug', 'title'])
        ->and(HasUnreviewedAiContentAction::run($page))->toBeTrue();

    $cloned = Page::withoutEvents(fn (): CapabilityResultData => (new ClonePageCapabilityAction)->execute(pageInvocation([
        'page_id' => $page->getKey(),
        'site_id' => $siteId,
        'title' => 'Winter campaign',
    ], $user, 'capell.pages.clone')));

    $cloneRows = AiProvenance::query()->where('pageable_id', $cloned->data['page_id'])->orderBy('field')->get();

    // The copied content stays unreviewed and the agent's new title is recorded as its own write.
    expect($cloneRows->pluck('field')->all())->toBe(['content', 'name', 'slug', 'title'])
        ->and($cloneRows->pluck('capability', 'field')->all())->toMatchArray([
            'content' => 'capell.pages.create_draft',
            'title' => 'capell.pages.clone',
        ])
        ->and(HasUnreviewedAiContentAction::run(findAgentBridgeCapabilityPage($cloned->data['page_id'])))->toBeTrue();
});
