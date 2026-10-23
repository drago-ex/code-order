# Drago Code Order

English | [Čeština](readme.cs.md)


Checks and fixes ordering conventions in Nette application code. The package contains two focused commands: one for fluent Nette Form control methods and one for PHP class members, including Nette Presenter lifecycle methods.

Both commands preserve method bodies and formatting. They move only supported declarations or calls and keep the original order for items with the same priority. Ambiguous code is left unchanged. Before a file is written, the result is checked to be valid PHP with the same tokens in a different order.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/drago-ex/code-order/blob/main/license)
[![PHP version](https://badge.fury.io/ph/drago-ex%2Fcode-order.svg)](https://badge.fury.io/ph/drago-ex%2Fcode-order)
[![Tests](https://github.com/drago-ex/code-order/actions/workflows/tests.yml/badge.svg)](https://github.com/drago-ex/code-order/actions/workflows/tests.yml)
[![Coding Style](https://github.com/drago-ex/code-order/actions/workflows/coding-style.yml/badge.svg)](https://github.com/drago-ex/code-order/actions/workflows/coding-style.yml)

## Requirements

- PHP >= 8.3, < 9
- Composer 2

## Installation

Install globally to use both commands from any project, like other Composer CLI tools:

```bash
composer global require drago-ex/code-order
```

Composer makes the `form-order` and `class-order` commands available in its global `bin` directory. If that directory is not already in `PATH`, find it with `composer global config bin-dir --absolute` and add it to `PATH`. Alternatively, install Code Order as a development dependency in a single project with `composer require --dev drago-ex/code-order`.

## Form control method order

Check PHP files without changing them. `-d` selects the file or directory; without it, the current directory is scanned:

```bash
form-order -d app
```

Apply the configured ordering:

```bash
form-order -d app --fix
```

The `-f` option is a short form of `--fix`. From the global Composer `bin` directory on Windows, the command can also be run directly with PHP:

```powershell
php .\form-order -d C:\path\to\project\app -f
```

The form command reads `form-order.neon` from the current working directory when present. Pass another file with `--config`:

```bash
vendor/bin/form-order --config form-order.neon app
vendor/bin/form-order --config=form-order.neon --fix app
```

Only standard Nette Form control factory methods and methods are enabled by default. Project-specific controls and methods can be added in `form-order.neon`:

```neon
form-order:
	custom:
		controls:
			- addDateRange
		methods:
			setAutocomplete: presentation
			setPlaceholder: presentation
			setReadOnly: presentation
```

The available method groups are `presentation`, `choices`, `state`, `defaults`, `input`, `required`, and `validation`. An integer can be used instead of a group name when a custom method needs a specific priority; lower numbers run earlier. Calls with equal priority keep their source order.

The ordering rule does not add or remove control methods or validation rules. If a chain contains an unsupported method, the tool leaves that chain unchanged. Calls after `addCondition()` or `addConditionOn()` are never moved, and `addFilter()` shares the priority of `addRule()` because filters run in sequence with the rules. It is syntax-based and does not verify that a method named `addText()` or another configured control factory actually belongs to a Nette form. An example configuration for Drago Form is available in `examples/drago-project.neon`.

## Class member and Presenter method order

Check PHP files:

```bash
class-order -d app
```

Apply the configured ordering:

```bash
class-order -d app --fix
```

The general rule places constants before properties, groups properties by visibility (`public`, `protected`, `private`), puts the constructor before other methods, then groups remaining methods by visibility. Equal-priority members retain their relative order. A class is left unchanged when a comment shares a line with a member, the class brace or a `use` statement, or when moving a constructor with promoted public properties would change the declaration order of public properties (visible in `json_encode()`).

For classes that extend `Nette\Application\UI\Presenter` directly or through a base class whose name ends in `Presenter`, recognized methods follow the lifecycle order after the constructor: `inject*()`, `startup()`, `action*()`, `handle*()`, `beforeRender()`, `render*()`, `afterRender()`, and `shutdown()`. This is a source-order convention based on runtime behavior; Nette does not require it.

## Exit codes

Both commands exit with code `1` when the check finds code to reorder, `0` when no changes are needed (or `--fix` succeeds), and `2` for parse, read, write, configuration, or path errors.

In interactive terminals, `NEEDS FIX` is yellow, successful fixes and clean results are green, and errors are red. Colors are omitted when output is redirected, `NO_COLOR` is set, or the terminal reports `TERM=dumb`.

Directories named `.git`, `vendor`, `node_modules`, `temp`, and `cache` are skipped during recursive scans. A directly named PHP file is processed regardless of its parent directory.
