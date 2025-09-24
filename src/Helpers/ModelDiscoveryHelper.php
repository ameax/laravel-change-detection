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
     * @param Model&Hashable $model
     * @return array<string, class-string> Array with relation names as keys and model classes as values
     */
    public static function getDependencyModelsFromModel(Model $model): array
    {
        if (!$model instanceof Hashable) {
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
     * @param string $morphName
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
     * Get model class from a model instance and relation name.
     *
     * @param Model $model
     * @param string $relationName
     * @return class-string|null
     */
    public static function getModelClassFromRelation(Model $model, string $relationName): ?string
    {
        if (!method_exists($model, $relationName)) {
            return null;
        }

        try {
            $relation = $model->$relationName();
            $relatedModel = $relation->getRelated();
            return get_class($relatedModel);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get dependency models from a morph name.
     *
     * @param string $morphName
     * @return array<string, class-string> Array with relation names as keys and model classes as values
     */
    public static function getDependencyModelsFromMorphName(string $morphName): array
    {
        $modelClass = self::getModelClassFromMorphName($morphName);

        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass();

        if (!$model instanceof Model) {
            return [];
        }

        return self::getDependencyModelsFromModel($model);
    }

    /**
     * Get all model classes that need to be synced for a given morph name.
     * Includes the main model and all its dependencies.
     *
     * @param string $morphName
     * @return array<class-string> Array of model classes
     */
    public static function getAllModelsForSync(string $morphName): array
    {
        $mainModelClass = self::getModelClassFromMorphName($morphName);

        if (!$mainModelClass) {
            return [];
        }

        $models = [$mainModelClass];
        $dependencyModels = self::getDependencyModelsFromMorphName($morphName);

        foreach ($dependencyModels as $dependencyClass) {
            if (!in_array($dependencyClass, $models)) {
                $models[] = $dependencyClass;
            }
        }

        return $models;
    }

    /**
     * Check if a model class implements Hashable interface.
     *
     * @param string $modelClass
     * @return bool
     */
    public static function isHashable(string $modelClass): bool
    {
        if (!class_exists($modelClass)) {
            return false;
        }

        $reflection = new \ReflectionClass($modelClass);
        return $reflection->implementsInterface(Hashable::class);
    }

    /**
     * Filter an array of model classes to only include Hashable models.
     *
     * @param array<class-string> $modelClasses
     * @return array<class-string>
     */
    public static function filterHashableModels(array $modelClasses): array
    {
        return array_filter($modelClasses, [self::class, 'isHashable']);
    }
}