<?php declare(strict_types=1);

class GraphQLExtensionInfo extends ExtensionInfo
{
    public const KEY = "graphql";

    public $key = self::KEY;
    public $name = "GraphQL";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $license = self::LICENSE_MIT;
    public $description = "Dependency extension used to provide a standardized source for performing operations via an API";
    //public $visibility = self::VISIBLE_HIDDEN;
}
