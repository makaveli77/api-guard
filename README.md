# API Guard

API Guard is a framework-independent PHP package for comparing OpenAPI specifications and identifying changes that can break API consumers.

## Features

- Framework-independent PHP package
- OpenAPI 3.x YAML and JSON loading
- Strict validation for malformed or unsupported specifications
- Structured breaking, non-breaking, and informational comparison results
- Human-readable CLI reports with predictable CI exit codes
- Recursive comparison of nested and referenced object and array schemas
- Minimal dependency footprint
- PHPStan and PHPUnit configured for code quality

## Installation

```bash
composer require ahdev/api-guard
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

## CLI

Compare two OpenAPI documents with:

```bash
vendor/bin/api-guard check --old=openapi-v1.yaml --new=openapi-v2.yaml
```

The command accepts YAML or JSON files and prints a deterministic summary followed by separate sections for breaking, non-breaking, and informational changes. For example:

```text
API Guard

Comparing:
    openapi-v1.yaml
    openapi-v2.yaml

Summary:
    Breaking changes:     1
    Non-breaking changes: 0
    Informational changes: 0
    Total changes:        1

BREAKING CHANGES
    ! /users [RESPONSE SCHEMA]
        GET response 200 application/json property email was removed.
```

Exit codes are designed for scripts and CI:

| Code | Meaning |
| ---: | --- |
| 0 | Comparison succeeded with no breaking changes |
| 1 | Breaking changes were detected |
| 2 | Invalid command arguments or input files |
| 3 | An input is not a valid OpenAPI 3.x specification |
| 4 | Unexpected internal error |

Use `vendor/bin/api-guard --help` to display command usage. JSON output and GitHub annotations are not included.

## GitHub Actions

The repository provides a composite action that sets up PHP 8.2, installs API Guard's locked production dependencies, and invokes the existing CLI. Check out the API repository before using the action. The `old` and `new` inputs accept paths relative to `GITHUB_WORKSPACE` or absolute paths. The example step below uses the same inputs declared in `action.yml`.

```yaml
- name: Check API compatibility
  uses: makaveli77/api-guard@v1
  with:
    old: .api-guard-baseline/docs/openapi.yaml
    new: docs/openapi.yaml
```

Exit code `1` makes the action fail when breaking changes are found. Invalid arguments or files, invalid specifications, and unexpected errors also fail with the CLI's corresponding exit codes. The full pull-request workflow checks out the exact base and head commits and is available at [docs/examples/api-guard.yml](api-guard/docs/examples/api-guard.yml).

## Laravel Integration

Laravel support is provided by a separate package, keeping Laravel dependencies out of the core:

```bash
composer require ahdev/laravel-api-guard
php artisan api:guard docs/openapi-v1.yaml docs/openapi-v2.yaml
```

Laravel package discovery registers the provider and command automatically. The command reuses the core loader, comparison service, and report formatter. See [packages/laravel-api-guard/README.md](api-guard/packages/laravel-api-guard/README.md) for supported Laravel versions and details.

## Symfony Integration

Symfony support is also provided as a separate package:

```bash
composer require ahdev/symfony-api-guard
php bin/console api-guard:check docs/openapi-v1.yaml docs/openapi-v2.yaml
```

Register `ApiGuard\Symfony\ApiGuardBundle::class` in `config/bundles.php`. The bundle wires the core loader, comparison service, report formatter, and Console command through Symfony's dependency injection container. See [packages/symfony-api-guard/README.md](api-guard/packages/symfony-api-guard/README.md) for full installation details.

Symfony Console, DependencyInjection, and HttpKernel dependencies are isolated in the adapter package. The core retains its pre-existing standalone `symfony/yaml` parser component, but has no Symfony framework bundle or Console integration.

## Pro Features

API Guard Pro is a separate proprietary, commercial package. A valid commercial license is required for its use; commercial licensing, payment, and distribution are not automated yet. The Core package remains free and MIT licensed.

```bash
composer require ahdev/api-guard-pro
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml --format=json
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml --ignore-config=.api-guard-pro.yaml
```

Versioned ignore rules match exact change types and endpoint paths, require a reason, and keep suppressed changes visible in reports. The package provides text, JSON, and Markdown output. See [packages/api-guard-pro/README.md](api-guard-pro/README.md) for the configuration format and exit behavior.

## Comparison rules

Breaking changes include removed paths or methods, removed parameters, newly required parameters or request bodies, request-property removal or newly required properties, incompatible schema type changes, removed enum values, removed request or response media types, removed response properties, response properties becoming optional, and removed response statuses.

Non-breaking changes include added paths or methods, optional parameters and request properties, parameters, request bodies, or request properties becoming optional, added response properties, response statuses or media types, widened request enums, and operation summary or description edits. Adding a value to a response enum is breaking because clients may receive an unrecognized value. A required request property or required request body is breaking.

Comparisons recurse through inline object properties, array items, inline `additionalProperties` schemas, and local `#/components/schemas/...` references, including recursive references and escaped JSON Pointer names. External references and composed schemas such as `oneOf`, `anyOf`, and `allOf` are not currently expanded.

## Real-world regression coverage

The fixture-based comparison suite compares a production-style Commerce API in YAML and JSON. It covers multiple endpoints and methods; path, query, header, and cookie parameters; request and response bodies; referenced and recursive components; nested objects and arrays; nullable and required properties; enum changes; and multiple response statuses. Assertions include simultaneous breaking and non-breaking changes, malformed-input coverage, escaped reference names, repeated comparisons, and stable result ordering when path declarations are reordered.

## Development status

Phases 1-9 are implemented: package foundation, OpenAPI loading and validation, structured compatibility comparison, CLI reporting, real-world regression coverage, GitHub Actions integration, separate Laravel and Symfony adapters, and the initial Pro ignore/report package. HTML reports, history, notifications, consumer tracking, and advanced custom rules remain future work.

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
