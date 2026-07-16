<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class AgentBridgeRecordResolver
{
    /**
     * @return Collection<int, Site>
     */
    public function authorizedSites(?Authenticatable $user): Collection
    {
        /** @var Builder<Site> $query */
        $query = Site::query()
            ->with(['language', 'siteDomains.language'])
            ->orderByDesc('default')
            ->orderBy('order')
            ->orderBy('name');

        AgentBridgePageAccess::constrainSiteQuery($query, $user);

        $authorizer = resolve(AgentBridgePolicyAuthorizer::class);

        return $query->get()
            ->filter(fn (Site $site): bool => $authorizer->allows($user, 'view', $site))
            ->values();
    }

    public function site(?Authenticatable $user, int|string|null $reference = null): Site
    {
        $sites = $this->authorizedSites($user);

        if ($reference === null || (is_string($reference) && trim($reference) === '')) {
            if ($sites->count() === 1) {
                return $sites->firstOrFail();
            }

            $defaultSites = $sites->where('default', true)->values();

            if ($defaultSites->count() === 1) {
                return $defaultSites->firstOrFail();
            }

            throw ValidationException::withMessages([
                'site' => __('capell-agent-bridge::admin.capability_site_ambiguous', [
                    'sites' => $this->siteChoices($sites),
                ]),
            ]);
        }

        if (is_int($reference) || ctype_digit(trim((string) $reference))) {
            $site = $sites->firstWhere('id', (int) $reference);

            if ($site instanceof Site) {
                return $site;
            }
        }

        $needle = $this->normalize((string) $reference);
        $exact = $sites->filter(fn (Site $site): bool => $this->siteMatches($site, $needle, exact: true))->values();

        if ($exact->count() === 1) {
            return $exact->firstOrFail();
        }

        $partial = $sites->filter(fn (Site $site): bool => $this->siteMatches($site, $needle, exact: false))->values();

        if ($partial->count() === 1) {
            return $partial->firstOrFail();
        }

        $matches = $exact->isNotEmpty() ? $exact : $partial;

        throw ValidationException::withMessages([
            'site' => $matches->isEmpty()
                ? __('capell-agent-bridge::admin.capability_site_not_found', ['site' => (string) $reference])
                : __('capell-agent-bridge::admin.capability_site_ambiguous', ['sites' => $this->siteChoices($matches)]),
        ]);
    }

    /**
     * @param  list<string>  $with
     */
    public function page(
        ?Authenticatable $user,
        int|string $reference,
        int|string|null $siteReference = null,
        array $with = [],
    ): Page {
        if (is_int($reference) || ctype_digit(trim((string) $reference))) {
            $page = AgentBridgePageAccess::authorizedPage($user, (int) $reference, $with);
            resolve(AgentBridgePolicyAuthorizer::class)->authorize($user, 'view', $page);

            if ($siteReference !== null) {
                $site = $this->site($user, $siteReference);

                if (AgentBridgeValue::integer($page->site_id, 'Page site ID')
                    !== AgentBridgeValue::integer($site->getKey(), 'Site key')) {
                    throw ValidationException::withMessages([
                        'page' => __('capell-agent-bridge::admin.capability_page_not_found', ['page' => (string) $reference]),
                    ]);
                }
            }

            return $page;
        }

        $matches = $this->searchPages(
            user: $user,
            search: (string) $reference,
            siteReference: $siteReference,
            limit: 11,
            with: $with,
        );
        $exact = $matches
            ->filter(fn (Page $page): bool => $this->pageMatchesExactly($page, (string) $reference))
            ->values();

        if ($exact->count() === 1) {
            return $exact->firstOrFail();
        }

        if ($exact->isEmpty() && $matches->count() === 1) {
            return $matches->firstOrFail();
        }

        $ambiguous = $exact->isNotEmpty() ? $exact : $matches;

        throw ValidationException::withMessages([
            'page' => $ambiguous->isEmpty()
                ? __('capell-agent-bridge::admin.capability_page_not_found', ['page' => (string) $reference])
                : __('capell-agent-bridge::admin.capability_page_ambiguous', [
                    'pages' => $this->pageChoices($ambiguous),
                ]),
        ]);
    }

    /**
     * @param  list<string>  $with
     * @return Collection<int, Page>
     */
    public function searchPages(
        ?Authenticatable $user,
        string $search,
        int|string|null $siteReference = null,
        int $limit = 10,
        array $with = [],
    ): Collection {
        $search = trim($search);
        $limit = max(1, min(50, $limit));

        if ($search === '') {
            return new Collection;
        }

        $path = $this->urlPath($search);
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $pathLike = '%' . addcslashes(ltrim($path, '/'), '%_\\') . '%';

        /** @var Builder<Page> $query */
        $query = Page::query();
        AgentBridgePageAccess::constrainPageQuery($query, $user);

        if ($siteReference !== null && (! is_string($siteReference) || trim($siteReference) !== '')) {
            $site = $this->site($user, $siteReference);
            $query->where('site_id', $site->getKey());
        }

        $query->where(function (Builder $query) use ($like, $pathLike): void {
            $query
                ->where('pages.name', 'like', $like)
                ->orWhereHas('translations', function (BuilderContract $query) use ($like, $pathLike): void {
                    $query
                        ->where('title', 'like', $like)
                        ->orWhere('meta->slug', 'like', $pathLike);
                })
                ->orWhereHas(
                    'pageUrls',
                    fn (BuilderContract $query): BuilderContract => $query
                        ->where('url', 'like', $like)
                        ->orWhere('url', 'like', $pathLike),
                );
        });

        $relations = array_values(array_unique([
            'site',
            'type',
            'layout',
            'translations.language',
            'pageUrls',
            ...$with,
        ]));

        $authorizer = resolve(AgentBridgePolicyAuthorizer::class);

        return $query
            ->with($relations)
            ->orderBy('site_id')
            ->orderBy('name')
            ->limit(min(200, $limit * 4))
            ->get()
            ->filter(fn (Page $page): bool => $authorizer->allows($user, 'view', $page))
            ->take($limit)
            ->values();
    }

    public function language(Site $site, int|string|null $reference = null): Language
    {
        $site->loadMissing(['language', 'siteDomains.language']);
        $languages = collect([$site->language])
            ->merge($site->siteDomains->pluck('language'))
            ->filter(fn (mixed $language): bool => $language instanceof Language)
            ->unique(fn (Language $language): int => AgentBridgeValue::integer($language->getKey(), 'Language key'))
            ->values();

        if ($reference === null || (is_string($reference) && trim($reference) === '')) {
            $language = $site->language ?? $languages->first();

            if ($language instanceof Language) {
                return $language;
            }
        } else {
            $needle = $this->normalize((string) $reference);
            $matches = $languages->filter(fn (Language $language): bool => (is_int($reference) || ctype_digit(trim((string) $reference)))
                    ? AgentBridgeValue::integer($language->getKey(), 'Language key') === (int) $reference
                    : in_array($needle, [
                        $this->normalize((string) $language->name),
                        $this->normalize((string) $language->code),
                        $this->normalize((string) $language->locale),
                    ], true))->values();

            if ($matches->count() === 1) {
                return $matches->firstOrFail();
            }
        }

        throw ValidationException::withMessages([
            'language' => __('capell-agent-bridge::admin.capability_language_not_found', [
                'language' => (string) $reference,
            ]),
        ]);
    }

    public function pageType(int|string|null $reference = null): Blueprint
    {
        /** @var Collection<int, Blueprint> $pageTypes */
        $pageTypes = Blueprint::query()
            ->pageType()
            ->enabled()
            ->orderByDesc('default')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $pageType = $this->resolveNamedRecord($pageTypes, $reference);

        if ($pageType instanceof Blueprint) {
            return $pageType;
        }

        throw ValidationException::withMessages([
            'page_type' => __('capell-agent-bridge::admin.capability_page_type_not_found', [
                'page_type' => (string) $reference,
            ]),
        ]);
    }

    public function layout(Site $site, int|string|null $reference = null): Layout
    {
        /** @var Collection<int, Layout> $layouts */
        $layouts = Layout::query()
            ->enabled()
            ->where(function (Builder $query) use ($site): void {
                $query->whereNull('site_id')->orWhere('site_id', $site->getKey());
            })
            ->orderByRaw('CASE WHEN site_id = ? THEN 0 ELSE 1 END', [$site->getKey()])
            ->orderByDesc('default')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $layout = $this->resolveNamedRecord($layouts, $reference);

        if ($layout instanceof Layout) {
            return $layout;
        }

        throw ValidationException::withMessages([
            'layout' => __('capell-agent-bridge::admin.capability_layout_not_found', [
                'layout' => (string) $reference,
            ]),
        ]);
    }

    /**
     * @param  Collection<int, covariant Blueprint|Layout>  $records
     */
    private function resolveNamedRecord(Collection $records, int|string|null $reference): Blueprint|Layout|null
    {
        if ($reference === null || (is_string($reference) && trim($reference) === '')) {
            return $records->first();
        }

        $needle = $this->normalize((string) $reference);
        $exact = $records->filter(function (Blueprint|Layout $record) use ($reference, $needle): bool {
            if (is_int($reference) || ctype_digit(trim((string) $reference))) {
                return AgentBridgeValue::integer($record->getKey(), 'Named record key') === (int) $reference;
            }

            return in_array($needle, [
                $this->normalize((string) $record->name),
                $this->normalize((string) $record->key),
            ], true);
        })->values();

        if ($exact->count() === 1) {
            return $exact->firstOrFail();
        }

        $partial = $records->filter(fn (Blueprint|Layout $record): bool => str_contains(
            $this->normalize((string) $record->name . ' ' . (string) $record->key),
            $needle,
        ))->values();

        return $partial->count() === 1 ? $partial->firstOrFail() : null;
    }

    private function pageMatchesExactly(Page $page, string $reference): bool
    {
        $needle = $this->normalize($reference);
        $urlNeedle = $this->urlPath($reference);

        if ($this->normalize((string) $page->name) === $needle) {
            return true;
        }

        if ($page->translations->contains(fn (mixed $translation): bool => $translation instanceof Translation
                && ($this->normalize((string) $translation->title) === $needle
                    || $this->urlPath((string) $translation->slug) === $urlNeedle))) {
            return true;
        }

        return $page->pageUrls->contains(
            fn (mixed $pageUrl): bool => $pageUrl instanceof PageUrl
                && $this->urlPath(AgentBridgeValue::string($pageUrl->url, 'Page URL')) === $urlNeedle,
        );
    }

    private function siteMatches(Site $site, string $needle, bool $exact): bool
    {
        $values = [$this->normalize((string) $site->name)];

        foreach ($site->siteDomains as $siteDomain) {
            $values[] = $this->normalize((string) $siteDomain->domain);
            $values[] = $this->normalize((string) $siteDomain->full_url);
        }

        return collect($values)->contains(
            static fn (string $value): bool => $exact ? $value === $needle : str_contains($value, $needle),
        );
    }

    /** @param Collection<int, Site> $sites */
    private function siteChoices(Collection $sites): string
    {
        return $sites
            ->map(fn (Site $site): string => sprintf(
                '%s (#%d)',
                AgentBridgeValue::string($site->name, 'Site name'),
                AgentBridgeValue::integer($site->getKey(), 'Site key'),
            ))
            ->implode(', ');
    }

    /** @param Collection<int, Page> $pages */
    private function pageChoices(Collection $pages): string
    {
        return $pages
            ->take(10)
            ->map(function (Page $page): string {
                $pageUrl = $page->pageUrls->first();
                $url = $pageUrl instanceof PageUrl
                    ? AgentBridgeValue::string($pageUrl->url, 'Page URL')
                    : null;

                return sprintf(
                    '%s (#%d%s)',
                    AgentBridgeValue::string($page->name, 'Page name'),
                    AgentBridgeValue::integer($page->getKey(), 'Page key'),
                    $url !== null ? ', ' . $url : '',
                );
            })
            ->implode(', ');
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function urlPath(string $value): string
    {
        $path = parse_url(trim($value), PHP_URL_PATH);
        $path = is_string($path) ? $path : trim($value);
        $path = '/' . trim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
