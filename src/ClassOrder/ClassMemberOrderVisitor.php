<?php

declare(strict_types=1);

namespace Drago\ClassOrder;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects patches that reorder the members of top-level classes: constants, properties,
 * constructor, methods (by visibility; presenters by their lifecycle).
 */
final class ClassMemberOrderVisitor extends NodeVisitorAbstract
{
	/** @var list<Node> */
	private array $nodeStack = [];

	/** @var list<array{start: int, length: int, replacement: string}> */
	private array $patches = [];


	public function __construct(
		private string $source,
	) {
	}


	public function enterNode(Node $node): ?Node
	{
		$this->nodeStack[] = $node;
		return null;
	}


	public function leaveNode(Node $node): ?Node
	{
		$parentStack = array_slice($this->nodeStack, 0, -1);
		$hasClassLikeParent = count(array_filter($parentStack, static fn(Node $parent): bool => $parent instanceof ClassLike)) > 0;
		if ($node instanceof ClassLike && !$hasClassLikeParent) {
			$this->collectPatches($node);
		}

		array_pop($this->nodeStack);
		return null;
	}


	/** @return list<array{start: int, length: int, replacement: string}> */
	public function getPatches(): array
	{
		return $this->patches;
	}


	private function collectPatches(ClassLike $class): void
	{
		$isPresenter = $class instanceof Class_
			&& $class->extends !== null
			&& str_ends_with(strtolower($class->extends->toString()), 'presenter');
		$runStart = $this->getBodyStart($class);
		if ($runStart === null) {
			return;
		}

		$run = [];
		foreach ($class->stmts as $statement) {
			if ($this->getRank($statement, $isPresenter) !== null) {
				$run[] = $statement;
				continue;
			}

			$this->collectRunPatch($run, $runStart, $this->getNodeStart($statement), $isPresenter);
			$run = [];
			$end = $statement->getAttribute('endFilePos');
			if (!is_int($end)) {
				return;
			}

			$runStart = $end + 1;
		}

		$this->collectRunPatch($run, $runStart, $class->getAttribute('endFilePos'), $isPresenter);
	}


	/** @param list<Node> $run */
	private function collectRunPatch(array $run, int $runStart, ?int $boundary, bool $isPresenter): void
	{
		if (count($run) < 2) {
			return;
		}

		/** @var list<array{node: Node, rank: int, index: int}> $ranked */
		$ranked = [];
		foreach ($run as $index => $statement) {
			$rank = $this->getRank($statement, $isPresenter);
			if ($rank === null) {
				return;
			}

			$ranked[] = ['node' => $statement, 'rank' => $rank, 'index' => $index];
		}

		$sorted = $ranked;
		usort($sorted, static fn(array $first, array $second): int => [$first['rank'], $first['index']] <=> [$second['rank'], $second['index']]);
		$sameOrder = true;
		foreach ($ranked as $index => $item) {
			if ($item['node'] !== $sorted[$index]['node']) {
				$sameOrder = false;
				break;
			}
		}

		if ($sameOrder) {
			return;
		}

		// Promoted public properties are declared where the constructor stands, which
		// is visible in json_encode(), foreach and var_dump().
		if ($this->changesPublicPropertyOrder($ranked, $sorted)) {
			return;
		}

		$lastNode = $run[count($run) - 1];
		$lastEnd = $lastNode->getAttribute('endFilePos');
		if (!is_int($lastEnd)) {
			return;
		}

		if ($boundary !== null && $this->containsComment($lastEnd + 1, $boundary)) {
			return;
		}

		$declarations = [];
		$gaps = [];
		$cursor = $runStart;
		foreach ($run as $statement) {
			$start = $this->getNodeStart($statement);
			$end = $statement->getAttribute('endFilePos');
			if (
				!is_int($start)
				|| !is_int($end)
				|| $start < $cursor
				|| $end < $start
				|| $this->hasTrailingComment($end)
				|| $this->hasLeadingInlineComment($cursor, $statement)
			) {
				return;
			}

			if ($this->hasUnattachedComment($cursor, $start, $statement)) {
				return;
			}

			$gap = substr($this->source, $cursor, $start - $cursor);
			$declaration = substr($this->source, $start, $end + 1 - $start);

			$gaps[] = $gap;
			$declarations[spl_object_id($statement)] = $declaration;
			$cursor = $end + 1;
		}

		$replacement = $gaps[0];
		foreach ($sorted as $index => $item) {
			$replacement .= $declarations[spl_object_id($item['node'])];
			if (isset($gaps[$index + 1])) {
				$replacement .= $gaps[$index + 1];
			}
		}

		$this->patches[] = [
			'start' => $runStart,
			'length' => $lastEnd + 1 - $runStart,
			'replacement' => $replacement,
		];
	}


	/**
	 * @param list<array{node: Node, rank: int, index: int}> $before
	 * @param list<array{node: Node, rank: int, index: int}> $after
	 */
	private function changesPublicPropertyOrder(array $before, array $after): bool
	{
		$positionBefore = [];
		foreach ($before as $position => $item) {
			$positionBefore[spl_object_id($item['node'])] = $position;
		}

		$positionAfter = [];
		foreach ($after as $position => $item) {
			$positionAfter[spl_object_id($item['node'])] = $position;
		}

		foreach ($before as $constructor) {
			if (!$constructor['node'] instanceof ClassMethod || !$this->hasPromotedPublicParameter($constructor['node'])) {
				continue;
			}

			$constructorId = spl_object_id($constructor['node']);
			foreach ($before as $property) {
				if (!$property['node'] instanceof Property || !$property['node']->isPublic()) {
					continue;
				}

				$propertyId = spl_object_id($property['node']);
				if (($positionBefore[$constructorId] < $positionBefore[$propertyId]) !== ($positionAfter[$constructorId] < $positionAfter[$propertyId])) {
					return true;
				}
			}
		}

		return false;
	}


