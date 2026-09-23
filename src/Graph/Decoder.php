<?php

declare(strict_types=1);

namespace Gcf\Graph;

use Gcf\Constants;
use Gcf\Edge;
use Gcf\GcfDecodeException;
use Gcf\Payload;
use Gcf\Symbol;

/**
 * Decodes GCF text back into a graph-profile Payload (SPEC section 16.1).
 * Ported from gcf-python's decode.py.
 */
final class Decoder
{
    /** @var list<string> */
    private const VALID_DELTA_SECTIONS = ['removed', 'added', 'edges_removed', 'edges_added'];

    private function __construct()
    {
    }

    public static function decode(string $input): Payload
    {
        $lines = explode("\n", $input);
        if ($lines === []) {
            throw new GcfDecodeException('empty input');
        }

        $header = $lines[0];
        if (!str_starts_with($header, 'GCF ')) {
            throw new GcfDecodeException("invalid header, expected 'GCF ...' got '{$header}'");
        }
        ['tool' => $tool, 'tokenBudget' => $tokenBudget, 'tokensUsed' => $tokensUsed, 'packRoot' => $packRoot]
            = self::parseHeader(substr($header, 4));

        // v3.1: tool field is optional (SHOULD be present for MCP tool responses, not required).

        $isDelta = str_contains($header, 'delta=true');

        $symbols = [];
        $symById = [];
        $currentDistance = 0;
        $inEdges = false;
        $declaredEdges = -1;
        $edgesDeclared = false;
        $edges = [];

        for ($i = 1, $n = count($lines); $i < $n; ++$i) {
            $line = rtrim($lines[$i], "\r");
            if ($line === '') {
                continue;
            }

            // Skip ##! summary trailer.
            if (str_starts_with($line, '##! ')) {
                continue;
            }

            // Group header.
            if (str_starts_with($line, '## ')) {
                $group = substr($line, 3);
                // Strip bracket suffix: "edges [200]" -> "edges", capturing the
                // declared count so it can be enforced per Section 13.
                $declaredCount = -1;
                $bracketIdx = strpos($group, ' [');
                if ($bracketIdx !== false) {
                    $bracket = substr($group, $bracketIdx + 2);
                    $group = substr($group, 0, $bracketIdx);
                    $end = strpos($bracket, ']');
                    if ($end !== false) {
                        $cntStr = substr($bracket, 0, $end);
                        if ($cntStr !== '?') {
                            // "[?]" is a streaming deferred count (Section 8).
                            if (preg_match('/^\d+$/', $cntStr) !== 1) {
                                throw new GcfDecodeException("count_mismatch: invalid section count '{$cntStr}'");
                            }
                            $declaredCount = (int) $cntStr;
                        }
                    }
                }
                if ($isDelta && !in_array($group, self::VALID_DELTA_SECTIONS, true)) {
                    throw new GcfDecodeException("malformed_delta: invalid delta section '{$group}'");
                }
                $inEdges = $group === 'edges';
                if ($inEdges && $declaredCount >= 0) {
                    $declaredEdges = $declaredCount;
                    $edgesDeclared = true;
                }
                if (!$inEdges) {
                    match (true) {
                        $group === 'targets' => $currentDistance = 0,
                        $group === 'related' => $currentDistance = 1,
                        $group === 'extended' => $currentDistance = 2,
                        str_starts_with($group, 'distance_') => $currentDistance = self::tryParseDistance(
                            substr($group, 9),
                            $currentDistance,
                        ),
                        default => null,
                    };
                }
                continue;
            }

            // Comment.
            if (str_starts_with($line, '# ')) {
                continue;
            }

            if ($inEdges) {
                $edges[] = self::parseEdgeLine($line, $symById);
            } else {
                [$sym, $symId] = self::parseSymbolLine($line, $currentDistance);
                $symbols[] = $sym;
                $symById[$symId] = $sym;
            }
        }

        // Section 13: a declared [N] section count MUST match the actual item count.
        // The graph edges section is the graph profile's only [N]-bearing section.
        if ($edgesDeclared && count($edges) !== $declaredEdges) {
            throw new GcfDecodeException(
                "count_mismatch: declared {$declaredEdges} edges, got ".count($edges),
            );
        }

        return new Payload(
            tool: $tool,
            tokensUsed: $tokensUsed,
            tokenBudget: $tokenBudget,
            packRoot: $packRoot,
            symbols: $symbols,
            edges: $edges,
        );
    }

