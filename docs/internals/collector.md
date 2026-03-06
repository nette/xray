# NEON analysis, Collector & the anonymization contract

## NeonAnalyzer

Parses config with `Nette\Neon\Neon::decode` (parse errors counted) and classifies
rather than copies. Sections are bucketed by privacy policy:

- **`CountOnlySections`** (constants/decorator/extensions/includes/parameters/php) →
  only an item count.
- **`KeyUsageSections`** (application/assets/di/http/latte/mail/routing/security/
  session/tracy) → which keys are used, with each **value classified** to
  `true`/`false`/`array(n)`/`null`/`present` — **never the raw value**.
- **`database`** → keys only (multi-connection detected); **`services`** → key type
  (bullet/type/name), value type (class/reference/entity/array/false), and setup-item
  classification (methodCall/propertySet/referenceCall); **`search`** → entry count.

## Collector

Four `stdClass` buckets (`meta`/`php`/`latte`/`neon`) built from two primitive
counters (`inc`, `incIn`); the Collector object **is** the JSON report root. `meta`
records `projectId = md5(sorted paths)`, `userId = md5(hostname \0 username)`,
file/line/indentation histograms, the PHP version, and the tracked composer/npm
packages. Per call-site, `recordArgs` keeps arg-count histograms, named-argument
frequencies, a spread flag, and returnUsed/returnDiscarded — the granular "how is this
API actually called" data that is the whole point.

## The anonymization contract ("no code, no names, just numbers")

This is an invariant upheld cooperatively by every analyzer, not a scrubbing step:

- **PHP** — only records when the **declaring class is tracked** (a public Nette
  namespace), so user class/method/variable names never enter (see php-analyzer.md).
- **Latte** — unknown tags/filters/functions/n:attributes/constants are stored as
  `#<md5>`, so custom names are counted but never leaked.
- **NEON** — values are classified or dropped; only keys survive.
- **meta** — paths and identity become stable pseudonymous md5 IDs, never raw strings.

An edit that stored a raw user identifier anywhere would break this contract silently,
so treat "does this key come from a tracked Nette symbol or a hash?" as the rule when
adding any collected field.

## Report, upload, stars — and two live caveats

- **`HtmlReport`** renders `report.latte` with the Collector as data into a standalone
  HTML file. This path **is** live (`App` writes `xray-report.json` and
  `xray-report.html`).
- **`Uploader`** would POST the Collector JSON to `stats.nette.org` behind an
  interactive opt-in — but it is **currently commented out** at the call site
  (`App::run`, "TODO: re-enable after debugging"). The tool today only writes local
  files.
- **`GitHubStars`** would auto-star the GitHub repos of the tracked packages a project
  uses, reading a GitHub OAuth token from the user's `COMPOSER_AUTH`/Composer
  `auth.json`. It is **also commented out**. A dormant but notably aggressive behavior
  worth flagging before re-enabling.
