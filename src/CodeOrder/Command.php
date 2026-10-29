<?php declare(strict_types=1);

namespace Drago\CodeOrder;

use Closure;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use function count, function_exists, strlen;


/**
 * Command line of form-order and class-order: options, scanning, check or fix, output and exit codes
 * (0 clean or fixed, 1 needs fix, 2 error).
 */
final class Command
{
	private const array SkippedDirectories = ['.git', 'vendor', 'node_modules', 'temp', 'cache'];

	private bool $useColors;


	public function __construct(
		private readonly string $name,
		private readonly string $description,
		private readonly bool $acceptsConfig,
		/** @var Closure(?string): (Closure(string): ?string) Gets the --config path, returns the reorder function. */
		private readonly Closure $createReorder,
	) {
		$this->useColors = function_exists('stream_isatty')
			&& stream_isatty(STDOUT)
			&& getenv('NO_COLOR') === false
			&& getenv('TERM') !== 'dumb';
	}


	/** @param list<string> $arguments */
	public function run(array $arguments): int
	{
		$fix = false;
		$configPath = null;
		$paths = [];

		for ($index = 0; $index < count($arguments); $index++) {
			$argument = $arguments[$index];
			if ($argument === '--help' || $argument === '-h') {
				$config = $this->acceptsConfig ? ' [--config FILE]' : '';
				$this->out("Usage: {$this->name} [-d path] [-f|--fix]{$config} [path ...]\n{$this->description}\n");
				return 0;
			}

			if ($argument === '--fix' || $argument === '-f') {
				$fix = true;
				continue;
			}

			if ($argument === '-d') {
				$path = $arguments[++$index] ?? null;
				if ($path === null) {
					return $this->fail('-d requires a directory or file path.');
				}

				$paths[] = $path;
				continue;
			}

			if ($this->acceptsConfig && $argument === '--config') {
				$configPath = $arguments[++$index] ?? null;
				if ($configPath === null) {
					return $this->fail('--config requires a file path.');
				}

				continue;
			}

			if ($this->acceptsConfig && str_starts_with($argument, '--config=')) {
				$configPath = substr($argument, strlen('--config='));
				continue;
			}

			if (str_starts_with($argument, '-')) {
				return $this->fail("Unknown option: {$argument}");
			}

			$paths[] = $argument;
		}

		$paths = $paths !== [] ? $paths : ['.'];
		$this->out('Mode: ' . $this->colorize($fix ? 'Fix' : 'Check', '36') . "\n");
		$this->out('Paths: ' . implode(', ', $paths) . "\n");

		$errors = 0;
		$files = $this->findFiles($paths, $errors);
		$this->out('Found ' . count($files) . " PHP files.\n");

		try {
			$reorder = ($this->createReorder)($configPath);
		} catch (Throwable $exception) {
			return $this->fail($exception->getMessage());
		}

		$needsFix = 0;
		$fixed = 0;
		foreach ($files as $file) {
			try {
				$source = file_get_contents($file);
				if ($source === false) {
					throw new RuntimeException('Unable to read file.');
				}

				$ordered = $reorder($source);
				if ($ordered === null) {
					continue;
				}

				if (!$fix) {
					$this->out($this->colorize('NEEDS FIX', '33') . ": {$file}\n");
					$needsFix++;
					continue;
				}

				if (file_put_contents($file, $ordered) === false) {
					throw new RuntimeException('Unable to write file.');
				}

				$this->out($this->colorize('FIXED', '32') . ": {$file}\n");
				$fixed++;
			} catch (Throwable $exception) {
				$this->error("{$file}: {$exception->getMessage()}");
				$errors++;
			}
		}

		if ($errors > 0) {
			$this->out($this->colorize("Done with {$errors} error(s).", '31') . "\n");
			return 2;
		}

		if ($fix) {
			$this->out($this->colorize($fixed > 0
				? 'Done. Fixed ' . $fixed . ' of ' . count($files) . ' PHP files.'
				: 'Done. No changes needed.', '32') . "\n");
			return 0;
		}

		if ($needsFix > 0) {
			$this->out($this->colorize("Done. {$needsFix} PHP files need reordering.", '33') . "\n");
			return 1;
		}

		$this->out($this->colorize('Done. No ordering issues found in ' . count($files) . ' PHP files.', '32') . "\n");
		return 0;
	}


	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function findFiles(array $paths, int &$errors): array
	{
		$files = [];
		foreach ($paths as $path) {
			$this->out("Scanning {$path}\n");
			if (is_file($path)) {
				if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
					$files[] = $path;
				}

				continue;
			}

			if (!is_dir($path)) {
				$this->error("Path not found: {$path}");
				$errors++;
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
					static fn(SplFileInfo $file): bool => !$file->isDir()
						|| !in_array($file->getFilename(), self::SkippedDirectories, true),
				),
			);

			foreach ($iterator as $file) {
				if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
					$files[] = $file->getPathname();
				}
			}
		}

		return array_values(array_unique($files));
	}


	private function fail(string $message): int
	{
		$this->error($message);
		return 2;
	}


	private function out(string $text): void
	{
		fwrite(STDOUT, $text);
	}


	private function error(string $message): void
	{
		fwrite(STDERR, $this->colorize('ERROR', '31') . ": {$message}\n");
	}


	private function colorize(string $text, string $color): string
	{
		return $this->useColors ? "\033[{$color}m{$text}\033[0m" : $text;
	}
}
