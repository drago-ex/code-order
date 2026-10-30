<?php declare(strict_types=1);

namespace Drago\CodeOrder;

use PhpParser\Error;
use PhpParser\ParserFactory;
use RuntimeException;
use function is_array;


/** Safety net for the orderers: reordering may only permute code. */
final class ReorderGuard
{
	/**
	 * Fails when the result does not parse or when its tokens differ from the original
	 * (ignoring whitespace and order), so a bug in an orderer can never damage a file.
	 */
	public static function assertOnlyReordered(string $before, string $after): void
	{
		try {
			(new ParserFactory)->createForNewestSupportedVersion()->parse($after);
		} catch (Error $exception) {
			throw new RuntimeException(
				'Reordering would produce invalid code, the file was left unchanged: ' . $exception->getMessage(),
				0,
				$exception,
			);
		}

		if (self::tokens($before) !== self::tokens($after)) {
			throw new RuntimeException('Reordering would change more than the order of the code, the file was left unchanged.');
		}
	}


	/** @return list<string> */
	private static function tokens(string $code): array
	{
		$tokens = [];
		foreach (token_get_all($code) as $token) {
			if (!is_array($token)) {
				$tokens[] = $token;
			} elseif ($token[0] !== T_WHITESPACE) {
				$tokens[] = $token[0] . ':' . $token[1];
			}
		}

		sort($tokens, SORT_STRING);
		return $tokens;
	}
}
