<?php declare(strict_types=1);

use Drago\ClassOrder\ClassMemberOrderer;
use Drago\FormOrder\FormOrderer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function runCodeOrderCommand(string $command, array $arguments, ?string $cwd = null): array
{
	$packagePath = dirname(__DIR__, 2);
	$process = proc_open(
		[PHP_BINARY, $packagePath . '/bin/' . $command, ...$arguments],
		[
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		],
		$pipes,
		$cwd ?? $packagePath,
	);

	Assert::notSame(false, $process);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $output, $error];
}


test('form command reports ordering issues without modifying the file and fixes them on request', function () {
	$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'code-order-form-' . bin2hex(random_bytes(8)) . '.php';
	$source = <<<'PHP'
		<?php
		$form->addText('username')->addRule(Form::Required, 'Required')->setHtmlAttribute('class', 'input');
		PHP;

	try {
		file_put_contents($file, $source);
		[$status] = runCodeOrderCommand('form-order', [$file]);
		Assert::same(1, $status);
		Assert::same($source, file_get_contents($file));

		[$status] = runCodeOrderCommand('form-order', ['--fix', $file]);
		Assert::same(0, $status);
		Assert::null((new FormOrderer)->reorder(file_get_contents($file)));
	} finally {
		if (is_file($file)) {
			unlink($file);
		}
	}
});

test('class command reports ordering issues without modifying the file and fixes them on request', function () {
	$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'code-order-class-' . bin2hex(random_bytes(8)) . '.php';
	$source = <<<'PHP'
		<?php
		class ExamplePresenter extends BasePresenter
		{
			public function renderDefault(): void {}

			public function actionDefault(): void {}
		}
		PHP;

	try {
		file_put_contents($file, $source);
		[$status] = runCodeOrderCommand('class-order', [$file]);
		Assert::same(1, $status);
		Assert::same($source, file_get_contents($file));

		[$status] = runCodeOrderCommand('class-order', ['--fix', $file]);
		Assert::same(0, $status);
		Assert::null((new ClassMemberOrderer)->reorder(file_get_contents($file)));
	} finally {
		if (is_file($file)) {
			unlink($file);
		}
	}
});

test('the commands also run from a project whose own vendor does not contain this package', function () {
	$project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'code-order-project-' . bin2hex(random_bytes(8));
	mkdir($project . '/vendor', recursive: true);
	file_put_contents($project . '/vendor/autoload.php', '<?php ');

	try {
		[$status, $output] = runCodeOrderCommand('class-order', ['.'], $project);
		Assert::same(0, $status);
		Assert::contains('No ordering issues found', $output);
	} finally {
		unlink($project . '/vendor/autoload.php');
		rmdir($project . '/vendor');
		rmdir($project);
	}
});
