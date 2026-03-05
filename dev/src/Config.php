<?php declare(strict_types=1);

namespace Nette\Xray;

use Nette\Neon\Neon;
use function array_merge, is_array, is_file;


/**
 * User configuration loaded from nette-xray.neon.
 */
final readonly class Config
{
	private const FileName = 'nette-xray.neon';


	/**
	 * @param string[] $paths default analysis paths
	 * @param string[] $excludeDirs extra directories to exclude
	 */
	public function __construct(
		public array $paths = [],
		public array $excludeDirs = [],
	) {
	}


	/**
	 * Loads config from nette-xray.neon in the given directory.
	 */
	public static function load(string $directory): self
	{
		$file = $directory . '/' . self::FileName;
		if (!is_file($file)) {
			return new self;
		}

		$content = file_get_contents($file);
		if ($content === false) {
			return new self;
		}

		try {
			$data = Neon::decode($content);
		} catch (\Throwable) {
			echo 'Warning: could not parse ' . self::FileName . "\n";
			return new self;
		}

		if (!is_array($data)) {
			return new self;
		}

		return new self(
			paths: (array) ($data['paths'] ?? []),
			excludeDirs: (array) ($data['excludeDirs'] ?? []),
		);
	}


	/**
	 * Returns excluded directories merged with defaults.
	 * @param string[] $defaults
	 * @return string[]
	 */
	public function getExcludeDirs(array $defaults): array
	{
		return $this->excludeDirs !== []
			? array_merge($defaults, $this->excludeDirs)
			: $defaults;
	}
}
