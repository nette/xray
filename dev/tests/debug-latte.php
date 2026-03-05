<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$content = file_get_contents(__DIR__ . '/fixtures/template.latte');

$engine = new Latte\Engine;
$engine->setAutoRefresh(false);

// Show existing tags
echo "Existing tags:\n";
$existingTags = [];
foreach ($engine->getExtensions() as $ext) {
	foreach (array_keys($ext->getTags()) as $name) {
		$existingTags[$name] = true;
	}
}
echo '  ' . implode(', ', array_keys($existingTags)) . "\n\n";

// Register catch-all
$engine->addExtension(new Nette\Xray\LatteCatchAllExtension($content));

try {
	$node = $engine->parse($content);
	echo 'Parse OK: ' . $node::class . "\n";

	// Traverse and print node types
	$traverse = function (Latte\Compiler\Node $node, int $depth = 0) use (&$traverse) {
		$indent = str_repeat('  ', $depth);
		$class = substr($node::class, strrpos($node::class, '\\') + 1);
		$extra = '';
		if ($node instanceof Nette\Xray\LatteCatchAllNode) {
			$extra = " [{$node->tagName}]";
		}
		echo "{$indent}{$class}{$extra}\n";
		foreach ($node->getIterator() as $child) {
			if ($child instanceof Latte\Compiler\Node) {
				$traverse($child, $depth + 1);
			}
		}
	};
	$traverse($node);
} catch (Throwable $e) {
	echo 'Parse FAIL: ' . $e->getMessage() . "\n";
	echo $e->getFile() . ':' . $e->getLine() . "\n";
}
