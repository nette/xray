<?php declare(strict_types=1);

namespace Nette\Xray;

use Latte;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Html\AttributeNode;
use Latte\Compiler\Nodes\Html\ElementNode;
use Latte\Compiler\Nodes\Php\Expression\ConstantFetchNode;
use Latte\Compiler\Nodes\Php\Expression\FunctionCallNode;
use Latte\Compiler\Nodes\Php\FilterNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\Nodes\Php\Scalar\BooleanNode;
use Latte\Compiler\Nodes\Php\Scalar\NullNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\Tag;
use Latte\Essential\Nodes\BlockNode;
use Latte\Essential\Nodes\DefineNode;
use Latte\Essential\Nodes\ExtendsNode;
use Latte\Essential\Nodes\FirstLastSepNode;
use Latte\Essential\Nodes\ForeachNode;
use Latte\Essential\Nodes\IfNode;
use Latte\Essential\Nodes\ImportNode;
use Latte\Essential\Nodes\IncludeBlockNode;
use Latte\Essential\Nodes\IncludeFileNode;
use Latte\Essential\Nodes\VarNode;
use Latte\Essential\TranslatorExtension;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\CacheLatte\CacheExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Caching\Storages\DevNullStorage;
use function count, in_array;


/**
 * Analyzes .latte template files using Latte's own compiler and Node tree.
 * Registers real Nette extensions for full AST, uses catch-all only for unknown tags.
 */
final class LatteAnalyzer
{
	/** Complete list of all known Nette/Latte tags (for counting in statistics) */
	public const KnownTags = [
		// CoreExtension
		'embed', 'define', 'block', 'layout', 'extends', 'import', 'include',
		'do', 'php', 'contentType', 'spaceless', 'capture', 'l', 'r', 'syntax',
		'dump', 'debugbreak', 'trace', 'var', 'default', 'try', 'rollback',
		'foreach', 'for', 'while', 'iterateWhile', 'sep', 'last', 'first',
		'skipIf', 'breakIf', 'exitIf', 'continueIf',
		'if', 'ifset', 'ifchanged', 'switch',
		'sandbox', 'parameters', 'varType', 'varPrint', 'templateType', 'templatePrint',
		// TranslatorExtension
		'_', 'translate',
		// UIExtension
		'control', 'plink', 'link', 'linkBase', 'ifCurrent', 'snippet', 'snippetArea',
		// FormsExtension
		'form', 'formContext', 'formContainer', 'label', 'input', 'inputError', 'formPrint', 'formClassPrint',
		// CacheExtension
		'cache',
		// AssetsExtension
		'asset', 'preload',
		// TexyExtension
		'texy',
	];

	/** Known dedicated n:attributes (not derived from tags) */
	public const KnownNAttributes = [
		// Core
		'n:attr', 'n:class', 'n:tag', 'n:ifcontent', 'n:else',
		// UIExtension
		'n:href', 'n:nonce',
		// FormsExtension
		'n:name',
		// AssetsExtension
		'n:asset', 'n:asset?',
	];

	/** Known Latte filters */
	public const KnownFilters = [
		// Core
		'batch', 'breakLines', 'bytes', 'capitalize', 'ceil', 'checkUrl', 'clamp', 'dataStream',
		'date', 'escape', 'escapeCss', 'escapeHtml', 'escapeHtmlComment', 'escapeICal', 'escapeJs',
		'escapeUrl', 'escapeXml', 'explode', 'filter', 'first', 'firstLower', 'firstUpper', 'floor',
		'noescape',
		'group', 'implode', 'indent', 'join', 'last', 'length', 'localDate', 'lower', 'number',
		'padLeft', 'padRight', 'query', 'random', 'repeat', 'replace', 'replaceRe', 'reverse',
		'round', 'slice', 'sort', 'spaceless', 'split', 'strip', 'stripHtml', 'stripTags', 'substr',
		'trim', 'truncate', 'upper', 'webalize',
		// TranslatorExtension
		'translate',
		// UIExtension
		'modifyDate', 'absoluteUrl',
		// TexyExtension
		'texy',
	];

