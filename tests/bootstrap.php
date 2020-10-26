<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

chdir(dirname(dirname(__FILE__)));
require_once "core/sanitize_php.php";
require_once "vendor/autoload.php";
require_once "tests/defines.php";
require_once "core/sys_config.php";
require_once "core/polyfills.php";
require_once "core/util.php";

$_SERVER['QUERY_STRING'] = '/';
if (file_exists("tests/trace.json")) {
    unlink("tests/trace.json");
}

global $cache, $config, $database, $user, $page, $_tracer;
_set_up_shimmie_environment();
$tracer_enabled = true;
$_tracer = new EventTracer();
$_tracer->begin("bootstrap");
_load_core_files();
$cache = new Cache(CACHE_DSN);
$dsn = getenv("TEST_DSN");
$database = new Database($dsn ? $dsn : "sqlite::memory:");
create_dirs();
create_tables($database);
$config = new DatabaseConfig($database);
ExtensionInfo::load_all_extension_info();
Extension::determine_enabled_extensions();
require_all(zglob("ext/{".Extension::get_enabled_extensions_as_string()."}/main.php"));
_load_theme_files();
$page = new Page();
_load_event_listeners();
$config->set_string("thumb_engine", "static");  # GD has less overhead per-call
$config->set_bool("nice_urls", true);
send_event(new DatabaseUpgradeEvent());
send_event(new InitExtEvent());
$_tracer->end();

abstract class ShimmiePHPUnitTestCase extends TestCase
{
    protected static $anon_name = "anonymous";
    protected static $admin_name = "demo";
    protected static $user_name = "test";
    protected $wipe_time = "test";

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $_tracer;
        $_tracer->begin(get_called_class());

        self::create_user(self::$admin_name);
        self::create_user(self::$user_name);
    }

    public function setUp(): void
    {
        global $database, $_tracer;
        $_tracer->begin($this->getName());
        $_tracer->begin("setUp");
        $class = str_replace("Test", "", get_class($this));
        if (!ExtensionInfo::get_for_extension_class($class)->is_supported()) {
            $this->markTestSkipped("$class not supported with this database");
        }

        // Set up a clean environment for each test
        self::log_out();
        foreach ($database->get_col("SELECT id FROM images") as $image_id) {
            send_event(new ImageDeletionEvent(Post::by_id((int)$image_id), true));
        }

        $_tracer->end();  # setUp
        $_tracer->begin("test");
    }

    public function tearDown(): void
    {
        global $_tracer;
        $_tracer->end();  # test
        $_tracer->end();  # $this->getName()
        $_tracer->clear();
        $_tracer->flush("tests/trace.json");
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        global $_tracer;
        $_tracer->end();  # get_called_class()
    }

    protected static function create_user(string $name)
    {
        if (is_null(User::by_name($name))) {
            $userPage = new UserPage();
            $userPage->onUserCreation(new UserCreationEvent($name, $name, "", false));
            assert(!is_null(User::by_name($name)), "Creation of user $name failed");
        }
    }

    protected static function get_page($page_name, $args=null): Page
    {
        // use a fresh page
        global $page;
        if (!$args) {
            $args = [];
        }
        $_SERVER['REQUEST_URI'] = make_link($page_name, http_build_query($args));
        $_GET = $args;
        $_POST = [];
        $page = new Page();
        send_event(new PageRequestEvent($page_name));
        if ($page->mode == PageMode::REDIRECT) {
            $page->code = 302;
        }
        return $page;
    }

    protected static function post_page($page_name, $args=null): Page
    {
        // use a fresh page
        global $page;
        if (!$args) {
            $args = [];
        }
        $_SERVER['REQUEST_URI'] = make_link($page_name);
        foreach ($args as $k=>$v) {
            if (is_array($v)) {
                $args[$k] = $v;
            } else {
                $args[$k] = (string)$v;
            }
        }
        $_GET = [];
        $_POST = $args;
        $page = new Page();
        send_event(new PageRequestEvent($page_name));
        if ($page->mode == PageMode::REDIRECT) {
            $page->code = 302;
        }
        return $page;
    }

    // page things
    protected function assert_title(string $title)
    {
        global $page;
        $this->assertStringContainsString($title, $page->title);
    }

    protected function assert_title_matches($title)
    {
        global $page;
        $this->assertStringMatchesFormat($title, $page->title);
    }

    protected function assert_no_title(string $title)
    {
        global $page;
        $this->assertStringNotContainsString($title, $page->title);
    }

    protected function assert_response(int $code)
    {
        global $page;
        $this->assertEquals($code, $page->code);
    }

    protected function page_to_text(string $section=null)
    {
        global $page;
        if ($page->mode == PageMode::PAGE) {
            $text = $page->title . "\n";
            foreach ($page->blocks as $block) {
                if (is_null($section) || $section == $block->section) {
                    $text .= $block->header . "\n";
                    $text .= $block->body . "\n\n";
                }
            }
            return $text;
        } elseif ($page->mode == PageMode::DATA) {
            return $page->data;
        } else {
            $this->assertTrue(false, "Page mode is not PAGE or DATA");
        }
    }

    protected function assert_text(string $text, string $section=null)
    {
        $this->assertStringContainsString($text, $this->page_to_text($section));
    }

    protected function assert_no_text(string $text, string $section=null)
    {
        $this->assertStringNotContainsString($text, $this->page_to_text($section));
    }

    protected function assert_content(string $content)
    {
        global $page;
        $this->assertStringContainsString($content, $page->data);
    }

    protected function assert_no_content(string $content)
    {
        global $page;
        $this->assertStringNotContainsString($content, $page->data);
    }

    protected function assert_search_results($tags, $results)
    {
        $images = Post::find_images(0, null, $tags);
        $ids = [];
        foreach ($images as $image) {
            $ids[] = $image->id;
        }
        $this->assertEquals($results, $ids);
    }

    // user things
    protected static function log_in_as_admin()
    {
        send_event(new UserLoginEvent(User::by_name(self::$admin_name)));
    }

    protected static function log_in_as_user()
    {
        send_event(new UserLoginEvent(User::by_name(self::$user_name)));
    }

    protected static function log_out()
    {
        global $config;
        send_event(new UserLoginEvent(User::by_id($config->get_int("anon_id", 0))));
    }

    // post things
    protected function post_image(string $filename, string $tags): int
    {
        $dae = new DataUploadEvent($filename, [
            "filename" => $filename,
            "extension" => pathinfo($filename, PATHINFO_EXTENSION),
            "tags" => Tag::explode($tags),
            "source" => null,
        ]);
        send_event($dae);
        return $dae->image_id;
    }

    protected function delete_image(int $image_id)
    {
        $img = Post::by_id($image_id);
        if ($img) {
            $ide = new ImageDeletionEvent($img, true);
            send_event($ide);
        }
    }
}
