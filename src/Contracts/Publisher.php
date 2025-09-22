<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Contracts;

use Illuminate\Database\Eloquent\Model;

interface Publisher
{
    /**
     * Publish the model data to the external system.
     *
     * @param  Model  $model  The model to publish
     * @param  array<string, mixed>  $data  The prepared data to publish
     * @return bool True if successful, false otherwise
     */
    public function publish(Model $model, array $data): bool;

    /**
     * Prepare the data for publishing.
     * This method should gather all necessary data from the model
     * and its relations that need to be sent to the external system.
     *
     * @param  Model  $model  The model to prepare data for
     * @return array<string, mixed> The prepared data
     */
    public function getData(Model $model): array;

    /**
     * Determine if the model should be published.
     * Can be used to filter out certain records or states.
     *
     * @param  Model  $model  The model to check
     * @return bool True if should publish, false otherwise
     */
    public function shouldPublish(Model $model): bool;

    /**
     * Get the maximum number of retry attempts for this publisher.
     */
    public function getMaxAttempts(): int;

    /**
     * Get the batch size for bulk processing.
     * Return 0 for unlimited batch size.
     */
    public function getBatchSize(): int;

    /**
     * Get the delay in milliseconds between individual publishes.
     * Return 0 for no delay.
     */
    public function getDelayMs(): int;

    /**
     * Get retry intervals in seconds for this publisher.
     * Array with attempt number as key and delay in seconds as value.
     * Example: [1 => 30, 2 => 300, 3 => 1800]
     * @return array<int, int>
     */
    public function getRetryIntervals(): array;

    /**
     * Determine if an exception should stop the entire job or just defer this record.
     *
     * @param  \Throwable  $exception  The exception that occurred
     * @return string 'stop_job', 'defer_record', or 'fail_record'
     */
    public function handlePublishException(\Throwable $exception): string;

    /**
     * Get the maximum number of validation errors before stopping the job.
     * Return 0 for unlimited.
     */
    public function getMaxValidationErrors(): int;

    /**
     * Get the maximum number of infrastructure/system errors before stopping the job.
     * These are errors that affect the entire publishing system (API timeouts, permissions, etc.).
     * Return 0 for unlimited.
     */
    public function getMaxInfrastructureErrors(): int;
}
