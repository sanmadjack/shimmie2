<?php declare(strict_types=1);

class PostRatingsMatrixScale
{
    /** @var string */
    public $name = null;

    /** @var string */
    public $code = null;

    /** @var string */
    public $search_term = null;

    /** @var string */
    public $description = null;

    /** @var string */
    public $database_field = null;


    /** @var PostRatingsMatrixValue[] */
    public $values = [];

    /** @var PostRatingsMatrixValue */
    private static $unratedValue;

    public function __construct(string $code, string $name, string $search_term, string $description, string $database_field, array $values)
    {
        assert(strlen($code)==1, "Rating code must be exactly one character");

        $this->name = $name;
        $this->code = $code;
        $this->description = $description;
        $this->database_field = $database_field;
        if ($database_field!="rating_matrix_1"&&$database_field!="rating_matrix_2"&&$database_field!="rating_matrix_3") {
            throw new Exception("database_field must be set to rating_matrix_[1-3]");
        }
        $this->search_term = strtolower($search_term);
        if (self::$unratedValue==null) {
            self::$unratedValue =
                new PostRatingsMatrixValue("?", "Unrated", RatingsMatrix::UNRATED_KEYWORDS, "Unknown", 99999);
        }
        $this->values[self::$unratedValue->code] = self::$unratedValue;
        foreach ($values as $value) {
            $this->addValue($value);
        }
    }

    public function addValue(PostRatingsMatrixValue $value): void
    {
        if ($value->code == "?" && array_key_exists("?", $this->values)) {
            throw new RuntimeException("? is a reserved rating code that cannot be overridden");
        }
        foreach ($value->search_terms as $search_term) {
            if ($value->code != "?" && in_array(strtolower($search_term), RatingsMatrix::UNRATED_KEYWORDS)) {
                throw new RuntimeException("$value->search_terms is a reserved search term");
            }
            foreach ($this->values as $existingValue) {
                if (in_array(strtolower($search_term), $existingValue->search_terms)) {
                    throw new RuntimeException("$search_term is already used by value {$existingValue->name}");
                }
            }
        }
        if (array_key_exists($value->code, $this->values)) {
            var_dump($value);
            throw new Exception("$value->code is already in use by value {$this->values[$value->code]->name}");
        }

        $this->values[$value->code] = $value;
    }

    public function clearValues(): void
    {
        $keys = array_keys($this->values);
        foreach ($keys as $key) {
            if ($key != "?") {
                unset($this->values[$key]);
            }
        }
    }

    public function isValidValue(string $value): bool
    {
        return array_key_exists($value, $this->values);
    }

    public function getPostValue(Image $post): string
    {
        $field = $this->database_field;
        return $post->$field;
    }


    public function setPostValue(Image $post, string $value): void
    {
        if (empty($value)) {
            throw new Exception("value cannot be empty");
        }
        if (!$this->isValidValue($value)) {
            throw new Exception("Invalid scale value: $value");
        }
        $field = $this->database_field;
        $post->$field = $value;
    }

    public function getHumanValue(string $rating): string
    {
        if ($this->isValidValue($rating)) {
            return $this->values[$rating]->name;
        }
        return $rating;
    }

    public function getHumanValueForPost(Image $post): string
    {
        $rating = $this->getPostValue($post);
        return $this->getHumanValue($rating);
    }

    /**
     * @return PostRatingsMatrixValue[]
     */
    public function getSortedValues(): array
    {
        $ratings = array_values($this->values);
        usort($ratings, function ($a, $b) {
            return $a->order <=> $b->order;
        });
        return $ratings;
    }

    private $searchRegExp = "";
    public function getSearchRegExp(): string
    {
        if (empty($this->searchRegExp)) {
            $search_terms = [];
            foreach ($this->values as $key => $rating) {
                $search_terms = array_merge($search_terms, $rating->search_terms);
            }
            $codes = implode("", array_keys($this->values));
            $termsString=implode("|", $search_terms);

            $this->searchRegExp = "/^(".$this->code."|".$this->search_term.")([:=]|>[:=]?|<[:=]?)(?:(\*|[" . $codes . "]+)|(" .
                $termsString."))$/D";
        }
        return $this->searchRegExp;
    }