	/** Known Latte functions */
	public const KnownFunctions = [
		// Core
		'clamp', 'divisibleBy', 'even', 'first', 'group', 'last', 'odd', 'slice', 'hasBlock', 'hasTemplate',
		// UIExtension
		'isLinkCurrent', 'isModuleCurrent',
		// AssetsExtension
		'asset', 'tryAsset',
	];

	// n:attribute syntax variants
	private const Braces = 'braces';
	private const Quoted = 'quoted';
	private const Bare = 'bare';


	/**
	 * @param string[] $files
	 * @param ?\Closure(): void $onFile
	 */
	public function analyze(array $files, Collector $collector, ?\Closure $onFile = null): void
	{
		if (!class_exists(Latte\Engine::class)) {
			return;
		}

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

		// Normalize line endings to match Latte's internal representation (Position offsets)
		$content = str_replace("\r\n", "\n", $content);

		$engine = new Latte\Engine;
		$engine->setAutoRefresh(false);

		// Register known Nette extensions for full AST
		foreach (self::createExtensions() as $ext) {
			$engine->addExtension($ext);
		}

		// Catch-all for remaining unknown tags
		$engine->addExtension(new LatteCatchAllExtension($content));

		try {
			$node = $engine->parse($content);
		} catch (\Throwable) {
			$collector->addLatteParseError();
			return;
		}

		$this->traverseNode($node, $content, $collector);
	}


	/** @return Latte\Extension[] */
	private static function createExtensions(): array
	{
		$extensions = [];
		$extensions[] = new TranslatorExtension(null);
		if (class_exists(UIExtension::class)) {
			$extensions[] = new UIExtension(null);
		}
		if (class_exists(FormsExtension::class)) {
			$extensions[] = new FormsExtension;
		}
		if (class_exists(CacheExtension::class) && class_exists(DevNullStorage::class)) {
			$extensions[] = new CacheExtension(new DevNullStorage);
		}
		return $extensions;
	}


	// =========================================================================
	// AST-based analysis
	// =========================================================================


	private function traverseNode(Node $node, string $source, Collector $collector): void
	{
		if ($node instanceof StatementNode) {
			$tagName = $this->getTagName($node, $source);
			if ($tagName !== null) {
				$collector->addTag($tagName);
				$this->analyzeTagDetails($node, $tagName, $source, $collector);
			}
		}

		if ($node instanceof ElementNode) {
			$this->analyzeElement($node, $collector);
		}

		if ($node instanceof FilterNode) {
			$filterName = $this->resolveKnownName($node->name->name, self::KnownFilters);
			$collector->addFilter($filterName, count($node->args), $node->nullsafe);
		}

		if ($node instanceof FunctionCallNode && $node->name instanceof NameNode) {
			$funcName = $this->resolveKnownName($node->name->name, self::KnownFunctions);
			$collector->addLatteFunction($funcName);
		}

		if ($node instanceof ConstantFetchNode) {
			$name = ltrim((string) $node->name, '\\');
			if ($name !== 'true' && $name !== 'false' && $name !== 'null') {
				$collector->addLatteConstant($name);
			}
		}

		foreach ($node->getIterator() as $child) {
			$this->traverseNode($child, $source, $collector);
		}
	}


	private function getTagName(StatementNode $node, string $source): ?string
	{
		if ($node instanceof LatteCatchAllNode) {
			$name = $node->tagName;
		} else {
			$name = $this->extractTagName($node, $source);
		}

		if ($name === null) {
			return null;
		}

		return in_array($name, self::KnownTags, true)
			? $name
			: '#' . md5($name);
	}


	private function extractTagName(StatementNode $node, string $source): ?string
	{
		if ($node->position === null) {
			return null;
		}
		$offset = $node->position->offset;
		if (preg_match('/\{(\w+)/', $source, $m, PREG_OFFSET_CAPTURE, $offset) && $m[0][1] === $offset) {
			return $m[1][0];
		}
		return null;
	}


