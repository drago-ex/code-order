<?php

declare(strict_types=1);

use Drago\FormOrder\FormOrderConfiguration;
use Tester\Assert;
use Tester\FileMock;

require __DIR__ . '/../bootstrap.php';


test('loads custom controls, method groups and integer priorities', function () {
	$configuration = FormOrderConfiguration::fromFile(FileMock::create('
		form-order:
			custom:
				controls:
					- addDateRange
				methods:
					setPlaceholder: presentation
					setMask: 15
	', 'neon'));

	Assert::contains('addDateRange', $configuration->getControlMethods());
	Assert::contains('addText', $configuration->getControlMethods());
	Assert::same(10, $configuration->getMethodOrder()['setPlaceholder']);
	Assert::same(15, $configuration->getMethodOrder()['setMask']);
});


test('a missing configuration file is reported', function () {
	Assert::exception(
		fn() => FormOrderConfiguration::fromFile(__DIR__ . '/missing.neon'),
		RuntimeException::class,
		'Configuration file not found: %a%',
	);
});


test('invalid configurations are rejected with a clear message', function () {
	$cases = [
		'other: 1' => 'Configuration must define a form-order section.',
		"form-order:\n\tunknown: 1" => 'Only the custom section is supported in form-order configuration.',
		"form-order:\n\tcustom:\n\t\tother: 1" => 'The form-order.custom section accepts only controls and methods.',
		"form-order:\n\tcustom:\n\t\tcontrols:\n\t\t\tname: addX" => 'Custom controls must be a list and custom methods must be a mapping.',
		"form-order:\n\tcustom:\n\t\tcontrols:\n\t\t\t- textField" => 'Custom control names must be PHP method names beginning with add and an uppercase letter.',
		"form-order:\n\tcustom:\n\t\tmethods:\n\t\t\tsetX: unknownGroup" => 'Invalid ordering for custom method setX. Use a known group or an integer priority.',
	];

	foreach ($cases as $neon => $message) {
		Assert::exception(
			fn() => FormOrderConfiguration::fromFile(FileMock::create($neon, 'neon')),
			InvalidArgumentException::class,
			$message,
		);
	}
});
