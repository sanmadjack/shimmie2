<?php declare(strict_types=1);

use GraphQL\Error\ClientAware;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

require_once 'type_registry.php';
require_once 'types/paginated_list_type.php';
require_once 'types/shimmie_type.php';
require_once 'types/user_type.php';
require_once 'types/tag_type.php';
require_once 'types/post_type.php';
require_once 'types/query_type.php';


class GraphQLException extends SCoreException implements ClientAware
{
    public $redirect;

    public function __construct(string $msg)
    {
        parent::__construct($msg);
    }

    public function isClientSafe()
    {
        return true;
    }

    public function getCategory()
    {
        return 'shimmie';
    }
}



class GraphQLGenerateSchemaEvent extends Event
{
    /** @var TypeRegistry  */
    private $registry;

    public function __construct(TypeRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;
    }

    public function addType(string $name, ObjectType $type): void
    {
        $this->registry->add($name, $type);
    }

    public function getType(string $name): ObjectType
    {
        return $this->registry->getByName($name);
    }
}



const GQL_TYPE_TAG = 'tag';
const GQL_TYPE_POST = 'post';
const GQL_TYPE_USER = 'user';
const GQL_TYPE_QUERY = 'query';

const GQL_PATH = "api/graphql";

const GQL_OFFSET = "offset";
const GQL_LIMIT = "limit";

const GQL_FIELDS = "fields";
const GQL_TYPE = 'type';
const GQL_DESCRIPTION = 'description';
const GQL_RESOLVE = 'resolve';
const GQL_ARGS = 'args';

class GraphQLExtension extends Extension
{
    public function __construct($class = null)
    {
        parent::__construct();
    }

    public function get_priority(): int
    {
        return 30;
    } // before Home

    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $_tracer;

        $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE;// | DebugFlag::INCLUDE_TRACE;

        if ($event->args[0]==="api"&&$event->args[1]==="graphql") {
            try {
                $schema = $this->GenerateSchema();

                $input = json_decode($event->raw_input, true);
                $query = $input['query'];
                $variableValues = isset($input['variables']) ? $input['variables'] : null;

                $rootValue = [];
                $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variableValues);
                $output = $result->toArray($debug);
            } catch (\Exception $e) {
                $output = [
                    'errors' => [
                        [
                            'message' => $e->getMessage(),
                            'stackTrace' => $e->getTraceAsString()
                        ]
                    ]
                ];
            }

            $page->set_mode(PageMode::JSON);
            $page->set_data(json_encode($output));
        }
    }

    private static function GenerateSchema(): Schema
    {
        $registry = new TypeRegistry();


        $registry->add(GQL_TYPE_TAG, new TagType());
        $registry->add(GQL_TYPE_USER, new UserType($registry));
        $registry->add(GQL_TYPE_POST, new PostType($registry));
        $registry->add(GQL_TYPE_QUERY, new QueryType($registry));

        send_event(new GraphQLGenerateSchemaEvent($registry));

        return $registry->generateSchema();
    }
}
