<?php declare(strict_types=1);

namespace Nette\Xray;


/**
 * Holds all collected usage statistics, serializable to JSON.
 */
final class Collector
{
	// Property access types
	public const Read = 'read';
	public const Write = 'write';
	public const ArrayPush = 'arrayPush';
	public const Reference = 'reference';

	// Node context
	public const Discarded = 'discarded';

	// Inheritance types
	public const Extends = 'extends';
	public const Implements = 'implements';
	public const Traits = 'traits';

	// Indentation styles
	public const Tabs = 'tabs';
	public const Spaces = 'spaces';
	public const Mixed = 'mixed';

	public \stdClass $meta;
	public \stdClass $php;
	public \stdClass $latte;
	public \stdClass $neon;


	public function __construct()
	{
		$this->meta = new \stdClass;
		$this->php = new \stdClass;
		$this->latte = new \stdClass;
		$this->neon = new \stdClass;
	}


	/** @param string[] $namedArgs */
	public function addMethodCall(
		string $class,
		string $method,
		int $argCount,
		array $namedArgs,
		bool $spread,
		bool $returnUsed,
	): void
	{
		$this->php->methodCalls ??= [];
		$key = $class . '::' . $method;
		$this->recordArgs($this->php->methodCalls[$key] ??= new \stdClass, $argCount, $namedArgs, $spread, $returnUsed);
	}


	/** @param string[] $namedArgs */
	public function addStaticCall(
		string $class,
		string $method,
		int $argCount,
		array $namedArgs,
		bool $spread,
		bool $returnUsed,
	): void
	{
		$this->php->staticCalls ??= [];
		$key = $class . '::' . $method;
		$this->recordArgs($this->php->staticCalls[$key] ??= new \stdClass, $argCount, $namedArgs, $spread, $returnUsed);
	}


	/** @param string[] $namedArgs */
	public function addFunctionCall(
		string $function,
		int $argCount,
		array $namedArgs,
		bool $spread,
		bool $returnUsed,
	): void
	{
		$this->php->functionCalls ??= [];
		$this->recordArgs($this->php->functionCalls[$function] ??= new \stdClass, $argCount, $namedArgs, $spread, $returnUsed);
	}


	/** @param string[] $namedArgs */
	public function addInstantiation(string $class, int $argCount, array $namedArgs, bool $spread): void
	{
		$this->php->instantiations ??= [];
		$this->recordArgs($this->php->instantiations[$class] ??= new \stdClass, $argCount, $namedArgs, $spread);
	}


	public function addPropertyAccess(string $class, string $property, string $type): void
	{
		$this->recordPropertyAccess('propertyAccess', $class, $property, $type);
	}


	public function addVirtualPropertyAccess(string $class, string $property, string $type): void
	{
		$this->recordPropertyAccess('virtualPropertyAccess', $class, $property, $type);
	}


	public function addConstantAccess(string $class, string $constant): void
	{
		$key = $class . '::' . $constant;
		$this->php->constants ??= [];
		self::incIn($this->php->constants, $key);
	}


	public function addInheritance(string $type, string $class): void
	{
		$this->php->inheritance ??= [];
		$this->php->inheritance[$type][$class] = ($this->php->inheritance[$type][$class] ?? 0) + 1;
	}


	public function addOverride(string $class, string $method): void
	{
		$key = $class . '::' . $method;
		$this->php->overrides ??= [];
		self::incIn($this->php->overrides, $key);
	}


	public function addCallableReference(string $class, string $method): void
	{
		$key = $class . '::' . $method;
		$this->php->callableReferences ??= [];
		self::incIn($this->php->callableReferences, $key);
	}


	public function addPhpParseError(): void
	{
		self::inc($this->php, 'parseErrors');
	}


	// ---- Latte ----


	public function addTag(string $name): void
	{
		$this->latte->tags ??= [];
		self::incIn($this->latte->tags, $name);
	}


	public function addLatteTagDetail(string $key, string $detail): void
	{
		$this->latte->$key ??= [];
		self::incIn($this->latte->$key, $detail);
	}


	public function addFilter(string $name, ?int $argCount = null, bool $nullsafe = false): void
	{
		$this->latte->filters ??= [];
		$entry = $this->latte->filters[$name] ??= new \stdClass;
		self::inc($entry, 'count');
		if ($argCount !== null) {
			$entry->args ??= [];
			self::incIn($entry->args, (string) $argCount);
		}
		if ($nullsafe) {
			self::inc($entry, 'nullsafe');
		}
	}