    public function getSearchTerms(string $term): array
    {
        if (preg_match($this->getSearchRegExp(), strtolower($term), $matches)) {
            $specifiedRatings = $matches[3] ? str_split($matches[3], 1) : [$matches[4]];
            $operator = $matches[2];
            $equal_to = false;
            $less_than = false;
            $greater_than = false;

            if ($specifiedRatings[0] == '*') {
                $specifiedRatings = array_keys($this->values);
            }

            if (strpos($operator, ":")!==false||
                strpos($operator, "=")!==false) {
                $equal_to = true;
            }

            if (strpos($operator, "<")!==false) {
                $less_than = true;
                if (sizeof($specifiedRatings)>1) {
                    // Can't perform relative operation on more than one value
                    return [];
                }
            }
            if (strpos($operator, ">")!==false) {
                $greater_than = true;
                if (sizeof($specifiedRatings)>1) {
                    // Can't perform relative operation on more than one value
                    return [];
                }
            }

            $calculatedRatings = [];
            $found = false;
            foreach ($this->getSortedValues() as $value) {
                if (in_array(strtolower($specifiedRatings[0]), $value->search_terms)
                    ||$value->code==$specifiedRatings[0]) {
                    if ($equal_to) {
                        array_push($calculatedRatings, $value->code);
                    }
                    $found = true;
                    if (!$greater_than&&!$less_than) {
                        break;
                    }
                } else {
                    if ($value->code=="?") {
                        // Unrated doesn't get set as part of range operators
                        continue;
                    }
                    if ($found && $greater_than) {
                        array_push($calculatedRatings, $value->code);
                    }
                    if (!$found && $less_than) {
                        array_push($calculatedRatings, $value->code);
                    }
                }
            }
            if (!$found||empty($calculatedRatings)) {
                // An unknown rating was specified, use defaults
                $calculatedRatings = [];
            }

            return $calculatedRatings;
        }
        return [];
    }
}

class PostRatingsMatrixValue
{
    /** @var string */
    public $name = null;

    /** @var string */
    public $code = null;

    /** @var string */
    public $description = null;

    /** @var string[] */
    public $search_terms = [];

    /** @var int */
    public $order = 0;

    public function __construct(string $code, string $name, array $search_terms, string $description, int $order)
    {
        assert(strlen($code)==1, "Rating code must be exactly one character");

        $this->name = $name;
        $this->code = $code;
        $this->search_terms = array_map('strtolower', $search_terms);
        $this->order = $order;
        $this->description = $description;
    }
}

class RatingsMatrixSetEvent extends Event
{
    /** @var Image */
    public $post;
    /** @var array  */
    public $ratings;

    public function __construct(Image $post, array $ratings)
    {
        parent::__construct();
        global $_shm_rating_matrix;

        foreach (array_keys($ratings) as $scale) {
            assert(in_array($scale, array_keys($_shm_rating_matrix)));
            assert(in_array($ratings[$scale], array_keys($_shm_rating_matrix[$scale])));
        }

        $this->post = $post;
        $this->ratings = $ratings;
    }
}

abstract class RatingsMatrixConfig
{
    const VERSION = "ext_ratings_matrix_version";
    public const USER_FILTERS_PREFIX = "ratings_matrix_user_filters_";
    private const PRIVILEGES_PREFIX = "ext_rating_matrix_user_class_privileges_";

    public static function getPrivilegesSettingName(string $scaleCode, string $userClass): string
    {
        return RatingsMatrixConfig::PRIVILEGES_PREFIX.$userClass."_".$scaleCode;
    }

    public static function setUserClassPrivileges(string $scaleCode, string $userClass, array $values): void
    {
        global $config;
        $config->set_default_array(self::getPrivilegesSettingName($scaleCode, $userClass), $values);
    }

