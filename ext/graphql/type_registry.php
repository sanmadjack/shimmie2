<?php declare(strict_types=1);


use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

class TypeRegistry
{
    public $types = [];


    public function add(string $name, ObjectType $type): void
    {
        if ($type == null) {
            throw new GraphQLException("type cannot be null");
        }
        $this->types[$name] = $type;
    }

    public function getByName(string $name): ObjectType
    {
        if (!array_key_exists($name, $this->types)) {
            throw new GraphQLException("Type not found: $name");
        }
        return $this->types[$name];
    }

    public function generateSchema(): Schema
    {
        return new Schema($this->types);
    }

    public function paginatedListOf(Type $wrappedType): PaginatedListType
    {
        $candidate = new PaginatedListType($wrappedType);
        $name = $candidate->toString();
        if (!array_key_exists($name, $this->types)) {
            $this->types[$name] = $candidate;
        }
        return @$this->types[$name]??$candidate;
    }
}
