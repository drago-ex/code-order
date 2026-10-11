<?php

declare(strict_types=1);

namespace Drago\FormOrder;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

final class FormOrderer
{
	public function __construct(
		private FormOrderConfiguration $configuration = new FormOrderConfiguration,
	) {
	}


	public function reorder(string $source): ?string
	{
		$parser = (new ParserFactory)->createForNewestSupportedVersion();
		$statements = $parser->parse($source);
		if ($statements === null) {
			return null;
		}

		$visitor = new FormElementOrderVisitor(
			$this->configuration->getControlMethods(),
			$this->configuration->getMethodOrder(),
			$source,
		);
		(new NodeTraverser($visitor))->traverse($statements);

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
