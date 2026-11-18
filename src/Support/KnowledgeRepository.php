<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * Serves package metadata and an allowlist of Markdown documents to the knowledge server.
 *
 * Anything an agent reads can steer it, so a document is served only when it is under a
 * published path (package READMEs and docs by default) or under an opt-in path and marked
 * with `agent_knowledge: true` front matter. `agent_knowledge: false` withholds a document
 * from any path, hidden files and folders are never served, and every result carries its
 * provenance so the client can tell reference documentation from instructions.
 */
final class KnowledgeRepository
{
    public const int MAX_DOCUMENT_BYTES = 262_144;

    private const string MARKER = 'agent_knowledge';

    /** @var array<int, array{path: string, title: string, provenance: array{source: string, package: string|null}}>|null */
    private ?array $documents = null;

    /** @return array<int, array<string, mixed>> */
    public function packages(): array
    {
        // Packages developed in this repository, then Capell packages installed from Composer.
        $packageFiles = [
            ...File::glob(base_path('packages/*/capell.json')),
            ...File::glob(base_path('vendor/capell-app/*/capell.json')),
        ];
        $packages = [];

        foreach ($packageFiles as $packageFile) {
            $decoded = json_decode(File::get($packageFile), true);

            if (! is_array($decoded)) {
                continue;
            }

            $product = is_array($decoded['product'] ?? null) ? $decoded['product'] : [];
            $dependencies = is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [];

            $packages[] = [
                'name' => $decoded['name'] ?? basename(dirname((string) $packageFile)),
                'description' => $decoded['description'] ?? null,
                'productGroup' => $product['group'] ?? null,
                'tier' => $product['tier'] ?? null,
                'bundle' => $product['bundle'] ?? null,
                'contexts' => $decoded['surfaces'] ?? $decoded['contexts'] ?? [],
                'capabilities' => $decoded['capabilities'] ?? [],
                'requires' => $dependencies['requires'] ?? $decoded['requires'] ?? [],
                // Repository-relative, so the server's filesystem layout is not disclosed.
                'path' => $this->relativePath(dirname((string) $packageFile)) ?? basename(dirname((string) $packageFile)),
            ];
        }

        return $packages;
    }

    /**
     * The allowlist is scanned once per repository instance, which the knowledge tools
     * resolve per request, so listing and reading in one request share the scan.
     *
     * @return array<int, array{path: string, title: string, provenance: array{source: string, package: string|null}}>
     */
    public function documents(): array
    {
        return $this->documents ??= $this->scanDocuments();
    }

    /**
     * @return array{path: string, title: string, provenance: array{source: string, package: string|null}, content: string}|null
     */
    public function readDocument(string $relativePath): ?array
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');

        foreach ($this->documents() as $document) {
            if ($document['path'] !== $normalized) {
                continue;
            }

            $resolvedPath = $this->resolveInsideBase($normalized);

            if ($resolvedPath === null || ! $this->isServableTarget($resolvedPath)) {
                return null;
            }

            return [
                ...$document,
                'content' => File::get($resolvedPath),
            ];
        }

