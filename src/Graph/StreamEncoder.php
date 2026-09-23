<?php

declare(strict_types=1);

namespace Gcf\Graph;

use Gcf\Constants;
use Gcf\Edge;
use Gcf\StreamSink;
use Gcf\Symbol;

/**
 * Writes GCF graph-profile output incrementally as symbols and edges arrive
 * (SPEC section 8, 16.1). Ported from gcf-python's stream.py `StreamEncoder`.
 *
 * Zero buffering: each symbol/edge is written immediately to $sink. A trailer
 * summary is emitted on close() with the final counts.
 *
 * $sink accepts a PHP stream resource (written via fwrite) or a
 * callable(string $chunk): void — see Gcf\StreamSink.
 *
 * Example:
 *
 *     $enc = new StreamEncoder(STDOUT, 'context_for_task', tokenBudget: 5000);
 *     $enc->writeSymbol($sym1); // emitted immediately
 *     $enc->writeEdge($edge1);  // emitted immediately
 *     $enc->close();            // emits ##! summary trailer
 */
final class StreamEncoder
{
    private const GROUP_NAMES = ['targets', 'related', 'extended'];

    /** @var array<string,int> qualifiedName -> local id */
    private array $symIndex = [];

    private int $nextId = 0;

    private string $currentGroup = '';

    /** @var array<string,int> group name -> count, insertion-ordered (== group-header emission order) */
    private array $groupCounts = [];

    private int $edgeCount = 0;

    private bool $edgesStarted = false;

    public function __construct(
        private readonly mixed $sink,
        string $tool,
        private readonly int $tokenBudget = 0,
        private readonly int $tokensUsed = 0,
        private readonly string $packRoot = '',
        private readonly bool $session = false,
        private readonly bool $labeledTrailerCounts = false,
    ) {
        $parts = ["GCF profile=graph tool={$tool}"];
        if ($this->tokenBudget !== 0) {
            $parts[] = "budget={$this->tokenBudget}";
        }
        if ($this->tokensUsed !== 0) {
            $parts[] = "tokens={$this->tokensUsed}";
        }
        if ($this->packRoot !== '') {
            $parts[] = "pack_root={$this->packRoot}";
        }
        if ($this->session) {
            $parts[] = 'session=true';
        }
        $this->writeChunk(implode(' ', $parts)."\n");
    }

    /** Emits a symbol line immediately. Group headers are auto-managed. */
    public function writeSymbol(Symbol $s): void
    {
        $groupName = self::groupName($s->distance);
        $this->enterGroup($groupName);

        $idx = $this->nextId;
        $this->symIndex[$s->qualifiedName] = $idx;
        ++$this->nextId;

        $kind = Constants::KIND_ABBREV[$s->kind] ?? $s->kind;
        $score = sprintf('%.2f', $s->score);
        $this->writeChunk("@{$idx} {$kind} {$s->qualifiedName} {$score} {$s->provenance}\n");

        $this->groupCounts[$groupName] = ($this->groupCounts[$groupName] ?? 0) + 1;
    }

    /** Emits an edge line immediately. The edges section header is auto-emitted on the first edge. */
    public function writeEdge(Edge $e): void
    {
        $srcIdx = $this->symIndex[$e->source] ?? null;
        $tgtIdx = $this->symIndex[$e->target] ?? null;
        if ($srcIdx === null || $tgtIdx === null) {
            return;
        }

        if (!$this->edgesStarted) {
            $this->writeChunk("## edges [?]\n");
            $this->edgesStarted = true;
        }

        $line = "@{$tgtIdx}<@{$srcIdx} {$e->edgeType}";
        if ($e->status !== '' && $e->status !== 'unchanged') {
            $line .= " {$e->status}";
        }
        $this->writeChunk($line."\n");
        ++$this->edgeCount;
    }

    /** Emits a bare reference for a previously-transmitted symbol (session mode). */
    public function writeBareRef(string $qualifiedName, int $distance): void
    {
        $groupName = self::groupName($distance);
        $this->enterGroup($groupName);

        $idx = $this->nextId;
        $this->symIndex[$qualifiedName] = $idx;
        ++$this->nextId;
        $this->writeChunk("@{$idx}  # previously transmitted\n");
        $this->groupCounts[$groupName] = ($this->groupCounts[$groupName] ?? 0) + 1;
    }

    /** Emits the ##! summary trailer with final counts. */
    public function close(): void
    {
        // Build label:count sections, then either emit as-is (labeled form, SPEC
        // 8.4.1) or strip to values (default positional form). One entry per
        // non-empty distance group in group-header emission order (SPEC 8.4): the
        // array preserves insertion order, which is the order the group headers
        // were emitted, so the trailer is deterministic and matches the section
        // order (including distance_N groups) across all SDKs.
        $sections = [];
        foreach ($this->groupCounts as $g => $c) {
            if ($c > 0) {
                $sections[] = "{$g}:{$c}";
            }
        }
        // The edge count is always the last counts entry, even when 0 (SPEC 8.4,
        // 8.4.1): it keeps the positional form unambiguous and anchors the labeled
        // form (minimal counts=edges:0). Zero-count distance groups are omitted,
        // but edges is not.
        $sections[] = "edges:{$this->edgeCount}";

        if ($this->labeledTrailerCounts) {
            $countsStr = implode(',', $sections);
        } else {
            $countsStr = implode(',', array_map(
                static fn (string $s): string => explode(':', $s, 2)[1],
                $sections,
            ));
        }

        $this->writeChunk(
            "##! summary symbols={$this->nextId} edges={$this->edgeCount} counts={$countsStr}\n",
        );
    }

    /** Number of symbols written so far. */
    public function getSymbolCount(): int
    {
        return $this->nextId;
    }

    /** Number of edges written so far. */
    public function getEdgeCount(): int
    {
        return $this->edgeCount;
    }

    private static function groupName(int $distance): string
    {
        return $distance < count(self::GROUP_NAMES) ? self::GROUP_NAMES[$distance] : "distance_{$distance}";
    }

    private function enterGroup(string $groupName): void
    {
        if ($groupName !== $this->currentGroup) {
            $this->writeChunk("## {$groupName}\n");
            $this->currentGroup = $groupName;
        }
    }

    private function writeChunk(string $chunk): void
    {
        StreamSink::write($this->sink, $chunk);
    }
}
