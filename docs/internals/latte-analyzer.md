# LatteAnalyzer

X-Ray analyzes templates with a **real `Latte\Engine`**, not a custom parser. Per
file it builds a fresh engine (`setAutoRefresh(false)`), registers the **real Nette
extensions** (Translator always; UI/Forms/Cache conditionally via `class_exists`),
`parse()`s the source into a real Node AST, and traverses it. Real extensions are
used deliberately: only they produce full-fidelity typed nodes (`ExtendsNode`,
`ForeachNode`, `IncludeBlockNode`, …) whose real properties the detail analysis reads.

## The catch-all layer (and its order dependence)

`LatteCatchAllExtension` is registered **last**. In its `beforeCompile` it:

1. enumerates every tag registered by **prior** extensions by iterating
   `engine->getExtensions()` and **breaking when it reaches itself** — so it is
   strictly order-dependent;
2. regex-scans the raw source for `{tag}` / `n:attr` and registers a handler **only
   for names not already registered**;
3. its parsers drain the whole tag token stream and emit a `LatteCatchAllNode` (which
   prints `''` and yields its content) so an **unknown tag does not break
   compilation**.

## Known-vs-unknown is the anonymization seam

A tag/filter/function/n:attribute whose name is in the hand-maintained `Known*` const
lists is kept **verbatim**; anything else becomes **`#<md5(name)>`** — a custom name
is counted but never leaked. (Filters/functions also have a `function_exists()` escape
hatch so PHP built-ins stay visible.)

Two traps to internalize:

- **Version drift.** The `KnownTags`/`KnownFilters`/… lists must track the *bundled*
  Latte/Nette version. A newer tag the analyzed project uses that isn't in the lists
  (and isn't handled by a bundled extension) is silently **hashed as unknown** —
  miscategorized, not lost.
- **It's X-Ray's Latte, not the project's.** Unlike PhpAnalyzer (project reflection),
  LatteAnalyzer runs X-Ray's own bundled Latte (php-scoper-prefixed in the phar). So
  Latte feature detection reflects X-Ray's shipped Latte version, independent of the
  project's.

## Source-offset extraction and the `\r\n` invariant

Because different syntaxes collapse to one node class (`{extends}` and `{layout}` both
→ `ExtendsNode`), the actual keyword is recovered from the **source text via the
node's `Position->offset`**. This only works if newlines were normalized `\r\n → \n`
first, to keep X-Ray's offsets aligned with Latte's internal positions — a subtle
non-local invariant. Template parse errors are counted per file, with **no regex
fallback**.
