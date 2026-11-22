<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\AiProvenance;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every page field an agent wrote through the bridge as AI-generated, so
 * Core holds the page back from publishing until a person has reviewed it. The
 * agent wrote the value as sent, so the disclosure is always Generated.
 */
final class AgentBridgeAiProvenance
{
    public const string PROVIDER = 'agent-bridge';

    /**
     * Page-level payload keys and the field name Core records them under.
     *
     * @var array<string, string>
     */
    public const array PAGE_FIELDS = [
        'name' => 'name',
        'meta' => 'meta',
    ];

    /**
     * Translation payload keys and the field name Core records them under.
     *
     * @var array<string, string>
     */
    public const array TRANSLATION_FIELDS = [
        'title' => 'title',
        'content' => 'content',
        'slug' => 'slug',
        'translation_meta' => 'meta',
    ];

    /**
     * @param  list<string>  $payloadKeys  the payload keys the agent actually wrote
     */
    public static function record(
        Model $page,
        array $payloadKeys,
        ?int $languageId,
        CapabilityInvocationData $invocation,
    ): void {
        foreach ($payloadKeys as $key) {
            if (array_key_exists($key, self::PAGE_FIELDS)) {
                self::write($page, self::PAGE_FIELDS[$key], null, $invocation);
            }

            if (array_key_exists($key, self::TRANSLATION_FIELDS) && $languageId !== null) {
                self::write($page, self::TRANSLATION_FIELDS[$key], $languageId, $invocation);
            }
        }
    }

    /**
     * A copy keeps the review debt of what it copied: content an AI wrote on the source
     * and nobody has reviewed stays unreviewed on the copy, so cloning cannot launder it.
     */
    public static function carryUnreviewed(Model $source, Model $copy): void
    {
        $rows = AiProvenance::query()
            ->where('pageable_type', $source->getMorphClass())
            ->where('pageable_id', $source->getKey())
            ->whereNull('confirmed_at')
            ->where('disclosure', '!=', AiDisclosure::None->value)
            ->get();

        foreach ($rows as $row) {
            RecordAiProvenanceAction::run(new AiProvenanceRecordData(
                pageable: $copy,
                field: $row->field,
                disclosure: $row->disclosure,
                languageId: $row->language_id,
                capability: $row->capability,
                provider: $row->provider,
                model: $row->model,
                generationHistoryId: $row->generation_history_id,
                actorId: $row->actor_id,
            ));
        }
    }

    private static function write(Model $page, string $field, ?int $languageId, CapabilityInvocationData $invocation): void
    {
        $actorId = $invocation->user?->getAuthIdentifier();

        RecordAiProvenanceAction::run(new AiProvenanceRecordData(
            pageable: $page,
            field: $field,
            disclosure: AiDisclosure::Generated,
            languageId: $languageId,
            capability: $invocation->capability->key,
            provider: self::PROVIDER,
            actorId: is_numeric($actorId) ? (int) $actorId : null,
        ));
    }
}
