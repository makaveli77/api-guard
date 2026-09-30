# API Guard

API Guard is a framework-independent PHP package for comparing OpenAPI specifications and identifying changes that can break API consumers.

## Features

- Framework-independent PHP package
- OpenAPI 3.x YAML and JSON loading
- Strict validation for malformed or unsupported specifications
- Structured breaking and non-breaking comparison results
- Recursive comparison of inline object and array schemas
- Minimal dependency footprint
- PHPStan and PHPUnit configured for code quality

## Installation

```bash
composer require your-vendor/api-guard
```

## Basic usage

```php
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Application\ComparisonService;

$loader = new OpenApiFileLoader();
$old = $loader->load('openapi-v1.yaml');
$new = $loader->load('openapi-v2.yaml');
$changes = (new ComparisonService())->compare($old, $new);

foreach ($changes as $change) {
    printf("%s %s: %s\n", strtoupper($change->severity->value), $change->path, $change->message);
}
```

`ComparisonService::compare()` returns a list of immutable `Change` values. Each change includes a type, severity, affected endpoint path, and message. It does not print output or depend on a CLI framework.

## Comparison rules

Breaking changes include removed paths or methods, removed parameters, newly required parameters or request bodies, request-property removal or newly required properties, incompatible schema type changes, removed enum values, removed request or response media types, removed response properties, response properties becoming optional, and removed response statuses.

Non-breaking changes include added paths or methods, optional parameters and request properties, parameters, request bodies, or request properties becoming optional, added response properties, response statuses or media types, widened request enums, and operation summary or description edits. Adding a value to a response enum is breaking because clients may receive an unrecognized value. A required request property or required request body is breaking.

Comparisons recurse through inline object properties, array items, and inline `additionalProperties` schemas. Component or external `$ref` resolution and composed schemas such as `oneOf`/`allOf` are not currently expanded.

## Development status

Phases 1-3 are implemented: package foundation, OpenAPI loading and validation, and structured compatibility comparison. CLI reporting and CI output are Phase 4 and are not part of the current comparison API.

Run the checks with:

```bash
composer test
composer analyse
```

## Supported versions

- PHP 8.2 or later
- OpenAPI 3.x documents in YAML or JSON

## License

MIT
