<?php declare(strict_types=1);

class FramerTheme extends Themelet
{
    public function render_selector(Page $page, array $sizes)
    {
        $body = "";

        foreach ($sizes as $size) {
            $body .= "<label><input type='checkbox' style='width:13px;' name='framerSize'
                    id='framerCheckSize$size[0]x$size[1]'
                    onchange='framerCheckChanged(this.checked, $size[0], $size[1], \"$size[2]\")'
                    value='$size[0]x$size[1]' />$size[0]x$size[1]</label>";
        }

        $block = new Block("Framer", $body, "left", 40);
        $page->add_block($block);
    }
}
