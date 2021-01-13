<?php declare(strict_types=1);

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Class QueryType
 *
 * The root query type for GraphQL
 */
class QueryType extends GraphQLShimmieObjectType
{
    public const TAG = 'tag';
    public const POST = 'post';

    public function __construct(TypeRegistry $registry)
    {
        $config = [
            GQL_FIELDS => [
                self::TAG => [
                    GQL_TYPE => $registry->paginatedListOf($registry->getByName(GQL_TYPE_TAG)),
                    GQL_DESCRIPTION => "Fetches a list of tags from the server",
                    GQL_ARGS => [
                        'search' => [
                            'type' => Type::string(),
                            'description' => 'A search string to be found at the beginning of the tag name',
                            'defaultValue' => ""
                        ],
                        GQL_LIMIT => [
                            'type' => Type::int(),
                            'description' => 'Limit the number of tags returned',
                            'defaultValue' => 10
                        ],
                        GQL_OFFSET => [
                            'type' => Type::int(),
                            'description' => 'The offset of the first tag returned',
                            'defaultValue' => 0
                        ],
                        'search_categories' => [
                            'type' => Type::boolean(),
                            'description' => 'Whether to search starting after the category prefix',
                            'defaultValue' => false
                        ]
                    ],
                    GQL_RESOLVE => function ($rootValue, $args) {
                        return $this->getTags($args["search"], $args[GQL_LIMIT], $args[GQL_OFFSET], $args["search_categories"]);
                    }
                ],
                self::POST => [
                    GQL_TYPE => $registry->paginatedListOf($registry->getByName(GQL_TYPE_POST)),
                    GQL_DESCRIPTION => "Fetches a list of posts from the server",
                    GQL_ARGS => [
                        'id' => [
                            'type' => Type::int(),
                            'description' => 'The ID of the post to fetch',
                            'defaultValue' => -1
                        ],
                        'hash' => [
                            'type' => Type::string(),
                            'description' => 'The hash of the post to fetch',
                            'defaultValue' => ""
                        ],
                        GQL_LIMIT => [
                            'type' => Type::int(),
                            'description' => 'Limit the number of posts returned',
                            'defaultValue' => 10
                        ],
                        GQL_OFFSET => [
                            'type' => Type::int(),
                            'description' => 'The offset of the first post returned',
                            'defaultValue' => 0
                        ],
                    ],
                    GQL_RESOLVE => function ($rootValue, $args) {
                        return $this->getPosts($args["id"], $args["hash"], $args[GQL_LIMIT], $args[GQL_OFFSET]);
                    }
                ],
            ],
        ];
        parent::__construct($config);
    }

    private function getPosts(int $id, string $hash, int $limit, int $offset): iterable
    {
        if ($id>-1) {
            $post = Image::by_id($id);
            if ($post==null) {
                throw new GraphQLException("Post not found: ".$id);
            }
            return [$post->get_data_row()];
        } elseif (!empty($hash)) {
            $post = Image::by_hash($hash);
            if ($post==null) {
                throw new GraphQLException("Post not found: ".$id);
            }
            return [$post->get_data_row()];
        } else {
            throw new GraphQLException("Not Implemented");
        }
    }

    private function getTags(string $query, int $limit, int $offset, bool $search_categories): iterable
    {
        global $database, $cache;

        $cache_key = "api_tags_search-$query";
        $limitSQL = "";
        $searchSQL = "LOWER(tag) LIKE LOWER(:search)";
        $query = str_replace('_', '\_', $query);
        $query = str_replace('%', '\%', $query);
        $resultArgs = ["search"=>"$query%"];
        $totalArgs = ["search"=>"$query%"];

        if ($search_categories) {
            $searchSQL .= " OR LOWER(tag) LIKE LOWER(:cat_search)";
            $resultArgs['cat_search'] = "%:$query%";
            $totalArgs['cat_search'] = "%:$query%";
            $cache_key .= "+cat";
        }

        if ($limit !== 0) {
            $limitSQL = "LIMIT :limit";
            $resultArgs['limit'] = $limit;
            $cache_key .= "-LIMIT" . $limit;
        }
        if ($offset !== 0) {
            $limitSQL = "OFFSET :offset";
            $resultArgs['offset'] = $offset;
            $cache_key .= "-OFFSET" . $offset;
        }

        $res = $cache->get($cache_key);
        if (!$res) {
            $res = $database->get_all(
                "
					SELECT *
					FROM tags
					WHERE $searchSQL
					AND count > 0
					ORDER BY count DESC
					$limitSQL",
                $resultArgs
            );

            $total = $database->get_one(
                "
					SELECT COUNT(*)
					FROM tags
					WHERE $searchSQL
					AND count > 0",
                $totalArgs
            );

            $output = PaginatedListType::formatData($res, $total, $offset);

            $cache->set($cache_key, $output, 600);

            return $output;
        }

        return $res;
    }
}
