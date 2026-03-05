<?php declare(strict_types=1);

namespace Nette\Xray;

use Latte\Compiler\{Node, PrintContext};
use Latte\Compiler\Nodes\StatementNode;


/**
 * AST node carrying tag name and raw arguments for statistics.
 * Content is the parsed body area (or null for void tags).
 */
final class LatteCatchAllNode extends StatementNode
{
	public ?Node $content = null;


	public function __construct(
		public string $tagName,
	) {
	}


	public function print(PrintContext $context): string
	{
		return '';
	}


	public function &getIterator(): \Generator
	{
		if ($this->content !== null) {
			yield $this->content;
		}
	}
}
