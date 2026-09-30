# API Guard Pro

API Guard Pro is a separate package for team-oriented ignore policies and machine-readable or review-friendly reports. It composes the core specification loader and comparison service; comparison rules remain in API Guard core.

## Installation

```bash
composer require your-vendor/api-guard-pro
```

## Usage

```bash
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml --format=json
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml --format=markdown
vendor/bin/api-guard-pro --old=openapi-v1.yaml --new=openapi-v2.yaml --ignore-config=.api-guard-pro.yaml
```

The default `text` format uses the core formatter. `json` emits a `schema_version: 1` document suitable for CI consumers, and `markdown` emits a deterministic report for pull requests and release reviews.

## Ignore configuration

Ignore rules are opt-in and use a versioned YAML file:

```yaml
version: 1
ignore:
  - type: response_schema
    path: /users/{id}
    message: GET response 200 application/json property email was removed.
    reason: Existing consumer migration approved in API-142.
```

Each rule requires an exact change type, endpoint path, and non-empty reason. An optional exact `message` further narrows the match. Unknown keys, types, versions, or malformed configuration fail closed. A matching breaking change is excluded from the CI failure decision but remains in the report under `ignored_changes`, with its reason and ignored count visible.

Exit codes match the core CLI: `0` no active breaking changes, `1` active breaking changes, `2` invalid arguments or ignore configuration, `3` invalid OpenAPI specifications, and `4` unexpected errors.

## Scope

This package currently provides reasoned ignore rules plus text, JSON, and Markdown reports. HTML reports, historical comparisons, notifications, consumer tracking, custom engine rules, and license enforcement are not included.