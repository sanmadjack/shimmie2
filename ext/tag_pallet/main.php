<?php declare(strict_types=1);


class TagPallet extends Extension
{
    public function get_priority(): int
    {
        return 29;
    } // before Home

    public function onPostListBuilding(PostListBuildingEvent $event)
    {
        global $page, $user;

        if ($user->can(Permissions::EDIT_IMAGE_TAG)) {
            $this->theme->build_tagger($page, $event);
        }
    }


    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $sb = new SetupBlock("Tagger");
        $event->panel->add_block($sb);
    }
}
