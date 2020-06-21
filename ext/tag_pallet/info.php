<?php declare(strict_types=1);


class TagPalletInfo extends ExtensionInfo
{
    public const KEY = "tag_pallet";

    public $key = self::KEY;
    public $name = "[BETA] Tag Pallet";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $dependencies = [AutoCompleteInfo::KEY];
    public $license = self::LICENSE_GPLV2;
    public $description = "Provides a persistent pallet that can be used to quickly tag images. Based on advanced tagger by Artanis (Erik Youngren).";
}
