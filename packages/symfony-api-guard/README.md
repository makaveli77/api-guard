# Symfony API Guard

Symfony API Guard is a separate bundle for running API Guard comparisons through Symfony Console. It delegates specification loading, comparison, and report formatting to the framework-independent API Guard core.

## Requirements

- PHP 8.2 or later
- Symfony 6.4, 7, or 8

## Installation

```bash
composer require ahdev/symfony-api-guard
```

Register the bundle in `config/bundles.php`:

```php
return [
    ApiGuard\Symfony\ApiGuardBundle::class => ['all' => true],
];
```

The bundle loads its service definitions, aliases the core specification loader interface, and registers the Console command. No configuration file is required.

## Usage

```bash
php bin/console api-guard:check docs/openapi-v1.yaml docs/openapi-v2.yaml
```

Paths are resolved relative to the current working directory. YAML and JSON specifications are supported. Breaking changes return exit code `1`; invalid command input returns `2`, invalid OpenAPI documents return `3`, unexpected errors return `4`, and comparisons without breaking changes return `0`.

Symfony Console, DependencyInjection, and HttpKernel integration dependencies are confined to this package. The core retains its existing standalone `symfony/yaml` parser component, but contains no Symfony framework bundle or Console integration. See the [core API Guard documentation](https://github.com/makaveli77/api-guard/blob/main/README.md) for comparison rules.