<?php declare(strict_types=1);

namespace Nette\Xray;

use PHPStan\DependencyInjection\ContainerFactory;
use function array_slice, is_array, sprintf;
use const DIRECTORY_SEPARATOR;


/**
 * Pre-container setup: resolves paths, detects packages, creates PHPStan DI container, and runs App.
 */
final class Bootstrap
{
	/**
	 * @param  string[]  $argv
	 * @param  string[]  $composerAutoloaderProjectPaths
	 */
	public static function run(
		array $argv,
		string $currentWorkingDirectory,
		array $composerAutoloaderProjectPaths,
		string $configNeon,
	): int
	{
		$tempDirectory = sys_get_temp_dir() . '/nette-xray';
		if (
			!is_dir($tempDirectory)
			&& !@mkdir($tempDirectory, 0o777)
			&& !is_dir($tempDirectory)
		) { // @ - directory may already exist
			echo "Could not create TMP dir\n";
			return 1;
		}

		$config = Config::load($currentWorkingDirectory);
		$paths = self::resolvePaths($argv, $config, $currentWorkingDirectory);
		if (!$paths) {
			echo "Usage: xray <path> [path2] ...\n";
			echo "Or create nette-xray.neon with 'paths' key.\n";
			return 1;
		}

		$dynamicConfig = self::generateDynamicConfig($currentWorkingDirectory, $tempDirectory);

		$containerFactory = new ContainerFactory($currentWorkingDirectory);
		$container = $containerFactory->create(
			$tempDirectory,
			[$dynamicConfig, $configNeon],
			$paths,
			array_reverse($composerAutoloaderProjectPaths),
		);

		return $container->getByType(App::class)->run($paths, $config);
	}


	/**
	 * Resolves analysis paths from CLI args or config, expanding relative paths.
	 * @param  string[]  $argv
	 * @return string[]
	 */
	private static function resolvePaths(array $argv, Config $config, string $cwd): array
	{
		$cliPaths = array_slice($argv, 1);
		$rawPaths = $cliPaths !== [] ? $cliPaths : $config->paths;

		$paths = [];
		foreach ($rawPaths as $path) {
			if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^[a-zA-Z]:[/\\\]#', $path)) {
				$expandedPath = $path; // absolute on Windows
			} elseif ($path[0] === '/') {
				$expandedPath = $path; // absolute on Unix
			} else {
				$expandedPath = $cwd . '/' . $path;
			}

			$realPath = realpath($expandedPath);
			if ($realPath === false) {
				echo sprintf("Path %s does not exist.\n", $expandedPath);
				continue;
			}

			$paths[] = $realPath;
		}

		return $paths;
	}


	/**
	 * Detects installed packages and generates dynamic PHPStan config.
	 */
	private static function generateDynamicConfig(string $cwd, string $tempDirectory): string
	{
		$hasTester = false;
		$hasTracy = false;
		$composerJsonPath = $cwd . '/composer.json';
		if (is_file($composerJsonPath)) {
			$composerData = json_decode((string) file_get_contents($composerJsonPath), true);
			if (is_array($composerData)) {
				$allDeps = array_merge(
					$composerData['require'] ?? [],
					$composerData['require-dev'] ?? [],
				);
				$hasTester = isset($allDeps['nette/tester']);
				$hasTracy = isset($allDeps['tracy/tracy']);
			}
		}

		$path = $tempDirectory . '/dynamic-config.neon';
		file_put_contents($path, sprintf(
			"services:\n\tphpAnalyzer:\n\t\targuments:\n\t\t\thasTester: %s\n\t\t\thasTracy: %s\n\tapp:\n\t\targuments:\n\t\t\tcwd: %s\n",
			$hasTester ? 'true' : 'false',
			$hasTracy ? 'true' : 'false',
			json_encode($cwd),
		));
		return $path;
	}
}
