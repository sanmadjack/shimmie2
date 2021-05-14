<?php declare(strict_types=1);

class RatingsMatrixInfo extends ExtensionInfo
{
    public const KEY = "rating_matrix";

    public $key = self::KEY;
    public $name = "Post Ratings Matrix";
    public $url = self::SHIMMIE_URL;
    public $authors = self::SHISH_AUTHOR;
    public $license = self::LICENSE_GPLV2;
    public $description = "Allow users to rate posts on several different qualities";
    public $documentation = "";
}
