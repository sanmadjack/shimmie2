<?php declare(strict_types=1);

abstract class PostPermissionsConfig
{
    const VERSION = "ext_post_permissions_version";
    const USER_DEFAULT_PRIVACY = "user_post_permissions_default_privacy";
    const USER_DEFAULT_VIEW = "user_post_permissions_default_view";
    const USER_DEFAULT_VIEW_ALL_USERS = "user_post_permissions_default_view_all_users";
}

class PostPermissions extends Extension
{
    /** @var PostPermissionsTheme */
    protected $theme;

    public function onInitExt(InitExtEvent $event)
    {
        global $config;

        Image::$bool_props[] = "private ";
    }

    public function onInitUserConfig(InitUserConfigEvent $event)
    {
        $event->user_config->set_default_bool(PostPermissionsConfig::USER_DEFAULT_PRIVACY, false);
        $event->user_config->set_default_bool(PostPermissionsConfig::USER_DEFAULT_VIEW, true);
    }

    public function onUserOptionsBuilding(UserOptionsBuildingEvent $event)
    {
        $sb = $event->panel->create_new_block("Permissions");
        $sb->start_table();
        $sb->add_bool_option(PostPermissionsConfig::USER_DEFAULT_PRIVACY, "Mark posts private by default", true);
        $sb->add_bool_option(PostPermissionsConfig::USER_DEFAULT_VIEW, "View private posts by default", true);
        if ($event->user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
            $sb->add_bool_option(
                PostPermissionsConfig::USER_DEFAULT_VIEW_ALL_USERS,
                "View private posts of all users",
                true
            );
        }
        $sb->end_table();
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $user, $user_config;

        if ($event->page_matches("privatize_post") && $user->can(Permissions::SET_PRIVATE_POST)) {
            // Try to get the image ID
            $image_id = int_escape($event->get_arg(0));
            if (empty($image_id)) {
                $image_id = isset($_POST['image_id']) ? $_POST['image_id'] : null;
            }
            if (empty($image_id)) {
                throw new SCoreException("Can not make image private: No valid Post ID given.");
            }
            $image = Image::by_id($image_id);
            if ($image==null) {
                throw new SCoreException("Post not found.");
            }
            if ($image->owner_id!=$user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
                throw new SCoreException("Cannot set another user's image to private.");
            }

            self::privatize_post($image_id);
            $page->set_mode(PageMode::REDIRECT);
            $page->set_redirect(make_link("post/view/" . $image_id));
        }

        if ($event->page_matches("publicize_post")) {
            // Try to get the image ID
            $image_id = int_escape($event->get_arg(0));
            if (empty($image_id)) {
                $image_id = isset($_POST['image_id']) ? $_POST['image_id'] : null;
            }
            if (empty($image_id)) {
                throw new SCoreException("Can not make image public: No valid Post ID given.");
            }
            $image = Image::by_id($image_id);
            if ($image==null) {
                throw new SCoreException("Post not found.");
            }
            if ($image->owner_id!=$user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
                throw new SCoreException("Cannot set another user's image to private.");
            }

            self::publicize_post($image_id);
            $page->set_mode(PageMode::REDIRECT);
            $page->set_redirect(make_link("post/view/".$image_id));
        }
    }
    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        global $user, $page;

