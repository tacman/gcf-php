<?php

declare(strict_types=1);

namespace Gcf\Generic\Delta;

/**
 * Factory for ReanchorPolicy instances (SPEC Section 10a.8). Ported from
 * gcf-python's generic_delta.py: fixed_n, size_guard, DEFAULT_REANCHOR_N.
 */
final class Reanchor
{
    /** The working default cadence for FixedN (SPEC Section 10a.8). */
    public const DEFAULT_REANCHOR_N = 15;

    private function __construct()
    {
    }

    /**
     * Re-anchor every $n turns. $n <= 0 falls back to DEFAULT_REANCHOR_N.
     */
    public static function fixedN(int $n): ReanchorPolicy
    {
        if ($n <= 0) {
            $n = self::DEFAULT_REANCHOR_N;
        }

        return new ReanchorPolicy(ReanchorMode::FixedN, $n);
    }

    /**
     * Re-anchor once the cumulative delta bytes since the last anchor reach the
     * current full payload's byte size: it re-anchors more under heavy churn, rarely
     * under light churn, and bounds the delta spent between anchors to about one full
     * payload. Production-recommended.
     */
    public static function sizeGuard(): ReanchorPolicy
    {
        return new ReanchorPolicy(ReanchorMode::SizeGuard);
    }
}
