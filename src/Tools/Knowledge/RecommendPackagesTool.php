<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Knowledge;

use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\KnowledgeRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[Name('capell-knowledge-recommend-packages')]
#[Title('Recommend Capell Packages')]
#[Description('Recommend Capell packages for a short capability request.')]
#[IsReadOnly]
final class RecommendPackagesTool extends Tool
{
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Capability or problem statement, such as SEO audits, redirects, form-builder, or publishing-studio.')->required(),
        ];
    }

    public function handle(Request $request, KnowledgeRepository $repository): ResponseFactory
    {
        $data = $request->validate([
            'query' => ['required', 'string'],
        ]);

        $query = strtolower(AgentBridgeValue::string($data['query'] ?? null, 'Package recommendation query'));
        $recommendations = collect($repository->packages())
            ->map(function (array $package) use ($query): array {
                $haystack = strtolower(implode(' ', array_filter([
                    self::text($package['name'] ?? null),
                    self::text($package['description'] ?? null),
                    self::text($package['productGroup'] ?? null),
                    self::text($package['bundle'] ?? null),
                    implode(' ', self::strings($package['contexts'] ?? null)),
                    implode(' ', self::strings($package['capabilities'] ?? null)),
                ])));

                $score = 0;
                $terms = preg_split('/\s+/', $query);
                $terms = $terms === false ? [] : $terms;

                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($haystack, $term)) {
                        $score++;
                    }
                }

                return [
                    ...$package,
                    'score' => $score,
                ];
            })
            ->filter(fn (array $package): bool => AgentBridgeValue::integer($package['score'] ?? 0, 'Recommendation score') > 0)
            ->sortByDesc('score')
            ->values()
            ->take(8)
            ->all();

        return Response::structured([
            'query' => $query,
            'recommendations' => $recommendations,
        ]);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
