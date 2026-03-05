<?php declare(strict_types=1);

namespace Nette\Xray;

use Latte;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\Tag;
use function array_flip, array_unique, preg_match_all;


/**
 * Latte extension that catches unknown tags for statistics.
 * Real Nette extensions handle known tags with full AST.
 */
final class LatteCatchAllExtension extends Latte\Extension
{
	/** @var array<string, callable(Tag): (StatementNode|\Generator)> */
	private array $tags = [];


	public function __construct(
		private readonly string $source,
	) {
	}


	public function beforeCompile(Latte\Engine $engine): void
	{
		// Collect tag names from prior extensions (including n: prefixed)
		$registeredTags = [];
		foreach ($engine->getExtensions() as $ext) {
			if ($ext === $this) {
				break;
			}

			foreach ($ext->getTags() as $name => $handler) {
				$registeredTags[$name] = true;
			}
		}

		// Scan source for {tags}
		preg_match_all('/\{(\w+)\b/', $this->source, $tagMatches);
		preg_match_all('/\{\/(\w+)\b/', $this->source, $closingMatches);
		$closingTags = array_flip($closingMatches[1]);

		// Regular tags: catch-all only for unregistered ones
		foreach (array_unique($tagMatches[1]) as $name) {
			if (isset($registeredTags[$name])) {
				continue;
			}

			$this->tags[$name] = isset($closingTags[$name])
				? self::parsePaired(...)
				: self::parseVoid(...);
		}

		// n:attributes: register directly as n:foo (void — HTML parser handles pairing)
		preg_match_all('/\bn:(?:inner-|tag-)?([\w?-]+)/', $this->source, $nAttrMatches);
		foreach (array_unique($nAttrMatches[1]) as $name) {
			$fullName = 'n:' . $name;
			if (!isset($registeredTags[$name]) && !isset($registeredTags[$fullName])) {
				$this->tags[$fullName] = self::parseVoid(...);
			}
		}
	}


	public function getTags(): array
	{
		return $this->tags;
	}


	private static function parsePaired(Tag $tag): \Generator
	{
		$node = self::parseVoid($tag);
		[$node->content] = yield;
		return $node;
	}


	private static function parseVoid(Tag $tag): LatteCatchAllNode
	{
		while ($tag->parser->stream->tryConsume());
		return new LatteCatchAllNode($tag->name);
	}
}
