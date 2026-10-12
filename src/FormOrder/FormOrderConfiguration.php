<?php

declare(strict_types=1);

namespace Drago\FormOrder;

use InvalidArgumentException;
use Nette\Neon\Neon;
use RuntimeException;

final class FormOrderConfiguration
{
	private const MethodGroups = [
		'presentation' => 10,
		'choices' => 20,
		'state' => 25,
		'defaults' => 30,
		'input' => 35,
		'required' => 40,
		'validation' => 50,
	];

	/** @var list<string> */
	private array $controlMethods;

	/** @var array<string, int> */
	private array $methodOrder;


	/**
	 * @param list<mixed> $customControls
	 * @param array<array-key, mixed> $customMethods
	 */
	public function __construct(array $customControls = [], array $customMethods = [])
	{
		$this->controlMethods = array_values(array_unique([
			...FormElementOrderVisitor::getStandardControlMethods(),
			...$this->validateControls($customControls),
		]));
		$this->methodOrder = array_replace(
			FormElementOrderVisitor::getStandardMethodOrder(),
			$this->validateMethods($customMethods),
		);
	}


	public static function fromFile(?string $path): self
	{
		if ($path === null) {
			$path = getcwd() . '/form-order.neon';
			if (!is_file($path)) {
				return new self;
			}
		}

		if (!is_file($path)) {
			throw new RuntimeException("Configuration file not found: {$path}");
		}

		$data = Neon::decodeFile($path);
		if (!is_array($data) || !is_array($data['form-order'] ?? null)) {
			throw new InvalidArgumentException('Configuration must define a form-order section.');
		}

		$settings = $data['form-order'];
		if (array_diff(array_keys($settings), ['custom']) !== []) {
			throw new InvalidArgumentException('Only the custom section is supported in form-order configuration.');
		}

		$custom = $settings['custom'] ?? [];
		if (!is_array($custom) || array_diff(array_keys($custom), ['controls', 'methods']) !== []) {
			throw new InvalidArgumentException('The form-order.custom section accepts only controls and methods.');
		}

		$controls = $custom['controls'] ?? [];
		$methods = $custom['methods'] ?? [];
		if (!is_array($controls) || !array_is_list($controls) || !is_array($methods)) {
			throw new InvalidArgumentException('Custom controls must be a list and custom methods must be a mapping.');
		}

		return new self($controls, $methods);
	}


	/** @return list<string> */
	public function getControlMethods(): array
	{
		return $this->controlMethods;
	}


	/** @return array<string, int> */
	public function getMethodOrder(): array
	{
		return $this->methodOrder;
	}


	/**
	 * @param array<array-key, mixed> $controls
	 * @return list<string>
	 */
	private function validateControls(array $controls): array
	{
		$validated = [];
		foreach ($controls as $control) {
			if (!is_string($control) || preg_match('/^add[A-Z][A-Za-z0-9_]*$/D', $control) !== 1) {
				throw new InvalidArgumentException('Custom control names must be PHP method names beginning with add and an uppercase letter.');
			}

			$validated[] = $control;
		}

		return $validated;
	}


	/**
	 * @param array<array-key, mixed> $methods
	 * @return array<string, int>
	 */
	private function validateMethods(array $methods): array
	{
		$validated = [];
		foreach ($methods as $method => $order) {
			if (!is_string($method) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
				throw new InvalidArgumentException('Custom method names must be valid PHP method names.');
			}

			if (is_string($order) && isset(self::MethodGroups[$order])) {
				$validated[$method] = self::MethodGroups[$order];
				continue;
			}

			if (is_int($order)) {
				$validated[$method] = $order;
				continue;
			}

			throw new InvalidArgumentException("Invalid ordering for custom method {$method}. Use a known group or an integer priority.");
		}

		return $validated;
	}
}
