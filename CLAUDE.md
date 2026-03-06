# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Nette X-Ray is a CLI tool for collecting anonymized usage statistics from real-world PHP projects that use the Nette ecosystem (Nette Framework, Latte, Tracy, Dibi, Texy). The tool is designed to be run once per project, producing a JSON report and an HTML visualization.

### Motivation

As the creator of these libraries, David Grudl needs real-world data about how they are actually used — which classes, methods, parameters, filters, and configuration patterns are common, and which are rarely used. This data guides framework development decisions far better than surveys or guesswork.

### What It Analyzes

**PHP/PHPT files** (via PHPStan's type-aware AST analysis):
- Method calls (static + instance) — including declaring class resolution through inheritance
- Function calls (global functions from Tester/Tracy, namespaced functions)
- Constructor calls (`new Nette\...`)
- Arguments: positional vs named, count, spread usage
- Return value usage (used vs discarded)
- Property access (read, write, array push, reference) — native and virtual (magic/extension) tracked separately
- Class constants
- Inheritance (extends), interface implementation (implements), trait usage
- Method overrides — which inherited Nette methods are overridden in user classes
- Callable references — first-class callable syntax (`$obj->method(...)`, `Class::method(...)`)
- Parse errors tracked per file

**Latte templates** (via real Nette Latte extensions + catch-all for unknown tags):
- Registers real extensions (TranslatorExtension, UIExtension, FormsExtension, CacheExtension) for full AST
- `LatteCatchAllExtension` only catches tags NOT handled by real extensions
- Tags and their frequency — tag name extracted from source via `Position->offset` (handles aliases like extends/layout)
- Per-tag detail analysis using real node properties: `BlockNode->block->layer` for local, `ExtendsNode->extends` type for none/auto/file, `ForeachNode->iterator`/`checkArgs`/`else`, `IfNode->capture`, `IncludeBlockNode`/`IncludeFileNode` (separate classes) for block/file split with `from`/`parent`/`mode`, `FirstLastSepNode->width`/`else`, `VarNode` type annotations, `DefineNode->block->parameters` for args
- Position-based `extractRawArgs()` for details needing source text (`#` prefix, `block` keyword in ifset)
- n:attributes with syntax variant detection (`n:foo="$bar"` vs `n:foo={$bar}` vs `n:foo=$bar`)
- Filters detected from AST via `FilterNode` traversal — with arg count tracking
- Function calls detected from AST via `FunctionCallNode` traversal (hasBlock, isLinkCurrent, asset, etc.); unknown functions are hashed unless `function_exists()` returns true
- Constants detected from AST via `ConstantFetchNode` traversal (excludes `true`/`false`/`null`)
- Dynamic elements (`<{$name}>`), attribute expressions (`<div {$attrs}>`)
- Self-closing tags (`<br />`)
- Line endings normalized (`\r\n` → `\n`) to match Latte's internal Position offsets
- Parse errors tracked (file counted, no fallback to regex)
- Unknown (non-standard) tags, filters, functions, and n:attributes are tracked with MD5-hashed names (`#<hash>`) for privacy; known items use plain text names

**NEON configuration files** (via Nette\Neon parser):
- Top-level section usage
- Count-only sections: `constants`, `decorator`, `extensions`, `includes`, `parameters`, `php`
- Key usage sections (which keys are used + value classification): `application`, `assets`, `di`, `http`, `latte`, `mail`, `routing`, `security`, `session`, `tracy`
- Database configuration keys (normalized, multi-connection detection)
- DI service definition patterns: key types (bullet/type/name), value types (class/reference/entity/array/false), array keys (create/factory/type/arguments/setup/autowired/tags/implement/inject/etc.), setup types (methodCall/propertySet/referenceCall)
- Search section: entry count
- Parse errors tracked

### Output

- **JSON report** (`xray-report.json`) — structured, anonymized data for cross-project aggregation
- **HTML report** (`xray-report.html`) — standalone interactive visualization for the user
- **Console progress** — per-phase progress bars (PHP, NEON, Latte)
- **Server upload** — optional interactive opt-in to send data to stats.nette.org (currently disabled, TODO)
- **GitHub stars** — optional `symfony/thanks`-style starring of actually used repos (currently disabled, TODO)

### Statistical Design

Each project generates one JSON. Cross-project aggregation happens externally. The JSON includes project metrics (file counts, line counts, Nette package versions, npm package versions, indentation style) to enable normalization — so a large project doesn't disproportionately skew results.

### Tracked Namespaces

`Nette\*`, `Latte\*`, `Tracy\*`, `Dibi\*`, `Texy\*`

Tracked Composer vendor prefixes: `nette`, `latte`, `tracy`, `dibi`, `texy`, `dg`.

Global functions: `test()`, `testException()`, `testNoError()`, `setUp()`, `tearDown()` (only if nette/tester present), `dump()`, `dumpe()`, `bdump()` (only if tracy/tracy present).

npm packages: `@nette/*` scoped packages + `nette-forms` (from `package.json` dependencies/devDependencies).

## Repository Structure

This repo has a split structure: **root is the distribution package** (what Packagist users install), **`dev/` contains source code and development tools**, **`compiler/` contains PHAR build tooling**.

```
/                          # Distribution (Packagist)
  composer.json            # DIST: requires phpstan/phpstan only
  bin/xray                 # DIST: thin wrapper loading xray.phar + phpstan.phar
  xray.phar                # Compiled PHAR with scoped Nette/Latte deps

  dev/                     # Source + development
    composer.json          # DEV: all deps (nette/*, latte/*, phpstan/*)
    src/                   # Source PHP files
    config.neon            # PHPStan DI config
    bin/xray               # DEV entry point
    tests/
    phpstan.neon

  compiler/                # PHAR build tooling
    composer.json          # humbug/box + nette/neon
    box.json               # Box configuration
    scoper.inc.php         # PHP-Scoper namespace prefixing rules
    build.sh               # Build script
```

### PHAR Boxing

Dependencies inside `xray.phar` are namespace-scoped with prefix `_NetteXray_<git-hash>` using humbug/box + php-scoper. This prevents version conflicts with the target project's own Nette/Latte packages. PHPStan is NOT inside the PHAR — it's a regular Composer dependency loaded from its own PHAR at runtime.

## Essential Commands

All development commands run from `dev/`:

```bash
# Install dependencies
cd dev && C:/PHP/versions/php-8.2.30/php.exe C:/PHP/composer.phar install

# Run the xray tool on a directory or file (development mode)
cd dev && C:/PHP/versions/php-8.2.30/php.exe bin/xray path/to/analyze

# Run on test fixtures
cd dev && C:/PHP/versions/php-8.2.30/php.exe bin/xray tests/fixtures/

# PHPStan analysis (self-analysis)
cd dev && C:/PHP/versions/php-8.2.30/php.exe C:/PHP/composer.phar phpstan

# Tests
cd dev && C:/PHP/versions/php-8.2.30/php.exe C:/PHP/composer.phar tester

# Build PHAR
bash compiler/build.sh
```

## Architecture

### Entry Points & Bootstrap

**bin/xray (dist)** — Loads PHPStan PHAR, target project autoloader, xray.phar, then delegates to `Bootstrap::run()` with `'phar://xray.phar/config.neon'`.

**dev/bin/xray** — Development entry point managing three autoloader levels (PHPStan phar, project, dev vendor), then delegates to `Bootstrap::run()` with `__DIR__.'/../config.neon'`.

**Nette\Xray\Bootstrap** — Pre-container orchestrator (static `run()` method). Resolves CLI paths, loads user Config, detects hasTester/hasTracy from composer.json, generates dynamic PHPStan DI config (with package flags + CWD for App), creates PHPStan DI container via `ContainerFactory`, then calls `App::run()`.

### Core Classes

- **Nette\Xray\App** — Orchestrator (DI service): receives `$cwd`, analyzers, and HtmlReport via constructor injection. Discovers files, routes to analyzers by extension, collects results, outputs JSON + HTML. Collects project metadata (file/line counts, indentation style, Composer/npm package versions). Upload and GitHub stars are currently disabled (TODO).
- **Nette\Xray\PhpAnalyzer** — PHP/PHPT analysis using PHPStan (Parser, ScopeFactory, NodeScopeResolver)
- **Nette\Xray\LatteAnalyzer** — Latte template analysis: registers real Nette extensions (UIExtension, FormsExtension, CacheExtension, TranslatorExtension) for full AST, uses catch-all only for unknown tags. Tag counting via `Position->offset` extraction. Detail analysis dispatches on real node classes (BlockNode, ForeachNode, IfNode, etc.). Filter/function detection via AST traversal (FilterNode, FunctionCallNode). Has `KnownTags`, `KnownFilters`, `KnownFunctions`, `KnownNAttributes` constants.
- **Nette\Xray\LatteCatchAllExtension** — Latte extension that catches only tags NOT registered by real extensions; pre-scans source to classify paired vs void tags; registers unknown n:attributes as `n:foo`
- **Nette\Xray\LatteCatchAllNode** — AST wrapper node carrying `tagName` and optional `content` (for paired catch-all tags)
- **Nette\Xray\NeonAnalyzer** — NEON configuration analysis using Nette\Neon
- **Nette\Xray\Collector** — Central data structure with typed `add*()` methods for all three analyzers: PHP (`addMethodCall`, `addStaticCall`, `addFunctionCall`, `addInstantiation`, `addPropertyAccess`, `addVirtualPropertyAccess`, `addConstantAccess`, `addInheritance`, `addOverride`, `addCallableReference`, `addPhpParseError`), Latte (`addTag`, `addLatteTagDetail`, `addFilter`, `addLatteFunction`, `addLatteConstant`, `addNAttribute`, `addSelfClosingTag`, `addDynamicElement`, `addAttributeExpression`, `addLatteParseError`), NEON (`addNeonSection`, `addNeonItemCount`, `addNeonKeyUsage`, `addNeonDatabaseKey`, `addNeonSearchCount`, `addNeonService`, `addNeonServiceValue`, `addNeonServiceArrayKey`, `addNeonServiceSetup`, `addNeonParseError`). PascalCase constants for data keys.
- **Nette\Xray\Config** — Loads user configuration from `nette-xray.neon` (paths, excludeDirs, upload)
- **Nette\Xray\HtmlReport** — Renders HTML report using Latte template (`dev/src/report.latte`). Registers `formatReturn`/`formatArgs` custom functions for call tables.
- **Nette\Xray\Uploader** — Server upload with interactive opt-in
- **Nette\Xray\Progress** — Console progress indicator (per-phase: "Scanning PHP files ... 42/142")
- **Nette\Xray\GitHubStars** — GitHub starring of used Nette repos (composer via `RepoMap`, npm `@nette/*` via `NpmRepoMap`); always stars `nette/nette`

### PHPStan Integration

The tool uses PHPStan's type system for precise analysis:
1. Parse PHP files to AST via `Parser::parseFile()`
2. Create scope context via `ScopeFactory::create(ScopeContext::create($file))`
3. Traverse nodes with `NodeScopeResolver::processNodes()` callback
4. Resolve types via `Scope::getType()`, declaring classes via `Scope::getMethodReflection()`
5. This correctly handles inheritance — calling a Nette method on a subclass is detected

### PHPStan API Notes

We use PHPStan 2.1.x. Key API patterns:

- Use `$type->getObjectClassNames()` (not `TypeUtils::getDirectClassNames()`)
- Inject `ReflectionProvider` via constructor (not `Scope::getReflectionProvider()`)
- Use `@defaultAnalysisParser` from PHPStan's own DI instead of creating custom parser chain
- **Parameter validation is strict** — custom parameters under `parameters:` are rejected. Pass custom values via dynamic service definitions in a generated config file instead.

### PHPStan for Self-Analysis

`nette/phpstan-rules` (dev dependency) is activated via `phpstan/extension-installer` for analyzing X-Ray's own code (`cd dev && composer phpstan`). This does NOT affect PhpAnalyzer's container — `bin/xray` passes only the target project's `$composerAutoloaderProjectPaths` to `ContainerFactory::create()`, so extension-installer won't discover X-Ray's dev extensions.

### Configuration

**dev/config.neon** — Internal PHPStan DI config (not user-facing). Minimal: only service definitions for our classes. No custom parameters (PHPStan validates strictly).

**Dynamic config** — Generated at runtime in temp dir by `Bootstrap`. Contains service argument overrides: `hasTester`/`hasTracy` for PhpAnalyzer (detected from composer.json), `cwd` for App.

**nette-xray.neon** — User configuration: `paths` (default analysis paths when no CLI args), `excludeDirs` (additional excluded directories), `upload` (auto-upload without asking). File extensions are hardcoded in `App::DefaultExtensions`.

### Autoloader & PHP Version Considerations

- The tool itself runs on PHP 8.2+ (PHPStan 2.1.x requirement)
- Target projects may require newer PHP — their `vendor/autoload.php` may fail with platform_check.php. Need to handle this gracefully.
- `bin/xray` supports both absolute and relative paths (important on Windows)

## Testing

Always use PHP 8.2 and Composer via these paths (forward slashes!):

```bash
# PHP 8.2 (run from dev/)
cd dev && C:/PHP/versions/php-8.2.30/php.exe bin/xray tests/fixtures/

# Composer (run from dev/)
cd dev && C:/PHP/versions/php-8.2.30/php.exe C:/PHP/composer.phar install
cd dev && C:/PHP/versions/php-8.2.30/php.exe C:/PHP/composer.phar update
```

Test fixture files are in `dev/tests/fixtures/`.

## Coding Standards

- `declare(strict_types=1)` in all PHP files
- PSR-4 autoloading with `Nette\Xray\` namespace mapped to `dev/src/`
- Tabs for indentation
- PHP 8.2+ required
- PHPStan 2.1.x dependency
- Use `str_starts_with()` (available since PHP 8.0)
