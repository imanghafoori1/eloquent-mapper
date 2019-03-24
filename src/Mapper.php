<?php

namespace Railken\EloquentMapper;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Railken\Bag;

class Mapper
{
    public static $relations = [];

    public static function addRelation(string $name, Closure $callback)
    {
        if (!isset(static::$relations['*'])) {
            static::$relations['*'] = [];
        }

        static::$relations['*'][] = $name;

        \Illuminate\Database\Eloquent\Builder::macro($name, function () use ($name, $callback) {
            unset(static::$macros[$name]);

            return $callback;
        });
    }

    public static function findRelationByKey(array $relations, string $needle)
    {
        foreach ($relations as $key => $relation) {
            if ($needle === $key) {
                return $relation;
            }
        }

        return null;
    }

    public static function resolveRelations(string $class, array $relations)
    {
        $resolved = Collection::make();

        foreach ($relations as $relation) {
            $resolved = $resolved->merge(static::resolveRelation($class, $relation));
        }

        return $resolved;
    }

    public static function resolveRelation(string $class, string $key)
    {
        $resolved = Collection::make();

        $keys = explode('.', $key);

        foreach ($keys as $i => $key) {
            $relation = static::findRelationByKey(static::relations($class), $key);

            if (!$relation) {
                return Collection::make();
            }

            $class = $relation->model;

            $resolved[implode('.', array_slice($keys, 0, $i + 1))] = $relation;
        }

        return $resolved;
    }

    public static function isValidNestedRelation(string $class, string $key)
    {
        return static::resolveRelation($class, $key)->count() !== 0;
    }

    public static function relations(string $class)
    {
        $cacheKey = sprintf('relations:%s', $class);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $finder = new RelationFinder();
        $relations = $finder->getModelRelations($class)->toArray();

        foreach ($relations as $key => $relation) {
            if ($relation->type === 'MorphTo') {
                unset($relations[$key]);
            }
        }

        foreach ($relations as $key => $relation) {
            if (!static::findSameRelation($relations, $relation)) {
                $bag->set('children', static::relations($relation->model));
            }
        }

        Cache::forever($cacheKey, $relations);

        return $relations;
    }

    public static function findSameRelation(array $relations, Bag $needle)
    {
        foreach ($relations as $key => $relation) {
            $bag = $relation->remove('children');

            if (count(array_diff($bag->toArray(), $needle->toArray())) === 0) {
                return true;
            }

            if ($relation->children && static::findSameRelation($relation->children, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function mapKeysRelation(string $class)
    {
        return static::mapRelations($class, function ($prefix, $relation) {
            $key = $prefix ? $prefix.'.'.$relation->name : $relation->name;

            return [$key, [$key]];
        });
    }

    public static function mapRelations(string $class, Closure $parser)
    {
        $relations = static::relations($class);

        $closure = function ($relations, $prefix = '') use (&$closure, $parser) {
            $keys = [];

            foreach ((array) $relations as $relation) {
                list($newPrefix, $newKeys) = $parser($prefix, $relation);

                if ($newPrefix !== null) {
                    $keys = array_merge($keys, $newKeys, $closure($relation->children, $newPrefix));
                }
            }

            return $keys;
        };

        return $closure($relations);
    }
}
