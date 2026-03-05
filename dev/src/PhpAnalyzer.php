<?php declare(strict_types=1);

namespace Nette\Xray;

use PhpParser\Node;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use function is_array;


/**
 * Analyzes PHP/PHPT files using PHPStan's type-aware AST traversal.
 */
final class PhpAnalyzer
{
	/** Tracked top-level namespaces */
	private const TrackedNamespaces = ['Nette', 'Latte', 'Tracy', 'Dibi', 'Texy'];

	/** Global functions from Nette Tester — lowercase => canonical (only tracked if nette/tester is installed) */
	private const TesterFunctions = [
		'test' => 'test', 'testexception' => 'testException', 'testnoerror' => 'testNoError',
		'setup' => 'setUp', 'teardown' => 'tearDown',
	];

	/** Global functions from Tracy — lowercase => canonical (only tracked if tracy/tracy is installed) */
	private const TracyFunctions = ['dump' => 'dump', 'dumpe' => 'dumpe', 'bdump' => 'bdump'];


	public function __construct(
		private readonly Parser $parser,
		private readonly ScopeFactory $scopeFactory,
		private readonly NodeScopeResolver $nodeScopeResolver,
		private readonly ReflectionProvider $reflectionProvider,
		private readonly PathRoutingParser $pathRoutingParser,
		private readonly bool $hasTester,
		private readonly bool $hasTracy,
	) {
	}


	/**
	 * @param string[] $files
	 * @param ?\Closure(): void $onFile
	 */
	public function analyze(array $files, Collector $collector, ?\Closure $onFile = null): void
	{
		$this->pathRoutingParser->setAnalysedFiles($files);

		foreach ($files as $file) {
			if ($onFile) {
				$onFile();
			}

			$this->analyzeFile($file, $collector);
		}
	}


	private function analyzeFile(string $file, Collector $collector): void
	{
		try {
			$ast = $this->parser->parseFile($file);
		} catch (\Throwable) {
			$collector->addPhpParseError();
			return;
		}

		$scope = $this->scopeFactory->create(ScopeContext::create($file));

		// Pre-scan AST to classify nodes by context
		$nodeContext = [];
		$this->preScanNodes($ast, $nodeContext);

		try {
			$this->nodeScopeResolver->processNodes(
				$ast,
				$scope,
				function (Node $node, Scope $scope) use ($collector, &$nodeContext): void {
					$this->processNode($node, $scope, $collector, $nodeContext);
				},
			);
		} catch (\Throwable) {
			$collector->addPhpParseError();
		}
	}


	/**
	 * Recursively pre-scans AST to classify nodes by their context.
	 * @param mixed[] $nodes
	 * @param array<int, string> $context keyed by spl_object_id
	 */
	private function preScanNodes(array $nodes, array &$context): void
	{
		foreach ($nodes as $node) {
			if (!$node instanceof Node) {
				continue;
			}

			if ($node instanceof Node\Stmt\Expression) {
				$context[spl_object_id($node->expr)] = Collector::Discarded;
			}

			if ($node instanceof Node\Expr\Assign) {
				if ($node->var instanceof Node\Expr\PropertyFetch) {
					$context[spl_object_id($node->var)] = Collector::Write;
				} elseif ($node->var instanceof Node\Expr\ArrayDimFetch
					&& $node->var->var instanceof Node\Expr\PropertyFetch
				) {
					$context[spl_object_id($node->var->var)] = Collector::ArrayPush;
				}
			}

			if ($node instanceof Node\Expr\AssignRef && $node->var instanceof Node\Expr\PropertyFetch) {
				$context[spl_object_id($node->var)] = Collector::Reference;
			}

			foreach ($node->getSubNodeNames() as $name) {
				$sub = $node->$name;
				if ($sub instanceof Node) {
					$this->preScanNodes([$sub], $context);
				} elseif (is_array($sub)) {
					$this->preScanNodes($sub, $context);
				}
			}
		}
	}


