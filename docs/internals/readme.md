# Nette X-Ray internals

How X-Ray works underneath, for agents editing it. It is a static analyzer that
scans a project for **Nette-ecosystem API usage** (PHP calls, Latte tags/filters,
NEON keys) and produces an anonymized report. The clever, non-obvious part is that
it **borrows real engines** — PHPStan's type engine, Latte's compiler, Nette's NEON
parser — rather than writing its own. Split by seam:

- **[bootstrap-and-isolation.md](bootstrap-and-isolation.md)** — the most emergent
  piece: autoloader ordering, PHPStan-as-a-library, the generated config, and the
  php-scoper phar isolation.
- **[php-analyzer.md](php-analyzer.md)** — driving PHPStan's scope resolver and the
  declaring-class discrimination rule.
- **[latte-analyzer.md](latte-analyzer.md)** — the real-Latte + catch-all layering.
- **[collector.md](collector.md)** — NEON analysis, aggregation, and the
  anonymization contract (plus the currently-disabled upload/star behaviors).

> The actual product source is `dev/src/`. `compiler/vendor/` and `dist/` are the
> php-scoper build toolchain, not the product.
