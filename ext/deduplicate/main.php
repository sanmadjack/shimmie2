<?php declare(strict_types=1);


use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Hash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;

class DeduplicateConfig
{
    const VERSION = "ext_deduplicate_version";
    const MAXIMUM_DISTANCE = "deduplicate_maximum_distance";
    const SHOW_SAVED = "deduplicate_show_saved";
}

/*
* This is used by the image deduplicating code when there is an error while deduplicating
*/

class DeduplicateException extends SCoreException
{
}

class ComparisonSet
{
    public $post;

    public $children = [];

    public $siblings = [];

    public $parent = null;

    public $similar_posts = [];

    public function __construct(Image $post)
    {
        $this->post = $post;
    }

    public function other_ids(): array
    {
        $output = [];
        foreach ($this->similar_posts as $post) {
            $output[] = $post->post->id;
        }
        return $output;
    }
}

class SimilarItem
{
    /** @var float */
    public $similarity;
    /** @var Image */
    public $post;

    public $children = [];

    public $siblings = [];

    public $parent = null;

    public function __construct(Image $post, float $similarity)
    {
        $this->post = $post;
        $this->similarity = $similarity;
    }

    public function get_percentage(): float
    {
        $percent = (1 - $this->similarity) * 100;
        return $percent;
    }
}

class PerceptualHash
{
}
class PerceptualHashChannel
{
    public $values = [];
}

class Deduplicate extends Extension
{
    /** @var DeduplicateTheme */
    protected $theme;

    const SUPPORTED_MIME = [MimeType::PNG, MimeType::JPEG, MimeType::WEBP, MimeType::BMP, MimeType::TIFF, MimeType::GIF];

    const COMPARISON_OUTPUT_REGEX = '/all\: [\d\.]+ \((?<similarity>[\d\.]+)\)/';

    const PAGE_NAME = "deduplicate";

    const SEARCH_TERM_REGEX = "/^similar[=|:](\d+)$/i";

    private $hasher;

    public function __construct()
    {
        parent::__construct();

        $this->hasher = new ImageHash(new DifferenceHash());
    }


