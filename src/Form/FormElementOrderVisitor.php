<?php

declare(strict_types=1);

namespace Drago\FormOrder;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;

final class FormElementOrderVisitor extends NodeVisitorAbstract
{
	private const StandardControlMethods = [
		'addText',
		'addTextArea',
		'addPassword',
		'addHidden',
		'addInteger',
		'addFloat',
		'addEmail',
		'addDate',
		'addDateTime',
		'addTime',
		'addColor',
		'addSelect',
		'addMultiSelect',
		'addRadioList',
		'addCheckboxList',
		'addCheckbox',
		'addUpload',
		'addMultiUpload',
		'addSubmit',
		'addButton',
		'addImageButton',
	];

	private const StandardMethodOrder = [
		'setHtmlAttribute' => 10,
		'setHtmlId' => 10,
		'setCaption' => 10,
		'setTranslator' => 10,
		'setOption' => 10,
		'setHtmlType' => 10,
		'setItems' => 20,
		'setPrompt' => 20,
		'setEmptyValue' => 20,
		'setMaxLength' => 20,
		'setFormat' => 20,
		'setDisabled' => 25,
		'setNullable' => 30,
		'setDefaultValue' => 30,
		'setOmitted' => 30,
		'addFilter' => 35,
		'setRequired' => 40,
		'addRule' => 50,
		'addCondition' => 50,
		'addConditionOn' => 50,
		'setValidationScope' => 50,
	];
	/** @var list<string> */
	private array $controlMethods;

	/** @var array<string, int> */
	private array $methodOrder;

	/** @var list<Node> */
	private array $nodeStack = [];

	/** @var list<array{start: int, length: int, replacement: string}> */
	private array $patches = [];


	/**
	 * @param list<string> $controlMethods
	 * @param array<string, int> $methodOrder
	 */
	public function __construct(
		array $controlMethods,
		array $methodOrder,
		private string $source,
	) {
		$this->controlMethods = $controlMethods;
		$this->methodOrder = $methodOrder;
	}


	public function enterNode(Node $node): ?Node
	{
		$this->nodeStack[] = $node;
		return null;
	}


	public function leaveNode(Node $node): ?Node
	{
		$parent = count($this->nodeStack) > 1 ? $this->nodeStack[count($this->nodeStack) - 2] : null;
		if ($node instanceof MethodCall && !($parent instanceof MethodCall && $parent->var === $node)) {
			$this->collectPatch($node);
		}

		array_pop($this->nodeStack);
		return null;
	}


	/** @return list<array{start: int, length: int, replacement: string}> */
	public function getPatches(): array
	{
		return $this->patches;
	}


	/** @return list<string> */
	public static function getStandardControlMethods(): array
	{
		return self::StandardControlMethods;
	}


	/** @return array<string, int> */
	public static function getStandardMethodOrder(): array
	{
		return self::StandardMethodOrder;
	}


	private function collectPatch(MethodCall $node): void
	{
		/** @var list<MethodCall> $chain */
		$chain = [];
		$current = $node;
		while ($current instanceof MethodCall) {
			array_unshift($chain, $current);
			$current = $current->var;
		}

		if (!in_array($this->getMethodName($chain[0]), $this->controlMethods, true)) {
			return;
		}

		/** @var list<MethodCall> $methods */
		$methods = array_slice($chain, 1);
		if (count($methods) < 2) {
			return;
		}

		foreach ($methods as $method) {
			if (!array_key_exists($this->getMethodName($method) ?? '', $this->methodOrder)) {
				return;
			}
		}

		/** @var list<MethodCall> $sorted */
		$sorted = $methods;
		usort($sorted, fn(MethodCall $first, MethodCall $second): int => $this->methodOrder[$this->getMethodName($first)] <=> $this->methodOrder[$this->getMethodName($second)]);
		if ($this->sameOrder($methods, $sorted)) {
			return;
		}

		$rootEnd = $chain[0]->getAttribute('endFilePos');
		if (!is_int($rootEnd)) {
			return;
		}

		$cursor = $rootEnd + 1;
		/** @var array<int, string> $segments */
		$segments = [];
		foreach ($methods as $method) {
			$methodEnd = $method->getAttribute('endFilePos');
			if (!is_int($methodEnd) || $methodEnd < $cursor) {
				return;
			}

			$methodNamePosition = $method->name->getAttribute('startFilePos');
			if (!is_int($methodNamePosition) || $this->containsComment($cursor, $methodNamePosition)) {
				return;
			}

			$length = $methodEnd + 1 - $cursor;
			$segment = substr($this->source, $cursor, $length);

			$segments[spl_object_id($method)] = $segment;
			$cursor = $methodEnd + 1;
		}

		$replacement = '';
		foreach ($sorted as $method) {
			$replacement .= $segments[spl_object_id($method)];
		}

		$this->patches[] = [
			'start' => $rootEnd + 1,
			'length' => $cursor - ($rootEnd + 1),
			'replacement' => $replacement,
		];
	}


	private function containsComment(int $start, int $end): bool
	{
		$fragment = substr($this->source, $start, $end - $start);
		foreach (token_get_all('<?php ' . $fragment . 'method();') as $token) {
			if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				return true;
			}
		}

		return false;
	}


	private function getMethodName(MethodCall $method): ?string
	{
		return $method->name instanceof Identifier ? $method->name->toString() : null;
	}


	/**
	 * @param list<MethodCall> $first
	 * @param list<MethodCall> $second
	 */
	private function sameOrder(array $first, array $second): bool
	{
		foreach ($first as $index => $method) {
			if ($method !== $second[$index]) {
				return false;
			}
		}

		return true;
	}
}
