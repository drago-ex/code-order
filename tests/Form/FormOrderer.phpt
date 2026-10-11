<?php

declare(strict_types=1);

use Drago\FormOrder\FormOrderConfiguration;
use Drago\FormOrder\FormOrderer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

test('reorders existing control calls without changing their arguments', function () {
	$orderer = new FormOrderer(new FormOrderConfiguration);
	$source = <<<'PHP'
		<?php
		$form->addText('username')
			->addRule(Form::MaxLength, 'Too long', 40)
			->setRequired()
			->setDefaultValue('guest')
			->setHtmlAttribute('class', 'form-control');
		PHP;
	$expected = <<<'PHP'
		<?php
		$form->addText('username')
			->setHtmlAttribute('class', 'form-control')
			->setDefaultValue('guest')
			->setRequired()
			->addRule(Form::MaxLength, 'Too long', 40);
		PHP;

	Assert::same($expected, $orderer->reorder($source));
});

test('leaves a control chain unchanged when it contains an unsupported call', function () {
	$orderer = new FormOrderer(new FormOrderConfiguration);
	$source = <<<'PHP'
		<?php
		$form->addText('username')
			->addRule(Form::MaxLength, 'Too long', 40)
			->setCustomBehavior()
			->setRequired();
		PHP;

	Assert::null($orderer->reorder($source));
});

test('keeps calls with equal priority in their original order', function () {
	$orderer = new FormOrderer(new FormOrderConfiguration);
	$source = <<<'PHP'
		<?php
		$form->addText('username')
			->addRule(Form::MaxLength, 'Maximum length', 40)
			->addRule(Form::Pattern, 'Pattern', '[a-z]+')
			->setRequired()
			->setHtmlAttribute('class', 'form-control');
		PHP;
	$expected = <<<'PHP'
		<?php
		$form->addText('username')
			->setHtmlAttribute('class', 'form-control')
			->setRequired()
			->addRule(Form::MaxLength, 'Maximum length', 40)
			->addRule(Form::Pattern, 'Pattern', '[a-z]+');
		PHP;

	Assert::same($expected, $orderer->reorder($source));
});

test('orders configured custom controls and methods', function () {
	$configuration = new FormOrderConfiguration(
		customControls: ['addCustomSelect'],
		customMethods: ['setPlaceholder' => 'presentation'],
	);
	$orderer = new FormOrderer($configuration);
	$source = <<<'PHP'
		<?php
		$form->addCustomSelect('role')
			->addRule(Form::Required, 'Choose a role')
			->setPlaceholder('Select role');
		PHP;
	$expected = <<<'PHP'
		<?php
		$form->addCustomSelect('role')
			->setPlaceholder('Select role')
			->addRule(Form::Required, 'Choose a role');
		PHP;

	Assert::same($expected, $orderer->reorder($source));
});

test('does not move calls across comments', function () {
	$orderer = new FormOrderer(new FormOrderConfiguration);
	$source = <<<'PHP'
		<?php
		$form->addText('username')
			->addRule(Form::Required, 'Required')
			/* Keep this explanation with the validation call. */
			->setHtmlAttribute('class', 'form-control');
		PHP;

	Assert::null($orderer->reorder($source));
});
