# API Guard

API Guard is a framework-independent PHP package for loading, validating, and preparing OpenAPI specifications for later comparison and compatibility checks.

## Features

- Framework-independent PHP package
- OpenAPI 3.x YAML and JSON loading
- Strict validation for malformed or unsupported specifications
- Clean domain representation for future comparison phases
- Minimal dependency footprint
- PHPStan and PHPUnit configured for code quality

## Installation

```bash
composer require your-vendor/api-guard
```

## Current status

Phase 1 created the package foundation and Phase 2 adds the OpenAPI specification loader.

The project currently supports:

- loading valid OpenAPI 3.x YAML files
- loading valid OpenAPI 3.x JSON files
- rejecting invalid YAML/JSON
- rejecting malformed or non-OpenAPI-3 documents
- returning a clean domain model for future comparison work

The actual breaking-change comparison engine and final CLI comparison workflow are intentionally not implemented yet and remain for later phases.

## Basic usage

```php
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;

$loader = new OpenApiFileLoader();
$spec = $loader->load('openapi.yaml');

var_dump($spec->version, $spec->title, $spec->paths);
```

## Planned phases

- Phase 1: package foundation and architecture
- Phase 2: OpenAPI loading and validation
- Phase 3: comparison engine and rule evaluation
- Phase 4: CLI reporting and CI-friendly output

## License

MIT
