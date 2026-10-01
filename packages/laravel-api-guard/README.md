# Laravel API Guard

Laravel API Guard is a separate Artisan adapter for the framework-independent `ahdev/api-guard` package. It reuses the core specification loader, comparison service, and report formatter; comparison rules remain in the core package.

## Requirements

- PHP 8.2 or later
- Laravel 10, 11, or 12

## Installation

```bash
composer require ahdev/laravel-api-guard
```

Laravel package discovery registers the service provider and command automatically. If package discovery is disabled, add `ApiGuard\Laravel\ApiGuardServiceProvider::class` to the application's providers list.

No configuration file is required. The command takes the baseline and updated specification paths directly:

```bash
php artisan api:guard docs/openapi-v1.yaml docs/openapi-v2.yaml
```

Paths are resolved from the application's current working directory. YAML and JSON are supported. A breaking comparison returns exit code `1`; invalid command input returns `2`, invalid OpenAPI documents return `3`, and unexpected errors return `4`. A comparison without breaking changes returns `0`.

The package does not add Laravel dependencies to the core API Guard package. See the [core package documentation](https://github.com/makaveli77/api-guard/blob/main/README.md) for comparison rules and report details.