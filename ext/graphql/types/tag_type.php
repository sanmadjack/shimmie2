<?php declare(strict_types=1);

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class TagType extends GraphQLShimmieObjectType
{
    public const ID = "id";
    public const TAG = "tag";
    public const COUNT = "count";

    public function __construct()
    {
        $config = [
            GQL_FIELDS => [
                self::ID => Type::int(),
                self::TAG => Type::string(),
                self::COUNT => Type::int(),
            ]
        ];
        parent::__construct($config);
    }
}
