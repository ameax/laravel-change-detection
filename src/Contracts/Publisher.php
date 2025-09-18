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
     * @param  array  $data  The prepared data to publish
     * @return bool True if successful, false otherwise
     */
    public function publish(Model $model, array $data): bool;

    /**
     * Prepare the data for publishing.
     * This method should gather all necessary data from the model
     * and its relations that need to be sent to the external system.
     *
     * @param  Model  $model  The model to prepare data for
     * @return array The prepared data
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
}