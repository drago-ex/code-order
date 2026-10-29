<?php declare(strict_types=1);

namespace Drago\ClassOrder;

use Drago\CodeOrder\ReorderGuard;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;


final class ClassMemberOrderer
{
	public function reorder(string $source): ?string
	{
		$statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
		if ($statements === null) {
			return null;
		}

		$visitor = new ClassMemberOrderVisitor($source);

		(new NodeTraverser(new NameResolver, $visitor))->traverse($statements);
		$patches = $visitor->getPatches();
		if ($patches === []) {
			return null;
		}

		usort($patches, static fn(array $first, array $second): int => $second['start'] <=> $first['start']);
		$original = $source;
		foreach ($patches as $patch) {
			$source = substr_replace($source, $patch['replacement'], $patch['start'], $patch['length']);
		}

		ReorderGuard::assertOnlyReordered($original, $source);

		return $source;
	}
}
