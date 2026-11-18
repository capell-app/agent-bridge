<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support\Privacy;

use Capell\AgentBridge\Actions\BuildAgentBridgePrivacyExportAction;
use Capell\AgentBridge\Actions\EraseAgentBridgePrivacyDataAction;
use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacyExportSectionData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Exports and erases a staff member's tokens, confirmations and audit fingerprints through Core's privacy registry.
 */
final class AgentBridgePrivacyContributor implements PrivacySubjectEraser, PrivacySubjectExporter
{
    public const string KEY = 'agent-bridge';

    #[Override]
    public function contributor(): string
    {
        return self::KEY;
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return $subject->model instanceof Model;
    }

    #[Override]
    public function export(PrivacySubject $subject): PrivacyExportSectionData
    {
        return new PrivacyExportSectionData($subject->model instanceof Model ? BuildAgentBridgePrivacyExportAction::run($subject->model) : []);
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        $affectedRecords = $subject->model instanceof Model ? EraseAgentBridgePrivacyDataAction::run($subject->model) : 0;

        return new PrivacyErasureReceiptData(self::KEY, PrivacyErasureMode::Anonymize, $affectedRecords);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }
}
