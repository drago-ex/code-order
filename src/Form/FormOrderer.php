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
		$original = $source;
		$parser = (new ParserFactory)->createForNewestSupportedVersion();
		while (true) {
			$statements = $parser->parse($source);
			if ($statements === null) {
				return $source === $original ? null : $source;
			}

			$visitor = new FormElementOrderVisitor(
				$this->configuration->getControlMethods(),
				$this->configuration->getMethodOrder(),
				$source,
			);
			(new NodeTraverser($visitor))->traverse($statements);

			$patches = $this->getNonOverlappingPatches($visitor->getPatches());
			if ($patches === []) {
				break;
			}

			usort($patches, static fn(array $first, array $second): int => $second['start'] <=> $first['start']);
			$previous = $source;
			foreach ($patches as $patch) {
				$source = substr_replace($source, $patch['replacement'], $patch['start'], $patch['length']);
			}

			if ($source === $previous) {
				break;
			}
		}

		return $source === $original ? null : $source;
	}


	/**
	 * @param list<array{start: int, length: int, replacement: string}> $patches
	 * @return list<array{start: int, length: int, replacement: string}>
	 */
	private function getNonOverlappingPatches(array $patches): array
	{
		usort($patches, static fn(array $first, array $second): int => $first['length'] <=> $second['length']);
		$selected = [];
		foreach ($patches as $patch) {
			$end = $patch['start'] + $patch['length'];
			$overlaps = false;
			foreach ($selected as $other) {
				$otherEnd = $other['start'] + $other['length'];
				if ($patch['start'] < $otherEnd && $other['start'] < $end) {
					$overlaps = true;
					break;
				}
			}

			if (!$overlaps) {
				$selected[] = $patch;
			}
		}

		return $selected;
	}
}