	/** @param array<int, string> $nodeContext */
	private function processNode(Node $node, Scope $scope, Collector $collector, array $nodeContext): void
	{
		match (true) {
			$node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
				=> $this->processMethodCall($node, $scope, $collector, $nodeContext),
			$node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier
				=> $this->processStaticCall($node, $scope, $collector, $nodeContext),
			$node instanceof Node\Expr\FuncCall
				=> $this->processFunctionCall($node, $scope, $collector, $nodeContext),
			$node instanceof Node\Expr\New_
				=> $this->processInstantiation($node, $scope, $collector),
			$node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier
				=> $this->processPropertyFetch($node, $scope, $collector, $nodeContext),
			$node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier
				=> $this->processStaticPropertyFetch($node, $scope, $collector),
			$node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier
				=> $this->processClassConstFetch($node, $scope, $collector),
			$node instanceof Node\Stmt\Class_
				=> $this->processClassDeclaration($node, $scope, $collector),
			$node instanceof Node\Stmt\TraitUse
				=> $this->processTraitUse($node, $scope, $collector),
			$node instanceof Node\Stmt\ClassMethod
				=> $this->processClassMethod($node, $scope, $collector),
			$node instanceof StaticMethodCallableNode
				=> $this->processStaticMethodCallable($node, $scope, $collector),
			$node instanceof MethodCallableNode
				=> $this->processMethodCallable($node, $scope, $collector),
			default => null,
		};
	}


