<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

exec('git rev-parse --short HEAD', $output, $exitCode);
if ($exitCode !== 0) {
	die('Could not get Git commit');
}

return [
	'prefix' => sprintf('_NetteXray_%s', $output[0]),
	'exclude-namespaces' => ['Nette\Xray', 'PHPStan', 'PhpParser', 'Composer'],
	'expose-global-functions' => false,
	'expose-global-classes' => false,
	'patchers' => [
		// Composer ClassLoader must not be prefixed
		fn(string $filePath, string $prefix, string $content): string => str_replace(
			sprintf('%s\Composer\Autoload\ClassLoader', $prefix),
			'Composer\Autoload\ClassLoader',
			$content,
		),

		// Latte TemplateGenerator hardcodes class references in generated PHP code strings.
		// The scoper can't transform string literals, so we patch them manually.
		function (string $filePath, string $prefix, string $content): string {
			if (!str_ends_with($filePath, 'TemplateGenerator.php')) {
				return $content;
			}
			// "use Latte\\Runtime as LR;" → "use <prefix>\\Latte\\Runtime as LR;"
			$content = str_replace(
				'Latte\\\Runtime as LR;',
				$prefix . '\\\Latte\\\Runtime as LR;',
				$content,
			);
			// "extends Latte\\Runtime\\Template" → "extends <prefix>\\Latte\\Runtime\\Template"
			$content = str_replace(
				'extends Latte\\\Runtime\\\Template',
				'extends ' . $prefix . '\\\Latte\\\Runtime\\\Template',
				$content,
			);
			return $content;
		},
	],
];
