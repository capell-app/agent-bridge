<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\Admin\Actions\ReplicatePageAction;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\ClonePageCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePagePresenter;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ClonePageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $source = $this->source($invocation, $payload);
        $site = $source->site;

        throw_unless($site !== null, RuntimeException::class, (string) __('capell-agent-bridge::admin.error_page_site_missing'));

        $language = resolve(AgentBridgeRecordResolver::class)->language(
            $site,
            $payload->language_id ?? $payload->language,
        );
        $targetSlug = $this->uniqueCloneSlug(
            source: $source,
            languageId: AgentBridgeValue::integer($language->getKey(), 'Language key'),
            preferredSlug: $payload->slug,
        );

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_clone_preview'),
            data: [
                'source' => resolve(AgentBridgePagePresenter::class)->present($source),
                'target' => [
                    'name' => $payload->name,
                    'title' => $payload->title,
                    'slug' => $targetSlug,
                    'publishing_state' => 'draft',
                ],
            ],
            warnings: [(string) __('capell-agent-bridge::admin.warning_internal_clone_only')],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $source = $this->source($invocation, $payload);
        $resolver = resolve(AgentBridgeRecordResolver::class);
        $site = $source->site;

        throw_unless($site !== null, RuntimeException::class, (string) __('capell-agent-bridge::admin.error_page_site_missing'));

        $language = $resolver->language($site, $payload->language_id ?? $payload->language);
        $targetName = $payload->name !== null ? trim($payload->name) : null;
        $targetTitle = $payload->title !== null ? trim($payload->title) : null;
        $targetSlug = $this->uniqueCloneSlug(
            source: $source,
            languageId: AgentBridgeValue::integer($language->getKey(), 'Language key'),
            preferredSlug: $payload->slug,
        );

        $clone = DB::transaction(function () use ($source, $language, $targetName, $targetTitle, $targetSlug): Page {
            $source->loadMissing('translations');
            $translations = $source->translations
                ->map(function (Model $translation) use ($source, $language, $targetTitle, $targetSlug): array {
                    throw_unless($translation instanceof Translation, RuntimeException::class);

                    $meta = (array) $translation->meta;
                    $translationLanguageId = AgentBridgeValue::integer($translation->language_id, 'Translation language ID');
                    $isTargetLanguage = $translationLanguageId === AgentBridgeValue::integer($language->getKey(), 'Language key');
                    $meta['slug'] = $isTargetLanguage
                        ? $targetSlug
                        : $this->uniqueCloneSlug($source, $translationLanguageId);

                    return [
                        'language_id' => $translationLanguageId,
                        'title' => $isTargetLanguage && $targetTitle !== null
                            ? $targetTitle
                            : $translation->title,
                        'content' => $translation->content,
                        'meta' => $meta,
                    ];
                })
                ->all();

            if ($translations === []) {
                $translations[] = [
                    'language_id' => AgentBridgeValue::integer($language->getKey(), 'Language key'),
                    'title' => $targetTitle ?? $targetName ?? AgentBridgeValue::string($source->name, 'Page name'),
                    'content' => '',
                    'meta' => ['slug' => $targetSlug],
                ];
            }

            $data = [
                'visible_from' => PublishSentinel::draftValue(),
                'visible_until' => null,
                'translations' => $translations,
            ];

            if ($targetName !== null && $targetName !== '') {
                $data['name'] = $targetName;
            }

            $clone = ReplicatePageAction::run($source, $data);

            if (! is_string($clone->uuid) || $clone->uuid === '') {
                $clone->forceFill(['uuid' => Str::uuid()->toString()])->saveQuietly();
            }

            SetupPageUrlsAction::run($clone);

            return $clone->fresh(['translations.language', 'pageUrls']) ?? $clone;
        });

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_cloned'),
            data: [
                'source_page_id' => AgentBridgeValue::integer($source->getKey(), 'Source page key'),
                'page_id' => AgentBridgeValue::integer($clone->getKey(), 'Cloned page key'),
                'name' => AgentBridgeValue::string($clone->name, 'Cloned page name'),
                'publishing_state' => $clone->publishVisibilityState()->value,
                'urls' => $clone->pageUrls->pluck('url')->values()->all(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatedPayload(array $payload): ClonePageCapabilityInputData
    {
        validator($payload, [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'language' => ['sometimes', 'nullable'],
            'language_id' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:191'],
            'admin' => ['prohibited'],
        ])->validate();

        return ClonePageCapabilityInputData::fromPayload($payload);
    }

    private function source(CapabilityInvocationData $invocation, ClonePageCapabilityInputData $payload): Page
    {
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload->page_id ?? $payload->page ?? '',
            siteReference: $payload->site_id ?? $payload->site,
            with: ['translations.language', 'pageUrls', 'site'],
        );

        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'replicate', $page);

        return $page;
    }

    private function uniqueCloneSlug(
        Page $source,
        int $languageId,
        ?string $preferredSlug = null,
    ): string {
        $source->loadMissing(['translations', 'pageUrls']);
        /** @var Translation|null $translation */
        $translation = $source->translations->firstWhere('language_id', $languageId);
        $originalSlug = is_string($translation?->slug) ? trim($translation->slug, '/') : '';
        $base = Str::slug((string) $preferredSlug);

        if ($base === '') {
            $base = Str::slug($originalSlug !== ''
                ? $originalSlug . '-copy'
                : AgentBridgeValue::string($source->name, 'Page name') . '-copy');
        }

        $sourceUrl = $source->pageUrls->firstWhere('language_id', $languageId)?->url;
        $parentPath = is_string($sourceUrl) && $sourceUrl !== ''
            ? rtrim(dirname($sourceUrl), '/.')
            : '';
        $candidate = $base;
        $suffix = 2;

        while (PageUrl::query()
            ->where('site_id', $source->site_id)
            ->where('language_id', $languageId)
            ->where('url', '/' . ltrim($parentPath . '/' . $candidate, '/'))
            ->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
