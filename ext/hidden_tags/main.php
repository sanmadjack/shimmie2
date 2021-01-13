<?php declare(strict_types=1);


use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Hash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;

class HiddenTagsConfig
{
    const TAGS = "ext_hidden_tags_tags";
}

class HiddenTags extends Extension
{
    /** @var DeduplicateTheme */
    protected $theme;

    public function __construct()
    {
        parent::__construct();

        $this->hasher = new ImageHash(new DifferenceHash());
    }


    public function onInitExt(InitExtEvent $event)
    {
        global $config;

        $config->set_default_array(HiddenTagsConfig::TAGS, []);
    }

    public function onInitUserConfig(InitUserConfigEvent $event)
    {
        $event->user_config->set_default_array(HiddenTagsConfig::TAGS, []);
    }

    public function onUserOptionsBuilding(UserOptionsBuildingEvent $event)
    {
        $sb = $event->panel->create_new_block("Hidden Tags");
        $sb->start_table();
        $sb->add_text_option(HiddenTagsConfig::TAGS, "Tags", true);
        $sb->end_table();
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $sb = $event->panel->create_new_block("Hidden Tags");

        $sb->start_table();
        $sb->add_text_option(HiddenTagsConfig::TAGS, "Tags", true);
        $sb->end_table();
    }

    private static $hidden_tags_cache = null;

    private function get_hidden_tags(): array
    {
        global $config, $user_config;

        if (self::$hidden_tags_cache==null) {
            $global_hidden_tags = $config->get_array(HiddenTagsConfig::TAGS);
            $user_hidden_tags = $user_config->get_array(HiddenTagsConfig::TAGS);

            self::$hidden_tags_cache = array_filter(array_merge($global_hidden_tags, $user_hidden_tags));
        }
        return self::$hidden_tags_cache;
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        $hidden_tags = $this->get_hidden_tags();

        if (empty($hidden_tags)) {
            return;
        }

        $params = [];


        for ($i = 0; $i < sizeof($hidden_tags); $i++) {
            $params["tag".$i] = $hidden_tags[$i];
        }

        if (in_array($event->term, $hidden_tags)) {
        }

        $event->add_querylet(new Querylet(
            " id NOT IN (SELECT DISTINCT image_id FROM image_tags ot INNER JOIN tags t ON ot.tag_id = t.id AND t.tag IN (:".join(", :", array_keys($params))."))",
            $params
        ));
    }
}
