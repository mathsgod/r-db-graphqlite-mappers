<?php

namespace R\DB\GraphQLite\Mappers;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\Type;
use R\DB\Model;
use R\DB\Query;
use ReflectionClass;
use RuntimeException;
use TheCodingMachine\GraphQLite\FactoryContext;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeException;
use TheCodingMachine\GraphQLite\Mappers\TypeMapperInterface;
use TheCodingMachine\GraphQLite\Types\MutableInterface;
use TheCodingMachine\GraphQLite\Types\MutableInterfaceType;
use TheCodingMachine\GraphQLite\Types\MutableObjectType;
use TheCodingMachine\GraphQLite\Types\ResolvableMutableInputInterface;

use function get_class;
use function is_a;
use function strpos;
use function substr;


class TypeMapper implements TypeMapperInterface
{
    /** @var array<string, MutableInterface&(MutableObjectType|MutableInterfaceType)> */
    private $cache = [];
    private $context = null;

    public function __construct(FactoryContext $context)
    {
        $this->context = $context;
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL type.
     *
     * @param class-string<object> $className The exact class name to look for (this function does not look into parent classes).
     */
    public function canMapClassToType(string $className): bool
    {
        return is_a($className, Query::class, true);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL type.
     *
     * @param class-string<object> $className The exact class name to look for (this function does not look into parent classes).
     * @param (OutputType&Type)|null $subType An optional sub-type if the main class is an iterator that needs to be typed.
     *
     * @return MutableObjectType|MutableInterfaceType
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function mapClassToType(string $className, ?OutputType $subType): MutableInterface
    {
        if (!$this->canMapClassToType($className)) {
            throw CannotMapTypeException::createForType($className);
        }
        if ($subType === null) {
            throw MissingParameterException::noSubType();
        }

        return $this->getObjectType($subType);
    }

    private function getMetaObjectType()
    {
        if (!$this->cache["DB_META"]) {
            $this->cache["DB_META"] = new ObjectType([

                'name' => 'DB_META',
                'fields' => static function () {
                    return [
                        "total" => [
                            "type" => Type::int(),
                            "description" => "Total number of records",
                        ],
                        "class" => [
                            "type" => Type::string(),
                            "description" => "Class name of the records",
                        ],
                        "key" => [
                            "type" => Type::string(),
                        ],
                        "name" => [
                            "type" => Type::string(),
                            "description" => "Name of the records"
                        ]

                    ];
                }
            ]);
        }
        return $this->cache["DB_META"];
    }


    /**
     * @param OutputType&Type $subType
     *
     * @return MutableObjectType|MutableInterfaceType
     */
    private function getObjectType(OutputType $subType): MutableInterface
    {
        /** @var mixed $name - invalid vendor mapping */
        $name = $subType->toString();

        if ($name === null) {
            throw new RuntimeException('Cannot get name property from sub type ' . get_class($subType));
        }

        $typeName = 'DB_QUERY_' . $name;

        if ($subType instanceof NullableType) {
            $subType = Type::nonNull($subType);
        }

        //create Output object 
        if (!isset($this->cache[$typeName])) {

            $metaType = $this->getMetaObjectType();


            $this->cache[$typeName] = new MutableObjectType([
                'name' => $typeName,
                'fields' => static function () use ($subType, $metaType) {
                    return [
                        'data' => [
                            'type' => Type::nonNull(Type::listOf($subType)),
                            'args' => [
                                'limit' => Type::int(),
                                'offset' => Type::int(),
                            ],
                            'resolve' => static function (Query $root, $args) {
                                if (!isset($args['limit']) && isset($args['offset'])) {
                                    throw MissingParameterException::missingLimit();
                                }
                                if (isset($args['limit']) && $args['limit'] !== null) {
                                    $root->limit($args['limit']);
                                }

                                if (isset($args['offset']) && $args['offset'] !== null) {
                                    $root->offset($args["offset"]);
                                }

                                return $root;
                            },
                        ],
                        'meta' => [
                            'type' => Type::nonNull($metaType),
                            'description' => 'The total count of items.',
                            'resolve' => static function (Query $root) {

                                $key = "";
                                $class = $root->getClassName();


                                if (is_a($class, Model::class, true)) {
                                    $key = $class::_key();
                                }

                                return [
                                    "name" => (new ReflectionClass($root->getClassName()))->getShortName(),
                                    "class" => $root->getClassName(),
                                    "total" => $root->count(),
                                    "key" => $key
                                ];
                            },
                        ],
                    ];
                },
            ]);
        }

        return $this->cache[$typeName];
    }

    /**
     * Returns true if this type mapper can map the $typeName GraphQL name to a GraphQL type.
     *
     * @param string $typeName The name of the GraphQL type
     */
    public function canMapNameToType(string $typeName): bool
    {
        if ($typeName == "DB_META") return true;
        return strpos($typeName, 'DB_QUERY_') === 0;
    }

     /**
     * Returns a GraphQL type by name (can be either an input or output type)
     *
     * @param string $typeName The name of the GraphQL type
     *
     * @return NamedType&Type&((ResolvableMutableInputInterface&InputObjectType)|MutableObjectType|MutableInterfaceType)
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function mapNameToType(string $typeName): Type&NamedType
    {
        
        if (!$this->canMapNameToType($typeName)) {
            throw CannotMapTypeException::createForName($typeName);
        }

        if ($typeName === "DB_META") {
            return $this->getMetaObjectType();
        }

        $subTypeName = substr($typeName, 17);

        $subType = $this->context->getRecursiveTypeMapper()->recursiveTypeMapper->mapNameToType($subTypeName);

        if (!$subType instanceof OutputType) {
            throw CannotMapTypeException::mustBeOutputType($subTypeName);
        }

        return $this->getObjectType($subType);
    }

    /**
     * Returns the list of classes that have matching input GraphQL types.
     *
     * @return string[]
     */
    public function getSupportedClasses(): array
    {
        // We cannot get the list of all possible porpaginas results but this is not an issue.
        // getSupportedClasses is only useful to get classes that can be hidden behind interfaces
        // and Porpaginas results are not part of those.
        return [];
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL input type.
     *
     * @param class-string<object> $className
     */
    public function canMapClassToInputType(string $className): bool
    {
        return false;
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL input type.
     *
     * @param class-string<object> $className
     *
     * @return ResolvableMutableInputInterface&InputObjectType
     *
     * @throws CannotMapTypeException
     */
    public function mapClassToInputType(string $className): ResolvableMutableInputInterface
    {
        throw CannotMapTypeException::createForInputType($className);
    }

    /**
     * Returns true if this type mapper can extend an existing type for the $className FQCN
     *
     * @param MutableInterface&(MutableObjectType|MutableInterfaceType) $type
     */
    public function canExtendTypeForClass(string $className, MutableInterface $type): bool
    {
        return false;
    }

    /**
     * Extends the existing GraphQL type that is mapped to $className.
     *
     * @param class-string<object> $className
     * @param MutableInterface&(MutableObjectType|MutableInterfaceType) $type
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function extendTypeForClass(string $className, MutableInterface $type): void
    {
        throw CannotMapTypeException::createForExtendType($className, $type);
    }

    /**
     * Returns true if this type mapper can extend an existing type for the $typeName GraphQL type
     *
     * @param MutableInterface&(MutableObjectType|MutableInterfaceType) $type
     */
    public function canExtendTypeForName(string $typeName, MutableInterface $type): bool
    {
        return false;
    }

    /**
     * Extends the existing GraphQL type that is mapped to the $typeName GraphQL type.
     *
     * @param MutableInterface&(MutableObjectType|MutableInterfaceType) $type
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function extendTypeForName(string $typeName, MutableInterface $type): void
    {
        throw CannotMapTypeException::createForExtendName($typeName, $type);
    }

    /**
     * Returns true if this type mapper can decorate an existing input type for the $typeName GraphQL input type
     */
    public function canDecorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): bool
    {
        return false;
    }

    /**
     * Decorates the existing GraphQL input type that is mapped to the $typeName GraphQL input type.
     *
     * @param ResolvableMutableInputInterface&InputObjectType $type
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function decorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): void
    {
        throw CannotMapTypeException::createForDecorateName($typeName, $type);
    }
}