    /**
     * @return string[]
     */
    public static function getUserClassPrivileges(string $scaleCode, string $userClass): array
    {
        global $config;
        return $config->get_array(self::getPrivilegesSettingName($scaleCode, $userClass));
    }

    /**
     * @return string[]
     */
    public static function getUserFilters(string $scaleCode, User $user): array
    {
        global $user_config;

        $available = self::getUserClassPrivileges($scaleCode, $user->class->name);
        $selected = $user_config->get_array(RatingsMatrixConfig::USER_FILTERS_PREFIX.$scaleCode);

        return array_intersect($available, $selected);
    }
}

class RatingsMatrix extends Extension
{
    /** @var PostRatingsMatrixScale[] */
    private static $scales = [];

    /** @var RatingsMatrixTheme */
    protected $theme;

    public const UNRATED_KEYWORDS = ["unknown", "unrated"];

    public static function clearScales(): void
    {
        $keys = array_keys(self::$scales);
        foreach ($keys as $key) {
            if ($key != "?") {
                unset(self::$scales[$key]);
            }
        }
    }

    public static function scaleExists(string $code): bool
    {
        return array_key_exists($code, self::$scales);
    }

    public static function addScale(PostRatingsMatrixScale $scale): void
    {
        if ($scale->code == "?" && self::scaleExists("?")) {
            throw new RuntimeException("? is a reserved rating code that cannot be overridden");
        }
        if ($scale->code != "?" && in_array(strtolower($scale->search_term), RatingsMatrix::UNRATED_KEYWORDS)) {
            throw new RuntimeException("$scale->search_term is a reserved search term");
        }
        if (array_key_exists($scale->code, self::$scales)) {
            throw new Exception("$scale->code is already in use");
        }
        self::$scales[$scale->code] = $scale ;
    }

    public static function getScale(string $code): PostRatingsMatrixScale
    {
        return self::$scales[$code];
    }

    /**
     * @return string[]
     */
    public static function getScaleCodes(): array
    {
        return array_keys(self::$scales);
    }
    /**
     * @return PostRatingsMatrixScale[]
     */
    public static function getScales(): array
    {
        return self::$scales;
    }

    public static function getHumanValue(string $scale, string $rating): string
    {
        if (array_key_exists($scale, self::$scales)) {
            return self::$scales[$scale]->getHumanValue($rating);
        }
        return "Unknown";
    }



    public function onInitExt(InitExtEvent $event)
    {
        global $_shm_user_classes;

        foreach (self::getScaleCodes() as $code) {
            $scale = self::getScale($code);

            foreach (array_keys($_shm_user_classes) as $class) {
                if ($class == "base" || $class == "hellbanned") {
                    continue;
                }
                RatingsMatrixConfig::setUserClassPrivileges($code, $class, array_keys($scale->values));
            }
        }
    }

    private function check_permissions(Image $image): bool
    {
        global $user;

        foreach (self::getScaleCodes() as $key) {
            $scale = self::getScale($key);

            $user_view_level = RatingsMatrixConfig::getUserClassPrivileges($scale->code, $user->class->name);

            if (!in_array($scale->getPostValue($image), $user_view_level)) {
                return false;
            }
        }

        return true;
    }

    public function onInitUserConfig(InitUserConfigEvent $event)
    {
        foreach (self::getScales() as $scale) {
            $event->user_config->set_default_array(
                RatingsMatrixConfig::USER_FILTERS_PREFIX.$scale->code,
                RatingsMatrixConfig::getUserClassPrivileges($scale->code, $event->user->class->name)
            );
        }
    }

    public function onImageDownloading(ImageDownloadingEvent $event)
    {
        /**
         * Deny images upon insufficient permissions.
         **/
        if (!$this->check_permissions($event->image)) {
            throw new SCoreException("Access denied");
        }
    }

