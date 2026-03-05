<?php declare(strict_types=1);

namespace Nette\Xray;

use Latte;


/**
 * Generates a standalone HTML report from collected statistics using Latte templates.
 */
final class HtmlReport
{
	public function generate(Collector $collector): string
	{
		$engine = new Latte\Engine;
		$engine->setTempDirectory(sys_get_temp_dir() . '/nette-xray');
		$engine->addFunction('formatReturn', self::formatReturn(...));
		$engine->addFunction('formatArgs', self::formatArgs(...));

		return $engine->renderToString(__DIR__ . '/report.latte', ['data' => $collector]);
	}


	private static function formatReturn(\stdClass $data): string
	{
		$used = $data->returnUsed ?? 0;
		$discarded = $data->returnDiscarded ?? 0;
		if ($used > 0 && $discarded > 0) {
			return "$used used / $discarded discarded";
		}
		return $discarded > 0 ? 'discarded' : 'used';
	}


	private static function formatArgs(\stdClass $data): string
	{
		$parts = [];
		foreach ($data->args->counts ?? [] as $cnt => $freq) {
			$parts[] = "$cnt ({$freq}x)";
		}

		$named = $data->args->named ?? [];
		if ($named !== []) {
			$namedParts = [];
			foreach ($named as $name => $freq) {
				$namedParts[] = "$name: $freq";
			}
			$parts[] = 'named: ' . implode(', ', $namedParts);
		}

		return implode(', ', $parts);
	}
}
