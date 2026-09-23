<?php

declare(strict_types=1);

namespace Gcf\Graph;

use Gcf\Constants;
use Gcf\DeltaPayload;
use Gcf\Edge;
use Gcf\GcfDecodeException;
use Gcf\PackRoot;
use Gcf\Symbol;

/**
 * GCF graph-profile delta encoding: only added/removed symbols and edges for
 * incremental delivery (SPEC 10.4). Ported from gcf-python's delta.py.
 */
final class Delta
{
    /** @var list<string> */
    private const VALID_SECTIONS = ['removed', 'added', 'edges_removed', 'edges_added'];

    private function __construct()
    {
    }

    public static function encode(DeltaPayload $d): string
    {
        $parts = [];

        $savings = 0.0;
        if ($d->fullTokens > 0) {
            $savings = 100.0 * (1.0 - $d->deltaTokens / $d->fullTokens);
        }
        $savingsStr = sprintf('%.0f', $savings);

        $parts[] = "GCF profile=graph tool={$d->tool} delta=true base_root={$d->baseRoot} "
            ."new_root={$d->newRoot} tokens={$d->deltaTokens} savings={$savingsStr}%";

        // Removed symbols: short references (consumer already has the full declaration).
        if ($d->removed !== []) {
            $parts[] = '## removed';
            foreach ($d->removed as $s) {
                $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
                $parts[] = "{$kind} {$s->qualifiedName}";
            }
        }

        // Added symbols: full declarations (consumer doesn't have these).
        if ($d->added !== []) {
            $parts[] = '## added';
            foreach ($d->added as $i => $s) {
                $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
                $score = sprintf('%.2f', $s->score);
                $parts[] = "@{$i} {$kind} {$s->qualifiedName} {$score} {$s->provenance} {$s->distance}";
            }
        }

        // Removed edges.
        if ($d->removedEdges !== []) {
            $parts[] = '## edges_removed';
            foreach ($d->removedEdges as $e) {
                $parts[] = "{$e->source} -> {$e->target} {$e->edgeType}";
            }
        }

        // Added edges.
        if ($d->addedEdges !== []) {
            $parts[] = '## edges_added';
            foreach ($d->addedEdges as $e) {
                $parts[] = "{$e->source} -> {$e->target} {$e->edgeType}";
            }
        }

        return implode("\n", $parts)."\n";
    }

    /**
     * Parses a GCF graph delta wire payload back into a DeltaPayload.
     *
     * Kind abbreviations on removed/added lines are expanded to their full form so
     * the result matches a base snapshot's symbol identities. Throws
     * GcfDecodeException with a message containing "malformed_delta" on bad lines
     * or unknown sections.
     */
    public static function decode(string $wire): DeltaPayload
    {
        $lines = explode("\n", rtrim($wire, "\n"));
        if ($lines === [] || $lines[0] === '') {
            throw new GcfDecodeException('missing_header: empty delta payload');
        }
        $header = rtrim($lines[0], "\r");
        if (!str_starts_with($header, 'GCF profile=graph')) {
            throw new GcfDecodeException("missing_profile: delta header must begin with 'GCF profile=graph'");
        }

        $tool = '';
        $baseRoot = '';
        $newRoot = '';
        foreach (preg_split('/\s+/', $header, -1, PREG_SPLIT_NO_EMPTY) as $field) {
            $kv = explode('=', $field, 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            match ($key) {
                'tool' => $tool = $value,
                'base_root' => $baseRoot = $value,
                'new_root' => $newRoot = $value,
                default => null,
            };
        }

        $removed = [];
        $added = [];
        $removedEdges = [];
        $addedEdges = [];

        $section = '';
        for ($i = 1, $n = count($lines); $i < $n; ++$i) {
            $line = rtrim($lines[$i], "\r");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '## ')) {
                $section = trim(substr($line, 3));
                if (!in_array($section, self::VALID_SECTIONS, true)) {
                    throw new GcfDecodeException("malformed_delta: unknown section '{$section}'");
                }
                continue;
            }

            if ($section === 'removed') {
                $parts = self::splitFields($line);
                if (count($parts) !== 2) {
                    throw new GcfDecodeException("malformed_delta: removed line '{$line}' must be 'kind qname'");
                }
                $removed[] = new Symbol(
                    qualifiedName: $parts[1],
                    kind: Constants::KIND_EXPAND[$parts[0]] ?? $parts[0],
                );
            } elseif ($section === 'added') {
                $parts = self::splitFields($line);
                if (count($parts) !== 6) {
                    throw new GcfDecodeException(
                        "malformed_delta: added line '{$line}' must be "
                        ."'@id kind qname score provenance distance'",
                    );
                }
                if (!is_numeric($parts[3])) {
                    throw new GcfDecodeException("malformed_delta: invalid added score '{$parts[3]}'");
                }
                $score = (float) $parts[3];
                if (preg_match('/^[+-]?\d+$/', $parts[5]) !== 1) {
                    throw new GcfDecodeException("malformed_delta: invalid added distance '{$parts[5]}'");
                }
                $distance = (int) $parts[5];
                $added[] = new Symbol(
                    qualifiedName: $parts[2],
                    kind: Constants::KIND_EXPAND[$parts[1]] ?? $parts[1],
                    score: $score,
                    provenance: $parts[4],
                    distance: $distance,
                );
            } elseif ($section === 'edges_removed' || $section === 'edges_added') {
                $e = self::parseDeltaEdge($line);
                if ($section === 'edges_removed') {
                    $removedEdges[] = $e;
                } else {
                    $addedEdges[] = $e;
                }
            } else {
                throw new GcfDecodeException("malformed_delta: data line '{$line}' before any section header");
            }
        }

