<?php declare(strict_types=1);

namespace Nette\Xray;

use function count, in_array, is_array;
use const PHP_MAJOR_VERSION, PHP_MINOR_VERSION;


/**
 * Orchestrates file discovery, analysis, and report generation.
 */
final class App
{
	/** Default excluded directories */
	private const ExcludedDirs = ['vendor', 'temp', 'node_modules', '.git'];

	private const DefaultExtensions = [
		'php' => ['php', 'phpt'],
		'latte' => ['latte'],
		'neon' => ['neon'],
	];

	/** Tracked Composer vendor prefixes */
	private const TrackedVendors = ['nette', 'latte', 'tracy', 'dibi', 'texy', 'dg'];

	/** Tracked npm package prefixes */
	private const TrackedNpmPrefixes = ['@nette/', 'nette-forms'];


	public function __construct(
		private readonly string $cwd,
		private readonly PhpAnalyzer $phpAnalyzer,
		private readonly NeonAnalyzer $neonAnalyzer,
		private readonly LatteAnalyzer $latteAnalyzer,
		private readonly HtmlReport $htmlReport,
	) {
	}


	/** @param string[] $paths */
	public function run(array $paths, Config $config = new Config): int
	{
		fwrite(STDERR, <<<'XX'
			__  _______   ____ __  __
			\ \/ /| () ) / () \\ \/ /
			/_/\_\|_|\_\/__/\__\|__|   v1.0


			XX);

		$collector = new Collector;
		$progress = new Progress;

		// Discover files grouped by type
		$excludeDirs = $config->getExcludeDirs(self::ExcludedDirs);
		$fileGroups = $this->discoverFiles($paths, $excludeDirs);

		// Collect project metrics
		$this->collectMeta($fileGroups, $collector, $paths);

		// PHP analysis
		$phpFiles = array_merge($fileGroups['php'] ?? [], $fileGroups['phpt'] ?? []);
		if ($phpFiles !== []) {
			$progress->begin('Scanning PHP files', count($phpFiles));
			$this->phpAnalyzer->analyze($phpFiles, $collector, $progress->advance(...));
			$progress->end();
		}

		// NEON analysis
		$neonFiles = $fileGroups['neon'] ?? [];
		if ($neonFiles !== []) {
			$progress->begin('Scanning NEON files', count($neonFiles));
			$this->neonAnalyzer->analyze($neonFiles, $collector, $progress->advance(...));
			$progress->end();
		}

		// Latte analysis
		$latteFiles = $fileGroups['latte'] ?? [];
		if ($latteFiles !== []) {
			$progress->begin('Scanning Latte files', count($latteFiles));
			$this->latteAnalyzer->analyze($latteFiles, $collector, $progress->advance(...));
			$progress->end();
		}

		fwrite(STDERR, "\n");

		// Output JSON
		$json = json_encode($collector, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents($this->cwd . '/xray-report.json', $json);

		// Output HTML
		file_put_contents($this->cwd . '/xray-report.html', $this->htmlReport->generate($collector));

		fwrite(STDOUT, "Reports saved to:\n");
		fwrite(STDOUT, "  xray-report.json\n");
		fwrite(STDOUT, "  xray-report.html\n\n");

		return 0;
	}


	/**
	 * Discovers files in given paths, grouped by extension.
	 * @param string[] $paths
	 * @param string[] $excludeDirs
	 * @return array<string, string[]> extension => file paths
	 */
	private function discoverFiles(array $paths, array $excludeDirs): array
	{
		$allExtensions = array_merge(...array_values(self::DefaultExtensions));

		$pattern = '/\.(' . implode('|', $allExtensions) . ')$/i';
		$groups = [];

		foreach ($paths as $path) {
			if (is_file($path)) {
				$ext = pathinfo($path, PATHINFO_EXTENSION);
				$groups[$ext][] = $path;
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
					function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $iterator) use ($excludeDirs): bool {
						if ($iterator->hasChildren()) {
							return !in_array($file->getFilename(), $excludeDirs, true);
						}

						return true;
					},
				),
			);

			foreach ($iterator as $file) {
				if (preg_match($pattern, $file->getPathname())) {
					$ext = pathinfo($file->getPathname(), PATHINFO_EXTENSION);
					$groups[$ext][] = $file->getPathname();
				}
			}
		}

		return $groups;
	}


	/**
	 * @param array<string, string[]> $fileGroups
	 * @param string[] $paths
	 */
	private function collectMeta(array $fileGroups, Collector $collector, array $paths): void
	{
		$files = [];
		$lines = [];
		$indentation = [Collector::Tabs => 0, Collector::Spaces => 0, Collector::Mixed => 0];

		foreach ($fileGroups as $ext => $extFiles) {
			$files[$ext] = count($extFiles);
			$extLines = 0;
			foreach ($extFiles as $file) {
				$content = file_get_contents($file);
				if ($content === false) {
					continue;
				}

				$extLines += substr_count($content, "\n") + 1;

				// Detect indentation
				$hasTabs = (bool) preg_match('/^\t/m', $content);
				$hasSpaces = (bool) preg_match('/^ {2,}/m', $content);
				match (true) {
					$hasTabs && $hasSpaces => $indentation[Collector::Mixed]++,
					$hasTabs => $indentation[Collector::Tabs]++,
					$hasSpaces => $indentation[Collector::Spaces]++,
					default => null,
				};
			}

			$lines[$ext] = $extLines;
		}

		// Read tracked package versions from composer.lock and package.json
		$composerPackages = $this->readComposerPackages();
		$npmPackages = $this->readNpmPackages();

		$collector->meta = (object) [
			'version' => '1.0',
			'generatedAt' => date('c'),
			'files' => $files,
			'lines' => $lines,
			'indentation' => $indentation,
			'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'composerPackages' => $composerPackages,
			'npmPackages' => $npmPackages,
		];
	}


	/**
	 * Reads tracked Composer package versions from composer.lock.
	 * @return array<string, string>
	 */
	private function readComposerPackages(): array
	{
		$lockFile = $this->cwd . '/composer.lock';
		if (!is_file($lockFile)) {
			return [];
		}

		$content = file_get_contents($lockFile);
		if ($content === false) {
			return [];
		}

		$lock = json_decode($content, associative: true);
		if (!is_array($lock)) {
			return [];
		}

		$packages = [];
		foreach (['packages', 'packages-dev'] as $section) {
			foreach ($lock[$section] ?? [] as $pkg) {
				$name = $pkg['name'] ?? '';
				foreach (self::TrackedVendors as $vendor) {
					if (str_starts_with($name, $vendor . '/')) {
						$packages[$name] = $pkg['version'] ?? 'unknown';
					}
				}
			}
		}

		ksort($packages);
		return $packages;
	}


	/**
	 * Reads Nette-related npm packages from package.json.
	 * @return array<string, string>
	 */
	private function readNpmPackages(): array
	{
		$packageFile = $this->cwd . '/package.json';
		if (!is_file($packageFile)) {
			return [];
		}

		$content = file_get_contents($packageFile);
		if ($content === false) {
			return [];
		}

		$data = json_decode($content, associative: true);
		if (!is_array($data)) {
			return [];
		}

		$packages = [];
		foreach (['dependencies', 'devDependencies'] as $section) {
			foreach ($data[$section] ?? [] as $name => $version) {
				foreach (self::TrackedNpmPrefixes as $prefix) {
					if (str_starts_with($name, $prefix)) {
						$packages[$name] = $version;
						continue 2;
					}
				}
			}
		}

		ksort($packages);
		return $packages;
	}
}
