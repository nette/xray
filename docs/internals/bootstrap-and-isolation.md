# Bootstrap & phar isolation

This is the part you cannot reconstruct from the runtime PHP alone — the coupling
lives in autoloader ordering and `.neon` wiring, not in method calls.

## X-Ray drives PHPStan as a library, not as a plugin

X-Ray does **not** register as a PHPStan rule/collector/extension. It builds a real
PHPStan container (`Bootstrap` → `new ContainerFactory($cwd)` →
`create($tempDir, [$dynamicConfig, config.neon], $paths, …)`) and **registers its own
services inside it** via `dev/config.neon`. The analyzers then autowire PHPStan's own
internal services straight out of that container — `ScopeFactory`, `NodeScopeResolver`,
`ReflectionProvider`, `PathRoutingParser` — and X-Ray calls them directly. So PHPStan
is used as a **type-aware AST + reflection engine**, and `App` is simply pulled from
the container and run.

## Autoloader ordering is a load-bearing invariant

`bin/xray` (the real entry, `dev/bin/xray`) does a precise dance:

1. load PHPStan **from its own phar** and immediately `unregister()` its autoloader;
2. when the cwd is a *different* project, require **that project's `vendor/autoload.php`
   first (higher priority)**, then X-Ray's own autoloader **second, scoped to only
   `Nette\Xray\*`**;
3. re-register PHPStan's autoloader with `register(true)` (prepend).

The consequence is the whole point: when PHPStan reflects a class, it resolves to the
**analyzed project's** Nette classes, so the recorded API data is accurate to the
project's actual Nette version. Reorder these and you either analyze the wrong Nette
or fail to load the project's code.

## The generated dynamic config carries the feature flags

`Bootstrap::generateDynamicConfig` writes a runtime `.neon` into the temp dir that
sniffs the project's `composer.json` for `nette/tester` and `tracy/tracy` and injects
`hasTester`/`hasTracy` (and `cwd`) as constructor args of the analyzers. This is how
the two package-conditional feature toggles reach the DI-constructed `PhpAnalyzer`
(which uses them to decide whether `test()`/`dump()` globals are "tracked") — a
generated, non-local file, not a code path.

## php-scoper isolation (why X-Ray's Nette can't clash with yours)

The distributed phar is built with php-scoper under a prefix `_NetteXray_<git-sha>`,
with **`exclude-namespaces: [Nette\Xray, PHPStan, PhpParser, Composer]`**. The
asymmetry is the key fact:

- **PhpParser and PHPStan stay un-prefixed** — they are the AST vocabulary and engine
  shared with the *external* PHPStan phar (`build.sh` even `rm -rf`s phpstan from the
  bundled vendor, "loaded externally").
- **Nette and Latte ARE prefixed** — X-Ray's bundled Nette/Latte become
  `_NetteXray_<sha>\Nette\…`, so when X-Ray instantiates its own Latte engine to
  analyze a template (see latte-analyzer.md) it cannot collide with the project's
  Nette during the same process.

Two patchers finish the job: keep `Composer\Autoload\ClassLoader` un-prefixed, and
manually rewrite **string-literal** class references inside Latte's
`TemplateGenerator.php` (generated-code strings php-scoper cannot see). This split —
project reflection for PHP, X-Ray's own bundled Latte for templates — is exactly why
PHP feature detection is version-accurate but Latte feature detection reflects
*X-Ray's* shipped Latte.
