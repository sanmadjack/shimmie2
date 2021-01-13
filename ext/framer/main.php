<?php declare(strict_types=1);

class Framer extends Extension
{

    public function onPostListBuilding(PostListBuildingEvent $event)
    {
        global $page, $user;

        $sizes = [
            [8,9,"red"],
            [16,9,"green"],
            [8,3,"blue"],
            [1,2,"yellow"],
        ];


        $this->theme->render_selector($page, $sizes);
    }

}
