<?php

declare(strict_types=1);

namespace Gcf\Tests;

use Gcf\Components;
use Gcf\Edge;
use Gcf\GcfDecodeException;
use Gcf\Graph\Decoder;
use Gcf\Graph\Encoder;
use Gcf\PackRoot;
use Gcf\Payload;
use Gcf\Symbol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the graph-profile GCF conformance fixtures under tests/conformance/
 * (graph-encode/, graph-decode/, graph-pack-root/) plus the graph-scoped
 * error fixtures under tests/conformance/errors-v2/ against Gcf\Graph\Encoder,
 * Gcf\Graph\Decoder, and Gcf\PackRoot.
 *
 * This test class intentionally globs only the graph-related error fixtures
 * by name; the rest of errors-v2/ (generic-profile errors, plus the
 * graph-delta fixture 033) is out of scope here.
 */
final class GraphConformanceTest extends TestCase
{
    private const GRAPH_ERROR_FIXTURES = [
        '028_invalid_graph_node.json',
        '029_invalid_graph_symbol_id.json',
        '030_invalid_graph_score.json',
        '031_invalid_graph_edge_syntax.json',
        '032_unknown_graph_edge_reference.json',
        '039_graph_edges_count_surplus.json',
        '040_graph_edges_count_deficit.json',
    ];

    #[DataProvider('fixtures')]
    public function testFixture(string $path): void
    {
        $fixture = self::rawFixture($path);
        $operation = $fixture['operation'];

        match ($operation) {
            'encode' => self::assertSame(
                $fixture['expected'],
                Encoder::encode(self::hydratePayload($fixture['input'])),
            ),
            'decode' => self::assertEquals(
                self::hydratePayload($fixture['expected']),
                Decoder::decode(self::resolveInputText($fixture)),
            ),
            'pack-root' => self::assertSame(
                $fixture['expected'],
                PackRoot::compute(
                    array_map(self::hydrateSymbol(...), $fixture['input']['symbols'] ?? []),
                    array_map(self::hydrateEdge(...), $fixture['input']['edges'] ?? []),
                ),
            ),
            'error' => self::assertErrorFixture($fixture),
            default => self::fail("Unknown fixture operation: {$operation}"),
        };
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtures(): iterable
    {
        $root = __DIR__.'/conformance';

        foreach (['graph-encode', 'graph-decode', 'graph-pack-root'] as $dir) {
            $paths = glob("{$root}/{$dir}/*.json");
            sort($paths);
            foreach ($paths as $path) {
                yield "{$dir}/".basename($path) => [$path];
            }
        }

        foreach (self::GRAPH_ERROR_FIXTURES as $name) {
            $path = "{$root}/errors-v2/{$name}";
            yield "errors-v2/{$name}" => [$path];
        }
    }

    private static function assertErrorFixture(array $fixture): void
    {
        try {
            Decoder::decode(self::resolveInputText($fixture));
            self::fail("Expected a GcfDecodeException containing \"{$fixture['expectedError']}\", none was thrown.");
        } catch (GcfDecodeException $e) {
            self::assertStringContainsString($fixture['expectedError'], $e->getMessage());
        }
    }

    /**
     * Fixtures normally carry GCF text in "input". A handful need to exercise
     * malformed byte sequences that cannot round-trip through a JSON string
     * literal, so they carry base64-encoded raw bytes in "inputBase64" instead.
     */
    private static function resolveInputText(array $fixture): string
    {
        if (isset($fixture['inputBase64'])) {
            return base64_decode($fixture['inputBase64'], true);
        }

        return $fixture['input'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawFixture(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read fixture: {$path}");
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydratePayload(array $d): Payload
    {
        return new Payload(
            tool: $d['tool'] ?? '',
            tokensUsed: $d['tokensUsed'] ?? 0,
            tokenBudget: $d['tokenBudget'] ?? 0,
            packRoot: $d['packRoot'] ?? '',
            symbols: array_map(self::hydrateSymbol(...), $d['symbols'] ?? []),
            edges: array_map(self::hydrateEdge(...), $d['edges'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateSymbol(array $d): Symbol
    {
        return new Symbol(
            qualifiedName: $d['qualifiedName'] ?? '',
            kind: $d['kind'] ?? '',
            score: (float) ($d['score'] ?? 0.0),
            provenance: $d['provenance'] ?? '',
            distance: $d['distance'] ?? 0,
            signature: $d['signature'] ?? '',
            components: isset($d['components']) ? self::hydrateComponents($d['components']) : new Components(),
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateEdge(array $d): Edge
    {
        return new Edge(
            source: $d['source'] ?? '',
            target: $d['target'] ?? '',
            edgeType: $d['edgeType'] ?? '',
            status: $d['status'] ?? '',
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function hydrateComponents(array $d): Components
    {
        return new Components(
            blastRadius: (float) ($d['blastRadius'] ?? 0.0),
            confidence: (float) ($d['confidence'] ?? 0.0),
            recency: (float) ($d['recency'] ?? 0.0),
            distance: (float) ($d['distance'] ?? 0.0),
        );
    }
}