	private function hasPromotedPublicParameter(ClassMethod $method): bool
	{
		if (strtolower($method->name->toString()) !== '__construct') {
			return false;
		}

		foreach ($method->params as $parameter) {
			if (!$parameter->isPromoted()) {
				continue;
			}

			if (($parameter->flags & Modifiers::PUBLIC) !== 0 || ($parameter->flags & Modifiers::VISIBILITY_MASK) === 0) {
				return true;
			}
		}

		return false;
	}


	/**
	 * PHP-Parser attaches a comment to the node that follows it, even when it is written
	 * at the end of the previous line (after the class brace or after "use Trait;").
	 * Such a comment must not travel with that node.
	 */
	private function hasLeadingInlineComment(int $cursor, Node $node): bool
	{
		$first = PHP_INT_MAX;
		foreach ($node->getComments() as $comment) {
			$position = $comment->getStartFilePos();
			if ($position < $cursor) {
				return true;
			}

			$first = min($first, $position);
		}

		return $first !== PHP_INT_MAX
			&& !str_contains(substr($this->source, $cursor, $first - $cursor), "\n");
	}


	private function getBodyStart(ClassLike $class): ?int
	{
		if ($class->stmts === []) {
			return null;
		}

		$start = $class->getAttribute('startFilePos');
		$firstStatementStart = $this->getNodeStart($class->stmts[0]);
		if (!is_int($start) || $firstStatementStart === null || $firstStatementStart < $start) {
			return null;
		}

		$fragment = substr($this->source, $start, $firstStatementStart - $start);

		$offset = $start;
		$firstToken = true;
		foreach (token_get_all('<?php ' . $fragment) as $token) {
			$tokenText = is_array($token) ? $token[1] : $token;
			if ($firstToken && is_array($token) && $token[0] === T_OPEN_TAG) {
				$firstToken = false;
				continue;
			}

			$firstToken = false;
			if ($token === '{') {
				return $offset + 1;
			}

			$offset += strlen($tokenText);
		}

		return null;
	}


	private function getRank(Node $node, bool $isPresenter): ?int
	{
		if ($node instanceof ClassConst || $node instanceof EnumCase) {
			return 10;
		}

		if ($node instanceof Property) {
			return 20 + $this->getVisibilityRank($node);
		}

		if ($node instanceof ClassMethod) {
			$name = $node->name->toString();
			if (strtolower($name) === '__construct') {
				return 30;
			}

			if ($isPresenter) {
				$lifecycleRank = $this->getPresenterLifecycleRank($name);
				if ($lifecycleRank !== null) {
					return 40 + $lifecycleRank;
				}

				return 50 + $this->getVisibilityRank($node);
			}

			return 40 + $this->getVisibilityRank($node);
		}

		return null;
	}


	private function getPresenterLifecycleRank(string $name): ?int
	{
		if (preg_match('/^inject[A-Z]/', $name) === 1) {
			return 1;
		}

		foreach ([
			'startup' => 2,
			'action' => 3,
			'handle' => 4,
			'beforeRender' => 5,
			'render' => 6,
			'afterRender' => 7,
			'shutdown' => 8,
		] as $method => $rank) {
			if ($name === $method || preg_match('/^' . $method . '[A-Z]/', $name) === 1) {
				return $rank;
			}
		}

		return null;
	}


	private function getVisibilityRank(ClassMethod|Property $node): int
	{
		if ($node->isPublic()) {
			return 0;
		}

		if ($node->isProtected()) {
			return 1;
		}

		return 2;
	}


	private function getNodeStart(Node $node): ?int
	{
		$start = $node->getAttribute('startFilePos');
		if (!is_int($start)) {
			return null;
		}

		foreach ($node->getComments() as $comment) {
			$commentStart = $comment->getStartFilePos();
			if ($commentStart >= 0) {
				$start = min($start, $commentStart);
			}
		}

		return $start;
	}


	private function hasUnattachedComment(int $gapStart, ?int $nodeStart, Node $node): bool
	{
		if ($nodeStart === null || $nodeStart < $gapStart) {
			return true;
		}

		$fragment = substr($this->source, $gapStart, $nodeStart - $gapStart);

		$attachedComments = [];
		foreach ($node->getComments() as $comment) {
			$attachedComments[$comment->getStartFilePos()] = $comment->getEndFilePos();
		}

		$offset = $gapStart;
		foreach (token_get_all('<?php ' . $fragment) as $token) {
			$tokenText = is_array($token) ? $token[1] : $token;
			if (
				is_array($token)
				&& in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
				&& !isset($attachedComments[$offset - 6])
			) {
				return true;
			}

			$offset += strlen($tokenText);
		}

		return false;
	}


	private function containsComment(int $start, int $end): bool
	{
		if ($end < $start) {
			return true;
		}

		$fragment = substr($this->source, $start, $end - $start);

		foreach (token_get_all('<?php ' . $fragment) as $token) {
			if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				return true;
			}
		}

		return false;
	}


	private function hasTrailingComment(int $end): bool
	{
		$lineEnd = strpos($this->source, "\n", $end + 1);
		$lineEnd = $lineEnd === false ? strlen($this->source) : $lineEnd;
		$fragment = substr($this->source, $end + 1, $lineEnd - $end - 1);

		foreach (token_get_all('<?php ' . $fragment) as $token) {
			if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				return true;
			}
		}

		return false;
	}
}
