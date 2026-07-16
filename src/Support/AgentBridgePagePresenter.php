<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnexpectedValueException;

final class AgentBridgePagePresenter
{
    /** @return array<string, mixed> */
    public function present(Page $page, bool $includeContent = false): array
    {
        $page->loadMissing([
            'site',
            'type',
            'layout',
            'translations.language',
            'pageUrls',
        ]);
        $updatedAt = $page->getAttribute('updated_at');

        $translations = $page->translations
            ->map(function (Model $translation) use ($includeContent): array {
                throw_unless($translation instanceof Translation, UnexpectedValueException::class);

                $payload = [
                    'language' => [
                        'id' => AgentBridgeValue::integer($translation->language_id, 'Translation language ID'),
                        'name' => $translation->language?->name,
                        'code' => $translation->language?->code,
                    ],
                    'title' => $translation->title,
                    'slug' => $translation->slug,
                ];

                if ($includeContent) {
                    $payload['content'] = $translation->content;
                }

                return $payload;
            })
            ->values()
            ->all();

        /** @var Collection<int, string> $urls */
        $urls = $page->pageUrls
            ->pluck('url')
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->sort()
            ->values();

        return [
            'id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
            'name' => AgentBridgeValue::string($page->name, 'Page name'),
            'site' => $page->site === null ? null : [
                'id' => AgentBridgeValue::integer($page->site->getKey(), 'Site key'),
                'name' => AgentBridgeValue::string($page->site->name, 'Site name'),
            ],
            'page_type' => $page->type === null ? null : [
                'id' => AgentBridgeValue::integer($page->type->getKey(), 'Page type key'),
                'name' => AgentBridgeValue::string($page->type->name, 'Page type name'),
                'key' => AgentBridgeValue::string($page->type->key, 'Page type machine key'),
            ],
            'layout' => $page->layout === null ? null : [
                'id' => AgentBridgeValue::integer($page->layout->getKey(), 'Layout key'),
                'name' => AgentBridgeValue::string($page->layout->name, 'Layout name'),
                'key' => AgentBridgeValue::string($page->layout->key, 'Layout machine key'),
            ],
            'parent_id' => $page->parent_id === null
                ? null
                : AgentBridgeValue::integer($page->parent_id, 'Parent page ID'),
            'publishing_state' => $page->publishVisibilityState()->value,
            'urls' => $urls->all(),
            'translations' => $translations,
            'edit_url' => $this->editUrl($page),
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toIso8601String() : null,
        ];
    }

    public function editUrl(Page $page): string
    {
        $coreUrl = $this->coreEditUrl($page);

        if ($coreUrl !== null) {
            return $coreUrl;
        }

        $routeName = 'filament.admin.resources.pages.edit';

        if (Route::has($routeName)) {
            return route($routeName, ['record' => $page->getKey()]);
        }

        $adminPath = trim(AgentBridgeValue::string(config('capell-admin.path', 'admin'), 'Admin path'), '/');

        return url(sprintf(
            '/%s/pages/%s/edit',
            $adminPath,
            AgentBridgeValue::identifier($page->getRouteKey(), 'Page route key'),
        ));
    }

    private function coreEditUrl(Page $page): ?string
    {
        try {
            $url = GetEditPageResourceUrlAction::run($page);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