        return new DeltaPayload(
            tool: $tool,
            baseRoot: $baseRoot,
            newRoot: $newRoot,
            removed: $removed,
            added: $added,
            removedEdges: $removedEdges,
            addedEdges: $addedEdges,
        );
    }

    /**
     * Applies a delta to a base snapshot and verifies the resulting pack root.
     * Ported from gcf-python's verify_delta.
     *
     * Symbols are matched by identity (kind, qualified_name); edges by
     * (source, target, edge_type). Throws GcfDecodeException with a message
     * containing "delta_invalid" when removing a symbol/edge that does not exist
     * or adding one that already exists, and "root_mismatch" when the recomputed
     * pack root differs from $expectedNewRoot. On success returns the applied
     * [symbols, edges] tuple.
     *
     * @param list<Symbol> $baseSymbols
     * @param list<Edge>   $baseEdges
     * @param list<Symbol> $removed
     * @param list<Symbol> $added
     * @param list<Edge>   $removedEdges
     * @param list<Edge>   $addedEdges
     *
     * @return array{0: list<Symbol>, 1: list<Edge>}
     */
    public static function verify(
        array $baseSymbols,
        array $baseEdges,
        array $removed,
        array $added,
        array $removedEdges,
        array $addedEdges,
        string $expectedNewRoot,
    ): array {
        $symMap = [];
        foreach ($baseSymbols as $s) {
            $symMap[self::symbolKey($s)] = $s;
        }

        foreach ($removed as $s) {
            $key = self::symbolKey($s);
            if (!array_key_exists($key, $symMap)) {
                throw new GcfDecodeException(
                    "delta_invalid: removing symbol {$s->kind} {$s->qualifiedName} that does not exist in base",
                );
            }
            unset($symMap[$key]);
        }

        foreach ($added as $s) {
            $key = self::symbolKey($s);
            if (array_key_exists($key, $symMap)) {
                throw new GcfDecodeException(
                    "delta_invalid: adding symbol {$s->kind} {$s->qualifiedName} that already exists",
                );
            }
            $symMap[$key] = $s;
        }

        $resultSymbols = array_values($symMap);

        $edgeMap = [];
        foreach ($baseEdges as $e) {
            $edgeMap[self::edgeKey($e)] = $e;
        }

        foreach ($removedEdges as $e) {
            $key = self::edgeKey($e);
            if (!array_key_exists($key, $edgeMap)) {
                throw new GcfDecodeException(
                    "delta_invalid: removing edge {$e->source} -> {$e->target} {$e->edgeType} that does not exist",
                );
            }
            unset($edgeMap[$key]);
        }

        foreach ($addedEdges as $e) {
            $key = self::edgeKey($e);
            if (array_key_exists($key, $edgeMap)) {
                throw new GcfDecodeException(
                    "delta_invalid: adding edge {$e->source} -> {$e->target} {$e->edgeType} that already exists",
                );
            }
            $edgeMap[$key] = $e;
        }

        $resultEdges = array_values($edgeMap);

        $computedRoot = PackRoot::compute($resultSymbols, $resultEdges);
        if ($computedRoot !== $expectedNewRoot) {
            throw new GcfDecodeException("root_mismatch: computed {$computedRoot}, expected {$expectedNewRoot}");
        }

        return [$resultSymbols, $resultEdges];
    }

    private static function symbolKey(Symbol $s): string
    {
        return $s->kind."\0".$s->qualifiedName;
    }

    private static function edgeKey(Edge $e): string
    {
        return $e->source."\0".$e->target."\0".$e->edgeType;
    }

    /** Parses a `source -> target type` delta edge line. */
    private static function parseDeltaEdge(string $line): Edge
    {
        $idx = strpos($line, ' -> ');
        if ($idx === false) {
            throw new GcfDecodeException("malformed_delta: edge line missing ' -> ': '{$line}'");
        }
        $source = substr($line, 0, $idx);
        $rest = self::splitFields(substr($line, $idx + 4));
        if (count($rest) !== 2) {
            throw new GcfDecodeException("malformed_delta: edge line '{$line}' must be 'source -> target type'");
        }

        return new Edge(source: $source, target: $rest[0], edgeType: $rest[1]);
    }

    /**
     * @return list<string>
     */
    private static function splitFields(string $line): array
    {
        return preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
    }
}
