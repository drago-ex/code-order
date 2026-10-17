<?php

declare(strict_types=1);

use Drago\ClassOrder\ClassMemberOrderer;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

test('orders constants properties constructor and methods by visibility', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			/** Keeps this note with the hidden method. */
			private function hidden(): string { return 'hidden'; }

			protected function guarded(): string { return 'guarded'; }

			private string $privateValue;

			protected string $protectedValue;

			public string $publicValue;

			private const Hidden = 1;

			public const Visible = 2;

			public function __construct() {}

			public function visible(): string { return 'visible'; }
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same([
		'Hidden',
		'Visible',
		'publicValue',
		'protectedValue',
		'privateValue',
		'__construct',
		'visible',
		'guarded',
		'hidden',
	], array_map(static function ($member): string {
		if ($member instanceof PhpParser\Node\Stmt\ClassMethod) {
			return $member->name->toString();
		}

		if ($member instanceof PhpParser\Node\Stmt\Property) {
			return $member->props[0]->name->toString();
		}

		return $member->consts[0]->name->toString();
	}, $class->stmts));
	Assert::match('~function visible\(\): string \{ return \'visible\'; \}~', $ordered);
	Assert::match('~function guarded\(\): string \{ return \'guarded\'; \}~', $ordered);
	Assert::match('~function hidden\(\): string \{ return \'hidden\'; \}~', $ordered);
	Assert::match('~\/\*\* Keeps this note with the hidden method\. \*\/\s+private function hidden~', $ordered);
});

test('orders Nette Presenter methods according to the lifecycle through a custom base class', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		namespace App\Presentation;
		class ExamplePresenter extends BasePresenter
		{
			private function helper(): void {}

			protected function shutdown(): void {}

			public function renderDefault(): void {}

			public function handleSave(): void {}

			public function beforeRender(): void {}

			public function actionDefault(): void {}

			protected function injectServices(): void {}

			public function startup(): void {}

			public function __construct() {}

			public function afterRender(): void {}
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same([
		'__construct',
		'injectServices',
		'startup',
		'actionDefault',
		'handleSave',
		'beforeRender',
		'renderDefault',
		'afterRender',
		'shutdown',
		'helper',
	], array_map(static fn($method): string => $method->name->toString(), $class->getMethods()));
});

test('recognizes an imported Presenter alias and keeps equal-stage actions in source order', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		namespace App\Presentation;
		use Nette\Application\UI\Presenter as Base;
		class AliasPresenter extends Base
		{
			public function renderDefault(): void {}

			public function actionSecond(): void {}

			public function actionFirst(): void {}

			public function startup(): void {}
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same([
		'startup',
		'actionSecond',
		'actionFirst',
		'renderDefault',
	], array_map(static fn($method): string => $method->name->toString(), $class->getMethods()));
});

test('does not apply lifecycle ordering to an ordinary class with similarly named methods', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Ordinary
		{
			private function helper(): void {}

			public function renderDefault(): void {}

			public function actionDefault(): void {}
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same(['renderDefault', 'actionDefault', 'helper'], array_map(static fn($method): string => $method->name->toString(), $class->getMethods()));
});

test('does not move class members across an unsupported trait-use statement', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			private function beforeTrait(): void {}

			use ExampleTrait;

			public function afterTrait(): void {}
		}
		PHP;

	Assert::null($orderer->reorder($source));
});


test('does not separate trailing comments from class members', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			private int $privateValue; // Private value.
			public int $publicValue; // Public value.
			protected int $protectedValue;
		}
		PHP;

	Assert::null($orderer->reorder($source));
});


test('orders methods without visibility as public methods', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			private function hidden(): void {}

			function visible(): void {}
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same(['visible', 'hidden'], array_map(static fn($method): string => $method->name->toString(), $class->getMethods()));
});


test('orders asymmetric properties by their read visibility', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			private string $privateValue;

			private(set) string $publicReadValue;

			protected string $protectedValue;
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same(
		['publicReadValue', 'protectedValue', 'privateValue'],
		array_map(static fn($property): string => $property->props[0]->name->toString(), $class->getProperties()),
	);
});


test('keeps a comment after a use statement in place', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			use SmartObject; // magic properties

			private int $b;

			public int $a;
		}
		PHP;

	Assert::null($orderer->reorder($source));
});


test('keeps a comment on the line of the class brace in place', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example { // about the class
			private int $a;

			public int $b;
		}
		PHP;

	Assert::null($orderer->reorder($source));
});


test('does not change the declaration order of public properties through a promoted constructor', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Dto
		{
			public function __construct(public int $id) {}

			public string $name = '';
		}
		PHP;

	Assert::null($orderer->reorder($source));
});


test('moves a constructor that promotes only non-public properties', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Service
		{
			public function __construct(private Foo $foo) {}

			public string $name = '';
		}
		PHP;
	$expected = <<<'PHP'
		<?php
		class Service
		{
			public string $name = '';

			public function __construct(private Foo $foo) {}
		}
		PHP;

	Assert::same($expected, $orderer->reorder($source));
});


test('recognizes the constructor regardless of letter case', function () {
	$orderer = new ClassMemberOrderer;
	$source = <<<'PHP'
		<?php
		class Example
		{
			public function run(): void {}

			public function __Construct() {}
		}
		PHP;
	$ordered = $orderer->reorder($source);
	$parser = (new ParserFactory)->createForNewestSupportedVersion();
	$class = (new NodeFinder)->findFirstInstanceOf($parser->parse($ordered ?? ''), Class_::class);

	Assert::type(Class_::class, $class);
	Assert::same(['__Construct', 'run'], array_map(static fn($method): string => $method->name->toString(), $class->getMethods()));
});
