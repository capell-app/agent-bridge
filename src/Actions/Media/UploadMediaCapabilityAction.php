<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Media;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\Capabilities\UploadMediaCapabilityInputData;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Exceptions\AgentBridgeMcpV1ConflictException;
use Capell\AgentBridge\Models\CapellAgentBridgeMediaUpload;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Capell\AgentBridge\Support\AgentBridgeRecordResolver;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use finfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UploadMediaCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    private const int MAX_BYTES = 2_097_152;

    /** @var array<string, list<string>> */
    private const array ALLOWED_MIME_EXTENSIONS = [
        'image/gif' => ['gif'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$input, $site, $bytes, $mime] = $this->context($invocation);

        return new CapabilityResultData(
            ok: true,
            message: AgentBridgeValue::string(__('capell-agent-bridge::admin.result_media_upload_preview'), 'Media upload preview message'),
            data: [
                'site_id' => AgentBridgeValue::integer($site->getKey(), 'Site key'),
                'file_name' => $input->file_name,
                'mime_type' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        [$input, $site, $bytes, $mime] = $this->context($invocation);
        $sha256 = hash('sha256', $bytes);

        return DB::transaction(function () use ($input, $site, $bytes, $sha256): CapabilityResultData {
            CapellAgentBridgeMediaUpload::query()->firstOrCreate([
                'site_id' => $site->getKey(),
                'idempotency_key' => $input->idempotency_key,
            ], [
                'payload_sha256' => $sha256,
            ]);

            $claim = CapellAgentBridgeMediaUpload::query()
                ->where('site_id', $site->getKey())
                ->where('idempotency_key', $input->idempotency_key)
                ->lockForUpdate()
                ->firstOrFail();

            throw_unless(hash_equals($claim->payload_sha256, $sha256), AgentBridgeMcpV1ConflictException::class, 'Media idempotency key payload conflict.');

            if ($claim->media_id !== null) {
                $existing = Media::query()->find($claim->media_id);
                throw_unless($existing instanceof Media, AgentBridgeMcpV1ConflictException::class, 'Media idempotency record is incomplete.');

                return $this->result($existing, $site, $sha256, replayed: true);
            }

            $media = $site
                ->addMediaFromString($bytes)
                ->usingName(pathinfo($input->file_name, PATHINFO_FILENAME))
                ->usingFileName($input->file_name)
                ->withCustomProperties(['capell' => ['agent_bridge' => ['idempotency_key' => $input->idempotency_key, 'sha256' => $sha256]]])
                ->toMediaCollection('uploads', AgentBridgeValue::string(config('media-library.disk_name', 'public'), 'Media library disk name'));

            throw_unless($media instanceof Media, ValidationException::class, 'Media backend returned an unsupported record.');

            if ($input->alt !== null && $site->language_id !== null) {
                $media->translations()->create([
                    'language_id' => $site->language_id,
                    'title' => $media->name,
                    'meta' => ['alt' => $input->alt],
                ]);
            }

            $claim->forceFill(['media_id' => $media->getKey()])->save();

            return $this->result($media, $site, $sha256, replayed: false);
        }, 3);
    }

    /**
     * @return array{UploadMediaCapabilityInputData, Site, string, string}
     */
    private function context(CapabilityInvocationData $invocation): array
    {
        validator($invocation->payload, [
            'site_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
            'file_name' => ['required', 'string', 'max:191', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/'],
            'content_base64' => ['required', 'string', 'max:2796204'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:500'],
        ])->validate();

        $input = UploadMediaCapabilityInputData::fromPayload($invocation->payload);
        $site = resolve(AgentBridgeRecordResolver::class)->site($invocation->user, $input->site_id);
        resolve(AgentBridgePolicyAuthorizer::class)->authorize($invocation->user, 'create', Media::class);
        $bytes = base64_decode($input->content_base64, true);

        throw_unless(is_string($bytes) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES, ValidationException::class, 'Invalid media content.');

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = strtolower(pathinfo($input->file_name, PATHINFO_EXTENSION));

        throw_unless(
            is_string($mime)
                && isset(self::ALLOWED_MIME_EXTENSIONS[$mime])
                && in_array($extension, self::ALLOWED_MIME_EXTENSIONS[$mime], true),
            ValidationException::class,
            'Unsupported media type.',
        );

        return [$input, $site, $bytes, $mime];
    }

    private function result(Media $media, Site $site, string $sha256, bool $replayed): CapabilityResultData
    {
        return new CapabilityResultData(
            ok: true,
            message: AgentBridgeValue::string(__($replayed
                ? 'capell-agent-bridge::admin.result_media_upload_replayed'
                : 'capell-agent-bridge::admin.result_media_uploaded'), 'Media upload result message'),
            data: [
                'media_id' => AgentBridgeValue::integer($media->getKey(), 'Media key'),
                'site_id' => AgentBridgeValue::integer($site->getKey(), 'Site key'),
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'sha256' => $sha256,
                'replayed' => $replayed,
            ],
        );
    }
}
