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

        $html = "<table class='similar_posts'>";

        foreach ($sets as $set) {
            $html .= "<tr><td><a href='" . make_link(Deduplicate::PAGE_NAME . "/" . $set->post->id) . "'><img src='" . $set->post->get_thumb_link() . "' /></a></td>";
            foreach ($set->similar_posts as $similar_post) {
                $html .= "<td><img src='" . $similar_post->post->get_thumb_link() . "' /><br/>" . $similar_post->get_percentage() . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</table>";

        $page->add_block(new Block("Similar posts", $html));
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

    public function display_post(ComparisonSet $set)
    {
        global $page, $database;

        $html = "";

        $left_post = $set->post;
        $right_post = $set->similar_posts[0]->post;

        $left_pixels = $left_post->width * $left_post->height;
        $right_pixels = $right_post->width * $right_post->height;

        $left_unique_tags = array_diff($left_post->get_tag_array(), $right_post->get_tag_array());
        $right_unique_tags = array_diff($right_post->get_tag_array(), $left_post->get_tag_array());

        $this->unique_tags = array_merge($left_unique_tags, $right_unique_tags);

        $left_parent = $set->parent;
        $left_children = $set->children;
        $left_siblings = $set->siblings;
        $right_parent = $set->similar_posts[0]->parent;
        $right_children = $set->similar_posts[0]->children;
        $right_siblings = $set->similar_posts[0]->siblings;

        $rows = 6;
        $extra_rows = "";

        if ($left_parent!=null||$right_parent!=null) {
            $rows++;
            $extra_rows .= "<tr><td class='left-post-info post-info'>";
            if ($left_parent!=null) {
                $extra_rows .= "Parent<br/><a href='" . (make_link("post/view/" . $left_parent->id)) . "'><img src='" . $left_parent->get_thumb_link() . "' /></a>";
            }
            $extra_rows .= "</td><td class='right-post-info post-info'>";

            if ($right_parent!=null) {
                $extra_rows .= "Parent<br/><a href='" . (make_link("post/view/" . $right_parent->id)) . "'><img src='" . $right_parent->get_thumb_link() . "' /></a>";
            }
            $extra_rows .= "</td></tr>";
        }
        if (!empty($left_children)||!empty($right_children)) {
            $rows++;
            $extra_rows .= "<tr><td class='left-post-info post-info'>";
            if (!empty($left_children)) {
                $extra_rows .= "Children<br/>";
                foreach ($left_children as $child) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $child->id)) . "'><img src='" . $child->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td><td class='right-post-info post-info'>";

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
            $extra_rows .= "<tr><td class='left-post-info post-info'>";
            if (!empty($left_siblings)) {
                $extra_rows .= "Siblings<br/>";
                foreach ($left_siblings as $sibling) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $sibling->id)) . "'><img src='" . $sibling->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td><td class='right-post-info post-info'>";

            if (!empty($right_siblings)) {
                $extra_rows .= "Siblings<br/>";
                foreach ($right_siblings as $sibling) {
                    $extra_rows .= "<a href='" . (make_link("post/view/" . $sibling->id)) . "'><img src='" . $sibling->get_thumb_link() . "' /></a>";
                }
            }
            $extra_rows .= "</td></tr>";
        }


        $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action"),"POST",false,"deduplicateForm","") ."
                <input type='hidden' name='left_post' value='" . $set->post->id . "'>
                <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
                <table class='img-comp'>
                <tr>
                    <td class='left-post-info post-info'>
                        <a href='".make_link("post/view/".$left_post->id)."'><img src='" . $left_post->get_thumb_link() . "' /><br/>{$left_post->id}</a> (<a href='{$left_post->get_image_link()}'>File</a>)
                    </td>
                    <td id='img-comp-container' class=\"img-comp-container\" rowspan='$rows'>
                          <div class=\"img-comp-img\" >
                            <div id='right-post' data-id='{$right_post->id}' data-width='{$right_post->width}' data-height='{$right_post->height}' data-filesize='{$right_post->filesize}' data-lossless='{$right_post->lossless}' style='background-image: url(\"{$right_post->get_image_link()}\")'></div>
                          </div>
                          <div class=\"img-comp-img img-comp-overlay\">
                            <div id='left-post' data-id='{$left_post->id}' data-width='{$left_post->width}' data-height='{$left_post->height}' data-filesize='{$left_post->filesize}' data-lossless='{$left_post->lossless}' style='background-image: url(\"{$left_post->get_image_link()}\")'></div>
                          </div>
                    </td>
                    <td class='right-post-info post-info'>
                        <a href='".make_link("post/view/".$right_post->id)."'><img src='" . $right_post->get_thumb_link() . "' /><br/>{$right_post->id}</a> (<a href='{$right_post->get_image_link()}'>File</a>)
                    </td>
                </tr>
                <tr>
                    <td class='left-post-info post-info'>{$left_post->filename}</td>
                    <td class='right-post-info post-info'>{$right_post->filename}</td>
                </tr>
                <tr>
                    <td class='left-post-info post-info ".$this->determine_comparison_class($left_pixels, $right_pixels)."'>{$left_post->width}x{$left_post->height}</td>
                    <td class='right-post-info post-info ".$this->determine_comparison_class($right_pixels, $left_pixels)."'>{$right_post->width}x{$right_post->height}</td>
                </tr>
                <tr>
                    <td class='left-post-info post-info ".$this->determine_comparison_class($left_post->filesize, $right_post->filesize)."'>".number_format($left_post->filesize)." bytes</td>
                    <td class='right-post-info post-info ".$this->determine_comparison_class($right_post->filesize, $left_post->filesize)."'>".number_format($right_post->filesize)." bytes</td>
                </tr>
                <tr>
                    <td class='left-post-info post-info ".
                        $this->determine_comparison_class($left_post->lossless ? 1 : 0, $right_post->lossless ? 1 : 0)
                    ."'>{$left_post->get_mime()}".($left_post->lossless?" (lossless)":"")."</td>
                    <td class='right-post-info post-info ".
                        $this->determine_comparison_class($right_post->lossless ? 1 : 0, $left_post->lossless ? 1 : 0)
                    ."'>{$right_post->get_mime()}".($right_post->lossless?" (lossless)":"")."</td>
                </tr>
                <tr><td class='left-post-info post-info'>".
                    implode(" ", array_map([$this,"get_tag_link"], $left_post->get_tag_array()))
                ."</td><td class='right-post-info post-info'>".
                    implode(" ", array_map([$this,"get_tag_link"], $right_post->get_tag_array()))
                ."</td></tr>
                $extra_rows
                <tr>
                    <td>
                       <button name=\"action\" value=\"merge_left\" type=\"submit\" onclick=\"return deduplicateFormSubmit('merge_left')\">Merge To Left</button><br/><br/>
                       <button name=\"action\" value=\"delete_left\" type=\"submit\" onclick=\"return deduplicateFormSubmit('delete_left')\">Delete Left</button><br/><br/>
                    </td>
                    <td class='similarity'>
                    ".($set->similar_posts[0]->similarity)." variance<br/><br/>
                       <button name=\"action\" value=\"delete_both\" type=\"submit\" style='width:25%;margin-right:5%' onclick=\"return deduplicateFormSubmit('delete_both')\">Delete Both</button>
                       <button name=\"action\" value=\"save\" type=\"submit\" style='width:25%;margin-right:5%'>Save Similarity</button>
                       <button name=\"action\" value=\"dismiss\" type=\"submit\" style='width:25%;margin-right:5%'>Dismiss Similarity</button><br/><br/>
                       <button name=\"action\" value=\"save_and_tag\" type=\"submit\" style='width:25%;margin-right:5%'>Save Similarity And Equalize Tags</button>
                    </td>
                    <td>
                       <button name=\"action\" value=\"merge_right\" type=\"submit\" onclick=\"return deduplicateFormSubmit('merge_right')\">Merge To Right</button><br/><br/>
                       <button name=\"action\" value=\"delete_right\" type=\"submit\" onclick=\"return deduplicateFormSubmit('delete_right')\">Delete Right</button><br/><br/>
                    </td>
                </tr>
                </table></form>

            <script defer type='text/javascript'>
                document.addEventListener(\"DOMContentLoaded\", function(event) {
                    initComparisons();
                });
            </script>
                ";

        $page->add_block(new Block("Similar posts", $html));


        if (count($set->similar_posts)>1) {
            $first_row = "<tr>";
            $second_row = "<tr>";
            $first_row .= "<td><a href='" . (make_link("post/view/" . $left_post->id)) . "'><img class='similar_post' src='" . $left_post->get_thumb_link() . "' /></a></td>
                            <td rowspan='2'>".make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<br/>
                <input type='hidden' name='left_post' value='" . $set->post->id . "'>
                <input type='hidden' name='other_posts' value='" . html_escape(json_encode($set->other_ids())). "'>
               <button name=\"action\" value=\"dismiss_all\" type=\"submit\">Dismiss All Similarities</button><br/><br/>
               <button name=\"action\" value=\"save_all\" type=\"submit\">Save All Similarities</button><br/><br/>
               </form></td>
";
            $second_row.= "<td>Base post</td>";

            foreach ($set->similar_posts as $other_post) {
                $first_row .= "<td><a href='" . (make_link("deduplicate/" .$left_post->id."/". $other_post->post->id)) . "'><img class='similar_post' src='" . $other_post->post->get_thumb_link() . "' /></a></td>";
                $second_row .= "<td>{$other_post->similarity}</td>";
            }
            $html = "<table>".$first_row."</tr>".$second_row."</tr></table>";

            $page->add_block(new Block("Other Similar Posts", $html));
        }
    }

    public function add_action_block(ComparisonSet $set, array $pools, string $default_pool)
    {
        global $page;

        $html = make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<br/>
                <input type='hidden' name='left_post' value='" . $set->post->id . "'>
                <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
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
                    <input type='hidden' name='left_post' value='" . $set->post->id . "'>
                    <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
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
                    <input type='hidden' name='left_post' value='" . $set->post->id . "'>
                    <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
            ";
            $buttons = "
                        <button name=\"action\" value=\"dismiss_left_as_parent\" type=\"submit\">Dismiss And Set Left Parent</button><br/><br/>
                        <button name=\"action\" value=\"dismiss_right_as_parent\" type=\"submit\">Dismiss And Set Right Parent</button><br/><br/>
                        <button name=\"action\" value=\"save_left_as_parent\" type=\"submit\">Save And Set Left Parent</button><br/><br/>
                        <button name=\"action\" value=\"save_right_as_parent\" type=\"submit\">Save And Set Right Parent</button><br/><br/>
            ";

            if ($set->parent!=null&&$set->parent->id!=$set->similar_posts[0]->post->id) {
                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->parent->id . "'>";
                $buttons .= "<button name=\"action\" value=\"dismiss_use_left_parent\" type=\"submit\">Dismiss And Use Left Parent</button><br/><br/>";
                $buttons .= "<button name=\"action\" value=\"save_use_left_parent\" type=\"submit\">Save And Use Left Parent</button><br/><br/>";
            }

            if ($set->similar_posts[0]->parent!=null&&$set->post->id!=$set->similar_posts[0]->parent->id) {
                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->similar_posts[0]->post->id . "'>";
                $buttons .= "<button name=\"action\" value=\"dismiss_use_right_parent\" type=\"submit\">Dismiss And Use Right Parent</button><br/><br/>";
                $buttons .= "<button name=\"action\" value=\"save_use_right_parent\" type=\"submit\">Save And Use Right Parent</button><br/><br/>";
            }


            $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . $inputs.$buttons."<br/><br/></form>";
        }


        $page->add_block(new Block("Dedupe", $html, "left"));
    }

    public function show_similar_posts(array $posts): string
    {
        $html = "";
        $count = count($posts);
        if ($count>0) {
            $html = "<tr><th class='similar_posts'>Similar</th><td class='similar_posts'>";
            foreach ($posts as $post) {
                $html .= "<a href='" . (make_link("post/view/" . $post->id)) . "'><img class='similar_post' src='" . $post->get_thumb_link() . "' /></a>";
            }
            $html .= "</td>";
        }
        return $html;
    }

    public function get_help_html()
    {
        return '<p>Search for posts that have been marked as similar to a particular post.</p>
        <div class="command_example">
        <pre>similar=123</pre>
        <p>Returns posts that have been marked as similar to post 123.</p>
        </div>
        ';
    }
}
