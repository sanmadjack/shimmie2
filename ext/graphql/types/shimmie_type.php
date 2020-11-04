<?php declare(strict_types=1);

use GraphQL\Type\Definition\ObjectType;

/**
 * Class GraphQLCreateTypeEvent
 *
 * Fires when setting up a GraphQL type that extends GraphQLShimmieObjectType.
 * Allows adding additional fields/methods to the type.
 */
class GraphQLCreateTypeEvent extends Event
{
    /** @var array */
    public $config;

    /** @var string */
    public $name;

    public function __construct(string $name, array $config)
    {
        parent::__construct();

        $this->name = $name;
        $this->config = $config;
    }

    /**
     * Adds an additional field to the GraphQL type represented by this event
     *
     * @param string $name  The name of the field to add
     * @param $field        The field config
     */
    public function addField(string $name, $field): void
    {
        if (array_key_exists($name, $this->config[GQL_FIELDS])) {
            throw new GraphQLException("Field $name is already present");
        }
        $this->config[GQL_FIELDS][$name] = $field;
    }
}

/**
 * Class GraphQLShimmieObjectType
 *
 * Convenience class that will trigger a GraphQLCreateTypeEvent upon instantiation.
 * Should not be used unless it's important that the type be passed through the event system for modification.
 */
class GraphQLShimmieObjectType extends ObjectType
{
    public function __construct(array $config)
    {
        $event = new GraphQLCreateTypeEvent(get_class($this), $config);
        send_event($event);
        parent::__construct($event->config);
    }
}
