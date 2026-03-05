# Nette X-Ray

![Nette X-Ray](https://github.com/user-attachments/assets/8d3d9fb8-81a9-45a0-8a6b-96d9aafa03cb)


[![Downloads this Month](https://img.shields.io/packagist/dm/nette/xray.svg)](https://packagist.org/packages/nette/xray)
[![Tests](https://github.com/nette/xray/workflows/Tests/badge.svg?branch=master)](https://github.com/nette/xray/actions)
[![Latest Stable Version](https://poser.pugx.org/nette/xray/v/stable)](https://github.com/nette/xray/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/nette/xray/blob/master/license.md)


### See exactly how your project uses the Nette ecosystem

Ever wondered which Nette methods you call the most? Which Latte filters you actually use? Whether anyone still touches that old configuration key?

Nette X-Ray scans your codebase and gives you a detailed, visual breakdown of your Nette API usage - every method call, every template filter, every config pattern.

✅ deep PHP analysis powered by PHPStan's type system<br>
✅ Latte template analysis - tags, filters, n:attributes<br>
✅ NEON configuration analysis - services, extensions, settings<br>
✅ beautiful standalone HTML report you can explore<br>
✅ completely anonymized - no code, no names, just numbers<br>

 <!---->


How It Works
============

Nette X-Ray uses static analysis to understand your code precisely - not regex, not guessing. It resolves types through inheritance, detects declaring classes, and tracks argument patterns. The result is an accurate picture of how your project interacts with the Nette ecosystem.

**What gets analyzed:**

| PHP / PHPT | Latte templates | NEON config |
|---|---|---|
| method & function calls | tags & n:attributes | DI service patterns |
| arguments (positional, named) | filters & their arguments | extension registration |
| return value usage | functions & constants | section usage |
| property access | syntax variants | configuration keys |
| class inheritance & traits | dynamic elements | database keys |
| method overrides | self-closing tags | |
| constants & instantiation | | |
| callable references | | |

 <!---->


Installation
============

```shell
composer require --dev nette/xray
```

Requires PHP 8.2 or higher. Runs on PHPStan 2.1. Works with any project using Nette, Latte, Tracy, Dibi, or Texy.

 <!---->


Usage
=====

Point it at your source directories:

```shell
vendor/bin/xray app/ src/
```

That's it. Within moments you get:

1. **HTML report** (`xray-report.html`) - an interactive visual breakdown you can open in your browser
2. **JSON data** (`xray-report.json`) - structured data for further processing

```
Nette X-Ray
==========

Scanning PHP files ... 142/142 done
Scanning NEON files ... 4/4 done
Scanning Latte files ... 87/87 done

Reports saved to:
  xray-report.json
  xray-report.html
```


The HTML Report
---------------

The HTML report is a standalone file - no server needed, just open it in your browser. It gives you a complete picture:

- Which Nette APIs your project depends on most
- How you pass arguments - positionally or by name
- Which methods you override from Nette base classes
- Which Latte filters and tags dominate your templates
- How your DI configuration is structured

It's your project's X-Ray.


Configuration
-------------

Create a `nette-xray.neon` file in your project root to customize behavior:

```neon
# Default analysis paths (used when no CLI arguments given)
paths:
    - app
    - src

# Additional directories to exclude (vendor, temp, node_modules, .git excluded by default)
excludeDirs:
    - generated

# Auto-upload without asking (default: false)
upload: true
```

 <!---->


Help Shape the Future of Nette
===============================

When you run Nette X-Ray, you can optionally send the anonymized report to help guide Nette's development:

```
Share anonymized data with Nette? [y/N] y
Data sent. Thank you!
```

**What gets sent:** only aggregated numbers - how many times each API is called, which patterns are used. No file paths, no variable names, no business logic. Just statistics.

**Why it matters:** real usage data helps prioritize what to improve, what to keep, and what can safely evolve. It's far more reliable than surveys.


 <!---->


Privacy
=======

Nette X-Ray is designed with privacy as a core principle:

- **No code is collected** - only counts and patterns
- **No file paths** - you can't tell what the project does
- **No string values** - argument values are never captured
- **Completely optional** - data is only sent if you explicitly confirm
- **Open source** - you can read exactly what gets collected

The JSON report is saved locally first. You can inspect it before deciding to share.

 <!---->


[Support Me](https://github.com/sponsors/dg)
=============================================

Do you like Nette X-Ray? Are you looking forward to the new features?

[![Buy me a coffee](https://files.nette.org/icons/donation-3.svg)](https://github.com/sponsors/dg)

Thank you!
