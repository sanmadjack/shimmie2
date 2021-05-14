<?php declare(strict_types=1);

use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Hash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;

class DeduplicateConfig
{
    const VERSION = "ext_deduplicate_version";
    const MAXIMUM_VARIANCE = "deduplicate_maximum_variance";
    const SHOW_SAVED = "deduplicate_show_saved";
    const BAN_DELETED_POSTS = "deduplicate_ban_deleted_posts";
}

/*
* This is used by the image deduplicating code when there is an error while deduplicating
*/

class DeduplicateException extends SCoreException
{
}

class PostNotHashableException extends SCoreException
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

    private static $SCAN_RUNNING = false;

    private static $HASH_CACHE = [];

    public function __construct()
    {
        parent::__construct();

        $this->hasher = new ImageHash(new DifferenceHash());
    }


    public function onInitExt(InitExtEvent $event): void
    {
        global $config, $_shm_user_classes, $_shm_ratings;

        if ($config->get_int(DeduplicateConfig::VERSION) < 2) {
            $this->install();
        }

        $config->set_default_float(DeduplicateConfig::MAXIMUM_VARIANCE, 13);
        $config->set_default_bool(DeduplicateConfig::SHOW_SAVED, false);
        $config->set_default_bool(DeduplicateConfig::BAN_DELETED_POSTS, false);


        foreach (array_keys($_shm_user_classes) as $key) {
            if ($key == "base" || $key == "hellbanned") {
                continue;
            }
            $config->set_default_array("ext_rating_" . $key . "_privs", array_keys($_shm_ratings));
        }
    }

    public function onSetupBuilding(SetupBuildingEvent $event): void
    {
        $sb = $event->panel->create_new_block("Deduplication");

        $sb->start_table();
        $sb->add_int_option(DeduplicateConfig::MAXIMUM_VARIANCE, "Max variance", true);
        $sb->add_bool_option(DeduplicateConfig::SHOW_SAVED, "Show saved similar posts", true);
        $sb->add_bool_option(DeduplicateConfig::BAN_DELETED_POSTS, "Ban posts deleted on deduplication screen", true);
        $sb->end_table();
    }

    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event): void
    {
        global $config, $database;
        if ($config->get_bool(DeduplicateConfig::SHOW_SAVED)) {
            $results = Image::find_images(0, 10, ["similar:".$event->image->id]);

            $event->add_part($this->theme->show_similar_posts($results), 35);
        }
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        global $page, $user, $database, $config;

        if ($event->page_matches(self::PAGE_NAME)) {
            if (!$user->can(Permissions::DEDUPLICATE)) {
                $this->theme->display_permission_denied();
            } else {
                if ($_SERVER['REQUEST_METHOD']=="GET") {
                    $max_variance = @$_GET["max_variance"];
                } elseif ($_SERVER['REQUEST_METHOD']=="POST") {
                    $max_variance = @$_POST["max_variance"];
                }

                if (empty($max_variance)) {
                    $max_variance = $config->get_int(DeduplicateConfig::MAXIMUM_VARIANCE);
                } else {
                    $max_variance = intval($max_variance);
                }

                if ($event->arg_count > 1 && $event->args[1] == "action") {
                    $left_post = @$_POST["left_post"];
                    $right_post = @$_POST["right_post"];
                    $left_parent = @$_POST["left_parent"];
                    $right_parent = @$_POST["right_parent"];

                    $action = $_POST["action"];
                    if (empty($left_post) || !is_numeric($left_post)) {
                        throw new SCoreException("left_post required");
                    }
                    if ($action!=="dismiss_checked" && $action!=="save_checked"
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
                    $page->set_redirect(make_link(self::PAGE_NAME, "max_variance=$max_variance"));

                    return;
                }

                $this->theme->display_page();

                //$order_by = "CASE WHEN i1.id > i2.ID THEN i2.filesize ELSE i1.filesize END desc, post_1_id";
                $order_by = "post_1_id";
                //$order_by = "i2.filesize desc, i1.filesize desc";

                if ($event->count_args() == 0) {
                    if (Extension::is_enabled(TrashInfo::KEY)) {
                        $one = $database->get_one("SELECT post_1_id id FROM post_similarities
                                    INNER JOIN images i1 on post_similarities.post_1_id = i1.id AND i1.trash = :false
                                    INNER JOIN images i2 on post_similarities.post_2_id = i2.id AND i2.trash = :false
                                     WHERE saved = :false and similarity <= :similarity
                                     ORDER BY $order_by FETCH FIRST ROW ONLY", ["false"=>false, "similarity"=>$max_variance]);
                    } else {
                        $one = $database->get_one("SELECT post_1_id id FROM post_similarities
                                                            INNER JOIN images i1 on post_similarities.post_1_id = i1.id
                                                            INNER JOIN images i2 on post_similarities.post_2_id = i2.id
                                                            WHERE saved = :false and similarity <= :similarity
                                                         ORDER BY $order_by FETCH FIRST ROW ONLY", ["false"=>false, "similarity"=>$max_variance]);
                    }
                    if ($one != 0) {
                        $this->build_deduplication_page($max_variance, intval($one));
                    } else {
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link(""));
                        return;
                    }
                } else {
                    if ($event->args[1]=="list") {
                        $this->theme->display_list();
                    } else {
                        if ($event->arg_count>2) {
                            $this->build_deduplication_page($max_variance, intval($event->get_arg(0)), intval($event->get_arg(1)));
                        } else {
                            $this->build_deduplication_page($max_variance, intval($event->get_arg(0)));
                        }
                    }
                }

                $page->set_mode(PageMode::PAGE);
            }
        } elseif ($event->page_matches("auto_deduplicate_scan")) {
            if ($user->can(Permissions::DEDUPLICATE)) {
                $this->run_auto_scan(); // Start upload
            } else {
                $this->theme->display_permission_denied();
            }
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event): void
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

    public function onHelpPageListBuilding(HelpPageListBuildingEvent $event)
    {
        global $user;
        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_page("deduplicate", "Deduplicate");
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event): void
    {
        global $user;
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Similar Posts";
            $block->body = $this->theme->get_search_help_html();
            $event->add_block($block);
        }
        if ($event->key==="deduplicate"&&$user->can(Permissions::DEDUPLICATE)) {
            $block = new Block();
            $block->header = "Deduplicate";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }

    public function onTagTermParse(TagTermParseEvent $event): void
    {
        $matches = [];

        if (preg_match(self::SEARCH_TERM_REGEX, strtolower($event->term), $matches) && $event->parse) {
            // Nothing to save, just helping filter out reserved tags
            if (!empty($matches)) {
                $event->metatag = true;
            }
        }
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        global $user;
        if ($event->parent==="system") {
            if ($user->can(Permissions::DEDUPLICATE)) {
                $event->add_nav_link("deduplicate", new Link(self::PAGE_NAME), "Deduplicate");
            }
        }
    }


    public function onUserBlockBuilding(UserBlockBuildingEvent $event): void
    {
        global $user;
        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_link("Deduplicate", make_link(self::PAGE_NAME));
        }
    }

    public function onBulkAction(BulkActionEvent $event): void
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
                    $max_variance = $config->get_float(DeduplicateConfig::MAXIMUM_VARIANCE);
                    if ($max_variance < 0) {
                        $max_variance = 0;
                    }

                    $convert = $config->get_string(MediaConfig::CONVERT_PATH);

                    if (empty($convert)) {
                        throw new MediaException("convert command not configured");
                    }

                    $result = $this->scan_for_similar_posts($event->items, $max_variance);

                    $page->flash("Compared {$result["count"]} items across {$result["iterations"]} combinations, found {$result["similar_posts"]} new similar items");
                }
                break;
        }
    }

    public function scan_for_similar_posts(iterable $posts, $max_variance): array
    {
        global $database;

        $post_list = [];
        $i = 0;
        // Pre-load and filter everything
        foreach ($posts as $post) {
            $i++;
            if (!MimeType::matches_array($post->get_mime(), self::SUPPORTED_MIME)) {
                log_debug("deduplicate", "Type {$post->get_mime()} not supported for post $post->id");
                continue;
            }
            if ($post->video===true) {
                log_debug("deduplicate", "Video not supported for post $post->id");
                continue;
            }


            $hash = $this->get_hash_for_post($post);

            if (empty($hash)) {
                continue;
            }
            self::$HASH_CACHE[$post->id] = $hash;
            $post_list[] = $post;
        }

        $similar_posts = 0;
        $iterations = 0;
        $count = 0;
        $max_id = 0;
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
                    if ($this->compare_posts($post_1, $post_2, $max_variance)) {
                        $similar_posts++;
                        $database->commit();
                    } else {
                        $database->rollback();
                    }
                } catch (Exception $e) {
                    try {
                        $database->rollback();
                    } catch (Exception $e) {
                    }

                    throw new DeduplicateException("An error occurred while comparing posts $post_1->id and $post_2->id: " . $e->getMessage());
                }
            }

            unset($post_1);
            $count++;
        }

        return [
            "count"=>$count,
            "iterations"=>$iterations,
            "similar_posts"=>$similar_posts
        ];
    }

    private function compare_posts(Image $post_1, Image $post_2, float $max_variance): bool
    {
        $mime_1 = $post_1->get_mime();
        $mime_2 = $post_1->get_mime();
        $this->log_message(SCORE_LOG_INFO, "Comparing posts {$post_1->id} ($mime_1) and {$post_2->id} ($mime_2)");
        if (!MimeType::matches_array($post_1->get_mime(), self::SUPPORTED_MIME)) {
            $this->log_message(SCORE_LOG_DEBUG, "Post {$post_1->id} not supported ($mime_1), skipping");
            return false;
        }
        if (!MimeType::matches_array($post_2->get_mime(), self::SUPPORTED_MIME)) {
            $this->log_message(SCORE_LOG_DEBUG, "Post {$post_2->id} not supported($mime_2), skipping");
            return false;
        }

        $record = $this->get_similarity_record($post_1->id, $post_2->id);
        if ($record != null) {
            unset($record);
            $this->log_message(SCORE_LOG_DEBUG, "Posts {$post_1->id} and {$post_2->id} have already been found to be similar, returning early");
            // Posts have already been compared
            return true;
        };

        $hash1 = $this->get_hash_for_post($post_1);
        $hash2 = $this->get_hash_for_post($post_2);

        if (empty($hash1)) {
            $this->log_message(SCORE_LOG_DEBUG, "Post {$post_1->id} did not successfully generate a hash");
            throw new DeduplicateException("Post {$post_1->id} did not successfully generate a hash");
            return false;
        }
        if (empty($hash2)) {
            $this->log_message(SCORE_LOG_DEBUG, "Post {$post_2->id} did not successfully generate a hash");
            throw new DeduplicateException("Post {$post_2->id} did not successfully generate a hash");
            return false;
        }

        $distance = $hash1->distance($hash2);

        if ($distance <= $max_variance) {
            $this->log_message(SCORE_LOG_INFO, "Posts {$post_1->id} and {$post_2->id} seen as similar");
            $this->record_similarity($post_1->id, $post_2->id, $distance);
            unset($hash);
            unset($distance);
            return true;
        } else {
            $this->log_message(SCORE_LOG_INFO, "Posts {$post_1->id} and {$post_2->id} not seen as similar");
            unset($hash);
            unset($distance);
            return false;
        }
    }

    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event): void
    {
        global $user;

        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_action("bulk_deduplicate", "Scan for similar posts");
            $event->add_action("bulk_deduplicate_clear", "Clear similar posts");
        }
    }

    public function save_perceptual_hash(Image $post, Hash $hash): void
    {
        global $database;

        $values = ["post_id"=>$post->id, "data"=>pg_escape_bytea($hash->toHex())];

        $database->execute("UPDATE images SET perceptual_hash =:data  WHERE id = :post_id", $values);
    }

    public function install(): void
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
            $database->execute("CREATE INDEX posts_sim_saved_similar_idx ON post_similarities(saved, similarity)", []);

            $database->Execute("ALTER TABLE images ADD COLUMN perceptual_hash bytea NULL");

            $config->set_int(DeduplicateConfig::VERSION, 1);
        }
        if ($config->get_int(DeduplicateConfig::VERSION) < 2) {
            $database->Execute("ALTER TABLE images ADD COLUMN auto_dedupe_progress INTEGER NULL");

            $config->set_int(DeduplicateConfig::VERSION, 2);
        }
    }

    private function perform_deduplication_action(string $action, int $left_post_id, int $right_post_id, ?int $left_parent, ?int $right_parent): void
    {
        $left_post = Image::by_id($left_post_id);
        $righ_post = Image::by_id($right_post_id);

        switch ($action) {
            case "merge_left":
                $this->merge_posts($right_post_id, $left_post_id);
                break;
            case "merge_right":
                $this->merge_posts($left_post_id, $right_post_id);
                break;
            case "delete_right":
                $this->delete_item_by_id($right_post_id, "Deleted via de-duplicate in favor of {$left_post->hash}");
                break;
            case "delete_left":
                $this->delete_item_by_id($left_post_id, "Deleted via de-duplicate in favor of {$righ_post->hash}");
                break;
            case "delete_both":
                $this->delete_item_by_id($left_post_id, "Deleted via de-duplicate");
                $this->delete_item_by_id($right_post_id, "Deleted via de-duplicate");
                break;
            case "dismiss":
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "dismiss_checked":
                if (isset($_POST["other_posts"]) && !empty($_POST["other_posts"])) {
                    $other_posts = $_POST["other_posts"];
                } else {
                    throw new DeduplicateException("other_posts required");
                }

                foreach ($other_posts as $other_post) {
                    $this->delete_similarity($left_post_id, intval($other_post));
                }
                break;
            case "save":
                $this->save_similarity($left_post_id, $right_post_id, false);
                break;
            case "save_and_tag":
                $this->save_similarity($left_post_id, $right_post_id, true);
                break;
            case "save_checked":
                if (isset($_POST["other_posts"]) && !empty($_POST["other_posts"])) {
                    $other_posts = $_POST["other_posts"];
                } else {
                    throw new DeduplicateException("other_posts required");
                }

                foreach ($other_posts as $other_post) {
                    $this->save_similarity($left_post_id, intval($other_post), false);
                }
                break;
            case "dismiss_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_post_id, $right_post_id, $pool);
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "save_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_post_id, $right_post_id, $pool);
                $this->save_similarity($left_post_id, $right_post_id);
                break;
            case "dismiss_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_post_id, $left_post_id));
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "dismiss_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_post_id, $right_post_id));
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "dismiss_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_post_id, $left_parent));
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "dismiss_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_post_id, $right_parent));
                $this->delete_similarity($left_post_id, $right_post_id);
                break;
            case "save_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_post_id, $left_post_id));
                $this->save_similarity($left_post_id, $right_post_id);
                break;
            case "save_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_post_id, $right_post_id));
                $this->save_similarity($left_post_id, $right_post_id);
                break;
            case "save_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_post_id, $left_parent));
                $this->save_similarity($left_post_id, $right_post_id);
                break;
            case "save_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_post_id, $right_parent));
                $this->save_similarity($left_post_id, $right_post_id);
                break;
            default:
                throw new DeduplicateException("Action not supported: " . $action);
        }
    }

    private function build_deduplication_page(int $max_variance, int $id, ?int $id2 = null): void
    {
        global $page, $database;

        $set = $this->get_post_set($max_variance, $id, $id2);


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
        $this->theme->display_post($set, $max_variance);
        $this->theme->add_action_block($set, $pools, $default_pool, $max_variance);
    }

    private function build_deduplication_list_page(int $max_variance): void
    {
        global $page, $database;
//
//        $set = $this->get_post_set($max_variance, $id, $id2);
//
//
//        $pools = [];
//        $default_pool = "";
//        if (Extension::is_enabled(PoolsInfo::KEY)) {
//            $pools = $database->get_all("SELECT * FROM pools ORDER BY title");
//            $data = $database->get_all(
//                "SELECT * FROM pool_images WHERE image_id = :id",
//                ["id" => $set->post->id]
//            );
//            if ($data && count($data) > 0) {
//                $default_pool = $data[0]["pool_id"];
//            }
//        }
//        $this->theme->display_post($set, $max_variance);
//        $this->theme->add_action_block($set, $pools, $default_pool, $max_variance);
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

    private function record_similarity(int $post_id_1, int $post_id_2, float $similarity): PDOStatement
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

    private function save_similarity(int $post_id_1, int $post_id_2, bool $equalize_tags): PDOStatement
    {
        global $database;

        if ($equalize_tags) {
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
            "UPDATE post_similarities SET saved = :true WHERE post_1_id = :post_1_id AND post_2_id = :post_2_id",
            [
                "post_1_id" => min($post_id_1, $post_id_2),
                "post_2_id" => max($post_id_1, $post_id_2),
                "true" => true
            ]
        );
    }

    private function delete_similarity(int $post_id_1, int $post_id_2): PDOStatement
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

    private function delete_similarities(int $post_id): PDOStatement
    {
        global $database;

        return $database->execute(
            "DELETE FROM post_similarities WHERE post_1_id = :post_id OR post_2_id = :post_id AND saved = :false",
            [
                "post_id" => $post_id,
                "false" => false
            ]
        );
    }

    private function merge_posts(int $source, int $target): void
    {
        $source_post = Image::by_id($source);
        $target_post = Image::by_id($target);

        $source_tags = $source_post->get_tag_array() ?? [];
        $target_tags = $target_post->get_tag_array() ?? [];

        $new_tags = array_unique(array_merge($source_tags, $target_tags));

        send_event(new TagSetEvent($target_post, $new_tags));

        $this->delete_item($source_post, "Merged into $target_post->hash via de-duplicate");
    }

    private function add_to_pool($left_post, $right_post, $pool): void
    {
        send_event(new PoolAddPostsEvent($pool, [$left_post, $right_post]));
    }

    private function delete_item_by_id(int $post_id, $reason): void
    {
        $this->delete_item(Image::by_id($post_id), $reason);
    }


    private function delete_item(Image $post, String $reason): void
    {
        global $config;
        if ($config->get_bool(DeduplicateConfig::BAN_DELETED_POSTS)) {
            send_event(new AddImageHashBanEvent($post->hash, $reason));
        }
        send_event(new ImageDeletionEvent($post));
    }

    private function get_hash_for_post(Image $post)
    {
        if (self::$HASH_CACHE==null||!array_key_exists($post->id, self::$HASH_CACHE)) {
            if ($post->perceptual_hash==null) {
                $this->log_message(SCORE_LOG_DEBUG, "Hash for post {$post->id} not found, calculating");
                return $this->calculate_hash($post);
            } else {
                $this->log_message(SCORE_LOG_DEBUG, "Hash for post {$post->id} already calculated, loading from record");
                $bytes = pg_unescape_bytea(stream_get_contents($post->perceptual_hash));
                return Hash::fromHex($bytes);
            }
        } else {
            $this->log_message(SCORE_LOG_DEBUG, "Hash for post {$post->id} found in cache");
            return self::$HASH_CACHE[$post->id];
        }
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

    private function get_post_set(int $max_variance, int $id, ?int $id2): ComparisonSet
    {
        global $database;

        $post = Image::by_id($id);

        if ($post == null) {
            throw new DeduplicateException("Post ID not found: $id");
        }

        if ($id2!=null) {
            // Check if there is a similarity for these two
            $result = $this->get_similarity_record($id, $id2);
            if (empty($result)) {
                $other_post = Image::by_id($id2);
                $result = $this->scan_for_similar_posts([$post,$other_post], PHP_INT_MAX);
            }
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

        $args = ["post_id" => $id, "false"=>false, "max_variance" => $max_variance];


        if (Extension::is_enabled(TrashInfo::KEY)) {
            $query = "SELECT * FROM post_similarities
                        INNER JOIN images i1 on post_similarities.post_1_id = i1.id AND i1.trash = :false
                        INNER JOIN images i2 on post_similarities.post_2_id = i2.id AND i2.trash = :false
                    ";
        } else {
            $query = "SELECT * FROM post_similarities";
        }
        $query .= "WHERE (:post_id IN (post_1_id, post_2_id))
                    AND ((saved = :false AND similarity <= :max_variance)";

        if ($id2!=null) {
            $args["id2"] = $id2;
            $query .= " OR :id2 IN (post_similarities.post_1_id, post_similarities.post_2_id) ";
        }
        $query .= ") ORDER BY similarity asc";

        $sub_data = $database->get_all($query, $args);

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

    private function set_headers(): void
    {
        global $page;

        $page->set_mode(PageMode::MANUAL);
        $page->set_mime(MimeType::TEXT);
        $page->send_headers();
    }

    public function onLog(LogEvent $event)
    {
        global $user_config;

        if (self::$SCAN_RUNNING) {
//            $all = $user_config->get_bool(CronUploaderConfig::INCLUDE_ALL_LOGS);
//            if ($event->priority >= $user_config->get_int(CronUploaderConfig::LOG_LEVEL) &&
//                ($event->section==self::NAME || $all)) {
            $output = "[" . date('Y-m-d H:i:s') . "] '[" . $event->section . "'] [" . LOGGING_LEVEL_NAMES[$event->priority] . "] " . $event->message;

            echo $output . "\r\n";
            flush_output();
//            }
        }
    }


    private function log_message(int $severity, string $message): void
    {
        log_msg(DeduplicateInfo::KEY, $severity, $message);
    }

    private function get_lock_file(): string
    {
        return join_path(DATA_DIR, ".dedupe-lock");
    }

    private function determine_next_post_to_scan(int $min_id = 0): ?Image
    {
        global $database;

        $max_id = $database->get_one("SELECT MAX(ID) From Images");
        $this->log_message(SCORE_LOG_DEBUG, "Current max ID is $max_id");

        $next_post_id = $database->get_one(
            "SELECT ID FROM Images
                            WHERE (auto_dedupe_progress IS NULL OR
                                   auto_dedupe_progress < :max_id)
                            AND id >= :min_id ORDER BY ID ASC LIMIT 1",
            ["max_id" => $max_id, "min_id" => $min_id]
        );

        if (empty($next_post_id)) {
            return null;
        }

        return Image::by_id($next_post_id);
    }

    private function run_auto_scan(): bool
    {
        global $database, $user, $user_config, $config, $_shm_load_start;

        $max_time = intval(ini_get('max_execution_time'))*.8;

        //$max_time = 20;

        $this->set_headers();

        if (!$config->get_bool(UserConfig::ENABLE_API_KEYS)) {
            throw new SCoreException("User API keys are note enabled. Please enable them for the auto deduplication scan functionality to work.");
        }

        if ($user->is_anonymous()) {
            throw new SCoreException("User not present. Please specify the api_key for the user to run the process as.");
        }

        $this->log_message(SCORE_LOG_INFO, "Logged in as user {$user->name}");

        if (!$user->can(Permissions::DEDUPLICATE)) {
            throw new SCoreException("User does not have permission to run deduplication scan");
        }

        $lockfile = fopen($this->get_lock_file(), "w");
        if (!flock($lockfile, LOCK_EX | LOCK_NB)) {
            throw new SCoreException("Deduplication scan is already running");
        }


        $total_scanned = 0;
        self::$SCAN_RUNNING  = true;
        try {
            //set_time_limit(0);

            $min_id = 0;
            $max_id = $database->get_one("SELECT MAX(ID) From Images");

            $this->log_message(SCORE_LOG_DEBUG, "Current max ID is $max_id");

            $post_1 = $this->determine_next_post_to_scan();

            $execution_time = microtime(true) - $_shm_load_start;


            $max_variance = $config->get_float(DeduplicateConfig::MAXIMUM_VARIANCE);
            if ($max_variance < 0) {
                $max_variance = 0;
            }

            $this->log_message(SCORE_LOG_DEBUG, "Current max variance is $max_variance");

            while ($execution_time<$max_time && $post_1!=null) {
                $last_other_post_id = $post_1->auto_dedupe_progress;
                if ($last_other_post_id == null) {
                    $last_other_post_id = 0;
                }
                if ($last_other_post_id ==$post_1->id) {
                    $last_other_post_id++;
                }

                try {
                    $min_id = $post_1->id;

                    $this->log_message(SCORE_LOG_DEBUG, "Currently finding comparison post against {$post_1->id}");


                    if (!array_key_exists($post_1->id, self::$HASH_CACHE)) {
                        $hash = $this->get_hash_for_post($post_1);
                        if (!empty($hash)) {
                            self::$HASH_CACHE[$post_1->id] = $hash;
                        } else {
                            // This post can't be hashed, skip it
                            $post_1 = null;
                            throw new PostNotHashableException();
                        }
                    }



                    $other_post_id = $database->get_one(
                        "SELECT ID FROM Images WHERE id > :min_id AND id != :same_id ORDER BY ID ASC LIMIT 1",
                        ["min_id" => $last_other_post_id, "same_id" => $post_1->id]
                    );


                    if ($other_post_id == null) {
                        $this->log_message(SCORE_LOG_WARNING, "No other post was found for post {$post_1->id} with last other post id of $last_other_post_id");
                    } else {
                        $post_2 = Image::by_id($other_post_id);

                        $database->begin_transaction();

                        $this->compare_posts($post_1, $post_2, $max_variance);

                        $database->execute(
                            "UPDATE Images SET auto_dedupe_progress = :other_id WHERE id = :id",
                            ["id" => $post_1->id, "other_id" => $other_post_id]
                        );
                        if ($database->is_transaction_open()) {
                            $database->commit();
                        }
                        $last_other_post_id = $other_post_id;
                        $post_1->auto_dedupe_progress = $last_other_post_id;
                    }
                    $total_scanned++;
                } catch (PostNotHashableException $e) {
                    try {
                        if ($database->is_transaction_open()) {
                            $database->rollback();
                        }
                    } catch (Exception $e) {
                    }

                    $this->log_message(SCORE_LOG_ERROR, "(" . gettype($e) . ") " . $e->getMessage());
                    $this->log_message(SCORE_LOG_ERROR, $e->getTraceAsString());
                    $min_id++;
                } catch (Exception $e) {
                    try {
                        if ($database->is_transaction_open()) {
                            $database->rollback();
                        }
                    } catch (Exception $e) {
                    }

                    $this->log_message(SCORE_LOG_ERROR, "(" . gettype($e) . ") " . $e->getMessage());
                    $this->log_message(SCORE_LOG_ERROR, $e->getTraceAsString());
                }

                if ($post_1==null||$last_other_post_id>=$max_id) {
                    $post_1 = $this->determine_next_post_to_scan($min_id);
                }
                $remaining = $max_time - $execution_time;
                $this->log_message(SCORE_LOG_DEBUG, "Max run time remaining: $remaining");
                $memory_usage = memory_get_usage();
                $this->log_message(SCORE_LOG_DEBUG, "Current memory usage: $memory_usage");
                $execution_time = microtime(true) - $_shm_load_start;
            }

            $rate = $total_scanned / $execution_time;
            $this->log_message(SCORE_LOG_INFO, "Total posts compared: $total_scanned at $rate/sec");
            return true;
        } finally {
            self::$SCAN_RUNNING = false;
            flock($lockfile, LOCK_UN);
            fclose($lockfile);
        }
    }
}
