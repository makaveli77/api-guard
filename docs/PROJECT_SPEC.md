# API Guard

**Working name:** API Guard
**Type:** Commercial/open-source PHP developer tool
**Primary purpose:** Detect breaking changes between OpenAPI specifications before they reach production.

## 1. Product Vision

API Guard is a framework-independent PHP package that compares two OpenAPI specifications and detects changes that may break existing API consumers.

The primary use case is:

> A developer changes an API, runs API Guard, and immediately knows whether the change is backwards-compatible.

The product should eventually support:

* PHP projects
* Laravel projects
* Symfony projects
* CI/CD pipelines
* GitHub Actions
* OpenAPI 3.x specifications

The first version must remain small, reliable, and easy to use.

---

# 2. MVP Goal

The MVP should do one thing extremely well:

> Compare an old OpenAPI specification with a new OpenAPI specification and report breaking changes.

Example:

```bash
vendor/bin/api-guard check \
    --old=openapi-v1.yaml \
    --new=openapi-v2.yaml
```

Expected output:

```text
API Guard

Comparing:
  openapi-v1.yaml
  openapi-v2.yaml

✓ New endpoints:       3
✓ Modified endpoints:  5
✓ Deprecated:          1
✗ Breaking changes:    2

BREAKING CHANGES

✗ DELETE /users/{id}
  Response property "email" was removed.

✗ POST /users
  Required parameter "name" was added.

Exit code: 1
```

Exit code `1` is important because it allows API Guard to fail a CI pipeline when breaking changes are detected.

---

# 3. Package Structure

The core package must be framework-independent.

Initial structure:

```text
api-guard/
├── bin/
│   └── api-guard
├── src/
│   ├── Application/
│   ├── Domain/
│   ├── Infrastructure/
│   └── CLI/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Fixtures/
├── docs/
│   └── PROJECT_SPEC.md
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── README.md
├── LICENSE
└── .gitignore
```

Do not create Laravel-specific code in the core package.

---

# 4. Supported PHP Version

Initial target:

```text
PHP 8.2+
```

Use modern PHP features where appropriate.

The package should use:

* strict types
* typed properties
* readonly properties where appropriate
* enums where useful
* interfaces
* dependency injection
* immutable/value-object style where appropriate

Avoid unnecessary dependencies.

---

# 5. Composer Package

The intended Composer package name is:

```text
your-vendor/api-guard
```

Do not hard-code a personal vendor name until the repository owner is known.

The package should expose:

```bash
vendor/bin/api-guard
```

Composer should correctly install the executable.

---

# 6. CLI

The initial CLI command should be:

```bash
vendor/bin/api-guard check
```

Required arguments:

```bash
--old=<file>
--new=<file>
```

Example:

```bash
vendor/bin/api-guard check \
    --old=docs/openapi-v1.yaml \
    --new=docs/openapi-v2.yaml
```

Optional arguments can be added later.

The CLI should:

1. Validate input files.
2. Detect YAML or JSON.
3. Parse both OpenAPI specifications.
4. Validate that they are supported OpenAPI documents.
5. Compare them.
6. Classify changes.
7. Print a human-readable report.
8. Return an appropriate exit code.

---

# 7. Exit Codes

Use predictable exit codes.

```text
0 = no breaking changes
1 = breaking changes detected
2 = invalid command/input
3 = invalid OpenAPI specification
4 = unexpected/internal error
```

The exact implementation can use constants or an enum.

---

# 8. OpenAPI Support

Initial target:

```text
OpenAPI 3.x
```

Support both:

```text
YAML
JSON
```

The implementation should not assume that specifications are always YAML.

The parser should be isolated behind an abstraction so the rest of the application does not depend directly on a specific parser library.

For example:

```php
interface SpecificationLoaderInterface
{
    public function load(string $path): OpenApiSpecification;
}
```

The exact architecture may differ if a better design is identified.

---

# 9. Comparison Engine

The comparison engine is the core of the product.

It should compare:

## Paths

Detect:

* removed endpoints
* added endpoints
* changed HTTP methods

Example:

```text
GET /users
```

exists in v1 but not v2.

This should normally be classified as a breaking change.

A newly added endpoint is not a breaking change.

---

# 10. Parameters

Detect changes such as:

### Required parameter added

Old:

```yaml
parameters:
  - name: page
    required: false
```

New:

```yaml
parameters:
  - name: page
    required: true
```

This is a breaking change.

### Parameter removed

