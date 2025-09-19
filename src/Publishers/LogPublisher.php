<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Publishers;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Contracts\Publisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class LogPublisher implements Publisher
{
    private string $logChannel;

    private string $logLevel;

    private bool $includeHashData;

    public function __construct(
        string $logChannel = 'default',
        string $logLevel = 'info',
        bool $includeHashData = true
    ) {
        $this->logChannel = $logChannel;
        $this->logLevel = $logLevel;
        $this->includeHashData = $includeHashData;
    }

    public function publish(Model $model, array $data): bool
    {
        try {
            $morphClass = $model instanceof Hashable ? $model->getMorphClass() : get_class($model);

            Log::channel($this->logChannel)->log($this->logLevel,
                "Hash change detected for {$morphClass}",
                $data
            );

            return true;

        } catch (\Exception $e) {
            $morphClass = $model instanceof Hashable ? $model->getMorphClass() : get_class($model);

            Log::channel($this->logChannel)->error(
                "Failed to log hash change for {$morphClass}",
                [
                    'model_id' => $model->getKey(),
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    public function getData(Model $model): array
    {
        $data = [
            'model_type' => $model instanceof Hashable ? $model->getMorphClass() : get_class($model),
            'model_id' => $model->getKey(),
            'timestamp' => now()->toISOString(),
        ];

        if ($this->includeHashData && $model instanceof Hashable) {
            $currentHash = $model->getCurrentHash();

            $data['hash_data'] = [
                'hash_id' => $currentHash?->id,
                'attribute_hash' => $currentHash?->attribute_hash,
                'composite_hash' => $currentHash?->composite_hash,
                'has_dependencies' => ! empty($model->getHashCompositeDependencies()),
            ];

            // Include sample model data for debugging
            $hashableAttributes = $model->getHashableAttributes();
            $data['model_data'] = collect($hashableAttributes)
                ->mapWithKeys(function ($attribute) use ($model) {
                    return [$attribute => $model->{$attribute}];
                })
                ->toArray();
        }

        return $data;
    }

    public function shouldPublish(Model $model): bool
    {
        // Always publish for logging (development purposes)
        return true;
    }

    public function getMaxAttempts(): int
    {
        // Don't retry log publishing
        return 1;
    }

    public function getBatchSize(): int
    {
        // Small batches for logging to avoid overwhelming logs
        return 10;
    }

    public function getDelayMs(): int
    {
        // 100ms delay for logging to prevent log spam
        return 100;
    }

    public function getRetryIntervals(): array
    {
        // Simple retry for logging: 30s, 5min, 30min
        return [
            1 => 30,
            2 => 300,
            3 => 1800,
        ];
    }

    public function handlePublishException(\Throwable $exception): string
    {
        // Infrastructure problems (permissions, config) should stop the job
        // Individual data problems can be deferred

        $message = $exception->getMessage();

        // File permission errors, disk space, configuration issues
        if (str_contains($message, 'Permission denied') ||
            str_contains($message, 'No space left') ||
            str_contains($message, 'Unable to create configured logger') ||
            str_contains($message, 'Failed to open stream')) {
            return 'stop_job';
        }

        // Individual data/validation issues
        return 'defer_record';
    }

    public function getMaxValidationErrors(): int
    {
        // Allow some validation errors for individual records
        return 50;
    }

    public function getMaxInfrastructureErrors(): int
    {
        // Log infrastructure problems should stop after 1 error
        return 1;
    }

    public function getLogChannel(): string
    {
        return $this->logChannel;
    }

    public function getLogLevel(): string
    {
        return $this->logLevel;
    }

    public function shouldIncludeHashData(): bool
    {
        return $this->includeHashData;
    }

    public function setLogChannel(string $channel): self
    {
        $this->logChannel = $channel;

        return $this;
    }

    public function setLogLevel(string $level): self
    {
        $this->logLevel = $level;

        return $this;
    }

    public function setIncludeHashData(bool $include): self
    {
        $this->includeHashData = $include;

        return $this;
    }
}
