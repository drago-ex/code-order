# Agent Guide

Use this file as package-specific guidance when working in this repository. Keep changes aligned with the existing public API, project conventions, and supported PHP range.

## Package Overview
- **Package**: `drago-ex/code-order`
- **Type**: library
- **Purpose**: Checks and fixes the order of Nette form controls and PHP class members.
- **PHP**: >=8.3 <9
- **Runtime dependencies**: composer-runtime-api (^2.2), nikic/php-parser (^5.3), nette/neon (^3.4)
- **License**: MIT

## Repository Layout
- `bin/`: command-line entry points.
- `examples/`: usage examples.
- `src/`: package source code.
- `tests/`: automated tests.
- `.github/`: CI workflows; keep their PHP versions and commands consistent with Composer and the package scripts.
- `composer.json`: package metadata, dependency constraints, autoloading, and available Composer scripts.

## Key Parts
- `bin/form-order` and `bin/class-order` share the CLI structure; exit codes are 0 (clean or fixed), 1 (needs fix), 2 (error).
- `FormOrder\FormOrderer` reorders fluent form-control chains; `FormOrderConfiguration` validates `form-order.neon`.
- `ClassOrder\ClassMemberOrderer` reorders class members; the Presenter lifecycle order applies to classes whose parent name ends with `Presenter`.
- `CodeOrder\ReorderGuard` must stay the last step: a result is written only when it parses and has the same tokens. Ambiguous code (comments on a member line, promoted public properties) is left unchanged on purpose.

## Dependencies

Composer constraints describe the package contract. Before changing integration behavior, inspect the source and the actual call sites in this package; do not infer runtime usage solely from dependency declarations.
Development dependencies include nette/tester (^2.5), phpstan/phpstan-nette (^2.0).

## Essential Commands

```bash
composer tester                 # tester tests -s
composer phpstan                # phpstan analyse
```

## Conventions & Rules

- Preserve backward compatibility unless the task explicitly permits a breaking change. Check callers and public signatures before changing behavior.
- Follow the existing PHP style: strict types, explicit parameter and return types, tabs for indentation, and single-quoted strings where practical. Match nearby code when a convention is unclear.
- Keep package-specific configuration, resources, and migrations in their established directories. Do not edit generated or consuming-project files without confirming they are part of this repository’s intended output.
- Add or update focused tests for behavioral changes when this package has a runnable test setup. Do not invent test commands; use only the scripts and configuration present here.
- Update README examples and Composer metadata when a public API or supported requirement changes.