    /**
     * @return array{tool: string, tokenBudget: int, tokensUsed: int, packRoot: string}
     */
    private static function parseHeader(string $fields): array
    {
        $tool = '';
        $tokenBudget = 0;
        $tokensUsed = 0;
        $packRoot = '';

        foreach (preg_split('/\s+/', trim($fields), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            switch ($key) {
                case 'tool':
                    $tool = $value;
                    break;
                case 'budget':
                    $tokenBudget = self::parseIntField($value, 'budget');
                    break;
                case 'tokens':
                    $tokensUsed = self::parseIntField($value, 'tokens');
                    break;
                case 'pack_root':
                    $packRoot = $value;
                    break;
                // "symbols" is informational, reconstructed from parsed symbols.
                default:
                    break;
            }
        }

        return ['tool' => $tool, 'tokenBudget' => $tokenBudget, 'tokensUsed' => $tokensUsed, 'packRoot' => $packRoot];
    }

    private static function parseIntField(string $value, string $fieldName): int
    {
        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new GcfDecodeException(
                "invalid {$fieldName} '{$value}': invalid literal for int() with base 10: '{$value}'",
            );
        }

        return (int) $value;
    }

    private static function tryParseDistance(string $s, int $fallback): int
    {
        if (preg_match('/^[+-]?\d+$/', $s) !== 1) {
            return $fallback;
        }

        return (int) $s;
    }

    /**
     * Parses a symbol line into a Symbol and its local ID.
     *
     * @return array{0: Symbol, 1: int}
     */
    private static function parseSymbolLine(string $line, int $distance): array
    {
        if (!str_starts_with($line, '@')) {
            throw new GcfDecodeException("invalid_node_line: expected symbol line starting with @, got '{$line}'");
        }

        $parts = self::splitFields($line);
        if (count($parts) < 5) {
            $count = count($parts);
            throw new GcfDecodeException(
                "invalid_node_line: symbol line needs at least 5 fields, got {$count} in '{$line}'",
            );
        }

        $idStr = substr($parts[0], 1); // strip @
        if (preg_match('/^[+-]?\d+$/', $idStr) !== 1) {
            throw new GcfDecodeException("invalid_symbol_id: invalid symbol id '{$idStr}'");
        }
        $symId = (int) $idStr;

        $kind = $parts[1];
        $kind = Constants::KIND_EXPAND[$kind] ?? $kind;

        $qname = $parts[2];

        if (!is_numeric($parts[3])) {
            throw new GcfDecodeException("invalid_score: invalid score '{$parts[3]}'");
        }
        $score = (float) $parts[3];

        $provenance = $parts[4];

        return [
            new Symbol(
                qualifiedName: $qname,
                kind: $kind,
                score: $score,
                provenance: $provenance,
                distance: $distance,
            ),
            $symId,
        ];
    }

    /**
     * Parses an edge line into an Edge.
     *
     * @param array<int, Symbol> $symById
     */
    private static function parseEdgeLine(string $line, array $symById): Edge
    {
        $parts = self::splitFields($line);
        if (count($parts) < 2) {
            throw new GcfDecodeException("edge line needs at least 2 fields, got '{$line}'");
        }

        $ref = $parts[0];
        $ltIdx = strpos($ref, '<');
        if ($ltIdx === false) {
            throw new GcfDecodeException("invalid_edge_syntax: edge line missing '<' separator in '{$ref}'");
        }

        $targetIdStr = substr($ref, 1, $ltIdx - 1); // strip leading @
        $sourceIdStr = substr($ref, $ltIdx + 2); // strip <@

        if (preg_match('/^[+-]?\d+$/', $targetIdStr) !== 1) {
            throw new GcfDecodeException("invalid target id '{$targetIdStr}'");
        }
        $targetId = (int) $targetIdStr;

        if (preg_match('/^[+-]?\d+$/', $sourceIdStr) !== 1) {
            throw new GcfDecodeException("invalid source id '{$sourceIdStr}'");
        }
        $sourceId = (int) $sourceIdStr;

        $targetSym = $symById[$targetId] ?? null;
        $sourceSym = $symById[$sourceId] ?? null;
        if ($targetSym === null || $sourceSym === null) {
            throw new GcfDecodeException(
                "unknown_edge_reference: edge references unknown symbol id(s): target={$targetId} source={$sourceId}",
            );
        }

        $edgeType = $parts[1];
        $status = count($parts) >= 3 ? $parts[2] : '';

        return new Edge(
            source: $sourceSym->qualifiedName,
            target: $targetSym->qualifiedName,
            edgeType: $edgeType,
            status: $status,
        );
    }

    /**
     * Splits a symbol/edge line on whitespace runs (SPEC 2.4: space-delimited over
     * code points). Splitting on the literal ASCII space byte is safe for multibyte
     * fields because UTF-8 continuation/leading bytes are always >= 0x80 and never
     * collide with 0x20.
     *
     * @return list<string>
     */
    private static function splitFields(string $line): array
    {
        return preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
    }
}
