# Package Distribution

This repository remains the development monorepo. Core is published from the repository root as `ahdev/api-guard`; the Laravel and Symfony adapter directories are split into standalone Git repositories when a GitHub Release is published. Pro remains in this monorepo and is not part of the adapter split workflow.

## Version strategy

No release tags currently exist. The adapter manifests require `ahdev/api-guard: ^1.0`, and their monorepo path aliases use `1.0.0`. Therefore the first compatible release is `v1.0.0`. The distribution workflow accepts stable `v1.x.y` tags and fails for other tag formats; it does not rewrite package constraints or invent versions.

Publish the Core `v1.0.0` tag to Packagist before, or at the same release as, the adapter tags so adapter dependency resolution can find a compatible Core package. Future compatible releases should use matching `v1.x.y` tags for Core and the adapters.

## Destination repositories

Create these empty GitHub repositories under the `ahdev` account before publishing:

- `ahdev/laravel-api-guard`
- `ahdev/symfony-api-guard`

Use `main` as each default branch and do not initialize the repositories with separate README/license commits; the split workflow supplies package history, manifests, tests, documentation, and MIT license files.

Register the root monorepo as `ahdev/api-guard` in Packagist, then register each standalone adapter repository as its corresponding Packagist package. Composer users can then install the Core, Laravel, and Symfony packages normally without custom repository configuration.

## Release workflow

The `Publish adapter packages` workflow runs only for a published GitHub Release. It checks the release tag, uses `git subtree split` on each adapter directory to preserve its package-only history, then atomically pushes the split commit to the standalone repository's `main` branch and adds the matching release tag.

Before the first release, configure the `PACKAGE_SPLIT_TOKEN` Actions secret in this monorepo. Use a fine-grained personal access token with **Contents: Read and write** permission scoped only to the two destination repositories. The source workflow itself has `contents: read`; it does not rely on the source repository's `GITHUB_TOKEN` to write to other repositories. The workflow never prints the secret and fails with a clear error if a target repository is missing or inaccessible.

Maintainer release steps:

1. Run the repository CI and choose the release commit.
2. Confirm Core `ahdev/api-guard` is or will be available on Packagist at the same `v1.x.y` version.
3. Create and publish a GitHub Release with a stable `v1.x.y` tag. The first compatible tag is `v1.0.0` unless the package constraints are deliberately changed in a separate versioning decision.
4. Confirm the split workflow pushed both package branches/tags, then confirm Packagist has indexed the standalone repositories.

Repository creation, Packagist registration, token creation, and release publication are manual; the workflow does not create repositories, publish directly to Packagist, or release Pro.