# PhpAnalyzer

`PhpAnalyzer` drives PHPStan's internals directly: it primes the parser routing
(`PathRoutingParser::setAnalysedFiles`), creates a `Scope` per file
(`ScopeFactory::create(ScopeContext::create($file))`), and calls
`NodeScopeResolver::processNodes($ast, $scope, $callback)` — PHPStan's own traversal,
which hands back a fully **resolved type `Scope`** at every node. (It deliberately
whitelists unstable PHPStan-internal API in `dev/phpstan.neon`.)

## The two-pass `spl_object_id` context map

A node cannot see its syntactic role from itself, so `preScanNodes` walks the whole
AST once and builds a map **`spl_object_id($node) => context`**: `Discarded` for the
expression of a `Stmt\Expression` (its return value is unused), and
`Write`/`ArrayPush`/`Reference` for property-assignment targets. Then `processNode`
reads `$nodeContext[spl_object_id($node)]` to decide `returnUsed` and the kind of
property access. This identity-keyed map is the trick that recovers parent context
without parent pointers — it is rebuilt per file and is only safe because the **same
node objects** flow through both passes.

## The core rule: track by *declaring* class

For every call/fetch, X-Ray resolves the **declaring class** through PHPStan
reflection and keeps it only if it `isTracked()` — the namespace starts with
`Nette\` / `Latte\` / `Tracy\` / `Dibi\` / `Texy\`. This is what separates "Nette API
usage" from user code even through inheritance:
`$myPresenter->redirect()` records `Nette\Application\UI\Presenter::redirect` (the
declaring class), **not** the user subclass, and a purely user method resolves to a
user declaring class and is dropped. Static calls have a fallback branch that records
an unresolved-but-tracked static call (facades). This declaring-class discrimination
is also what makes the whole dataset anonymous by construction (see collector.md).

Other emergent details:

- **Native vs virtual property** — `hasNativeProperty && isPublic` is a real property;
  otherwise it is magic/`@property` and routed to a separate "virtual property access"
  bucket. Only reflection distinguishes them.
- **Override tracking** — for a user method it walks the parent chain
  (`getParentClass()` loop) to detect a user method that overrides a tracked Nette
  method.
- **First-class callables** — it handles PHPStan's synthetic `MethodCallableNode` /
  `StaticMethodCallableNode` (for `$obj->m(...)` / `Class::m(...)`), which are
  PHPStan-specific virtual nodes, not native PhpParser nodes.
- **Global functions gated by flags** — Tester (`test`, `testException`, …) and Tracy
  (`dump`, `bdump`, …) globals are tracked **only when** `hasTester`/`hasTracy` (from
  the generated dynamic config); namespaced Nette functions go through `isTracked`.
- Parse failures are swallowed and counted, never fatal.
