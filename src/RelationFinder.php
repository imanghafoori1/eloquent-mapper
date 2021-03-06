<?php

namespace Railken\EloquentMapper;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Railken\Bag;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

class RelationFinder
{
    /**
     * Return all relations from a fully qualified model class name.
     *
     * @param string $model
     *
     * @return Collection
     *
     * @throws \ReflectionException
     */
    public function getModelRelations(string $model)
    {
        $class = new ReflectionClass($model);
        $traitMethods = Collection::make($class->getTraits())->map(function (ReflectionClass $trait) {
            return Collection::make($trait->getMethods(ReflectionMethod::IS_PUBLIC));
        })->flatten();
        $macroMethods = $this->getMacroMethods($model);

        $methods = Collection::make($class->getMethods(ReflectionMethod::IS_PUBLIC))
            ->merge($traitMethods)
            ->mapWithKeys(function (ReflectionMethod $method) {
                return [$method->getName() => $method];
            })
            ->merge($macroMethods)
            ->reject(function (ReflectionFunctionAbstract $functionAbstract) {
                return $functionAbstract->getNumberOfParameters() > 0 || !is_subclass_of((string) $functionAbstract->getReturnType(), Relation::class);
            });

        $relations = Collection::make();

        $methods = $methods->keys();

        // Detect imanghafoori1/eloquent-relativity
        $property = $class->getProperty('dynamicRelations');
        $property->setAccessible(true);
        $methods = $methods->merge(Collection::make($property->getValue('dynamicRelations'))->keys());

        $methods->map(function (string $functionName) use ($model, &$relations) {
            try {
                $return = app($model)->$functionName();
                $relations = $relations->merge($this->getRelationshipFromReturn($functionName, $return));
            } catch (\BadMethodCallException $e) {
            }
        });

        return $relations;
    }

    public function getKeyFromRelation(Relation $relation, string $keyName)
    {
        $getQualifiedKeyMethod = 'getQualified'.ucfirst($keyName).'Name';

        if (method_exists($relation, $getQualifiedKeyMethod)) {
            return last(explode('.', $relation->$getQualifiedKeyMethod()));
        }

        $getKeyMethod = 'get'.ucfirst($keyName);

        if (method_exists($relation, $getKeyMethod)) {
            return $relation->$getKeyMethod();
        }

        // relatedKey is protected before 5.7 in BelongsToMany

        $reflection = new \ReflectionClass($relation);

        $property = $reflection->getProperty($keyName);

        $property->setAccessible(true);

        return $property->getValue($relation);
    }

    /**
     * @param string $qualifiedKeyName
     *
     * @return mixed
     */
    protected function getParentKey(string $qualifiedKeyName)
    {
        $segments = explode('.', $qualifiedKeyName);

        return end($segments);
    }

    protected function getMacroMethods(string $model)
    {
        $query = app($model)->newModelQuery();
        $reflection = (new ReflectionClass($query));
        $property = $reflection->getProperty('macros');
        $property->setAccessible(true);

        return Collection::make($property->getValue($query))
            ->map(function ($callable) {
                return new ReflectionFunction($callable);
            });
    }

    protected function accessProtected($obj, $prop)
    {
        $reflection = new ReflectionClass($obj);
        $property = $reflection->getProperty($prop);
        $property->setAccessible(true);

        return $property->getValue($obj);
    }

    protected function getRelationshipFromReturn(string $name, $return)
    {
        if ($return instanceof Relation) {
            $localKey = null;
            $foreignKey = null;
            if ($return instanceof HasOneOrMany) {
                $localKey = $this->getKeyFromRelation($return, 'parentKey');
                $foreignKey = $this->getKeyFromRelation($return, 'foreignKey');
            }

            if ($return instanceof BelongsTo) {
                $foreignKey = $this->getKeyFromRelation($return, 'ownerKey');
                $localKey = $this->getKeyFromRelation($return, 'foreignKey');
            }

            $result = new Bag([
                'type'       => (new ReflectionClass($return))->getShortName(),
                'name'       => $name,
                'model'      => (new ReflectionClass($return->getRelated()))->getName(),
                'localKey'   => $localKey,
                'foreignKey' => $foreignKey,
                'scope'      => $this->getScopeRelation($return),
            ]);

            if ($return instanceof MorphOneOrMany || $return instanceof MorphToMany) {
                $result->set('morphType', $this->getKeyFromRelation($return, 'morphType'));
                $result->set('morphClass', $this->getKeyFromRelation($return, 'morphClass'));
            }

            if ($return instanceof BelongsToMany) {
                $result->set('table', $this->accessProtected($return, 'table'));
                $result->set('intermediate', $this->accessProtected($return, 'using'));
                $result->set('relatedPivotKey', $this->getKeyFromRelation($return, 'relatedPivotKey'));
                $result->set('foreignPivotKey', $this->getKeyFromRelation($return, 'foreignPivotKey'));
            }

            return [$name => $result];
        }
    }

    protected function skipClausesByClassRelation(Relation $relation)
    {
        if ($relation instanceof BelongsTo) {
            return 1;
        }

        if ($relation instanceof HasOneOrMany) {
            return 2;
        }

        if ($relation instanceof MorphToMany) {
            return 1;
        }

        if ($relation instanceof BelongsToMany) {
            return 3;
        }
    }

    protected function getScopeRelation($relation)
    {
        $relationBuilder = $relation->getQuery();

        $wheres = array_slice($relationBuilder->getQuery()->wheres, $this->skipClausesByClassRelation($relation));

        $return = [];

        foreach ($wheres as $n => $clause) {
            if ('Basic' === $clause['type']) {
                if ($n === 0) {
                    $partsColumn = explode('.', $clause['column']);

                    if (count($partsColumn) > 1) {
                        $clause['column'] = implode('.', array_slice($partsColumn, 1));
                    }
                }

                $return[] = $clause;
            }
        }

        return $return;
    }
}
