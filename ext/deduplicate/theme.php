<?php declare(strict_types=1);

class DeduplicateTheme extends Themelet
{
    private $unique_tags = [];

    public function display_page()
    {
        global $page;

        $page->set_title("Deduplicate");
        $page->set_heading("Deduplicate");
        $page->add_block(new NavBlock());
    }

    public function display_list(array $sets)
    {
        global $page, $database;

        $html = "<table class='similar_items'>";

        foreach ($sets as $set) {
            $html .= "<tr><td><a href='" . make_link(Deduplicate::PAGE_NAME . "/" . $set->image->id) . "'><img src='" . $set->image->get_thumb_link() . "' /></a></td>";
            foreach ($set->similar_items as $similar_item) {
                $html .= "<td><img src='" . $similar_item->image->get_thumb_link() . "' /><br/>" . $similar_item->get_percentage() . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</table>";

        $page->add_block(new Block("Similar images", $html));
    }

    private function get_tag_link(string $tag): string
    {
        return "<a href='".make_link("post/list/".urlencode($tag)."/1")."' class='".(array_search($tag, $this->unique_tags)!==false ? "unique_tag" : "")."'>$tag</a>";
    }

    private function determine_comparison_class(int $a, int $b): string
    {
        if ($a>$b) {
            return "greater";
        } elseif ($b>$a) {
            return "lesser";
        }
        return "";
    }

    public function display_item(ComparisonSet $set)
    {
        global $page, $database;

        $html = "";

        $left_image = $set->image;
        $right_image = $set->similar_items[0]->image;

        $left_pixels = $left_image->width * $left_image->height;
        $right_pixels = $right_image->width * $right_image->height;

        $left_unique_tags = array_diff($left_image->get_tag_array(), $right_image->get_tag_array());
        $right_unique_tags = array_diff($right_image->get_tag_array(), $left_image->get_tag_array());

        $this->unique_tags = array_merge($left_unique_tags, $right_unique_tags);

        $left_parent = $set->parent;
        $left_children = $set->children;
        $left_siblings = $set->siblings;
        $right_parent = $set->similar_items[0]->parent;
        $right_children = $set->similar_items[0]->children;
        $right_siblings = $set->similar_items[0]->siblings;

        $rows = 6;
        $extra_rows = "";

        if ($left_parent!=null||$right_parent!=null) {
            $rows++;
            $extra_rows .= "<tr><td class='left-image-info image-info'>";
            if ($left_parent!=null) {
                $extra_rows .= "Parent<br/><a href='" . (make_link("post/view/" . $left_parent->id)) . "'><img src='" . $left_parent->get_thumb_link() . "' /></a>";
            }
            $extra_rows .= "</td><td class='right-image-info image-info'>";

            if ($right_parent!=null) {
                $extra_rows .= "Parent<br/><a href='" . (make_link("post/view/" . $right_parent->id)) . "'><img src='" . $right_parent->get_thumb_link() . "' /></a>";
            }
            $extra_rows .= "</td></tr>";
        }
        if (!empty($left_children)||!empty($right_children)) {
            $rows++;
            $extra_rows .= "<tr><td class='left-image-info image-info'>";
            if (!empty($left_children)) {
                $extra_rows .= "Children<br/>";
                foreach ($left_children as $child) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $child->id)) . "'><img src='" . $child->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td><td class='right-image-info image-info'>";

            if (!empty($right_children)) {
                $extra_rows .= "Children<br/>";
                foreach ($right_children as $child) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $child->id)) . "'><img src='" . $child->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td></tr>";
        }

        if (!empty($left_siblings)||!empty($right_siblings)) {
            $rows++;
            $extra_rows .= "<tr><td class='left-image-info image-info'>";
            if (!empty($left_siblings)) {
                $extra_rows .= "Siblings<br/>";
                foreach ($left_siblings as $sibling) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $sibling->id)) . "'><img src='" . $sibling->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td><td class='right-image-info image-info'>";

            if (!empty($right_siblings)) {
                $extra_rows .= "Siblings<br/>";
                foreach ($right_siblings as $sibling) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $sibling->id)) . "'><img src='" . $sibling->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td></tr>";
        }


        $html .= "
            <table class='img-comp'>
                <tr>
                    <td class='left-image-info image-info'>
                        <a href='".make_link("post/view/".$left_image->id)."'>{$left_image->id}</a> (<a href='{$left_image->get_image_link()}'>Post</a>)
                    </td>
                    <td id='img-comp-container' class=\"img-comp-container\" rowspan='$rows'>
                          <div class=\"img-comp-img\" >
                            <div id='right-image' data-id='{$right_image->id}' data-width='{$right_image->width}' data-height='{$right_image->height}' style='background-image: url(\"{$right_image->get_image_link()}\")'></div>
                          </div>
                          <div class=\"img-comp-img img-comp-overlay\">
                            <div id='left-image' data-id='{$left_image->id}' data-width='{$left_image->width}' data-height='{$left_image->height}' style='background-image: url(\"{$left_image->get_image_link()}\")'></div>
                          </div>
                    </td>
                    <td class='right-image-info image-info'>
                        <a href='".make_link("post/view/".$right_image->id)."'>{$right_image->id}</a> (<a href='{$right_image->get_image_link()}'>Post</a>)
                    </td>
                </tr>
                <tr>
                    <td class='left-image-info image-info'>{$left_image->filename}</td>
                    <td class='right-image-info image-info'>{$right_image->filename}</td>
                </tr>
                <tr>
                    <td class='left-image-info image-info ".$this->determine_comparison_class($left_pixels, $right_pixels)."'>{$left_image->width}x{$left_image->height}</td>
                    <td class='right-image-info image-info ".$this->determine_comparison_class($right_pixels, $left_pixels)."'>{$right_image->width}x{$right_image->height}</td>
                </tr>
                <tr>
                    <td class='left-image-info image-info ".$this->determine_comparison_class($left_image->filesize, $right_image->filesize)."'>".number_format($left_image->filesize)." bytes</td>
                    <td class='right-image-info image-info ".$this->determine_comparison_class($right_image->filesize, $left_image->filesize)."'>".number_format($right_image->filesize)." bytes</td>
                </tr>
                <tr>
                    <td class='left-image-info image-info ".
                        $this->determine_comparison_class($left_image->lossless ? 1 : 0, $right_image->lossless ? 1 : 0)
                    ."'>{$left_image->get_mime()}".($left_image->lossless?" (lossless)":"")."</td>
                    <td class='right-image-info image-info ".
                        $this->determine_comparison_class($right_image->lossless ? 1 : 0, $left_image->lossless ? 1 : 0)
                    ."'>{$right_image->get_mime()}".($right_image->lossless?" (lossless)":"")."</td>
                </tr>
                <tr><td class='left-image-info image-info'>".
                    implode(" ", array_map([$this,"get_tag_link"], $left_image->get_tag_array()))
                ."</td><td class='right-image-info image-info'>".
                    implode(" ", array_map([$this,"get_tag_link"], $right_image->get_tag_array()))
                ."</td></tr>
                $extra_rows
                <tr><td class='similarity' colspan='3'>".($set->similar_items[0]->similarity)." variance</td></tr>
                </table>

            <script defer type='text/javascript'>
                document.addEventListener(\"DOMContentLoaded\", function(event) {
                    initComparisons();
                });
            </script>
                ";

        $page->add_block(new Block("Similar images", $html));


        if (count($set->similar_items)>1) {
            $first_row = "<tr>";
            $second_row = "<tr>";
            $first_row .= "<td><a href='" . (make_link("post/view/" . $left_image->id)) . "'><img class='similar_item' src='" . $left_image->get_thumb_link() . "' /></a></td>
                            <td rowspan='2'>".make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<br/>
                <input type='hidden' name='left_image' value='" . $set->image->id . "'>
                <input type='hidden' name='other_images' value='" . html_escape(json_encode($set->other_ids())). "'>
               <button name=\"action\" value=\"dismiss_all\" type=\"submit\">Dismiss All Similarities</button><br/><br/>
               <button name=\"action\" value=\"save_all\" type=\"submit\">Save All Similarities</button><br/><br/>
               </form></td>
";
            $second_row.= "<td>Base image</td>";

            foreach ($set->similar_items as $other_item) {
                $first_row .= "<td><a href='" . (make_link("deduplicate/" .$left_image->id."/". $other_item->image->id)) . "'><img class='similar_item' src='" . $other_item->image->get_thumb_link() . "' /></a></td>";
                $second_row .= "<td>{$other_item->similarity}</td>";
            }
            $html = "<table>".$first_row."</tr>".$second_row."</tr></table>";

            $page->add_block(new Block("Other Similar Items", $html));
        }
    }

    public function add_action_block(ComparisonSet $set, array $pools, string $default_pool)
    {
        global $page;

        $html = make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<br/>
                <input type='hidden' name='left_image' value='" . $set->image->id . "'>
                <input type='hidden' name='right_image' value='" . $set->similar_items[0]->image->id . "'>
               <button name=\"action\" value=\"merge_left\" type=\"submit\">Merge To Left</button><br/><br/>
               <button name=\"action\" value=\"merge_right\" type=\"submit\">Merge To Right</button><br/><br/>
               <button name=\"action\" value=\"delete_left\" type=\"submit\">Delete Left</button><br/><br/>
               <button name=\"action\" value=\"delete_right\" type=\"submit\">Delete Right</button><br/><br/>
               <button name=\"action\" value=\"delete_both\" type=\"submit\">Delete Both</button><br/><br/>
               <button name=\"action\" value=\"dismiss\" type=\"submit\">Dismiss Similarity</button><br/><br/>
               <button name=\"action\" value=\"save\" type=\"submit\">Save Similarity</button><br/><br/>
               </form>
        ";

        if (count($pools) > 0) {
            $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "
                    <input type='hidden' name='left_image' value='" . $set->image->id . "'>
                    <input type='hidden' name='right_image' value='" . $set->similar_items[0]->image->id . "'>
                    <select name='target_pool' required='required'><option value=''></option>";
            foreach ($pools as $pool) {
                $html .= "<option value='" . $pool["id"] . "'  " . ($default_pool == $pool["id"] ? "selected='selected'" : "") . " >" . $pool["title"] . "</option>";
            }
            $html .= "</select>";
            $html .= "<button name=\"action\" value=\"dismiss_to_pool\" type=\"submit\">Dismiss And Add To Pool</button><br/><br/></form>";
            $html .= "<button name=\"action\" value=\"save_to_pool\" type=\"submit\">Save And Add To Pool</button><br/><br/></form>";
        }

        if (Extension::is_enabled(RelationshipsInfo::KEY)) {
            $inputs = "
                    <input type='hidden' name='left_image' value='" . $set->image->id . "'>
                    <input type='hidden' name='right_image' value='" . $set->similar_items[0]->image->id . "'>
            ";
            $buttons = "
                        <button name=\"action\" value=\"dismiss_left_as_parent\" type=\"submit\">Dismiss And Set Left Parent</button><br/><br/>
                        <button name=\"action\" value=\"dismiss_right_as_parent\" type=\"submit\">Dismiss And Set Right Parent</button><br/><br/>
                        <button name=\"action\" value=\"save_left_as_parent\" type=\"submit\">Save And Set Left Parent</button><br/><br/>
                        <button name=\"action\" value=\"save_right_as_parent\" type=\"submit\">Save And Set Right Parent</button><br/><br/>
            ";

            if ($set->parent!=null&&$set->parent->id!=$set->similar_items[0]->image->id) {
                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->parent->id . "'>";
                $buttons .= "<button name=\"action\" value=\"dismiss_use_left_parent\" type=\"submit\">Dismiss And Use Left Parent</button><br/><br/>";
                $buttons .= "<button name=\"action\" value=\"save_use_left_parent\" type=\"submit\">Save And Use Left Parent</button><br/><br/>";
            }

            if ($set->similar_items[0]->parent!=null&&$set->image->id!=$set->similar_items[0]->parent->id) {
                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->similar_items[0]->image->id . "'>";
                $buttons .= "<button name=\"action\" value=\"dismiss_use_right_parent\" type=\"submit\">Dismiss And Use Right Parent</button><br/><br/>";
                $buttons .= "<button name=\"action\" value=\"save_use_right_parent\" type=\"submit\">Save And Use Right Parent</button><br/><br/>";
            }


            $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . $inputs.$buttons."<br/><br/></form>";
        }


        $page->add_block(new Block("Dedupe", $html, "left"));
    }

    public function show_similar_items(array $items): string
    {
        $html = "";
        $count = count($items);
        if ($count>0) {
            $html = "<tr><th class='similar_items'>Similar</th><td class='similar_items'>";
            foreach ($items as $item) {
                $html .= "<a href='" . (make_link("post/view/" . $item->id)) . "'><img class='similar_item' src='" . $item->get_thumb_link() . "' /></a>";
            }
            $html .= "</td>";
        }
        return $html;
    }

    public function get_help_html()
    {
        return '<p>Search for posts that have been marked as similar to a particular image.</p>
        <div class="command_example">
        <pre>similar=123</pre>
        <p>Returns posts that have been marked as similar to image 123.</p>
        </div>
        ';
    }
}