    public function onUserOptionsBuilding(UserOptionsBuildingEvent $event)
    {
        global $user, $_shm_rating_matrix;


        $sb = $event->panel->create_new_block("Default Rating Matrix Filter");
        $sb->start_table();
        foreach (array_keys($_shm_rating_matrix) as $key) {
            $scale = $_shm_rating_matrix[$key];

            $levels = self::get_user_class_privs($user, $scale);
            $options = [];
            foreach ($levels as $level) {
                $options[$_shm_rating_matrix[$level]->name] = $level;
                $sb->add_multichoice_option(RatingsMatrixConfig::USER_DEFAULTS, $options, "Output Log Level: ", true);
            }
        }
        $sb->end_table();
        $sb->add_label("This controls the default ratings search results will be filtered by, and nothing else. To override in your search results, add the appropriate rating scale terms to your search.");
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        global $_shm_user_classes;

        $sb = $event->panel->create_new_block("Post Ratings Matrix");
        foreach (array_keys($_shm_user_classes) as $key) {
            if ($key == "base" || $key == "hellbanned") {
                continue;
            }
            $sb->start_table();
            $sb->add_table_header($key);
            foreach (self::getScales() as $scale) {
                $options = [];
                foreach ($scale->getSortedValues() as $value) {
                    $options[$value->name] = $value->code;
                }
                $sb->add_multichoice_option(
                    RatingsMatrixConfig::getPrivilegesSettingName($scale->code, $key),
                    $options,
                    $scale->name,
                    true
                );
            }
            $sb->end_table();
        }
    }

    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        global $page;
        /**
         * Deny images upon insufficient permissions.
         **/
        if (!$this->check_permissions($event->image)) {
            $page->set_mode(PageMode::REDIRECT);
            $page->set_redirect(make_link("post/list"));
        }
    }

    public function onBulkExport(BulkExportEvent $event)
    {
        foreach (self::getScales() as $scale) {
            $event->fields[$scale->name] = $scale->getPostValue($event->image);
        }
    }
    public function onBulkImport(BulkImportEvent $event)
    {
        // TODO: Import code
//        if (property_exists($event->fields, "rating")
//            && $event->fields->rating != null
//            && RatingsMatrix::rating_is_valid($event->fields->rating)) {
//            $this->set_rating($event->image->id, $event->fields->rating, "");
//        }
    }

    public function onRatingsMatrixSet(RatingsMatrixSetEvent $event)
    {
        foreach (self::getScales() as $scale) {
            if (array_key_exists($scale->code, $event->ratings)) {
                $value = $event->ratings[$scale->code];
                if (!empty($value)) {
                    $scale->setPostValue($event->post, $value);
                }
            }
        }

        self::setRating($event->post);
    }

    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event)
    {
        global $user;
        foreach (self::getScales() as $scale) {
            $event->add_part(
                $this->theme->get_rater_html(
                    $scale,
                    $scale->getPostValue($event->image),
                    $user->can(Permissions::EDIT_IMAGE_RATING)
                ),
                80
            );
        }
    }

    public function onImageInfoSet(ImageInfoSetEvent $event)
    {
        global $user;
        if ($user->can(Permissions::EDIT_IMAGE_RATING)) {
            $ratings = [];
            foreach (self::getScales() as $scale) {
                if (isset($_POST[$scale->database_field])) {
                    $ratings[$scale->code] = $_POST[$scale->database_field];
                }
            }
            send_event(new RatingsMatrixSetEvent($event->image, $ratings));
        }
    }

    public function onParseLinkTemplate(ParseLinkTemplateEvent $event)
    {
        foreach (self::getScales() as $scale) {
            $event->replace('$'.$scale->name, $scale->getHumanValueForPost($event->image));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Rating Matrix";

            $ratings = self::get_sorted_ratings();

            $block->body = $this->theme->get_help_html($ratings);
            $event->add_block($block);
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        global $user;

        if (is_null($event->term)) {
            foreach (self::getScales() as $scale) {
                $found = false;
                foreach ($event->context as $term) {
                    if (preg_match($scale->getSearchRegExp(), $term)) {
                        $found  = true;
                        break;
                    }
                }
                if (!$found) {
                    $set = RatingsMatrix::privs_to_sql(RatingsMatrixConfig::getUserFilters($scale->code, $user));
                    $event->add_querylet(new Querylet($scale->database_field . " IN ($set)"));
                }
            }
        } else {
            foreach (self::getScales() as $scale) {
                $ratings = $scale->getSearchTerms($event->term);
                if (!empty($ratings)) {
                    $classCodes = RatingsMatrixConfig::getUserClassPrivileges($scale->code, $user->class->name);
                    $set = RatingsMatrix::privs_to_sql(array_intersect($classCodes, $ratings));
                    $event->add_querylet(new Querylet($scale->database_field . " IN ($set)"));
                }
            }
        }
    }

    public function onTagTermCheck(TagTermCheckEvent $event)
    {
        foreach (self::getScales() as $scale) {
            if (preg_match($scale->getSearchRegExp(), $event->term)) {
                $event->metatag = true;
            }
        }
    }

    public function onTagTermParse(TagTermParseEvent $event)
    {
        global $user;
        $matches = [];

        if (preg_match($this->search_regexp, strtolower($event->term), $matches)) {
            $ratings = $matches[1] ? $matches[1] : $matches[2][0];

            if (count($matches)>2&&in_array($matches[2], self::UNRATED_KEYWORDS)) {
                $ratings = "?";
            }

            $ratings = array_intersect(str_split($ratings), RatingsMatrix::get_user_class_privs($user));
            $rating = $ratings[0];
            $image = Image::by_id($event->image_id);
            $re = new RatingsMatrixSetEvent($image, $rating);
            send_event($re);
        }
    }

    public function onAdminBuilding(AdminBuildingEvent $event)
    {
        global $database;

        foreach (self::getScales() as $scale) {
            $results = $database->get_pairs_iterable("SELECT {$scale->database_field}, COUNT(*) FROM images GROUP BY {$scale->database_field} ORDER BY {$scale->database_field}");
            $original_values = [];
            foreach ($results as $code => $count) {
                $original_values[$code] = "{$scale->getHumanValue($code)} ($count)";
            }

            $this->theme->display_form($scale, $original_values, );
        }
    }

    public function onAdminAction(AdminActionEvent $event)
    {
        global $user, $database;

        $action = $event->action;
        $matches = [];
        $pattern = "/^update_ratings_matrix_([".join(self::getScaleCodes())."])$/i";
        if (preg_match($pattern, $action, $matches)) {
            $event->redirect = true;
            $scale = self::getScale($matches[1]);

            if (is_null($scale)) {
                throw new Exception("Scale not found: {$matches[i]}");
            }

            if (!array_key_exists("rating_old", $_POST) || empty($_POST["rating_old"])) {
                return;
            }
            if (!array_key_exists("rating_new", $_POST) || empty($_POST["rating_new"])) {
                return;
            }
            $old = $_POST["rating_old"];
            $new = $_POST["rating_new"];

            if (!$scale->isValidValue($new)) {
                throw new Exception("Invalid value {$new} for scale {$scale->name}");
            }

            if ($user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
                $result = $database->execute("UPDATE images SET {$scale->database_field} = :new WHERE {$scale->database_field} = :old", ["new"=>$new, "old"=>$old ]);

                log_msg(
                    RatingsMatrixInfo::KEY,
                    SCORE_LOG_INFO,
                    "Changed {$scale->name} ratings from {$scale->getHumanValue($old)} to {$scale->getHumanValue($new)} on {$result->rowCount()} posts",
                    "Changed {$scale->name} ratings from {$scale->getHumanValue($old)} to {$scale->getHumanValue($new)} on {$result->rowCount()} posts"
                );
            }
        }
    }

    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event)
    {
        global $user;

        if ($user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
            $event->add_action(
                "bulk_rate_matrix",
                "Set Rating Matrix",
                "",
                "",
                $this->theme->get_bulk_selection_rater_html(self::getScales())
            );
        }
    }

    public function onBulkAction(BulkActionEvent $event)
    {
        global $page, $user;

        switch ($event->action) {
            case "bulk_rate_matrix":
                if ($user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
                    $ratings = [];
                    foreach (self::getScales() as $scale) {
                        if (isset($_POST[$scale->database_field])) {
                            $ratings[$scale->code] = $_POST[$scale->database_field];
                        } else {
                            $ratings[$scale->code] = "";
                        }
                    }
                    $total = 0;
                    foreach ($event->items as $image) {
                        send_event(new RatingsMatrixSetEvent($image, $ratings));
                        $total++;
                    }

                    $page->flash("Ratings set for $total items");
                }
                break;
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $user, $page, $user_config;

        if ($event->page_matches("admin/bulk_rate")) {
            if (!$user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
                throw new PermissionDeniedException("Permission denied");
            } else {
                $n = 0;
                while (true) {
                    $images = Image::find_images($n, 100, Tag::explode($_POST["query"]));
                    if (count($images) == 0) {
                        break;
                    }

                    reset($images); // rewind to first element in array.

                    foreach ($images as $image) {
                        send_event(new RatingsMatrixSetEvent($image, $_POST['rating']));
                    }
                    $n += 100;
                }
                #$database->execute("
                #	update images set rating=:rating where images.id in (
                #		select image_id from image_tags join tags
                #		on image_tags.tag_id = tags.id where tags.tag = :tag);
                #	", ['rating'=>$_POST["rating"], 'tag'=>$_POST["tag"]]);
                $page->set_mode(PageMode::REDIRECT);
                $page->set_redirect(make_link("post/list"));
            }
        }
    }

    public static function privs_to_sql(array $privs): string
    {
        $arr = [];
        foreach ($privs as $i) {
            $arr[] = "'" . $i . "'";
        }
        if (sizeof($arr)==0) {
            return "' '";
        }
        return join(', ', $arr);
    }

    /**
     * #param string[] $context
     */
    private function no_rating_query(array $context): bool
    {
        foreach ($context as $term) {
            foreach (self::getScales() as $scale) {
                if (preg_match($scale->getSearchRegExp(), $term)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $database;

        if ($this->get_version(RatingsMatrixConfig::VERSION) < 1) {
            $database->execute("ALTER TABLE images ADD COLUMN rating_matrix_1 CHAR(1) NOT NULL DEFAULT '?'");
            $database->execute("ALTER TABLE images ADD COLUMN rating_matrix_2 CHAR(1) NOT NULL DEFAULT '?'");
            $database->execute("ALTER TABLE images ADD COLUMN rating_matrix_3 CHAR(1) NOT NULL DEFAULT '?'");
            $database->execute("CREATE INDEX images__rating_matrix ON images(rating_matrix_1,rating_matrix_2,rating_matrix_3)");
            $this->set_version(RatingsMatrixConfig::VERSION, 1);
        }
    }

    private static function setRating(Image $post)
    {
        global $database;
        $args = ['id'=>$post->id, 'rating_matrix_1'=>'?', 'rating_matrix_2'=>'?', 'rating_matrix_3'=>'?'];

        foreach (self::getScales() as $scale) {
            $args[$scale->database_field] = $scale->getPostValue($post);
        }

        $database->execute("UPDATE images SET rating_matrix_1=:rating_matrix_1,rating_matrix_2=:rating_matrix_2,rating_matrix_3=:rating_matrix_3 WHERE id=:id", $args);
        log_info("rating_matrix", "Rating Matrix for >>{$post->id} set to: {$args['rating_1']},{$args['rating_2']},{$args['rating_3']}");
    }
}

// Default Ratings Matrix scales
RatingsMatrix::addScale(
    new PostRatingsMatrixScale(
        "n",
        "Nudity",
        "nudity",
        "Exposure of sexual characteristics",
        "rating_matrix_1",
        [
    new PostRatingsMatrixValue(
        "n",
        "None",
        ["none"],
        "No ",
        0
    ),
    new PostRatingsMatrixValue(
        "c",
        "close-fitting",
        ["close-fitting"],
        "Covered enough to not qualify for a higher rating, but clothing clearly reveals the form of the body",
        10
    ),
    new PostRatingsMatrixValue(
        "r",
        "Revealing Attire",
        ["revealing"],
        "Depicts clothing that prominently displays the form of the body without excessively displaying sexual characteristics",
        25
    ),
    new PostRatingsMatrixValue(
        "e",
        "Exposing Attire",
        ["exposing"],
        "Depicts clothing that exposes much of the body without fully exposing sexual characteristics",
        50
    ),
    new PostRatingsMatrixValue(
        "t",
        "Transparent Attire",
        ["transparent"],
        "Depicts clothing that is fully exposing one or more sexual characteristic through the clothing",
        60
    ),
    new PostRatingsMatrixValue(
        "b",
        "Borderline",
        ["borderline"],
        "Sexual characteristics are all but exposed with minor coverings",
        75
    ),
    new PostRatingsMatrixValue(
        "p",
        "Partial",
        ["partial"],
        "One or more sexual characteristic is depicted as fully exposed, but not fully depicted",
        75
    ),
    new PostRatingsMatrixValue(
        "i",
        "Implied",
        ["implied"],
        "Fully exposed individual, but without fully depicting sexual characteristics",
        90
    ),
    new PostRatingsMatrixValue(
        "f",
        "Full",
        ["full"],
        "Fully depicted and exposed sexual characteristics",
        100
    ),
]
    )
);
RatingsMatrix::addScale(new PostRatingsMatrixScale(
    "s",
    "Sexuality",
    "sexuality",
    "The depiction of sexual activity",
    "rating_matrix_2",
    [
        new PostRatingsMatrixValue(
            "n",
            "None",
            ["none"],
            "No sexual activity",
            0
        ),
        new PostRatingsMatrixValue(
            "c",
            "Contact",
            ["contact"],
            "Depicts individual(s) in provocative contact that is not a sexual activity",
            20
        ),
        new PostRatingsMatrixValue(
            "p",
            "Provocative",
            ["provocative"],
            "Depicts individual(s) behaving in a manner that provokes arousal",
            30
        ),
        new PostRatingsMatrixValue(
            "s",
            "Suggestive",
            ["suggestive"],
            "Depicts individual(s) behaving in a manner to imply potential sexual activity",
            30
        ),
        new PostRatingsMatrixValue(
            "i",
            "Implicit",
            ["implicit"],
            "Depicts individual(s) engaged in sexual activity without showing  the activity",
            80
        ),
        new PostRatingsMatrixValue(
            "x",
            "Explicit",
            ["explicit"],
            "Depicts individual(s) engaged in sexual activity, showing the activity clearly",
            90
        ),
        new PostRatingsMatrixValue(
            "u",
            "Unconventional",
            ["unconventional", "unusual"],
            "Depicts sexual activity not suitable for categorization with \"explicit\" activity",
            100
        ),
]
));
RatingsMatrix::addScale(new PostRatingsMatrixScale(
    "v",
    "Violence",
    "violence",
    "The depiction of coercive activity",
    "rating_matrix_3",
    [
        new PostRatingsMatrixValue(
            "n",
            "None",
            ["none"],
            "No coercive activity",
            0
        ),
        new PostRatingsMatrixValue(
            "w",
            "Weapons",
            ["weapons"],
            "Depicts weaponry not engaged in combat",
            10
        ),
        new PostRatingsMatrixValue(
            "r",
            "Restraint",
            ["restraint"],
            "Depicts the restraint of an individual",
            20
        ),
        new PostRatingsMatrixValue(
            "t",
            "Threat",
            ["threat"],
            "Depicts the threat of violence",
            20
        ),
        new PostRatingsMatrixValue(
            "c",
            "Combat",
            ["combat"],
            "Depicts individuals engaged in combat",
            30
        ),
        new PostRatingsMatrixValue(
            "a",
            "Assault",
            ["assault"],
            "Depicts an individual assaulting another individual not participating in combat",
            90
        ),
        new PostRatingsMatrixValue(
            "g",
            "Gore",
            ["gore"],
            "Depicts bodily destruction",
            100
        ),
]
));

/** @noinspection PhpIncludeInspection */
@include_once "data/config/ratings_matrix.conf.php";
