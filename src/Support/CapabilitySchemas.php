<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

final class CapabilitySchemas
{
    /** @return array<string, mixed> */
    public static function emptyObject(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function createDraftPageInput(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255],
                'site' => self::recordReference('Site name, domain, or ID. It may be omitted when the actor has one unambiguous default site.'),
                'site_id' => ['type' => 'integer'],
                'page_type' => self::recordReference('Page type name, key, or ID. Defaults to the preferred page type.'),
                'blueprint_id' => ['type' => 'integer'],
                'layout' => self::recordReference('Layout name, key, or ID. Defaults to the preferred layout for the site.'),
                'layout_id' => ['type' => 'integer'],
                'parent' => self::recordReference('Optional parent page name, title, slug, URL, or ID.'),
                'parent_id' => ['type' => ['integer', 'null']],
                'language' => self::recordReference('Language code, name, locale, or ID. Defaults to the site language.'),
                'language_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'slug' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'content' => ['type' => ['string', 'array', 'object', 'null']],
                'meta' => ['type' => ['object', 'null']],
                'translation_meta' => ['type' => ['object', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function updateDraftPageInput(): array
    {
        return [
            'type' => 'object',
            'anyOf' => [
                ['required' => ['page']],
                ['required' => ['page_id']],
            ],
            'properties' => [
                ...self::pageReferenceProperties(),
                'page_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'meta' => ['type' => ['object', 'null']],
                'visible_from' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'visible_until' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'language' => self::recordReference('Language code, name, locale, or ID.'),
                'language_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'content' => ['type' => ['string', 'array', 'object', 'null']],
                'slug' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'translation_meta' => ['type' => ['object', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function pageIdInput(): array
    {
        return self::pageReferenceInput();
    }

    /** @return array<string, mixed> */
    public static function pageReferenceInput(bool $includeContent = false): array
    {
        $properties = self::pageReferenceProperties();

        if ($includeContent) {
            $properties['include_content'] = ['type' => 'boolean', 'default' => false];
        }

        return [
            'type' => 'object',
            'anyOf' => [
                ['required' => ['page']],
                ['required' => ['page_id']],
            ],
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function searchPagesInput(): array
    {
        return [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'site' => self::recordReference('Optional site name, domain, or ID.'),
                'site_id' => ['type' => ['integer', 'null']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function clonePageInput(): array
    {
        return [
            'type' => 'object',
            'anyOf' => [
                ['required' => ['page']],
                ['required' => ['page_id']],
            ],
            'properties' => [
                ...self::pageReferenceProperties(),
                'name' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'language' => self::recordReference('Language whose title and slug overrides should target.'),
                'language_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'slug' => ['type' => ['string', 'null'], 'maxLength' => 191],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function pageAuthoringOptionsInput(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site' => self::recordReference('Site name, domain, or ID.'),
                'site_id' => ['type' => ['integer', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function pagePublicationInput(?string $timeField = null): array
    {
        $properties = self::pageReferenceProperties();

        if ($timeField !== null) {
            $properties[$timeField] = [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Future ISO 8601 date and time.',
            ];
        }

        $schema = [
            'type' => 'object',
            'anyOf' => [
                ['required' => ['page']],
                ['required' => ['page_id']],
            ],
            'properties' => $properties,
            'additionalProperties' => false,
        ];

        if ($timeField !== null) {
            $schema['required'] = [$timeField];
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function searchPackagesInput(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'status' => ['type' => 'string', 'enum' => ['all', 'installed', 'available'], 'default' => 'all'],
                'include_core' => ['type' => 'boolean', 'default' => false],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function packageReferenceInput(): array
    {
        return [
            'type' => 'object',
            'required' => ['package'],
            'properties' => [
                'package' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Composer package name, display name, key, or unambiguous partial name.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function installPackageInput(): array
    {
        $schema = self::packageReferenceInput();
        $properties = AgentBridgeValue::associativeArray($schema['properties'] ?? null, 'Package schema properties');
        $properties['arguments'] = [
            'type' => 'object',
            'description' => 'Optional declared package install arguments. Undeclared keys are rejected.',
        ];
        $schema['properties'] = $properties;

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function searchSitesInput(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function siteReferenceInput(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site' => self::recordReference('Site name, domain, URL, or ID. It may be omitted when there is one unambiguous default site.'),
                'site_id' => ['type' => ['integer', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function capabilityResultOutput(): array
    {
        return [
            'type' => 'object',
            'required' => ['ok', 'message', 'data', 'warnings'],
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'object'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function recordReference(string $description): array
    {
        return [
            'type' => ['string', 'integer', 'null'],
            'description' => $description,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function pageReferenceProperties(): array
    {
        return [
            'page' => self::recordReference('Page name, translated title, slug, URL, or ID.'),
            'page_id' => ['type' => ['integer', 'null']],
            'site' => self::recordReference('Optional site name, domain, or ID used to disambiguate the page.'),
            'site_id' => ['type' => ['integer', 'null']],
        ];
    }
}
