<?php declare(strict_types=1);

namespace Nette\Xray;

use Nette\Neon\Entity;
use Nette\Neon\Exception;
use Nette\Neon\Neon;
use function array_key_exists, count, is_array, is_int, is_string, str_contains, str_starts_with;


/**
 * Analyzes .neon configuration files using Nette\Neon parser.
 */
final class NeonAnalyzer
{
	/** Sections where we only report item count */
	private const CountOnlySections = ['constants', 'decorator', 'extensions', 'includes', 'parameters', 'php'];

	/** Sections where we report which keys are used */
	private const KeyUsageSections = ['application', 'assets', 'di', 'http', 'latte', 'mail', 'routing', 'security', 'session', 'tracy'];


	/**
	 * @param string[] $files
	 * @param ?\Closure(): void $onFile
	 */
	public function analyze(array $files, Collector $collector, ?\Closure $onFile = null): void
	{
		foreach ($files as $file) {
			if ($onFile) {
				$onFile();
			}

			$this->analyzeFile($file, $collector);
		}
	}


	private function analyzeFile(string $file, Collector $collector): void
	{
		$content = file_get_contents($file);
		if ($content === false) {
			return;
		}

		try {
			$data = Neon::decode($content);
		} catch (Exception) {
			$collector->addNeonParseError();
			return;
		}

		if (!is_array($data)) {
			return;
		}

		// Collect top-level section names
		foreach (array_keys($data) as $section) {
			$collector->addNeonSection($section);
		}

		// Count-only sections
		foreach (self::CountOnlySections as $section) {
			if (isset($data[$section]) && is_array($data[$section])) {
				$collector->addNeonItemCount($section, count($data[$section]));
			}
		}

		// Key usage sections
		foreach (self::KeyUsageSections as $section) {
			if (!isset($data[$section]) || !is_array($data[$section])) {
				continue;
			}

			foreach ($data[$section] as $key => $value) {
				$collector->addNeonKeyUsage($section, $key, $this->classifyValue($value));
			}
		}

		// Special: database section (normalized keys only)
		if (isset($data['database']) && is_array($data['database'])) {
			$this->analyzeDatabaseSection($data['database'], $collector);
		}

		// Services section (placeholder — count patterns)
		if (isset($data['services']) && is_array($data['services'])) {
			$this->analyzeServicesSection($data['services'], $collector);
		}

		// Search section (placeholder — count patterns)
		if (isset($data['search']) && is_array($data['search'])) {
			$collector->addNeonSearchCount(count($data['search']));
		}
	}


	/**
	 * Classifies a config value for reporting: true/false/count/present.
	 */
	private function classifyValue(mixed $value): string
	{
		return match (true) {
			$value === true => 'true',
			$value === false => 'false',
			is_array($value) => 'array(' . count($value) . ')',
			$value === null => 'null',
			default => 'present',
		};
	}


	/** @param mixed[] $data */
	private function analyzeDatabaseSection(array $data, Collector $collector): void
	{
		// Report only which keys are used, not their values (privacy)
		foreach ($data as $key => $value) {
			if (is_string($key)) {
				$collector->addNeonDatabaseKey($key);
			}

			// Numbered entries (multiple database connections)
			if (is_int($key) && is_array($value)) {
				$collector->addNeonDatabaseKey('_multipleConnections');
				foreach (array_keys($value) as $subKey) {
					$collector->addNeonDatabaseKey($subKey);
				}
			}
		}
	}


	/** @param mixed[] $data */
	private function analyzeServicesSection(array $data, Collector $collector): void
	{
		foreach ($data as $key => $value) {
			$keyType = match (true) {
				is_int($key) => 'bullet',
				str_contains((string) $key, '\\') => 'type',
				default => 'name',
			};
			$collector->addNeonService($keyType);

			match (true) {
				is_string($value) => $collector->addNeonServiceValue(str_starts_with($value, '@') ? 'reference' : 'class'),
				$value === false => $collector->addNeonServiceValue('false'),
				$value instanceof Entity => $collector->addNeonServiceValue('entity'),
				is_array($value) => $this->analyzeServiceArray($value, $collector),
				default => null,
			};
		}
	}


	/** @param array<string, mixed> $service */
	private function analyzeServiceArray(array $service, Collector $collector): void
	{
		$collector->addNeonServiceValue('array');

		/** Known service definition keys (factory/class are back-compat for create/type) */
		$knownKeys = ['create', 'factory', 'type', 'class', 'arguments', 'setup',
			'autowired', 'tags', 'implement', 'inject', 'alteration', 'imported',
			'lazy', 'references', 'tagged', 'reset'];

		foreach ($knownKeys as $k) {
			if (array_key_exists($k, $service)) {
				$collector->addNeonServiceArrayKey($k);
			}
		}

		if (isset($service['setup']) && is_array($service['setup'])) {
			$this->analyzeSetupItems($service['setup'], $collector);
		}
	}


	/** @param mixed[] $items */
	private function analyzeSetupItems(array $items, Collector $collector): void
	{
		foreach ($items as $item) {
			$type = match (true) {
				$item instanceof Entity && str_starts_with($item->value, '@') => 'referenceCall',
				$item instanceof Entity && str_starts_with($item->value, '$') => 'propertySet',
				$item instanceof Entity => 'methodCall',
				is_array($item) => 'propertySet',
				default => 'other',
			};
			$collector->addNeonServiceSetup($type);
		}
	}
}
