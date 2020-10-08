<?php declare(strict_types=1);

class PostPermissionsInfo extends ExtensionInfo
{
    public const KEY = "post_permissions";

    public $key = self::KEY;
    public $name = "Post Permissions";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $license = self::LICENSE_WTFPL;
    public $description = "Allows users to manage post permissions.";
    public $conflicts = ["private_image"];
}
