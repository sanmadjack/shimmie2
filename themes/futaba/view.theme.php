<?php declare(strict_types=1);

class CustomViewImageTheme extends ViewImageTheme
{
    public function display_page(Post $image, $editor_parts)
    {
        global $page;
        $page->set_heading(html_escape($image->get_tag_list()));
        $page->add_block(new Block(null, $this->build_info($image, $editor_parts), "main", 10));
    }
}
