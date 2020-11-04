<?php
declare(strict_types=1);

class AutoCompleteTest extends ShimmiePHPUnitTestCase
{
    public function testAuth()
    {
        send_event(new UserLoginEvent(User::by_name(self::$anon_name)));
        $page = $this->get_page(
            'api/graphql/',
            null,
            "{\"query\": \"query{ tag{id} }\"}"
        );
        $this->assertEquals(200, $page->code);
        $this->assertEquals(PageMode::JSON, $page->mode);
        $this->assertEquals('{"tags":[]}', $page->data);
    }
}
