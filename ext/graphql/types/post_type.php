<?php declare(strict_types=1);

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class PostType extends GraphQLShimmieObjectType
{
    public const SET_TAGS = "setTags";
    public const ID = "id";
    public const TAGS = "tags";
    public const HASH = "hash";
    public const HEIGHT = "height";
    public const WIDTH = "width";
    public const LENGTH = "length";
    public const FILESIZE = "filesize";
    public const FILENAME = "filename";
    public const OWNER = "owner";
    public const OWNER_ID = "owner_id";
    public const OWNER_IP = "owner_ip";
    public const SOURCE = "source";
    public const POSTED = "posted"; // TODO: Dates in PHP? What is the deal?
    public const LOCKED = "locked";


    public function __construct(TypeRegistry $registry)
    {
        $config = [
            GQL_FIELDS => [
                self::ID => Type::int(),
                self::TAGS =>[
                    GQL_TYPE => Type::listOf($registry->getByName(GQL_TYPE_TAG)),
                    GQL_RESOLVE => function ($post) {
                        $image = Image::by_id($post["id"]);
                        if ($image==null) {
                            throw new GraphQLException("Post not found: ".$post["id"]);
                        }
                        return $image->get_tag_array();
                    }
                ],
                self::HASH => Type::string(),
                self::HEIGHT => Type::int(),
                self::WIDTH => Type::int(),
                self::LENGTH => Type::int(),
                self::FILESIZE => Type::int(),
                self::FILENAME => Type::string(),
                self::OWNER => [
                    GQL_TYPE => function () use ($registry) {
                        return $registry->getByName(GQL_TYPE_USER);
                    },
                    GQL_RESOLVE => function ($post) {
                        if ($post["id"]==null) {
                            return null;
                        }
                        $user = User::by_id($post["owner_id"]);
                        if ($user==null) {
                            throw new GraphQLException("User not found: ".$post["owner_id"]);
                        }
                        return $user->get_data_row();
                    }
                ],
                self::OWNER_ID => Type::int(),
                self::OWNER_IP => Type::string(),
                self::SOURCE => Type::string(),
                self::LOCKED => Type::boolean(),
            ],
        ];
        parent::__construct($config);
    }
}
