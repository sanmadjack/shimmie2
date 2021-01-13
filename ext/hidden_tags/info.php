<?php declare(strict_types=1);

class HiddenTagsInfo extends ExtensionInfo
{
    public const KEY = "hidden_tags";

    public $key = self::KEY;
    public $name = "Hidden Tags";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $license = self::LICENSE_WTFPL;
    public $description = "Provides the ability to specify tags that indicate that a post should be hidden.";
}
