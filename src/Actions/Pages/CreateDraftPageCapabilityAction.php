<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\Admin\Actions\CreatePageAction;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\CreateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateDraftPageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $context = $this->resolveContext($invocation, $payload);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_create_preview'),
            data: [
                'page' => $invocation->payload,
                'resolved' => [
                    'site' => ['id' => AgentBridgeValue::integer($context['site']->getKey(), 'Site key'), 'name' => $context['site']->name],
                    'page_type' => ['id' => AgentBridgeValue::integer($context['pageType']->getKey(), 'Page type key'), 'key' => $context['pageType']->key],
                    'layout' => ['id' => AgentBridgeValue::integer($context['layout']->getKey(), 'Layout key'), 'key' => $context['layout']->key],
                    'language' => ['id' => AgentBridgeValue::integer($context['language']->getKey(), 'Language key'), 'code' => $context['language']->code],
                    'publishing_state' => 'draft',
                ],
            ],
            warnings: $this->visibilityWarnings($invocation->payload),
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $context = $this->resolveContext($invocation, $payload);
        $title = trim($payload->title ?? $payload->name);
        $slug = Str::slug($payload->slug ?? $title);

        $page = DB::transaction(function () use ($context, $payload, $title, $slug): Page {
            $translationMeta = $payload->translation_meta ?? [];
            $translationMeta['slug'] = $slug;

            $page = CreatePageAction::run([
                'name' => $payload->name,
                'site_id' => AgentBridgeValue::integer($context['site']->getKey(), 'Site key'),
                'blueprint_id' => AgentBridgeValue::integer($context['pageType']->getKey(), 'Page type key'),
                'layout_id' => AgentBridgeValue::integer($context['layout']->getKey(), 'Layout key'),
                'parent_id' => $context['parent']?->getKey(),
                'meta' => $payload->meta,
                'visible_from' => PublishSentinel::draftValue(),
                'visible_until' => null,
                'translations' => [[
                    'language_id' => AgentBridgeValue::integer($context['language']->getKey(), 'Language key'),
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $payload->content ?? '',
                    'meta' => $translationMeta,
                ]],
            ]);

            SetupPageUrlsAction::run($page);

            return $page->fresh(['translations.language', 'pageUrls']) ?? $page;
        });

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_created'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'name' => $page->getAttribute('name'),
                'title' => $title,
                'slug' => $slug,
                'publishing_state' => $page->publishVisibilityState()->value,
                'urls' => $page->pageUrls->pluck('url')->values()->all(),
            ],
            warnings: $this->visibilityWarnings($invocation->payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatedPayload(array $payload): CreateDraftPageCapabilityInputData
    {
        validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'page_type' => ['sometimes', 'nullable'],
            'blueprint_id' => ['sometimes', 'nullable', 'integer'],
            'layout' => ['sometimes', 'nullable'],
            'layout_id' => ['sometimes', 'nullable', 'integer'],
            'parent' => ['sometimes', 'nullable'],
            'parent_id' => ['nullable', 'integer'],
            'language' => ['sometimes', 'nullable'],
            'language_id' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:191'],
            'content' => ['sometimes', 'nullable'],
            'meta' => ['nullable', 'array'],
            'translation_meta' => ['sometimes', 'nullable', 'array'],
            'translations' => ['prohibited'],
            'admin' => ['prohibited'],
            'visible_from' => ['nullable', 'date'],
            'visible_until' => ['nullable', 'date'],
        ])->validate();

        if (array_key_exists('content', $payload)
            && ! is_string($payload['content'])
            && ! is_array($payload['content'])
            && $payload['content'] !== null) {
            throw ValidationException::withMessages([
                'content' => __('capell-agent-bridge::admin.validation_content_shape'),
            ]);
        }

        return CreateDraftPageCapabilityInputData::fromPayload($payload);
    }

    /**
     * @return array{site: Site, pageType: Blueprint, layout: Layout, language: Language, parent: Page|null}
     */
    private function resolveContext(
        CapabilityInvocationData $invocation,
        CreateDraftPageCapabilityInputData $payload,
    ): array {
        $resolver = resolve(AgentBridgeRecordResolver::class);
        $site = $resolver->site($invocation->user, $payload->site_id ?? $payload->site);
        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'create', Page::class);
        $parentReference = $payload->parent_id ?? $payload->parent;

        return [
            'site' => $site,
            'pageType' => $resolver->pageType($payload->blueprint_id ?? $payload->page_type),
            'layout' => $resolver->layout($site, $payload->layout_id ?? $payload->layout),
            'language' => $resolver->language($site, $payload->language_id ?? $payload->language),
            'parent' => $parentReference === null
                ? null
                : $resolver->page(
                    $invocation->user,
                    $parentReference,
                    AgentBridgeValue::integer($site->getKey(), 'Site key'),
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function visibilityWarnings(array $payload): array
    {
        return array_intersect_key($payload, ['visible_from' => true, 'visible_until' => true]) === []
            ? []
            : [(string) __('capell-agent-bridge::admin.warning_draft_visibility_ignored')];
    }
}