	public function addLatteFunction(string $name): void
	{
		$this->latte->functions ??= [];
		self::incIn($this->latte->functions, $name);
	}


	public function addLatteConstant(string $name): void
	{
		$this->latte->constants ??= [];
		self::incIn($this->latte->constants, $name);
	}


	public function addNAttribute(string $name, string $variant): void
	{
		$this->latte->nAttributes ??= [];
		$entry = $this->latte->nAttributes[$name] ??= new \stdClass;
		self::inc($entry, 'count');
		self::inc($entry, $variant);
	}


	public function addLatteParseError(): void
	{
		self::inc($this->latte, 'parseErrors');
	}


	public function addSelfClosingTag(int $count = 1): void
	{
		self::inc($this->latte, 'selfClosingTags', $count);
	}


	public function addDynamicElement(): void
	{
		self::inc($this->latte, 'dynamicElements');
	}


	public function addAttributeExpression(): void
	{
		self::inc($this->latte, 'attributeExpressions');
	}


	public function addNeonParseError(): void
	{
		self::inc($this->neon, 'parseErrors');
	}


	// ---- NEON ----


	public function addNeonSection(string $name): void
	{
		$this->neon->sections ??= [];
		self::incIn($this->neon->sections, $name);
	}


	public function addNeonItemCount(string $section, int $count): void
	{
		$prop = 'itemCounts_' . $section;
		self::inc($this->neon, $prop, $count);
	}


	public function addNeonKeyUsage(string $section, string $key, string $value): void
	{
		$this->neon->$section ??= [];
		$this->neon->$section[$key] = $value;
	}


	public function addNeonDatabaseKey(string $key): void
	{
		$this->neon->database ??= [];
		$this->neon->database[$key] = true;
	}


	public function addNeonSearchCount(int $count): void
	{
		$this->neon->search ??= new \stdClass;
		self::inc($this->neon->search, 'count', $count);
	}


	public function addNeonService(string $keyType): void
	{
		$services = $this->neon->services ??= new \stdClass;
		self::inc($services, 'total');
		$services->keys ??= [];
		self::incIn($services->keys, $keyType);
	}


	public function addNeonServiceValue(string $type): void
	{
		$services = $this->neon->services ??= new \stdClass;
		$services->values ??= [];
		self::incIn($services->values, $type);
	}


	public function addNeonServiceArrayKey(string $key): void
	{
		$services = $this->neon->services ??= new \stdClass;
		$services->arrayKeys ??= [];
		self::incIn($services->arrayKeys, $key);
	}


	public function addNeonServiceSetup(string $type): void
	{
		$services = $this->neon->services ??= new \stdClass;
		$services->setup ??= [];
		self::incIn($services->setup, $type);
	}


	private function recordPropertyAccess(string $collection, string $class, string $property, string $type): void
	{
		$key = $class . '::$' . $property;
		$this->php->$collection ??= [];
		$entry = $this->php->$collection[$key] ??= new \stdClass;
		self::inc($entry, $type);
	}


	/** @param string[] $namedArgs */
	private function recordArgs(
		\stdClass $entry,
		int $argCount,
		array $namedArgs,
		bool $spread,
		?bool $returnUsed = null,
	): void
	{
		self::inc($entry, 'count');
		$args = $entry->args ??= new \stdClass;
		$args->counts ??= [];
		self::incIn($args->counts, (string) $argCount);

		if ($namedArgs) {
			$args->named ??= [];
			foreach ($namedArgs as $name) {
				self::incIn($args->named, $name);
			}
		}

		if ($spread) {
			self::inc($args, 'spread');
		}

		if ($returnUsed !== null) {
			$key = $returnUsed ? 'returnUsed' : 'returnDiscarded';
			self::inc($entry, $key);
		}
	}


	private static function inc(\stdClass $obj, string $key, int $by = 1): void
	{
		$obj->$key = ($obj->$key ?? 0) + $by;
	}


	/** @param array<string|int, int> $arr */
	private static function incIn(array &$arr, string $key, int $by = 1): void
	{
		$arr[$key] = ($arr[$key] ?? 0) + $by;
	}
}