        if ($event->image->private===true && $event->image->owner_id!=$user->id &&
            !$user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
            $page->set_mode(PageMode::REDIRECT);
            $page->set_redirect(make_link("post/list"));
        }
    }


    const SEARCH_REGEXP = "/^private:(yes|no|any|admin\\_any)/";
    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        global $user, $database, $user_config;
        $show_private = $user_config->get_bool(PostPermissionsConfig::USER_DEFAULT_VIEW);
        $show_private_all_users = $user_config->get_bool(PostPermissionsConfig::USER_DEFAULT_VIEW_ALL_USERS);

        $matches = [];

        if (is_null($event->term) && $this->no_private_query($event->context)) {
            if ($show_private) {
                if ($user->can(Permissions::SET_OTHERS_PRIVATE_POSTS) && $show_private_all_users) {
                    $event->add_querylet(
                        new Querylet(
                            "private = :false OR private = :true",
                            ["false"=>false, "true"=>true]
                        )
                    );
                } else {
                    $event->add_querylet(
                        new Querylet(
                            "private = :false OR owner_id = :private_owner_id",
                            ["private_owner_id"=>$user->id, "false"=>false]
                        )
                    );
                }
            } else {
                $event->add_querylet(
                    new Querylet(
                        "private = :false",
                        ["false"=>false]
                    )
                );
            }
        }

        if (is_null($event->term)) {
            return;
        }

        if (preg_match(self::SEARCH_REGEXP, strtolower($event->term), $matches)) {
            $params = [];
            $query = "";
            switch ($matches[1]) {
                case "no":
                    $query .= "private = :false";
                    $params["false"] = false;
                    break;
                case "yes":
                    $query .= "private = :true";
                    $params["true"] = true;

                    // Admins can view others private images, but they have to specify the user
                    if (!$user->can(Permissions::SET_OTHERS_PRIVATE_POSTS) ||
                        (!$show_private_all_users &&
                        !UserPage::has_user_query($event->context))) {
                        $query .= " AND owner_id = :private_owner_id";
                        $params["private_owner_id"] = $user->id;
                    }
                    break;
                case "any":
                    $query .= "private = :false";
                    $params["false"] = false;

                    if (!$user->can(Permissions::SET_OTHERS_PRIVATE_POSTS) ||
                        (!$show_private_all_users &&
                            !UserPage::has_user_query($event->context))) {
                        $query .= " OR owner_id = :private_owner_id";
                        $params["private_owner_id"] = $user->id;
                    } else {
                        $query .= " OR private = :true";
                        $params["true"] = true;
                    }
                    break;
                case "admin_any":
                    if ($user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
                        $query .= "private = :false OR private = :true";
                        $params["false"] = false;
                        $params["true"] = true;
                    } else {
                        $query .= "1 = 0";
                    }
                    break;
            }
            $event->add_querylet(new Querylet($database->scoreql_to_sql($query), $params));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Post Permissions";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }


    private function no_private_query(array $context): bool
    {
        foreach ($context as $term) {
            if (preg_match(self::SEARCH_REGEXP, $term)) {
                return false;
            }
        }
        return true;
    }

    public static function privatize_post($image_id)
    {
        global $database, $user;

        $database->execute(
            "UPDATE images SET private = :true WHERE id = :id AND private = :false",
            ["id"=>$image_id, "true"=>true, "false"=>false]
        );
    }

    public static function publicize_post($post_id)
    {
        global $database;

        $database->execute(
            "UPDATE images SET private = :false WHERE id = :id AND private = :true",
            ["id"=>$post_id, "true"=>true, "false"=>false]
        );
    }

    public static function set_owner($post_id, $new_owner)
    {
        global $database;

        $database->execute(
            "UPDATE images SET owner_id = :new_owner WHERE id = :id",
            ["id"=>$post_id, "new_owner"=>$new_owner]
        );
    }

    public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event)
    {
        global $user, $config;
        if ($user->can(Permissions::SET_PRIVATE_POST) && $user->id==$event->image->owner_id) {
            $event->add_part($this->theme->get_image_admin_html($event->image));
        }
    }

    public function onImageAddition(ImageAdditionEvent $event)
    {
        global $user_config;
        if ($user_config->get_bool(PostPermissionsConfig::USER_DEFAULT_PRIVACY)) {
            self::privatize_post($event->image->id);
        }
    }

    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event)
    {
        global $user, $config;

        if ($user->can(Permissions::SET_PRIVATE_POST)) {
            $event->add_action("bulk_privatize_post", "Make Private");
            $event->add_action("bulk_publicize_post", "Make Public");
        }
        if ($user->can(Permissions::EDIT_IMAGE_OWNER)) {
            $event->add_action(
                "bulk_set_owner",
                "Set Owner",
                null,
                "",
                $this->theme->get_owner_select_html()
            );
        }
    }

    public function onBulkAction(BulkActionEvent $event)
    {
        global $page, $user;

        switch ($event->action) {
            case "bulk_privatize_post":
                if ($user->can(Permissions::SET_PRIVATE_POST)) {
                    $total = 0;
                    foreach ($event->items as $image) {
                        if ($image->owner_id==$user->id ||
                            $user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
                            self::privatize_post($image->id);
                            $total++;
                        }
                    }
                    $page->flash("Made $total items private");
                }
                break;
            case "bulk_publicize_post":
                $total = 0;
                foreach ($event->items as $image) {
                    if ($image->owner_id==$user->id ||
                        $user->can(Permissions::SET_OTHERS_PRIVATE_POSTS)) {
                        self::publicize_post($image->id);
                        $total++;
                    }
                }
                $page->flash("Made $total items public");
                break;
            case "bulk_set_owner":
                $total = 0;
                if ($user->can(Permissions::EDIT_IMAGE_OWNER)) {
                    foreach ($event->items as $image) {
                        $new_id = intval($_POST["new_owner"]);
                        self::set_owner($image->id, $new_id);
                        $total++;
                    }
                }
                $page->flash("Changed owner of $total items");
                break;
        }
    }
    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $database;

        if ($this->get_version(PostPermissionsConfig::VERSION) < 1 &&
                $this->get_version("ext_private_image_version") < 1) {
            // We checked the version of the old private image extension above,
            // so that we don't end up breaking things.

            $database->execute("ALTER TABLE images ADD COLUMN private BOOLEAN NOT NULL DEFAULT FALSE");

            $database->execute("CREATE INDEX images_private_idx ON images(private)");
            $this->set_version(PostPermissionsConfig::VERSION, 1);
        }

        if ($this->get_version(PostPermissionsConfig::VERSION) < 2) {
            // Migrate old extension's user configs
            $database->execute(
                "UPDATE user_config SET name=:new_name WHERE name=:old_name",
                [
                    "old_name"=>"user_private_image_set_default",
                    "new_name"=>PostPermissionsConfig::USER_DEFAULT_PRIVACY
                ]
            );
            $database->execute(
                "UPDATE user_config SET name=:new_name WHERE name=:old_name",
                [
                    "old_name"=>"user_private_image_view_default",
                    "new_name"=>PostPermissionsConfig::USER_DEFAULT_VIEW
                ]
            );
            $this->set_version(PostPermissionsConfig::VERSION, 2);
        }
    }
}
