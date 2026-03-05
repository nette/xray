<?php declare(strict_types=1);

namespace Nette\Xray;

use function sprintf;


/**
 * Displays per-phase progress on stderr (e.g. "Scanning PHP files ... 42/142").
 */
final class Progress
{
	private int $current = 0;
	private int $total;
	private string $label;


	public function begin(string $label, int $total): void
	{
		$this->label = $label;
		$this->total = $total;
		$this->current = 0;
	}


	public function advance(): void
	{
		$this->current++;
		$this->print();
	}


	public function end(): void
	{
		fwrite(STDERR, sprintf("\r%s ... %d/%d done\n", $this->label, $this->total, $this->total));
	}


	private function print(): void
	{
		fwrite(STDERR, sprintf("\r%s ... %d/%d", $this->label, $this->current, $this->total));
	}
}