        return null;
    }

    /** @return array<int, array{path: string, title: string, provenance: array{source: string, package: string|null}}> */
    private function scanDocuments(): array
    {
        $documents = [];

        foreach ($this->publishedPaths() as $configuredPath) {
            $this->collect($documents, $configuredPath, requiresMarker: false);
        }

        foreach ($this->optInPaths() as $configuredPath) {
            $this->collect($documents, $configuredPath, requiresMarker: true);
        }

        return array_values($documents);
    }

    /** @return list<string> */
    private function publishedPaths(): array
    {
        return AgentBridgeValue::stringList(
            config('capell-agent-bridge.agent_knowledge.published_paths', []),
            'Agent Bridge published knowledge paths',
        );
    }

    /**
     * Hosts that published the configuration before the allowlist kept their list under
     * `public_docs_paths`; those paths now serve only documents marked for agents.
     *
     * @return list<string>
     */
    private function optInPaths(): array
    {
        $legacy = config('capell-agent-bridge.public_docs_paths');

        return [
            ...AgentBridgeValue::stringList(
                config('capell-agent-bridge.agent_knowledge.opt_in_paths', []),
                'Agent Bridge opt-in knowledge paths',
            ),
            ...($legacy === null ? [] : AgentBridgeValue::stringList($legacy, 'Agent Bridge public documentation paths')),
        ];
    }

    /**
     * @param  array<string, array{path: string, title: string, provenance: array{source: string, package: string|null}}>  $documents
     */
    private function collect(array &$documents, string $configuredPath, bool $requiresMarker): void
    {
        foreach (File::glob($configuredPath) as $path) {
            $files = is_dir($path)
                ? array_map(static fn (SplFileInfo $file): string => $file->getPathname(), File::allFiles($path))
                : [$path];

            foreach ($files as $file) {
                $this->append($documents, $file, $requiresMarker);
            }
        }
    }

    /**
     * @param  array<string, array{path: string, title: string, provenance: array{source: string, package: string|null}}>  $documents
     */
    private function append(array &$documents, string $path, bool $requiresMarker): void
    {
        if (! str_ends_with($path, '.md')) {
            return;
        }

        $relativePath = $this->relativePath($path);

        if ($relativePath === null || isset($documents[$relativePath]) || $this->isHidden($relativePath)) {
            return;
        }

        $resolvedPath = $this->resolveInsideBase($relativePath);

        if ($resolvedPath === null || ! $this->isServableTarget($resolvedPath)) {
            return;
        }

        $marker = $this->marker($resolvedPath);

        if ($marker === false || ($requiresMarker && $marker !== true)) {
            return;
        }

        $documents[$relativePath] = [
            'path' => $relativePath,
            'title' => basename($path, '.md'),
            'provenance' => $this->provenance($relativePath),
        ];
    }

    /**
     * Reads the `agent_knowledge` front-matter flag: true, false, or null when unset.
     */
    private function marker(string $path): ?bool
    {
        // Only the front matter is needed, so read the first block rather than the document.
        // capell-security-scan: local-stream the path was resolved inside the application base above
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', $head, $frontMatter) !== 1) {
            return null;
        }

        if (preg_match('/^' . self::MARKER . ':\s*(\S+)\s*$/mi', $frontMatter[1], $value) !== 1) {
            return null;
        }

        return match (strtolower(trim($value[1], '\'"'))) {
            'true', 'yes', 'public' => true,
            default => false,
        };
    }

    /** @return array{source: string, package: string|null} */
    private function provenance(string $relativePath): array
    {
        if (preg_match('#^(?:packages|vendor/capell-app)/([^/]+)/#', $relativePath, $matches) === 1) {
            return ['source' => 'package-documentation', 'package' => $matches[1]];
        }

        return ['source' => 'application-documentation', 'package' => null];
    }

    private function isHidden(string $relativePath): bool
    {
        return array_any(explode('/', $relativePath), fn ($segment): bool => str_starts_with($segment, '.'));
    }

    /**
     * A link is judged by where it points, not by its own name: `docs/a.md` linked to
     * `.env` or to a hidden folder elsewhere in the application is not served.
     */
    private function isServableTarget(string $resolvedPath): bool
    {
        $basePath = realpath(base_path());

        if ($basePath === false || ! str_starts_with($resolvedPath, $basePath . '/')) {
            return false;
        }

        $targetRelativePath = substr($resolvedPath, strlen($basePath) + 1);

        return str_ends_with($targetRelativePath, '.md')
            && ! $this->isHidden($targetRelativePath)
            && File::size($resolvedPath) <= self::MAX_DOCUMENT_BYTES;
    }

    private function resolveInsideBase(string $relativePath): ?string
    {
        $resolvedPath = realpath(base_path($relativePath));
        $basePath = realpath(base_path());

        if ($resolvedPath === false || $basePath === false || ! str_starts_with($resolvedPath, $basePath . '/') || ! is_file($resolvedPath)) {
            return null;
        }

        return $resolvedPath;
    }

    private function relativePath(string $path): ?string
    {
        $base = rtrim(base_path(), '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : null;
    }
}