	private function extractRawArgs(StatementNode $node, string $source): string
	{
		if ($node->position === null) {
			return '';
		}
		$offset = $node->position->offset;
		if (preg_match('/\{\w+\s*(.*?)(?:\}|$)/s', $source, $m, PREG_OFFSET_CAPTURE, $offset) && $m[0][1] === $offset) {
			return $m[1][0];
		}
		return '';
	}


	private function analyzeTagDetails(StatementNode $node, string $tagName, string $source, Collector $collector): void
	{
		match (true) {
			$node instanceof BlockNode => $this->analyzeBlock($node, $tagName, $source, $collector),
			$node instanceof DefineNode => $this->analyzeDefine($node, $source, $collector),
			$node instanceof ExtendsNode => $this->analyzeExtends($node, $tagName, $collector),
			$node instanceof ForeachNode => $this->analyzeForeach($node, $collector),
			$node instanceof IfNode && !$node->ifset => $this->analyzeIf($node, $collector),
			$node instanceof IfNode && $node->ifset => $this->analyzeIfset($node, $source, $collector),
			$node instanceof IncludeBlockNode => $this->analyzeIncludeBlock($node, $source, $collector),
			$node instanceof IncludeFileNode => $this->analyzeIncludeFile($node, $collector),
			$node instanceof FirstLastSepNode => $this->analyzeFirstLastSep($node, $tagName, $collector),
			$node instanceof ImportNode => $this->analyzeImport($node, $collector),
			$node instanceof VarNode => $this->analyzeVar($node, $tagName, $source, $collector),
			$node instanceof LatteCatchAllNode => $this->analyzeCatchAllDetails($node, $collector),
			default => null,
		};
	}


	private function analyzeBlock(BlockNode $node, string $tagName, string $source, Collector $collector): void
	{
		if ($node->block !== null) {
			if ($node->block->layer === Latte\Runtime\Template::LayerLocal) {
				$collector->addLatteTagDetail("tags.$tagName", 'local');
			}
			$rawArgs = $this->extractRawArgs($node, $source);
			if (str_contains($rawArgs, '#')) {
				$collector->addLatteTagDetail("tags.$tagName", 'hash');
			}
		}
	}


	private function analyzeDefine(DefineNode $node, string $source, Collector $collector): void
	{
		$rawArgs = $this->extractRawArgs($node, $source);
		if (str_contains($rawArgs, '#')) {
			$collector->addLatteTagDetail('tags.define', 'hash');
		}
		if ($node->block->parameters !== []) {
			$collector->addLatteTagDetail('tags.define', 'args');
		}
	}


	private function analyzeExtends(ExtendsNode $node, string $tagName, Collector $collector): void
	{
		$key = "tags.$tagName";
		$variant = match (true) {
			$node->extends instanceof BooleanNode => 'none',
			$node->extends instanceof NullNode => 'auto',
			default => 'file',
		};
		$collector->addLatteTagDetail($key, $variant);
	}


	private function analyzeFirstLastSep(FirstLastSepNode $node, string $tagName, Collector $collector): void
	{
		$key = "tags.$tagName";
		if ($node->width !== null) {
			$collector->addLatteTagDetail($key, 'args');
		}
		if ($node->else !== null) {
			$collector->addLatteTagDetail($key, 'else');
		}
	}


	private function analyzeForeach(ForeachNode $node, Collector $collector): void
	{
		if ($node->iterator === false) {
			$collector->addLatteTagDetail('tags.foreach', 'noiterator');
		}
		if ($node->checkArgs === false) {
			$collector->addLatteTagDetail('tags.foreach', 'nocheck');
		}
		if ($node->else !== null) {
			$collector->addLatteTagDetail('tags.foreach', 'else');
		}
	}


	private function analyzeIf(IfNode $node, Collector $collector): void
	{
		if ($node->capture) {
			$collector->addLatteTagDetail('tags.if', 'capture');
		}
	}


