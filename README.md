# gcf-php

A PHP implementation of [GCF (Graph Compact Format)](https://www.gcformat.com/) — a token-efficient
wire format for structured data designed for LLM agent loops. Lossless conversion to/from JSON,
50-92% fewer tokens depending on data shape.

GCF is specified and maintained by [blackwell-systems/gcf](https://github.com/blackwell-systems/gcf),
with reference implementations in Go, Rust, TypeScript, Python, Swift, and Kotlin. This is an
independent PHP port, targeting spec version 3.5.1 (see [`spec/SPEC.md`](spec/SPEC.md), vendored
from the source commit noted in [`spec/SOURCE-COMMIT.txt`](spec/SOURCE-COMMIT.txt)). It was ported
from [gcf-python](https://github.com/blackwell-systems/gcf-python) v2.3.0 and tracks its public API
1:1 — both profiles, delta encoding, session dedup, streaming, and the CLI.

## Requirements

PHP 8.1+. Zero runtime dependencies — matching the other six official implementations' permanent
zero-dependency commitment.

## Usage

### Generic profile — arbitrary structured data (tabular arrays, keyed maps, nested objects)

```php
use Gcf\Generic\Encoder;
use Gcf\Generic\Decoder;

$gcf = Encoder::encode($data);   // array|scalar|null -> GCF text
$data = Decoder::decode($gcf);   // GCF text -> array|scalar|null
```

### Graph profile — symbols/edges (code graphs, context packs)

```php
use Gcf\{Payload, Symbol, Edge};
use Gcf\Graph\Encoder;
use Gcf\Graph\Decoder;

$payload = new Payload(
    tool: 'context_for_task',
    tokenBudget: 5000,
    tokensUsed: 1847,
    symbols: [new Symbol(qualifiedName: 'pkg.Auth', kind: 'function', score: 0.9, provenance: 'lsp_resolved')],
);
$gcf = Encoder::encode($payload);
$payload = Decoder::decode($gcf);
```

### Session dedup — reuse symbols as bare references across multiple calls

```php
use Gcf\Session;

$session = new Session();
$out1 = $session->encode($payload1); // full declarations
$out2 = $session->encode($payload2); // previously-sent symbols become bare @N refs
```

### Delta encoding — send only what changed

```php
use Gcf\Graph\Delta;
use Gcf\Generic\Delta\{GenericDiff, GenericDeltaEncoder, GenericDeltaVerifier};

// Graph profile
$gcf = Delta::encode($deltaPayload);
$deltaPayload = Delta::decode($gcf);

// Generic profile (keyed row sets)
$delta = GenericDiff::diff($baseSet, $nextSet);
$gcf = GenericDeltaEncoder::encodeDelta($delta);
$updatedSet = GenericDeltaVerifier::verify($baseSet, $delta, $expectedNewRoot);
```

`Gcf\Generic\Delta\GenericDeltaSession` automates the delta-vs-full-reanchor decision across turns
(`Reanchor::fixedN(n)` / `Reanchor::sizeGuard()` policies).

### Streaming — emit rows incrementally, zero buffering

```php
use Gcf\Graph\StreamEncoder;
use Gcf\Generic\StreamEncoder as GenericStreamEncoder;

$enc = new StreamEncoder($sink, tool: 'context_for_task'); // $sink: resource|callable(string):void
$enc->writeSymbol($symbol);
$enc->writeEdge($edge);
$enc->close();
```

### Pack root — canonical content hash for delta base/new root comparisons

```php
use Gcf\PackRoot;                          // graph profile
use Gcf\Generic\Delta\GenericPackRoot;     // generic profile

$hash = PackRoot::compute($symbols, $edges);       // "sha256:..."
$hash = GenericPackRoot::compute($genericSet);
```

### CLI

```bash
vendor/bin/gcf encode < payload.json          # JSON graph payload -> GCF
vendor/bin/gcf decode < payload.gcf           # GCF graph text -> JSON
vendor/bin/gcf encode-generic < data.json     # JSON -> GCF generic profile
vendor/bin/gcf decode-generic < data.gcf      # GCF generic text -> JSON
vendor/bin/gcf stats < payload.json           # token-count comparison, JSON vs GCF
```

## Conformance

`tests/conformance/` is vendored from the spec repo's shared fixture suite (JSON files with
`input`/`expected`/`operation` — the same fixtures used to validate the other 6 implementations).
Five PHPUnit test classes cover every vendored fixture 1:1, split by concern:

| Test class | Fixture directories |
|---|---|
| `ConformanceTest` | generic-profile full snapshot: arrays, attachments, containers, decode, errors-v2 (non-graph), flatten, inline-schema, keyed-map, keys, numbers, roots, scalar, whitespace |
| `GraphConformanceTest` | graph-encode, graph-decode, graph-pack-root, graph-scoped errors-v2 |
| `GenericDeltaConformanceTest` | generic-delta, generic-delta-session, generic-pack-root |
| `GraphDeltaSessionConformanceTest` | graph-delta, graph-session, malformed-delta error |
| `StreamingConformanceTest` | streaming-v2 |

Run the whole suite: `vendor/bin/phpunit`.

## License

MIT. See [LICENSE](LICENSE). Format specification and prior art by Dayna Blackwell /
[blackwell-systems](https://github.com/blackwell-systems).
