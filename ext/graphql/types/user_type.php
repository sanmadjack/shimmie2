<?php declare(strict_types=1);

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class UserType extends GraphQLShimmieObjectType
{
    public const ID = "id";
    public const NAME = "name";
    public const POSTS = "posts";

    public function __construct(TypeRegistry $registry)
    {
        $config = [
            GQL_FIELDS => [
                self::ID => Type::int(),
                self::POSTS => [
                    GQL_TYPE => function () use ($registry) {
                        return Type::listOf($registry->getByName(GQL_TYPE_POST));
                    },
                    GQL_RESOLVE => function ($user) {
                        $results = Image::find_images(0, null, ["poster_id=".$user[UserType::ID]]);
                        $output = [];
                        foreach ($results as $result) {
                            $output[] = $result->get_data_row();
                        }
                        return $output;
                    },
                ],
                self::NAME => Type::string(),
            ]
        ];
        parent::__construct($config);
    }
}