	/** @param array<int, string> $nodeContext */
	private function processMethodCall(
		Node\Expr\MethodCall $node,
		Scope $scope,
		Collector $collector,
		array $nodeContext,
	): void
	{
		assert($node->name instanceof Node\Identifier);
		$calledOnType = $scope->getType($node->var);
		$methodName = $node->name->toString();
		$method = $scope->getMethodReflection($calledOnType, $methodName);
		if ($method === null) {
			return;
		}

		$declaringClass = $method->getDeclaringClass()->getName();
		if (!$this->isTracked($declaringClass)) {
			return;
		}

		[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
		$returnUsed = ($nodeContext[spl_object_id($node)] ?? null) !== Collector::Discarded;

		$collector->addMethodCall($declaringClass, $method->getName(), $argCount, $namedArgs, $spread, $returnUsed);
	}


	/**
	 * @param array<int, string> $nodeContext
	 */
	private function processStaticCall(
		Node\Expr\StaticCall $node,
		Scope $scope,
		Collector $collector,
		array $nodeContext,
	): void
	{
		assert($node->name instanceof Node\Identifier);
		$className = $this->resolveClassName($node->class, $scope);
		if ($className === null) {
			return;
		}

		$methodName = $node->name->toString();
		$classReflection = $this->getClassReflection($className);
		if ($classReflection === null || !$classReflection->hasMethod($methodName)) {
			if ($this->isTracked($className)) {
				[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
				$returnUsed = ($nodeContext[spl_object_id($node)] ?? null) !== Collector::Discarded;
				$collector->addStaticCall($className, $methodName, $argCount, $namedArgs, $spread, $returnUsed);
			}

			return;
		}

		$method = $classReflection->getMethod($methodName, $scope);
		$declaringClass = $method->getDeclaringClass()->getName();
		if (!$this->isTracked($declaringClass)) {
			return;
		}

		[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
		$returnUsed = ($nodeContext[spl_object_id($node)] ?? null) !== Collector::Discarded;
		$collector->addStaticCall($declaringClass, $method->getName(), $argCount, $namedArgs, $spread, $returnUsed);
	}


	/** @param array<int, string> $nodeContext */
	private function processFunctionCall(
		Node\Expr\FuncCall $node,
		Scope $scope,
		Collector $collector,
		array $nodeContext,
	): void
	{
		if (!$node->name instanceof Node\Name) {
			return;
		}

		$name = $node->name->toString();

		// Namespaced functions (Nette\*, etc.)
		if ($this->isTracked($name)) {
			[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
			$returnUsed = ($nodeContext[spl_object_id($node)] ?? null) !== Collector::Discarded;
			$collector->addFunctionCall($name, $argCount, $namedArgs, $spread, $returnUsed);
			return;
		}

		// Global Tester/Tracy functions (case-insensitive)
		$canonical = $this->findTrackedFunction($name);
		if ($canonical !== null) {
			[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
			$returnUsed = ($nodeContext[spl_object_id($node)] ?? null) !== Collector::Discarded;
			$collector->addFunctionCall($canonical, $argCount, $namedArgs, $spread, $returnUsed);
		}
	}


	private function processInstantiation(Node\Expr\New_ $node, Scope $scope, Collector $collector): void
	{
		if ($node->class instanceof Node\Stmt\Class_) {
			return; // anonymous class
		}

		$className = $this->resolveClassName($node->class, $scope);
		if ($className === null || !$this->isTracked($className)) {
			return;
		}

		[$argCount, $namedArgs, $spread] = $this->analyzeArgs($node->args);
		$collector->addInstantiation($className, $argCount, $namedArgs, $spread);
	}


	/** @param array<int, string> $nodeContext */
	private function processPropertyFetch(
		Node\Expr\PropertyFetch $node,
		Scope $scope,
		Collector $collector,
		array $nodeContext,
	): void
	{
		assert($node->name instanceof Node\Identifier);
		$calledOnType = $scope->getType($node->var);
		$propertyName = $node->name->toString();

		foreach ($calledOnType->getObjectClassNames() as $className) {
			$classReflection = $this->getClassReflection($className);
			if ($classReflection === null || !$classReflection->hasProperty($propertyName)) {
				continue;
			}

			$declaringClass = $classReflection->getProperty($propertyName, $scope)->getDeclaringClass()->getName();
			if (!$this->isTracked($declaringClass)) {
				continue;
			}

			$accessType = $nodeContext[spl_object_id($node)] ?? Collector::Read;
			if ($this->isNativeProperty($declaringClass, $propertyName)) {
				$collector->addPropertyAccess($declaringClass, $propertyName, $accessType);
			} else {
				$collector->addVirtualPropertyAccess($declaringClass, $propertyName, $accessType);
			}

			return;
		}
	}


	private function processStaticPropertyFetch(
		Node\Expr\StaticPropertyFetch $node,
		Scope $scope,
		Collector $collector,
	): void
	{
		assert($node->name instanceof Node\VarLikeIdentifier);
		$className = $this->resolveClassName($node->class, $scope);
		if ($className === null) {
			return;
		}

		$propertyName = $node->name->toString();
		$classReflection = $this->getClassReflection($className);
		if ($classReflection === null || !$classReflection->hasProperty($propertyName)) {
			return;
		}

		$className = $classReflection->getProperty($propertyName, $scope)->getDeclaringClass()->getName();
		if (!$this->isTracked($className)) {
			return;
		}

		if ($this->isNativeProperty($className, $propertyName)) {
			$collector->addPropertyAccess($className, $propertyName, Collector::Read);
		} else {
			$collector->addVirtualPropertyAccess($className, $propertyName, Collector::Read);
		}
	}


	private function processClassConstFetch(Node\Expr\ClassConstFetch $node, Scope $scope, Collector $collector): void
	{
		assert($node->name instanceof Node\Identifier);
		$className = $this->resolveClassName($node->class, $scope);
		if ($className === null) {
			return;
		}

		$constName = $node->name->toString();

		if ($constName === 'class') {
			return; // Foo::class is not a constant access
		}

		$classReflection = $this->getClassReflection($className);
		if ($classReflection === null || !$classReflection->hasConstant($constName)) {
			return;
		}

		$className = $classReflection->getConstant($constName)->getDeclaringClass()->getName();
		if (!$this->isTracked($className)) {
			return;
		}

		$collector->addConstantAccess($className, $constName);
	}


	private function processClassDeclaration(Node\Stmt\Class_ $node, Scope $scope, Collector $collector): void
	{
		if ($node->extends !== null) {
			$parentName = $scope->resolveName($node->extends);
			if ($this->isTracked($parentName)) {
				$collector->addInheritance(Collector::Extends, $parentName);
			}
		}

		foreach ($node->implements as $interface) {
			$interfaceName = $scope->resolveName($interface);
			if ($this->isTracked($interfaceName)) {
				$collector->addInheritance(Collector::Implements, $interfaceName);
			}
		}
	}


	private function processTraitUse(Node\Stmt\TraitUse $node, Scope $scope, Collector $collector): void
	{
		foreach ($node->traits as $trait) {
			$traitName = $scope->resolveName($trait);
			if ($this->isTracked($traitName)) {
				$collector->addInheritance(Collector::Traits, $traitName);
			}
		}
	}


	private function processClassMethod(Node\Stmt\ClassMethod $node, Scope $scope, Collector $collector): void
	{
		if (!$scope->isInClass()) {
			return;
		}

		$classReflection = $scope->getClassReflection();
		$methodName = $node->name->toString();

		// Walk up the parent chain to find if this method overrides a Nette method
		$parent = $classReflection->getParentClass();
		while ($parent !== null) {
			if ($this->isTracked($parent->getName()) && $parent->hasMethod($methodName)) {
				$collector->addOverride($parent->getName(), $parent->getMethod($methodName, $scope)->getName());
				return;
			}

			$parent = $parent->getParentClass();
		}
	}


	/**
	 * Analyzes call arguments.
	 * @param array<Node\Arg|Node\VariadicPlaceholder> $args
	 * @return array{int, string[], bool} [argCount, namedArgs, hasSpread]
	 */
	private function analyzeArgs(array $args): array
	{
		$argCount = 0;
		$namedArgs = [];
		$spread = false;

		foreach ($args as $arg) {
			if ($arg instanceof Node\VariadicPlaceholder) {
				$spread = true;
				continue;
			}

			$argCount++;
			if ($arg->name !== null) {
				$namedArgs[] = $arg->name->toString();
			}

			if ($arg->unpack) {
				$spread = true;
			}
		}

		return [$argCount, $namedArgs, $spread];
	}


	/**
	 * Finds canonical name of a tracked global function (case-insensitive).
	 */
	private function findTrackedFunction(string $name): ?string
	{
		$lower = strtolower($name);
		if ($this->hasTester && isset(self::TesterFunctions[$lower])) {
			return self::TesterFunctions[$lower];
		}
		if ($this->hasTracy && isset(self::TracyFunctions[$lower])) {
			return self::TracyFunctions[$lower];
		}
		return null;
	}


	private function isTracked(string $name): bool
	{
		foreach (self::TrackedNamespaces as $ns) {
			if (str_starts_with($name, $ns . '\\')) {
				return true;
			}
		}

		return false;
	}


	private function processStaticMethodCallable(StaticMethodCallableNode $node, Scope $scope, Collector $collector): void
	{
		$name = $node->getName();
		if (!$name instanceof Node\Identifier) {
			return;
		}

		$original = $node->getOriginalNode();
		$className = $this->resolveClassName($original->class, $scope);
		if ($className === null) {
			return;
		}

		$methodName = $name->toString();
		$classReflection = $this->getClassReflection($className);
		if ($classReflection !== null && $classReflection->hasMethod($methodName)) {
			$method = $classReflection->getMethod($methodName, $scope);
			$declaringClass = $method->getDeclaringClass()->getName();
			if ($this->isTracked($declaringClass)) {
				$collector->addCallableReference($declaringClass, $method->getName());
				return;
			}
		}

		if ($this->isTracked($className)) {
			$collector->addCallableReference($className, $methodName);
		}
	}


	private function processMethodCallable(MethodCallableNode $node, Scope $scope, Collector $collector): void
	{
		$name = $node->getName();
		if (!$name instanceof Node\Identifier) {
			return;
		}

		$calledOnType = $scope->getType($node->getVar());
		$methodName = $name->toString();
		$method = $scope->getMethodReflection($calledOnType, $methodName);
		if ($method === null) {
			return;
		}

		$declaringClass = $method->getDeclaringClass()->getName();
		if ($this->isTracked($declaringClass)) {
			$collector->addCallableReference($declaringClass, $method->getName());
		}
	}


	private function resolveClassName(Node\Name|Node\Expr $class, Scope $scope): ?string
	{
		if ($class instanceof Node\Name) {
			return $scope->resolveName($class);
		}

		return $scope->getType($class)->getObjectClassNames()[0] ?? null;
	}


	private function getClassReflection(string $className): ?ClassReflection
	{
		return $this->reflectionProvider->hasClass($className)
			? $this->reflectionProvider->getClass($className)
			: null;
	}


	private function isNativeProperty(string $className, string $propertyName): bool
	{
		$classReflection = $this->getClassReflection($className);
		return $classReflection !== null
			&& $classReflection->hasNativeProperty($propertyName)
			&& $classReflection->getNativeProperty($propertyName)->isPublic();
	}
}
