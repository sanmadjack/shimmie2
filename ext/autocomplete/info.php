<?php declare(strict_types=1);

class AutoCompleteInfo extends ExtensionInfo
{
    public const KEY = "autocomplete";

    public $key = self::KEY;
    public $name = "Autocomplete";
    public $authors = ["Daku"=>"admin@codeanimu.net"];
    public $description = "Adds autocomplete to search & tagging.";
}
