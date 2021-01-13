<?php declare(strict_types=1);


use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Schema;

/**
 * Provides a standardized format for returning data that may exceed the response limit of a particular request.
 */
class PaginatedListType extends ObjectType implements WrappingType, OutputType
{
    public const DATA = "data";
    public const TOTAL = "total";
    public const COUNT = "count";
    public const OFFSET = "offset";

    private $ofType;

    public function __construct(Type $type)
    {
        $this->ofType = is_callable($type) ? $type : Type::assertType($type);
        $config = [
            GQL_FIELDS => [
                self::DATA => Type::listOf($type),
                self::COUNT => Type::int(),
                self::TOTAL => Type::int(),
                self::OFFSET => Type::int(),
            ]
        ];
        parent::__construct($config);
    }

    public static function formatData(array $data, int $total, int $offset): array
    {
        $output = [];
        $output[self::DATA] = $data;
        $output[self::COUNT] = sizeof($data);
        $output[self::OFFSET] = $offset;
        $output[self::TOTAL] = $total;
        return $output;
    }

    public function toString() : string
    {
        return 'Paginated[' . $this->getOfType()->toString() . ']';
    }

    public function getOfType()
    {
        return Schema::resolveType($this->ofType);
    }

    public function getWrappedType(bool $recurse = false): Type
    {
        $type = $this->getOfType();

        return $recurse && $type instanceof WrappingType
            ? $type->getWrappedType($recurse)
            : $type;
    }
}
