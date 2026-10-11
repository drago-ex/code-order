<?php

declare(strict_types=1);

namespace Drago\ClassOrder;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class ClassMemberOrderer
{
	public function reorder(string $source): ?string
	{
		$statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
		if ($statements === null) {
			return null;
		}

		$visitor = new class ($source) extends NodeVisitorAbstract {
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
					if ($name === '__construct') {
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
		};

		(new NodeTraverser(new NameResolver, $visitor))->traverse($statements);
		$patches = $visitor->getPatches();
		if ($patches === []) {
			return null;
		}

		usort($patches, static fn(array $first, array $second): int => $second['start'] <=> $first['start']);
		foreach ($patches as $patch) {
			$source = substr_replace($source, $patch['replacement'], $patch['start'], $patch['length']);
		}

		return $source;
	}
}
