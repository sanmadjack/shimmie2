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
    public $image;

    public $children = [];

    public $siblings = [];

    public $parent = null;

    public $similar_items = [];

    public function __construct(Image $image)
    {
        $this->image = $image;
    }

    public function other_ids(): array
    {
        $output = [];
        foreach ($this->similar_items as $item) {
            $output[] = $item->image->id;
        }
        return $output;
    }
}

class SimilarItem
{
    /** @var float */
    public $similarity;
    /** @var Image */
    public $image;

    public $children = [];

    public $siblings = [];

    public $parent = null;

    public function __construct(Image $image, float $similarity)
    {
        $this->image = $image;
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

    const SUPPORTED_EXTENSIONS = ["png", "jpeg", "jpg", "webp", "bmp", "tiff", "gif"];

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
        $sb->add_bool_option(DeduplicateConfig::SHOW_SAVED, "Show saved on post", true);
        $sb->end_table();
    }

    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event)
    {
        global $config, $database;
        if ($config->get_bool(DeduplicateConfig::SHOW_SAVED)) {
            $results = Image::find_images(0, 10, ["similar:".$event->image->id]);

            $event->add_part($this->theme->show_similar_items($results), 35);
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $user, $database;

        if ($event->page_matches(self::PAGE_NAME)) {
            if (!$user->can(Permissions::DEDUPLICATE)) {
                $this->theme->display_permission_denied();
            } else {
                if ($event->arg_count > 1 && $event->args[1] == "action") {
                    $left_image = @$_POST["left_image"];
                    $right_image = @$_POST["right_image"];
                    $left_parent = @$_POST["left_parent"];
                    $right_parent = @$_POST["right_parent"];

                    $action = $_POST["action"];
                    if (empty($left_image) || !is_numeric($left_image)) {
                        throw new SCoreException("left_image required");
                    }
                    if ($action!=="dismiss_all" && $action!=="save_all"
                        && (empty($right_image) || !is_numeric($right_image))) {
                        throw new SCoreException("right_image required");
                    }
                    if (empty($action)) {
                        throw new SCoreException("action required");
                    }
                    $left_image = intval($left_image);
                    $right_image = intval($right_image);

                    if (is_numeric($left_parent)) {
                        $left_parent = intval($left_parent);
                    }
                    if (is_numeric($right_parent)) {
                        $right_parent = intval($right_parent);
                    }


                    $this->perform_deduplication_action($action, $left_image, $right_image, $left_parent, $right_parent);

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link(self::PAGE_NAME));

                    return;
                }

                $this->theme->display_page();

                if ($event->count_args() == 0) {
                    if (Extension::is_enabled(TrashInfo::KEY)) {
                        $one = $database->get_one($database->scoreql_to_sql(
                            "SELECT min(image_1_id) id FROM image_similarities
                                    INNER JOIN images i1 on image_similarities.image_1_id = i1.id AND i1.trash = SCORE_BOOL_N
                                    INNER JOIN images i2 on image_similarities.image_2_id = i2.id AND i2.trash = SCORE_BOOL_N
                                     WHERE saved = SCORE_BOOL_N "
                        ));
                    } else {
                        $one = $database->get_one($database->scoreql_to_sql(
                            "SELECT min(image_1_id) id FROM image_similarities WHERE saved = SCORE_BOOL_N "
                        ));
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
                $database->scoreql_to_sql(
                    " id IN (SELECT DISTINCT CASE WHEN image_1_id = :similar_id THEN image_2_id ELSE image_1_id END FROM image_similarities WHERE (image_2_id = :similar_id OR  image_1_id = :similar_id) AND saved = SCORE_BOOL_Y)"
                ),
                ["similar_id"=>$id]
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

                    $image_list = [];
                    $i = 0;
                    // Pre-load and filter everything
                    foreach ($event->items as $image) {
                        $i++;
                        if (!MimeType::matches_array($image->get_mime(), self::SUPPORTED_MIME)) {
                            log_debug("deduplicate", "Type {$image->get_mime()} not supported for item $image->id");
                            continue;
                        }
                        if ($image->video===true) {
                            log_debug("deduplicate", "Video not supported for item $image->id");
                            continue;
                        }


                        if ($image->imagehash==null) {
                            $hash = $this->calculate_hash($image);
                        } else {
                            $hash = pg_unescape_bytea(stream_get_contents($image->imagehash));
                            $hash = Hash::fromHex($hash);
                        }
                        if (empty($hash)) {
                            continue;
                        }

                        $hashes[$image->id] = $hash;
                        $image_list[] = $image;
                    }

                    $similar_items = 0;
                    $iterations = 0;
                    $count = 0;
                    while (count($image_list) > 0) {
                        $image_1 = array_pop($image_list);
                        $item_count = count($image_list);
                        for ($i = 0; $i < $item_count; $i++) {
                            $image_2 = $image_list[$i];

                            if ($image_1->id == $image_2->id) {
                                continue;
                            }

                            $iterations++;

                            $database->begin_transaction();
                            try {
                                $record = $this->get_similarity_record($image_1->id, $image_2->id);
                                if ($record != null) {
                                    unset($record);
                                    // Images have already been compared
                                    continue;
                                };

                                $hash1 = $hashes[$image_1->id];
                                $hash2 = $hashes[$image_2->id];

                                $distance = $hash1->distance($hash2);

                                if ($distance <= $max_distance) {
                                    $similar_items++;
                                    $this->record_similarity($image_1->id, $image_2->id, $distance);
                                }

                                unset($hash);
                                unset($distance);
                                $database->commit();
                            } catch (Exception $e) {
                                try {
                                    $database->rollback();
                                } catch (Exception $e) {
                                }

                                throw new DeduplicateException("An error occurred while comparing images $image_1->id and $image_2->id: " . $e->getMessage(), $e->getCode(), $e);
                            }
                        }

                        unset($hashes[$image_1->id]);
                        unset($image_1);
                        $count++;
                    }
                    $page->flash("Compared $count items across $iterations combinations, found $similar_items new similar items");
                }
                break;
        }
    }

    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event)
    {
        global $user;

        if ($user->can(Permissions::DEDUPLICATE)) {
            $event->add_action("bulk_deduplicate", "Scan for similar images");
        }
    }

    public function save_perceptual_hash(Image $image, $hash)
    {
        global $database;

        $values = ["image_id"=>$image->id, "data"=>pg_escape_bytea($hash->toHex())];

        $database->execute("UPDATE images SET imagehash =:data  WHERE id = :image_id", $values);
    }

    public function install()
    {
        global $database, $config;

        if ($config->get_int(DeduplicateConfig::VERSION) < 1) {
            $database->create_table("image_similarities", $database->scoreql_to_sql("
                image_1_id INTEGER NOT NULL,
                image_2_id INTEGER NOT NULL,
                similarity real NOT NULL,
                saved SCORE_BOOL NOT NULL DEFAULT SCORE_BOOL_N,
                PRIMARY KEY (image_1_id,image_2_id),
                FOREIGN KEY (image_1_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (image_2_id) REFERENCES images(id) ON DELETE CASCADE
            "));


            $database->execute("CREATE INDEX images_sim_image_id_1_idx ON image_similarities(image_1_id)", []);
            $database->execute("CREATE INDEX images_sim_image_id_2_idx ON image_similarities(image_2_id)", []);
            $database->execute("CREATE INDEX images_sim_saved_idx ON image_similarities(saved)", []);

            $database->Execute("ALTER TABLE images ADD COLUMN imagehash bytea NULL");

            $config->set_int(DeduplicateConfig::VERSION, 1);
        }
    }

    private function perform_deduplication_action(string $action, int $left_image, int $right_image, ?int $left_parent, ?int $right_parent)
    {
        switch ($action) {
            case "merge_left":
                $this->merge_items($right_image, $left_image);
                break;
            case "merge_right":
                $this->merge_items($left_image, $right_image);
                break;
            case "delete_right":
                $this->delete_item_by_id($right_image);
                break;
            case "delete_left":
                $this->delete_item_by_id($left_image);
                break;
            case "delete_both":
                $this->delete_item_by_id($left_image);
                $this->delete_item_by_id($right_image);
                break;
            case "dismiss":
                $this->delete_similarity($left_image, $right_image);
                break;
            case "dismiss_all":
                if (isset($_POST["other_images"]) && !empty($_POST["other_images"])) {
                    $other_images = json_decode($_POST["other_images"]);
                } else {
                    throw new DeduplicateException("other_images required");
                }

                foreach ($other_images as $other_image) {
                    $this->delete_similarity($left_image, $other_image);
                }
                break;
            case "save":
                $this->save_similarity($left_image, $right_image);
                break;
            case "save_all":
                if (isset($_POST["other_images"]) && !empty($_POST["other_images"])) {
                    $other_images = json_decode($_POST["other_images"]);
                } else {
                    throw new DeduplicateException("other_images required");
                }

                foreach ($other_images as $other_image) {
                    $this->save_similarity($left_image, $other_image);
                }
                break;
            case "dismiss_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_image, $right_image, $pool);
                $this->delete_similarity($left_image, $right_image);
                break;
            case "save_to_pool":
                $pool = "";
                if (isset($_POST["target_pool"]) && !empty($_POST["target_pool"])) {
                    $pool = intval($_POST["target_pool"]);
                } else {
                    throw new DeduplicateException("target_pool required");
                }

                $this->add_to_pool($left_image, $right_image, $pool);
                $this->save_similarity($left_image, $right_image);
                break;
            case "dismiss_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_image, $left_image));
                $this->delete_similarity($left_image, $right_image);
                break;
            case "dismiss_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_image, $right_image));
                $this->delete_similarity($left_image, $right_image);
                break;
            case "dismiss_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_image, $left_parent));
                $this->delete_similarity($left_image, $right_image);
                break;
            case "dismiss_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_image, $right_parent));
                $this->delete_similarity($left_image, $right_image);
                break;
            case "save_left_as_parent":
                send_event(new ImageRelationshipSetEvent($right_image, $left_image));
                $this->save_similarity($left_image, $right_image);
                break;
            case "save_right_as_parent":
                send_event(new ImageRelationshipSetEvent($left_image, $right_image));
                $this->save_similarity($left_image, $right_image);
                break;
            case "save_use_left_parent":
                if (empty($left_parent)) {
                    throw new DeduplicateException("left_parent required");
                }
                send_event(new ImageRelationshipSetEvent($right_image, $left_parent));
                $this->save_similarity($left_image, $right_image);
                break;
            case "save_use_right_parent":
                if (empty($right_parent)) {
                    throw new DeduplicateException("right_parent required");
                }
                send_event(new ImageRelationshipSetEvent($left_image, $right_parent));
                $this->save_similarity($left_image, $right_image);
                break;
            default:
                throw new DeduplicateException("Action not supported: " . $action);
        }
    }

    private function build_deduplication_page(int $id, ?int $id2 = null)
    {
        global $page, $database;

        $set = $this->get_item_set($id, $id2);


        $pools = [];
        $default_pool = "";
        if (Extension::is_enabled(PoolsInfo::KEY)) {
            $pools = $database->get_all("SELECT * FROM pools ORDER BY title");
            $data = $database->get_all(
                "SELECT * FROM pool_images WHERE image_id = :id",
                ["id" => $set->image->id]
            );
            if ($data && count($data) > 0) {
                $default_pool = $data[0]["pool_id"];
            }
        }
        $this->theme->display_item($set);
        $this->theme->add_action_block($set, $pools, $default_pool);
    }

    private function get_similarity_record(int $image_id_1, int $image_id_2): ?array
    {
        global $database;

        return $database->get_row(
            "SELECT * FROM image_similarities WHERE image_1_id = :image_1_id AND image_2_id = :image_2_id",
            [
                "image_1_id" => min($image_id_1, $image_id_2),
                "image_2_id" => max($image_id_1, $image_id_2)]
        );
    }

    private function record_similarity(int $image_id_1, int $image_id_2, float $similarity)
    {
        global $database;

        if ($image_id_1==$image_id_2) {
            throw new Exception("Post IDs cannot be the same");
        }

        return $database->execute(
            "INSERT INTO image_similarities (image_1_id,image_2_id ,similarity) VALUES (:image_1_id,:image_2_id,:similarity)",
            [
                "image_1_id" => min($image_id_1, $image_id_2),
                "image_2_id" => max($image_id_1, $image_id_2),
                "similarity" => $similarity
            ]
        );
    }

    private function save_similarity(int $image_id_1, int $image_id_2)
    {
        global $database;

        return $database->execute(
            $database->scoreql_to_sql(
                "UPDATE image_similarities SET saved = SCORE_BOOL_Y WHERE image_1_id = :image_1_id AND image_2_id = :image_2_id"
            ),
            [
                "image_1_id" => min($image_id_1, $image_id_2),
                "image_2_id" => max($image_id_1, $image_id_2)
            ]
        );
    }

    private function delete_similarity(int $image_id_1, int $image_id_2)
    {
        global $database;

        return $database->execute(
            "DELETE FROM image_similarities WHERE image_1_id = :image_1_id AND image_2_id = :image_2_id",
            [
                "image_1_id" => min($image_id_1, $image_id_2),
                "image_2_id" => max($image_id_1, $image_id_2)
            ]
        );
    }

    private function merge_items(int $source, int $target)
    {
        $source_image = Image::by_id($source);
        $target_image = Image::by_id($target);

        $source_tags = $source_image->get_tag_array() ?? [];
        $target_tags = $target_image->get_tag_array() ?? [];

        $new_tags = array_unique(array_merge($source_tags, $target_tags));

        send_event(new TagSetEvent($target_image, $new_tags));

        $this->delete_item($source_image, "Merged into $target_image->hash via de-duplicate");
    }

    private function add_to_pool($left_image, $right_image, $pool)
    {
        send_event(new PoolAddPostsEvent($pool, [$left_image, $right_image]));
    }

    private function delete_item_by_id(int $item)
    {
        $this->delete_item(Image::by_id($item), "Deleted via de-duplicate");
    }


    private function delete_item(Image $item, String $reason)
    {
        send_event(new AddImageHashBanEvent($item->hash, $reason));
        send_event(new ImageDeletionEvent($item));
    }


    private function calculate_hash(Image $image)
    {
        global $database;

        $database->begin_transaction();
        try {
            $hash = $this->hasher->hash($image->get_image_filename());
            $this->save_perceptual_hash($image, $hash);

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

    private function get_item_set(int $id, ?int $id2): ComparisonSet
    {
        global $database;

        $image = Image::by_id($id);

        if ($image == null) {
            throw new DeduplicateException("Post ID not found: $id");
        }

        $set = new ComparisonSet($image);


        $relationships = Extension::is_enabled(RelationshipsInfo::KEY);
        if ($relationships) {
            if ($image->parent_id) {
                $set->parent = Image::by_id($image->parent_id);
                $set->siblings= Relationships::get_children($set->parent, $image->id);
            }
            $set->children = Relationships::get_children($image);
        }

        if (Extension::is_enabled(TrashInfo::KEY)) {
            $sub_data = $database->get_all($database->scoreql_to_sql(
                "SELECT * FROM image_similarities
                        INNER JOIN images i1 on image_similarities.image_1_id = i1.id AND i1.trash = SCORE_BOOL_N
                        INNER JOIN images i2 on image_similarities.image_2_id = i2.id AND i2.trash = SCORE_BOOL_N
                        WHERE (image_1_id = :image_id OR image_2_id = :image_id) AND saved = SCORE_BOOL_N  ORDER BY similarity asc"
            ), ["image_id" => $id]);
        } else {
            $sub_data = $database->get_all("SELECT * FROM image_similarities  WHERE (image_1_id = :image_id OR image_2_id = :image_id) AND saved = SCORE_BOOL_N  ORDER BY similarity asc", ["image_id" => $id]);
        }

        foreach ($sub_data as $sub_item) {
            $other_id = $sub_item["image_1_id"];
            if ($other_id == $id) {
                $other_id = $sub_item["image_2_id"];
            }
            $image = Image::by_id($other_id);

            $si = new SimilarItem($image, floatval($sub_item["similarity"]));

            if ($relationships) {
                if ($image->parent_id) {
                    $si->parent = Image::by_id($image->parent_id);
                    $si->siblings= Relationships::get_children($si->parent, $image->id);
                }
                $si->children = Relationships::get_children($image);
            }
            if ($id2===$si->image->id) {
                array_unshift($set->similar_items, $si);
            } else {
                $set->similar_items[] = $si;
            }
        }
        return $set;
    }
}
