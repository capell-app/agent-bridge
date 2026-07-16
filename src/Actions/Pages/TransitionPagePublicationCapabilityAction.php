<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Pages;

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Data\Publishing\PublishReadinessData;
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\TransitionPagePublicationCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

final class TransitionPagePublicationCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$page, $request] = $this->context($invocation);
        $result = $this->evaluateTransition($request);

        return new CapabilityResultData(
            ok: $this->isSuccessful($result),
            message: (string) __('capell-agent-bridge::admin.result_page_publication_preview', [
                'transition' => $request->transition->value,
            ]),
            data: $this->resultPayload($page, $result, $request),
            warnings: $this->readinessWarnings($page, $request->transition),
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$page, $request] = $this->context($invocation);
        $result = $this->executeTransition($request);

        return new CapabilityResultData(
            ok: $this->isSuccessful($result),
            message: (string) __('capell-agent-bridge::admin.result_page_publication_executed', [
                'transition' => $request->transition->value,
                'outcome' => $result->outcome->value,
            ]),
            data: $this->resultPayload($page->fresh() ?? $page, $result, $request),
            warnings: $this->readinessWarnings($page, $request->transition),
        );
    }

    /** @return array{Page, PublicationTransitionRequestData} */
    private function context(CapabilityInvocationData $invocation): array
    {
        $transition = $this->transition($invocation->capability->key);
        $rules = [
            'page' => ['required_without:page_id'],
            'page_id' => ['required_without:page', 'integer'],
            'site' => ['sometimes', 'nullable'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'publish_at' => [$transition === PublicationTransition::SchedulePublish ? 'required' : 'prohibited', 'date', 'after:now'],
            'unpublish_at' => [$transition === PublicationTransition::ScheduleUnpublish ? 'required' : 'prohibited', 'date', 'after:now'],
        ];
        validator($invocation->payload, $rules)->validate();
        $payload = TransitionPagePublicationCapabilityInputData::fromPayload($invocation->payload);
        $page = resolve(AgentBridgeRecordResolver::class)->page(
            user: $invocation->user,
            reference: $payload->page_id ?? $payload->page ?? '',
            siteReference: $payload->site_id ?? $payload->site,
            with: ['site', 'blueprint', 'layout', 'translations', 'pageUrls'],
        );
        $actor = resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'update', $page);
        $requestedTime = match ($transition) {
            PublicationTransition::SchedulePublish => CarbonImmutable::parse($payload->publish_at),
            PublicationTransition::ScheduleUnpublish => CarbonImmutable::parse($payload->unpublish_at),
            default => null,
        };

        return [$page, new PublicationTransitionRequestData(
            record: $page,
            transition: $transition,
            actor: $actor,
            now: CarbonImmutable::now(),
            requestedTime: $requestedTime,
        )];
    }

    private function transition(string $capabilityKey): PublicationTransition
    {
        return match ($capabilityKey) {
            'capell.pages.publish_now' => PublicationTransition::PublishNow,
            'capell.pages.schedule_publish' => PublicationTransition::SchedulePublish,
            'capell.pages.schedule_unpublish' => PublicationTransition::ScheduleUnpublish,
            'capell.pages.unpublish' => PublicationTransition::Unpublish,
            'capell.pages.revert_to_draft' => PublicationTransition::RevertToDraft,
            default => throw new InvalidArgumentException(sprintf(
                'Capability [%s] is not a supported page publication transition.',
                $capabilityKey,
            )),
        };
    }

    /** @return array<string, mixed> */
    private function resultPayload(
        Page $page,
        PublicationTransitionResultData $result,
        PublicationTransitionRequestData $request,
    ): array {
        $readiness = $this->publishReadiness($page, $request);

        return [
            'page_id' => AgentBridgeValue::integer($page->getKey(), 'Page key'),
            'name' => AgentBridgeValue::string($page->name, 'Page name'),
            'transition' => $request->transition->value,
            'outcome' => $result->outcome->value,
            'before_state' => $result->beforeState->value,
            'after_state' => $result->afterState->value,
            'visible_from' => $result->visibleFrom?->toIso8601String(),
            'visible_until' => $result->visibleUntil?->toIso8601String(),
            'reason' => $result->reasonKey,
            'blocking_checks' => $readiness->blockingCheckIds,
            'allowed_transitions' => $readiness->allowedTransitions,
        ];
    }

    /** @return list<string> */
    private function readinessWarnings(Page $page, PublicationTransition $transition): array
    {
        if (! in_array($transition, [PublicationTransition::PublishNow, PublicationTransition::SchedulePublish], true)) {
            return [];
        }

        $blockingChecks = $this->publishReadiness($page)->blockingCheckIds;

        return $blockingChecks === []
            ? []
            : [(string) __('capell-agent-bridge::admin.warning_page_readiness_blockers', [
                'checks' => implode(', ', $blockingChecks),
            ])];
    }

    private function isSuccessful(PublicationTransitionResultData $result): bool
    {
        return in_array($result->outcome, [
            PublicationTransitionOutcome::Changed,
            PublicationTransitionOutcome::AlreadyCorrect,
        ], true);
    }

    private function evaluateTransition(PublicationTransitionRequestData $request): PublicationTransitionResultData
    {
        $result = EvaluatePublicationTransitionAction::run($request);

        throw_unless($result instanceof PublicationTransitionResultData, UnexpectedValueException::class);

        return $result;
    }

    private function executeTransition(PublicationTransitionRequestData $request): PublicationTransitionResultData
    {
        $result = TransitionPublicationAction::run($request);

        throw_unless($result instanceof PublicationTransitionResultData, UnexpectedValueException::class);

        return $result;
    }

    private function publishReadiness(
        Page $page,
        ?PublicationTransitionRequestData $request = null,
    ): PublishReadinessData {
        $readiness = BuildPublishReadinessAction::run($page, $request);

        throw_unless($readiness instanceof PublishReadinessData, UnexpectedValueException::class);

        return $readiness;
    }
}
