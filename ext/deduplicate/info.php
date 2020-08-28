<?php declare(strict_types=1);

class DeduplicateInfo extends ExtensionInfo
{
    public const KEY = "deduplicate";

    public $key = self::KEY;
    public $name = "Deduplicate";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $license = self::LICENSE_WTFPL;
    public $description = " Provides functions for automatically detecting duplicate images.";
    public $db_support = [DatabaseDriver::PGSQL];
}
