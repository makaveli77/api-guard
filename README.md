# API Guard

API Guard is a framework-independent PHP package for comparing OpenAPI specifications and identifying breaking API changes before they reach production.

## Features

- Framework-independent PHP package
- OpenAPI 3.x compatibility focus
- CLI-first workflow
- CI-friendly exit codes
- Extensible comparison rules

## Installation

```bash
composer require your-vendor/api-guard
```

## Basic usage

```bash
vendor/bin/api-guard check --old=openapi-v1.yaml --new=openapi-v2.yaml
```

## Current Phase

This repository is in the Phase 1 foundation stage. The package skeleton, Composer configuration, PHPUnit setup, and PHPStan configuration are in place. Actual comparison logic and CLI comparison features will be added in later phases.

## License

MIT