Removing an existing parameter may be breaking depending on its location and semantics.

The comparison engine must document and test the classification rules.

### Parameter type changed

Example:

```text
string → integer
```

This should normally be considered breaking.

---

# 11. Request Bodies

Detect important request schema changes.

Examples:

* required property added
* property removed
* property type changed
* enum value removed
* required request body introduced
* request body media type removed

Example:

```text
v1:
name: optional

v2:
name: required
```

Breaking change.

---

# 12. Response Schemas

Detect changes that can break consumers.

Examples:

* response property removed
* property type changed
* incompatible schema change
* enum value removed
* response status removed
* response media type removed

Example:

```text
v1:
{
    "email": "user@example.com"
}
```

v2:

```text
{
    "name": "John"
}
```

Removing `email` should be reported as a breaking change.

---

# 13. Required vs Optional Properties

The comparison engine must understand the difference between:

```text
required
optional
```

Adding a new optional response property is normally backwards-compatible.

Adding a required request property is normally breaking.

The rules should be explicit and covered by tests.

---

# 14. Breaking Change Rules

Do not simply classify every difference as breaking.

The engine should distinguish:

```text
BREAKING
NON-BREAKING
INFO
```

Example:

```text
BREAKING:
- endpoint removed
- HTTP method removed
- required request parameter added
- required request property added
- response property removed
- incompatible type change
- enum value removed
- response status removed

NON-BREAKING:
- endpoint added
- optional request property added
- optional response property added
- endpoint description changed
- documentation changed
```

These are initial rules, not absolute API standards.

The implementation should be designed so rules can evolve.

---

# 15. Result Model

Do not make the comparison engine directly print CLI output.

It should return structured results.

For example:

```php
final readonly class Change
{
    public function __construct(
        public ChangeType $type,
        public Severity $severity,
        public string $path,
        public string $message,
    ) {}
}
```

The exact model can be improved during implementation.

The important architectural principle is:

```text
Specification
      ↓
Comparison Engine
      ↓
Structured Result
      ↓
CLI Formatter
```

The domain layer must not know about terminal output.

---

# 16. Output Formats

MVP:

```text
Human-readable terminal output
```

Future versions:

```text
--format=json
--format=github
--format=markdown
```

Potential future GitHub output:

```text
::error file=openapi.yaml::Breaking API change detected
```

Do not implement every format in the MVP unless it is trivial.

---

# 17. GitHub Actions

After the core CLI is stable, create an official GitHub Action.

Example future usage:

```yaml
name: API Compatibility

on:
  pull_request:

jobs:
  api-guard:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: API Guard
        uses: your-vendor/api-guard-action@v1
        with:
          old: openapi-old.yaml
          new: openapi.yaml
```

The GitHub Action is a later phase.

The core package must work independently of GitHub.

---

# 18. Laravel Integration

Do NOT build Laravel integration into the core package.

Later, create a separate package:

```text
your-vendor/laravel-api-guard
```

Potential command:

```bash
php artisan api:guard
```

This package should depend on the core API Guard package.

---

# 19. Symfony Integration

Later, create:

```text
your-vendor/symfony-api-guard
```

Potential command:

```bash
bin/console api:guard
```

Again, it should use the same core comparison engine.

---

# 20. Architecture

Prefer a clean architecture with clear boundaries.

Suggested conceptual layers:

```text
CLI
 │
 ▼
Application
 │
 ▼
Domain
 │
 ├── Specification
 ├── Comparison
 ├── Rules
 └── Results
 │
 ▼
Infrastructure
 │
 ├── OpenAPI parser
 ├── File system
 └── serialization
```

Do not over-engineer the MVP.

The architecture should be extensible without creating unnecessary abstractions.

---

# 21. Testing

Testing is extremely important because this package will be used in CI pipelines.

Use PHPUnit.

Every breaking-change rule must have tests.

Tests should cover:

### Path tests

* added path
* removed path

### Method tests

* added method
* removed method

### Parameter tests

* parameter added
* parameter removed
* optional → required
* required → optional
* type changed

### Request schema tests

* property added
* property removed
* property required
* property optional
* type changed
* enum changed

### Response schema tests

* property added
* property removed
* type changed
* enum changed
* response status removed

### Input tests

* YAML
* JSON
* invalid YAML
* invalid JSON
* missing file
* invalid OpenAPI document

---

# 22. Test Fixtures

Keep OpenAPI fixtures in:

```text
tests/Fixtures/
```

For example:

