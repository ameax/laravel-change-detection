<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;

class CompositeHashCalculator
{
    private MySQLHashCalculator $attributeCalculator;

    private DependencyHashCalculator $dependencyCalculator;

    private string $hashAlgorithm;

    public function __construct(
        MySQLHashCalculator $attributeCalculator,
        DependencyHashCalculator $dependencyCalculator
    ) {
        $this->attributeCalculator = $attributeCalculator;
        $this->dependencyCalculator = $dependencyCalculator;
        $this->hashAlgorithm = config('change-detection.hash_algorithm', 'md5');
    }

    public function calculate(Hashable $model): string
    {
        $attributeHash = $this->attributeCalculator->calculateAttributeHash($model);
        $dependencyHash = $this->dependencyCalculator->calculate($model);

        if ($dependencyHash === null) {
            return $attributeHash;
        }

        $combinedData = $attributeHash.'|'.$dependencyHash;

        return match ($this->hashAlgorithm) {
            'sha256' => hash('sha256', $combinedData),
            default => md5($combinedData)
        };
    }

    public function calculateBulk(string $modelClass, array $modelIds): array
    {
        $attributeHashes = $this->attributeCalculator->calculateAttributeHashBulk($modelClass, $modelIds);
        $dependencyHashes = $this->dependencyCalculator->calculateBulk($modelClass, $modelIds);

        $compositeHashes = [];

        foreach ($modelIds as $modelId) {
            $attributeHash = $attributeHashes[$modelId] ?? null;
            $dependencyHash = $dependencyHashes[$modelId] ?? null;

            if ($attributeHash === null) {
                $compositeHashes[$modelId] = null;

                continue;
            }

            if ($dependencyHash === null) {
                $compositeHashes[$modelId] = $attributeHash;

                continue;
            }

            $combinedData = $attributeHash.'|'.$dependencyHash;
            $compositeHashes[$modelId] = match ($this->hashAlgorithm) {
                'sha256' => hash('sha256', $combinedData),
                default => md5($combinedData)
            };
        }

        return $compositeHashes;
    }

    public function getAttributeCalculator(): MySQLHashCalculator
    {
        return $this->attributeCalculator;
    }

    public function getDependencyCalculator(): DependencyHashCalculator
    {
        return $this->dependencyCalculator;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }
}
