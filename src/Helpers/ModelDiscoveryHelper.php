<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Helpers;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModelDiscoveryHelper
{
    /**
     * Get dependency model classes from a model instance.
     *
     * @param  Model&Hashable  $model
     * @return array<string, class-string> Array with relation names as keys and model classes as values
     */
    public static function getDependencyModelsFromModel(Model $model): array
    {
        if (! $model instanceof Hashable) {
            return [];
        }

        $dependencies = $model->getHashCompositeDependencies();
        $dependencyModels = [];

        foreach ($dependencies as $relationName) {
            if (method_exists($model, $relationName)) {
                $relation = $model->$relationName();
                $relatedModel = $relation->getRelated();
                $dependencyModels[$relationName] = get_class($relatedModel);
            }
        }

        return $dependencyModels;
    }

    /**
     * Get model class from morph name.
     *
     * @return class-string|null
     */
    public static function getModelClassFromMorphName(string $morphName): ?string
    {
        $morphMap = Relation::morphMap();

        // First check if it's in the morph map
        if (isset($morphMap[$morphName])) {
            return $morphMap[$morphName];
        }

        // If not in morph map, check if it's already a valid class name
        if (class_exists($morphName)) {
            return $morphName;
        }

        return null;
    }

    /**
     * Get dependency models from a morph name.
     *
     * @return array<string, class-string> Array with relation names as keys and model classes as values
     */
    public static function getDependencyModelsFromMorphName(string $morphName): array
    {
        $modelClass = self::getModelClassFromMorphName($morphName);

        if (! $modelClass || ! class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass;

        if (! $model instanceof Model) {
            return [];
        }

        return self::getDependencyModelsFromModel($model);
    }

    /**
     * Get all model classes that need to be synced for a given morph name.
     * Includes the main model and all its dependencies.
     * Dependencies are returned first, then the main model, to ensure proper hash creation order.
     *
     * @return array<class-string> Array of model classes
     */
    public static function getAllModelsForSync(string $morphName): array
    {
        $mainModelClass = self::getModelClassFromMorphName($morphName);

        if (! $mainModelClass) {
            return [];
        }

        $models = [];
        $dependencyModels = self::getDependencyModelsFromMorphName($morphName);

        // Add dependencies first
        foreach ($dependencyModels as $dependencyClass) {
            $models[] = $dependencyClass;
        }

        // Add main model last
        $models[] = $mainModelClass;

        return $models;
    }

    /**
     * Check if a model class implements Hashable interface.
     */
    public static function isHashable(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        $reflection = new \ReflectionClass($modelClass);

        return $reflection->implementsInterface(Hashable::class);
    }
}