```text
tests/Fixtures/
├── basic/
│   ├── old.yaml
│   └── new.yaml
├── breaking/
│   ├── old.yaml
│   └── new.yaml
├── parameters/
├── request-bodies/
├── responses/
└── invalid/
```

Fixtures should be small and focused.

Do not create huge OpenAPI documents for simple tests.

---

# 23. Static Analysis

Use PHPStan.

Target a high strictness level.

The project should have:

```bash
composer test
composer analyse
```

Potentially:

```bash
composer lint
```

The exact Composer scripts can be decided during implementation.

---

# 24. CI

GitHub Actions should eventually run:

```text
PHP versions
    ↓
Install dependencies
    ↓
PHPUnit
    ↓
PHPStan
    ↓
Code style
```

At minimum test supported PHP versions.

---

# 25. Documentation

README.md must explain:

1. What API Guard is.
2. Why it exists.
3. Installation.
4. Basic usage.
5. Example output.
6. Breaking-change rules.
7. CI usage.
8. Supported PHP/OpenAPI versions.
9. Contributing.
10. License.

The README should be understandable to a developer who has never seen the project.

---

# 26. Commercial Strategy

The initial version can be open source.

Potential future Pro features:

* advanced comparison rules
* HTML reports
* GitHub PR annotations
* Slack notifications
* email notifications
* historical API comparisons
* API change dashboards
* team reports
* custom rule configuration
* ignored changes
* baseline files
* multiple API specifications
* API consumer tracking
* advanced CI integrations

Do not build these features before the core product is useful.

---

# 27. Product Philosophy

API Guard should be:

* simple
* fast
* predictable
* framework-independent
* developer-friendly
* CI-friendly
* well-tested
* easy to install
* easy to understand

A developer should be able to go from:

```bash
composer require your-vendor/api-guard
```

to:

```bash
vendor/bin/api-guard check --old=v1.yaml --new=v2.yaml
```

in a few minutes.

---

# 28. Important Development Rules for AI Coding Assistants

When implementing API Guard, the coding assistant must:

1. Inspect the existing repository before creating files.
2. Do not rewrite working code unnecessarily.
3. Do not add dependencies without justification.
4. Prefer established PHP standards.
5. Write tests together with features.
6. Keep the core framework-independent.
7. Avoid Laravel-specific assumptions.
8. Avoid Symfony-specific assumptions.
9. Keep public APIs stable.
10. Use strict typing.
11. Handle invalid input gracefully.
12. Never silently ignore comparison errors.
13. Do not classify changes as breaking without a documented rule.
14. Keep comparison rules independently testable.
15. Do not implement future Pro features during MVP development.
16. Do not create unnecessary abstractions.
17. Keep the CLI thin; business logic belongs outside the CLI.
18. Maintain backwards compatibility once the public API is released.

---

# 29. Development Phases

## Phase 1 — Foundation

* Composer package
* directory structure
* CLI executable
* PHPUnit
* PHPStan
* GitHub Actions
* basic README

## Phase 2 — OpenAPI Loading

* YAML support
* JSON support
* validation
* parser abstraction

## Phase 3 — Comparison Engine

Implement:

* paths
* methods
* parameters
* request bodies
* response schemas
* required/optional properties
* enums
* response statuses

## Phase 4 — CLI Reporting

Human-readable output.

Correct exit codes.

## Phase 5 — Real-world Tests

Create realistic OpenAPI examples and verify the rules.

## Phase 6 — GitHub Action

Create CI integration.

## Phase 7 — Framework Integrations

Laravel and Symfony packages.

## Phase 8 — Optional Pro Features

Only after validating that developers actually want the product.

---

# 30. First Milestone

The first milestone is NOT:

> "Build the complete API Guard product."

It is:

> "Given two valid OpenAPI 3.x files, reliably identify a useful set of backwards-incompatible changes and return exit code 1 when they are detected."

Everything else is secondary until this works extremely well.

# 31. First Copilot Task

When beginning implementation, ask the coding assistant:

> Read `docs/PROJECT_SPEC.md` completely. Inspect the current repository before making changes. We are building API Guard, a production-quality framework-independent PHP package for detecting breaking changes between OpenAPI specifications.
>
> Do not implement the entire project at once.
>
> First, propose the concrete architecture, Composer dependencies, directory structure, interfaces, domain objects, CLI approach, testing strategy, and implementation order based on this specification.
>
> Do not write implementation code yet. Explain any architectural decisions and identify anything in the specification that needs clarification before implementation.
