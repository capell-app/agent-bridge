<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\Admin\Actions\Pages\ValidatePageAuthoringAction;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\UpdateDraftPageCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConflictException;
use Capell\AgentBridge\Support\AgentBridgeAiProvenance;
use Capell\AgentBridge\Support\AgentBridgePageRevertPoints;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class UpdateDraftPageCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $input = UpdateDraftPageCapabilityInputData::fromPayload($payload);
        $page = $this->page($invocation, $payload);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_update_preview'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'name' => AgentBridgeValue::string($page->name, 'Page name'),
                'revision' => $this->assertDraftRevision($page, $input->expected_revision),
                'changed_fields' => array_keys(array_diff_key($payload, [
                    'page_id' => true,
                    'page' => true,
                    'site' => true,
                    'site_id' => true,
                    'expected_revision' => true,
                ])),
            ],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        $payload = $this->validatedPayload($invocation->payload);
        $input = UpdateDraftPageCapabilityInputData::fromPayload($payload);
        $page = $this->page($invocation, $payload);

        $updates = array_intersect_key($payload, array_flip([
            'name',
            'meta',
        ]));
        $translationFields = array_intersect_key($payload, array_flip([
            'title',
            'content',
            'slug',
            'translation_meta',
        ]));

        ValidatePageAuthoringAction::run(
            formData: $payload,
            page: $page,
            operation: 'agent-bridge-update',
        );

        $versionBefore = 0;
        $updated = DB::transaction(function () use ($page, $payload, $input, $updates, $translationFields, $invocation, &$versionBefore): array {
            $page = Page::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraftRevision($page, $input->expected_revision, lock: true);
            // Read under the lock, so the revert point is the version this change replaced.
            $versionBefore = AgentBridgePageRevertPoints::currentVersion($page);
            resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'update', $page);
            $updated = array_keys($updates);
            $languageId = null;

            if ($translationFields !== []) {
                $site = $page->site;
                throw_unless($site !== null, RuntimeException::class, (string) __('capell-agent-bridge::admin.error_page_site_missing'));

                $language = resolve(AgentBridgeRecordResolver::class)->language(
                    $site,
                    $input->language_id ?? $input->language,
                );
                /** @var Translation $translation */
                $translation = $page->translations()->firstOrNew([
                    'language_id' => $language->getKey(),
                ]);
                $meta = array_replace(
                    (array) $translation->meta,
                    (array) ($payload['translation_meta'] ?? []),
                );

                if (array_key_exists('slug', $payload)) {
                    $meta['slug'] = Str::slug($input->slug ?? '');
                }

                if (array_key_exists('title', $payload)) {
                    $translation->title = $input->title;
                }

                if (array_key_exists('content', $payload)) {
                    $translation->content = $payload['content'];
                }

                $translation->meta = $meta;
                $translation->save();
                $languageId = AgentBridgeValue::integer($language->getKey(), 'Language key');
                $updated = [...$updated, ...array_keys($translationFields)];
            }

            if ($translationFields !== []) {
                SetupPageUrlsAction::run($page);
            }

            // Recorded before the page save: that save cuts the revision, which must hold
            // these rows so a rollback to it cannot bring back AI content as reviewed.
            AgentBridgeAiProvenance::record($page, array_values(array_unique($updated)), $languageId, $invocation);

            if (array_key_exists('meta', $updates)) {
                // Keep Core's disclosure summary: agent-sent meta must not replace or forge it.
                $updates['meta'] = [
                    ...(array) $updates['meta'],
                    AiDisclosure::PAGE_META_KEY => ((array) $page->meta)[AiDisclosure::PAGE_META_KEY] ?? AiDisclosure::None->value,
                ];
            }

            $page->forceFill($updates)->save();

            return array_values(array_unique($updated));
        });

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_page_updated'),
            data: [
                'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
                'revision' => $this->currentRevision($page),
                'updated' => $updated,
            ],
            revertPoint: AgentBridgePageRevertPoints::afterUpdate($page, $versionBefore),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatedPayload(array $payload): array
    {
        validator($payload, [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'string', 'max:255'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'admin' => ['prohibited'],
            'language' => ['sometimes', 'nullable'],
            'language_id' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'translation_meta' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        if (array_key_exists('content', $payload)
            && ! is_string($payload['content'])
            && ! is_array($payload['content'])
            && $payload['content'] !== null) {
            throw ValidationException::withMessages([
                'content' => __('capell-agent-bridge::admin.validation_content_shape'),
            ]);
        }

        return $payload;
    }

    private function assertDraftRevision(Page $page, ?int $expectedRevision, bool $lock = false): int
    {
        $query = PageWorkflowState::query()->where('page_uuid', $page->uuid);

        if ($lock) {
            $query->lockForUpdate();
        }

        $state = $query->first();

        throw_unless(
            $state instanceof PageWorkflowState
                && $state->status === PageWorkflowStatus::Draft
                && $page->publishVisibilityState()->value === 'draft'
                && $expectedRevision !== null
                && $state->aggregate_version === $expectedRevision,
            AgentBridgeMcpV1ConflictException::class,
            'Draft page revision conflict.',
        );

        return $state->aggregate_version;
    }

    private function currentRevision(Page $page): int
    {
        $state = PageWorkflowState::query()->where('page_uuid', $page->uuid)->first();

        throw_unless($state instanceof PageWorkflowState, AgentBridgeMcpV1ConflictException::class, 'Draft page revision conflict.');

        return $state->aggregate_version;
    }

    /** @param array<string, mixed> $payload */
    private function page(CapabilityInvocationData $invocation, array $payload): Page
    {
        $input = UpdateDraftPageCapabilityInputData::fromPayload($payload);
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $input->page_id ?? $input->page ?? '',
            siteReference: $input->site_id ?? $input->site,
            with: ['site', 'translations.language', 'pageUrls'],
        );

        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'update', $page);

        return $page;
    }
}
