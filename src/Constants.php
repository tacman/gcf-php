<?php

declare(strict_types=1);

namespace Gcf;

/** Kind abbreviation mappings for GCF graph-profile encoding/decoding. Ported from gcf-python's constants.py. */
final class Constants
{
    /** @var array<string,string> Full kind name -> short GCF abbreviation. */
    public const KIND_ABBREV = [
        'function' => 'fn',
        'type' => 'type',
        'method' => 'method',
        'interface' => 'iface',
        'var' => 'var',
        'const' => 'const',
        'resource' => 'resource',
        'table' => 'table',
        'class' => 'class',
        'selector' => 'selector',
        'field' => 'field',
        'route_handler' => 'route',
        'external' => 'ext',
        'file' => 'file',
        'package' => 'pkg',
        'service' => 'svc',
    ];

    /** @var array<string,string> Short GCF abbreviation -> full kind name. */
    public const KIND_EXPAND = [
        'fn' => 'function',
        'type' => 'type',
        'method' => 'method',
        'iface' => 'interface',
        'var' => 'var',
        'const' => 'const',
        'resource' => 'resource',
        'table' => 'table',
        'class' => 'class',
        'selector' => 'selector',
        'field' => 'field',
        'route' => 'route_handler',
        'ext' => 'external',
        'file' => 'file',
        'pkg' => 'package',
        'svc' => 'service',
    ];

    private function __construct()
    {
    }
}