    public function onInitExt(InitExtEvent $event)
    {
        global $config, $_shm_user_classes, $_shm_ratings;

        if ($config->get_int(DeduplicateConfig::VERSION) < 1) {
            $this->install();
        }

        $config->set_default_float(DeduplicateConfig::MAXIMUM_DISTANCE, 13);
        $config->set_default_bool(DeduplicateConfig::SHOW_SAVED, false);


        foreach (array_keys($_shm_user_classes) as $key) {
            if ($key == "base" || $key == "hellbanned") {
                continue;
            }
            $config->set_default_array("ext_rating_" . $key . "_privs", array_keys($_shm_ratings));
        }
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $sb = $event->panel->create_new_block("Deduplication");

        $sb->start_table();
        $sb->add_int_option(DeduplicateConfig::MAXIMUM_DISTANCE, "Max distance", true);
        $sb->add_bool_option(DeduplicateConfig::SHOW_SAVED, "Show saved similar posts", true);
        $sb->end_table();
    }

    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event)
    {
        global $config, $database;
        if ($config->get_bool(DeduplicateConfig::SHOW_SAVED)) {
            $results = Image::find_images(0, 10, ["similar:".$event->image->id]);

            $event->add_part($this->theme->show_similar_posts($results), 35);
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $user, $database, $config;

        if ($event->page_matches(self::PAGE_NAME)) {
            if (!$user->can(Permissions::DEDUPLICATE)) {
                $this->theme->display_permission_denied();
            } else {
                if ($event->arg_count > 1 && $event->args[1] == "action") {
                    $left_post = @$_POST["left_post"];
                    $right_post = @$_POST["right_post"];
                    $left_parent = @$_POST["left_parent"];
                    $right_parent = @$_POST["right_parent"];

                    $action = $_POST["action"];
                    if (empty($left_post) || !is_numeric($left_post)) {
                        throw new SCoreException("left_post required");
                    }
                    if ($action!=="dismiss_all" && $action!=="save_all"
                        && (empty($right_post) || !is_numeric($right_post))) {
                        throw new SCoreException("right_post required");
                    }
                    if (empty($action)) {
                        throw new SCoreException("action required");
                    }
                    $left_post = intval($left_post);
                    $right_post = intval($right_post);

                    if (is_numeric($left_parent)) {
                        $left_parent = intval($left_parent);
                    }
                    if (is_numeric($right_parent)) {
                        $right_parent = intval($right_parent);
                    }


                    $this->perform_deduplication_action($action, $left_post, $right_post, $left_parent, $right_parent);

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link(self::PAGE_NAME));

                    return;
                }

                $this->theme->display_page();

                if ($event->count_args() == 0) {
                    $max_distance = @$_POST["max_distance"];

                    if(empty($max_distance)) {
                        $max_distance = $config->get_int(DeduplicateConfig::MAXIMUM_DISTANCE);
                    }

                    if (Extension::is_enabled(TrashInfo::KEY)) {
                        $one = $database->get_one("SELECT min(post_1_id) id FROM post_similarities
                                    INNER JOIN images i1 on post_similarities.post_1_id = i1.id AND i1.trash = :false
                                    INNER JOIN images i2 on post_similarities.post_2_id = i2.id AND i2.trash = :false
                                     WHERE saved = :false and similarity <= :similarity"
                        , ["false"=>false, "similarity"=>$max_distance]);
                    } else {
                        $one = $database->get_one("SELECT min(post_1_id) id FROM post_similarities WHERE saved = :false and similarity <= :similarity"
                            , ["false"=>false, "similarity"=>$max_distance]);
                    }
                    if ($one != 0) {
                        $this->build_deduplication_page(intval($one));
                    } else {
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link(""));
                        return;
                    }
                } else {
                    $this->build_deduplication_page(intval($event->get_arg(0)), intval($event->get_arg(1)));
                }

                $page->set_mode(PageMode::PAGE);
            }
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        global $database;

        $matches = [];
        if (preg_match(self::SEARCH_TERM_REGEX, $event->term??"", $matches)) {
            $id = $matches[1];
            $event->add_querylet(new Querylet(
                " id IN (SELECT DISTINCT CASE WHEN post_1_id = :similar_id THEN post_2_id ELSE post_1_id END FROM post_similarities WHERE (post_2_id = :similar_id OR  post_1_id = :similar_id) AND saved = :true)",
                ["similar_id"=>$id, "true" => true]
            ));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Similar Posts";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }

    public function onTagTermParse(TagTermParseEvent $event)
    {
        $matches = [];

        if (preg_match(self::SEARCH_TERM_REGEX, strtolower($event->term), $matches) && $event->parse) {
            // Nothing to save, just helping filter out reserved tags
            if (!empty($matches)) {
                $event->metatag = true;
            }
        }
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event)
    {
        global $user;
        if ($event->parent==="system") {
            if ($user->can(Permissions::DEDUPLICATE)) {
                $event->add_nav_link("deduplicate", new Link(self::PAGE_NAME), "Deduplicate");
            }
        }
    }


    public function onUserBlockBuilding(UserBlockBuildingEvent $event)
    {
        global $user;
        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_link("Deduplicate", make_link(self::PAGE_NAME));
        }
    }

    public function onBulkAction(BulkActionEvent $event)
    {
        global $user, $config, $database, $page;

        switch ($event->action) {
            case "bulk_deduplicate_clear":
                if ($user->can(Permissions::DEDUPLICATE)) {
                    $i = 0;
                    $j = 0;
                    foreach ($event->items as $post) {
                        $i++;
                        $j += $this->delete_similarities($post->id);
                    }
                    $page->flash("Cleared $j similarities across $i posts");
                }
                break;
            case "bulk_deduplicate":
                if ($user->can(Permissions::DEDUPLICATE)) {
                    ini_set('memory_limit', '100M');


                    $max_distance = $config->get_float(DeduplicateConfig::MAXIMUM_DISTANCE);
                    if ($max_distance < 0) {
                        $max_distance = 0;
                    }

                    $convert = $config->get_string(MediaConfig::CONVERT_PATH);

                    if (empty($convert)) {
                        throw new MediaException("convert command not configured");
                    }

                    $hashes = [];

                    $post_list = [];
                    $i = 0;
                    // Pre-load and filter everything
                    foreach ($event->items as $post) {
                        $i++;
                        if (!MimeType::matches_array($post->get_mime(), self::SUPPORTED_MIME)) {
                            log_debug("deduplicate", "Type {$post->get_mime()} not supported for post $post->id");
                            continue;
                        }
                        if ($post->video===true) {
                            log_debug("deduplicate", "Video not supported for post $post->id");
                            continue;
                        }


                        if ($post->perceptual_hash==null) {
                            $hash = $this->calculate_hash($post);
                        } else {
                            $hash = pg_unescape_bytea(stream_get_contents($post->perceptual_hash));
                            $hash = Hash::fromHex($hash);
                        }
                        if (empty($hash)) {
                            continue;
                        }

                        $hashes[$post->id] = $hash;
                        $post_list[] = $post;
                    }

                    $similar_posts = 0;
                    $iterations = 0;
                    $count = 0;
                    while (count($post_list) > 0) {
                        $post_1 = array_pop($post_list);
                        $post_count = count($post_list);
                        for ($i = 0; $i < $post_count; $i++) {
                            $post_2 = $post_list[$i];

                            if ($post_1->id == $post_2->id) {
                                continue;
                            }

                            $iterations++;

                            $database->begin_transaction();
                            try {
                                $record = $this->get_similarity_record($post_1->id, $post_2->id);
                                if ($record != null) {
                                    unset($record);
                                    // Posts have already been compared
                                    continue;
                                };

                                $hash1 = $hashes[$post_1->id];
                                $hash2 = $hashes[$post_2->id];

                                $distance = $hash1->distance($hash2);

                                if ($distance <= $max_distance) {
                                    $similar_posts++;
                                    $this->record_similarity($post_1->id, $post_2->id, $distance);
                                }

                                unset($hash);
                                unset($distance);
                                $database->commit();
                            } catch (Exception $e) {
                                try {
                                    $database->rollback();
                                } catch (Exception $e) {
                                }

                                throw new DeduplicateException("An error occurred while comparing posts $post_1->id and $post_2->id: " . $e->getMessage());
                            }
                        }

                        unset($hashes[$post_1->id]);
                        unset($post_1);
                        $count++;
                    }
                    $page->flash("Compared $count items across $iterations combinations, found $similar_posts new similar items");
                }
                break;
        }
    }

    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event)
    {
        global $user;

        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_action("bulk_deduplicate", "Scan for similar posts");
            $event->add_action("bulk_deduplicate_clear", "Clear similar posts");
        }
    }

    public function save_perceptual_hash(Image $post, $hash)
    {
        global $database;

        $values = ["post_id"=>$post->id, "data"=>pg_escape_bytea($hash->toHex())];

        $database->execute("UPDATE images SET perceptual_hash =:data  WHERE id = :post_id", $values);
    }

    public function install()
    {
        global $database, $config;

        if ($config->get_int(DeduplicateConfig::VERSION) < 1) {
            $database->create_table("post_similarities", "
                post_1_id INTEGER NOT NULL,
                post_2_id INTEGER NOT NULL,
                similarity real NOT NULL,
                saved BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (post_1_id,post_2_id),
                FOREIGN KEY (post_1_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (post_2_id) REFERENCES images(id) ON DELETE CASCADE
            ");


            $database->execute("CREATE INDEX posts_sim_post_id_1_idx ON post_similarities(post_1_id)", []);
            $database->execute("CREATE INDEX posts_sim_post_id_2_idx ON post_similarities(post_2_id)", []);
            $database->execute("CREATE INDEX posts_sim_saved_idx ON post_similarities(saved)", []);

            $database->Execute("ALTER TABLE images ADD COLUMN perceptual_hash bytea NULL");

            $config->set_int(DeduplicateConfig::VERSION, 1);
        }
    }

    private function perform_deduplication_action(string $action, int $left_post, int $right_post, ?int $left_parent, ?int $right_parent)
    {
        switch ($action) {
            case "merge_left":
                $this->merge_posts($right_post, $left_post);
                break;
            case "merge_right":
                $this->merge_posts($left_post, $right_post);
                break;
            case "delete_right":
                $this->delete_item_by_id($right_post);
                break;
            case "delete_left":
                $this->delete_item_by_id($left_post);
                break;
            case "delete_both":
                $this->delete_item_by_id($left_post);
                $this->delete_item_by_id($right_post);
                break;
            case "dismiss":
                $this->delete_similarity($left_post, $right_post);
                break;
            case "dismiss_all":
                if (isset($_POST["other_posts"]) && !empty($_POST["other_posts"])) {
                    $other_posts = json_decode($_POST["other_posts"]);
                } else {
                    throw new DeduplicateException("other_posts required");
                }

                foreach ($other_posts as $other_post) {
                    $this->delete_similarity($left_post, $other_post);
                }
                break;
            case "save":
                $this->save_similarity($left_post, $right_post, false);
                break;
            case "save_and_tag":
                $this->save_similarity($left_post, $right_post, true);
                break;
            case "save_all":
                if (isset($_POST["other_posts"]) && !empty($_POST["other_posts"])) {
                    $other_posts = json_decode($_POST["other_posts"]);
                } else {
                    throw new DeduplicateException("other_posts required");
                }

                foreach ($other_posts as $other_post) {
                    $this->save_similarity($left_post, $other_post, false);
                }
                break;
            case "dismiss_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_post, $right_post, $pool);
                $this->delete_similarity($left_post, $right_post);
                break;
            case "save_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_post, $right_post, $pool);
                $this->save_similarity($left_post, $right_post);
                break;
            case "dismiss_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_post, $left_post));
                $this->delete_similarity($left_post, $right_post);
                break;
            case "dismiss_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_post, $right_post));
                $this->delete_similarity($left_post, $right_post);
                break;
            case "dismiss_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_post, $left_parent));
                $this->delete_similarity($left_post, $right_post);
                break;
            case "dismiss_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_post, $right_parent));
                $this->delete_similarity($left_post, $right_post);
                break;
            case "save_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_post, $left_post));
                $this->save_similarity($left_post, $right_post);
                break;
            case "save_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_post, $right_post));
                $this->save_similarity($left_post, $right_post);
                break;
            case "save_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_post, $left_parent));
                $this->save_similarity($left_post, $right_post);
                break;
            case "save_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_post, $right_parent));
                $this->save_similarity($left_post, $right_post);
                break;
            default:
                throw new DeduplicateException("Action not supported: " . $action);
        }
    }

    private function build_deduplication_page(int $id, ?int $id2 = null)
    {
        global $page, $database;

        $set = $this->get_post_set($id, $id2);


        $pools = [];
        $default_pool = "";
        if (Extension::is_enabled(PoolsInfo::KEY)) {
            $pools = $database->get_all("SELECT * FROM pools ORDER BY title");
            $data = $database->get_all(
                "SELECT * FROM pool_images WHERE image_id = :id",
                ["id" => $set->post->id]
            );
            if ($data && count($data) > 0) {
                $default_pool = $data[0]["pool_id"];
            }
        }
        $this->theme->display_post($set);
        $this->theme->add_action_block($set, $pools, $default_pool);
    }

    private function get_similarity_record(int $post_id_1, int $post_id_2): ?array
    {
        global $database;

        return $database->get_row(
            "SELECT * FROM post_similarities WHERE post_1_id = :post_1_id AND post_2_id = :post_2_id",
            [
                "post_1_id" => min($post_id_1, $post_id_2),
                "post_2_id" => max($post_id_1, $post_id_2)]
        );
    }

    private function record_similarity(int $post_id_1, int $post_id_2, float $similarity)
    {
        global $database;

        if ($post_id_1==$post_id_2) {
            throw new Exception("Post IDs cannot be the same");
        }

        return $database->execute(
            "INSERT INTO post_similarities (post_1_id,post_2_id ,similarity) VALUES (:post_1_id,:post_2_id,:similarity)",
            [
                "post_1_id" => min($post_id_1, $post_id_2),
                "post_2_id" => max($post_id_1, $post_id_2),
                "similarity" => $similarity
            ]
        );
    }

    private function save_similarity(int $post_id_1, int $post_id_2, bool $equalize_tags)
    {
        global $database;

        if($equalize_tags) {
            $post1 = Image::by_id($post_id_1);
            $post2 = Image::by_id($post_id_2);
            $tags1 = $post1->get_tag_array();
            $tags2 = $post2->get_tag_array();
            if ($tags1!=$tags2) {
                // If the tags are already the same, don't wast time re-setting them
                $new_tags = array_merge($post1->get_tag_array(), $post2->get_tag_array());
                send_event(new TagSetEvent($post1, $new_tags));
                send_event(new TagSetEvent($post2, $new_tags));
            }
        }

        return $database->execute(
            "UPDATE post_similarities SET saved = :true WHERE post_1_id = :post_1_id AND post_2_id = :post_2_id"
            ,
            [
                "post_1_id" => min($post_id_1, $post_id_2),
                "post_2_id" => max($post_id_1, $post_id_2),
                "true" => true
            ]
        );
    }

    private function delete_similarity(int $post_id_1, int $post_id_2)
    {
        global $database;

        return $database->execute(
            "DELETE FROM post_similarities WHERE post_1_id = :post_1_id AND post_2_id = :post_2_id",
            [
                "post_1_id" => min($post_id_1, $post_id_2),
                "post_2_id" => max($post_id_1, $post_id_2)
            ]
        );
    }

    private function delete_similarities(int $post_id)
    {
        global $database;

        return $database->execute(
            "DELETE FROM post_similarities WHERE post_1_id = :post_id OR post_2_id = :post_id",
            [
                "post_id" => $post_id,
            ]
        );
    }

    private function merge_posts(int $source, int $target)
    {
        $source_post = Image::by_id($source);
        $target_post = Image::by_id($target);

        $source_tags = $source_post->get_tag_array() ?? [];
        $target_tags = $target_post->get_tag_array() ?? [];

        $new_tags = array_unique(array_merge($source_tags, $target_tags));

        send_event(new TagSetEvent($target_post, $new_tags));

        $this->delete_item($source_post, "Merged into $target_post->hash via de-duplicate");
    }

    private function add_to_pool($left_post, $right_post, $pool)
    {
        send_event(new PoolAddPostsEvent($pool, [$left_post, $right_post]));
    }

    private function delete_item_by_id(int $post_id)
    {
        $this->delete_item(Image::by_id($post_id), "Deleted via de-duplicate");
    }


    private function delete_item(Image $post, String $reason)
    {
        //send_event(new AddImageHashBanEvent($item->hash, $reason));
        send_event(new ImageDeletionEvent($post));
    }


    private function calculate_hash(Image $post)
    {
        global $database;

        $database->begin_transaction();
        try {
            $hash = $this->hasher->hash($post->get_image_filename());
            $this->save_perceptual_hash($post, $hash);

            if (empty($hash)) {
                return [];
            }
            $database->commit();
        } catch (Exception $e) {
            $database->rollback();
            return [];
        }
        return $hash;
    }

    private function get_post_set(int $id, ?int $id2): ComparisonSet
    {
        global $database;

        $post = Image::by_id($id);

        if ($post == null) {
            throw new DeduplicateException("Post ID not found: $id");
        }

        $set = new ComparisonSet($post);


        $relationships = Extension::is_enabled(RelationshipsInfo::KEY);
        if ($relationships) {
            if ($post->parent_id) {
                $set->parent = Image::by_id($post->parent_id);
                $set->siblings= Relationships::get_children($set->parent, $post->id);
            }
            $set->children = Relationships::get_children($post);
        }

        if (Extension::is_enabled(TrashInfo::KEY)) {
            $sub_data = $database->get_all("SELECT * FROM post_similarities
                        INNER JOIN images i1 on post_similarities.post_1_id = i1.id AND i1.trash = :false
                        INNER JOIN images i2 on post_similarities.post_2_id = i2.id AND i2.trash = :false
                        WHERE (post_1_id = :post_id OR post_2_id = :post_id) AND saved = :false  ORDER BY similarity asc"
            , ["post_id" => $id, "false" => false]);
        } else {
            $sub_data = $database->get_all(
                "SELECT * FROM post_similarities  WHERE (post_1_id = :post_id OR post_2_id = :post_id) AND saved = :false  ORDER BY similarity asc",
                ["post_id" => $id, "false"=>false]);
        }

        foreach ($sub_data as $sub_post) {
            $other_id = $sub_post["post_1_id"];
            if ($other_id == $id) {
                $other_id = $sub_post["post_2_id"];
            }
            $post = Image::by_id($other_id);

            $si = new SimilarItem($post, floatval($sub_post["similarity"]));

            if ($relationships) {
                if ($post->parent_id) {
                    $si->parent = Image::by_id($post->parent_id);
                    $si->siblings= Relationships::get_children($si->parent, $post->id);
                }
                $si->children = Relationships::get_children($post);
            }
            if ($id2===$si->post->id) {
                array_unshift($set->similar_posts, $si);
            } else {
                $set->similar_posts[] = $si;
            }
        }
        return $set;
    }
}
