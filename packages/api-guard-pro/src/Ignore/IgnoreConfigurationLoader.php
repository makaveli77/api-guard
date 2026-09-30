<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Ignore;

use ApiGuard\Domain\Comparison\ChangeType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class IgnoreConfigurationLoader
{
    public function load(?string $path): IgnorePolicy
    {
        if ($path === null) {
            return new IgnorePolicy();
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new IgnoreConfigurationException(sprintf('Ignore configuration is missing or unreadable: %s', $path));
        }

        try {
            $configuration = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new IgnoreConfigurationException('Invalid ignore configuration YAML: ' . $exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            throw new IgnoreConfigurationException('Unable to read ignore configuration: ' . $exception->getMessage(), 0, $exception);
        }

        $configuration = $this->mapping($configuration, 'configuration');
        $this->assertAllowedKeys($configuration, ['version', 'ignore'], 'configuration');

        if (($configuration['version'] ?? null) !== 1) {
            throw new IgnoreConfigurationException('Ignore configuration version must be 1.');
        }

        $entries = $configuration['ignore'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new IgnoreConfigurationException('The "ignore" value must be a list of rules.');
        }

        $rules = [];
        foreach ($entries as $index => $entry) {
            $label = sprintf('ignore rule at index %d', $index);
            $entry = $this->mapping($entry, $label);
            $this->assertAllowedKeys($entry, ['type', 'path', 'reason', 'message'], $label);

            $typeValue = $entry['type'] ?? null;
            $pathValue = $entry['path'] ?? null;
            $reason = $entry['reason'] ?? null;
            $message = $entry['message'] ?? null;

            if (!is_string($typeValue) || ($type = ChangeType::tryFrom($typeValue)) === null) {
                throw new IgnoreConfigurationException($label . ' has an unknown change type.');
            }
            if (!is_string($pathValue) || !is_string($reason)) {
                throw new IgnoreConfigurationException($label . ' requires string type, path, and reason values.');
            }
            if ($message !== null && !is_string($message)) {
                throw new IgnoreConfigurationException($label . ' message must be a string when provided.');
            }

            try {
                $rules[] = new IgnoreRule($type, $pathValue, $reason, $message);
            } catch (\InvalidArgumentException $exception) {
                throw new IgnoreConfigurationException($label . ': ' . $exception->getMessage(), 0, $exception);
            }
        }

        return new IgnorePolicy($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new IgnoreConfigurationException(sprintf('The %s must be a YAML mapping.', $label));
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new IgnoreConfigurationException(sprintf('The %s contains a non-string key.', $label));
            }

            $mapping[$key] = $item;
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param list<string> $allowedKeys
     */
    private function assertAllowedKeys(array $mapping, array $allowedKeys, string $label): void
    {
        foreach (array_keys($mapping) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new IgnoreConfigurationException(sprintf('Unknown key "%s" in %s.', $key, $label));
            }
        }
    }
}