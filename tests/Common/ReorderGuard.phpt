<?php

declare(strict_types=1);

use Drago\CodeOrder\ReorderGuard;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('accepts a pure reordering', function () {
	ReorderGuard::assertOnlyReordered('<?php $a = 1; $b = 2;', '<?php $b = 2; $a = 1;');
	Assert::true(true);
});


test('rejects code that does not parse', function () {
	Assert::exception(
		fn() => ReorderGuard::assertOnlyReordered('<?php $a = 1; $b = 2;', '<?php $b = 2; $a = ;'),
		RuntimeException::class,
		'Reordering would produce invalid code%a%',
	);
});


test('rejects a result that has lost or changed code', function () {
	Assert::exception(
		fn() => ReorderGuard::assertOnlyReordered('<?php $a = 1; $b = 2;', '<?php $b = 2;'),
		RuntimeException::class,
		'Reordering would change more than the order of the code%a%',
	);
});