	private function analyzeIfset(IfNode $node, string $source, Collector $collector): void
	{
		$rawArgs = $this->extractRawArgs($node, $source);
		if (preg_match('/(?P<hash>#)|(?P<keyword>\bblock\b)/', $rawArgs, $m)) {
			if (!empty($m['hash'])) {
				$collector->addLatteTagDetail('tags.ifset', 'hash');
			}
			if (!empty($m['keyword'])) {
				$collector->addLatteTagDetail('tags.ifset', 'keyword');
			}
		}
	}


	private function analyzeIncludeBlock(IncludeBlockNode $node, string $source, Collector $collector): void
	{
		$collector->addLatteTagDetail('tags.include', 'block');

		$rawArgs = $this->extractRawArgs($node, $source);
		if (str_contains($rawArgs, '#')) {
			$collector->addLatteTagDetail('tags.include.block', 'hash');
		}
		if (preg_match('/\bblock\b/', $rawArgs)) {
			$collector->addLatteTagDetail('tags.include.block', 'keyword');
		}
		if ($node->from !== null) {
			$collector->addLatteTagDetail('tags.include.block', 'from');
		}
		if ($node->parent) {
			$collector->addLatteTagDetail('tags.include.block', 'parent');
		}
	}


	private function analyzeIncludeFile(IncludeFileNode $node, Collector $collector): void
	{
		$collector->addLatteTagDetail('tags.include', 'file');
		if ($node->mode === 'includeblock') {
			$collector->addLatteTagDetail('tags.include.file', 'with');
		}
	}


	private function analyzeImport(ImportNode $node, Collector $collector): void
	{
		if ($node->args->items !== []) {
			$collector->addLatteTagDetail('tags.import', 'args');
		}
	}


	private function analyzeVar(VarNode $node, string $tagName, string $source, Collector $collector): void
	{
		$rawArgs = $this->extractRawArgs($node, $source);
		if (preg_match('/(?:^|,)\s*(?!\$)[a-zA-Z?\\\]/', $rawArgs)) {
			$collector->addLatteTagDetail("tags.$tagName", 'type');
		}
	}


	/**
	 * Detail analysis for unknown tags caught by catch-all (fallback for tags without real extensions).
	 */
	private function analyzeCatchAllDetails(LatteCatchAllNode $node, Collector $collector): void
	{
		// No detail analysis for catch-all nodes currently
	}


	private function analyzeElement(ElementNode $node, Collector $collector): void
	{
		if ($node->selfClosing) {
			$collector->addSelfClosingTag();
		}

		if ($node->dynamicTag !== null) {
			$collector->addDynamicElement();
		}

		foreach ($node->nAttributes as $attr) {
			$this->analyzeNAttribute($attr, $collector);
		}

		foreach ($node->attributes->children as $attr) {
			if (!$attr instanceof AttributeNode) {
				$collector->addAttributeExpression();
			}
		}
	}


	private function analyzeNAttribute(Tag $attr, Collector $collector): void
	{
		$name = 'n:' . $attr->name;
		$name = $this->resolveNAttributeName($name);
		$text = $attr->parser->text;
		$variant = match (true) {
			$text === '' => self::Bare,
			str_starts_with($text, '{') && str_ends_with($text, '}') => self::Braces,
			default => self::Quoted,
		};
		$collector->addNAttribute($name, $variant);
	}


	/** @param string[] $known */
	private function resolveKnownName(string $name, array $known): string
	{
		return in_array($name, $known, true) || function_exists($name)
			? $name
			: '#' . md5($name);
	}


	private function resolveNAttributeName(string $fullName): string
	{
		if (in_array($fullName, self::KnownNAttributes, true)) {
			return $fullName;
		}

		// n:foreach → foreach, n:inner-foreach → foreach, n:tag-if → if
		$baseName = preg_replace('/^n:(?:inner-|tag-)?/', '', $fullName);
		if (in_array($baseName, self::KnownTags, true)) {
			return $fullName;
		}

		return '#' . md5($fullName);
	}
}
