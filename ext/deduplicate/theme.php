<?php declare(strict_types=1);

class DeduplicateTheme extends Themelet
{
    private $unique_tags = [];

    public function display_page()
    {
        global $page;

        $page->set_title("Deduplicate");
        $page->set_heading("Deduplicate");
        $navBlack = new NavBlock();
        $navBlack->body .= "<br/><a href='".make_link(Deduplicate::PAGE_NAME."/list")."'>Deduplication list</a>";
        $page->add_block($navBlack);
    }

    public function display_list(array $sets)
    {
        global $page;

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

    private function get_tag_link(string $tag, string $input_name): string
    {
        return "<label><input name='{$input_name}[]' type='checkbox' value='".html_escape($input_name)."' checked='checked'/>
                    <a href='".make_link("post/list/".urlencode($tag)."/1")."' class='".(array_search($tag, $this->unique_tags)!==false ? "unique_tag" : "")."'>$tag</a></label><br/>";
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

    public function display_post(ComparisonSet $set, int $max_variance)
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

        $query_args = "max_variance=$max_variance";

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
        $left_tag_list = "";
        $right_tag_list = "";
        foreach ($left_post->get_tag_array() as $tag) {
            $left_tag_list .= $this->get_tag_link($tag, "left_tags");
        }
        foreach ($right_post->get_tag_array() as $tag) {
            $right_tag_list .= $this->get_tag_link($tag, "right_tags");
        }

        $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action"), "POST", false, "deduplicateForm", "") ."
                <input type='hidden' name='left_post' value='" . $set->post->id . "' />
                <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "' />
                <input type='hidden' name='max_variance' value='$max_variance' />
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
                    $left_tag_list
                ."</td><td class='right-post-info post-info'>".
                    $right_tag_list
                ."</td></tr>
                $extra_rows
                <tr>
                    <td>
                       <button name=\"action\" value=\"merge_left\" type=\"submit\" onclick=\"return deduplicateFormSubmit('merge_left')\">Merge To Base Post</button><br/><br/>
                       <button name=\"action\" value=\"delete_left\" type=\"submit\" onclick=\"return deduplicateFormSubmit('delete_left')\">Delete Base Post</button><br/><br/>
                    </td>
                    <td class='similarity'>
                    ".($set->similar_posts[0]->similarity)." variance<br/><br/>
                       <button name=\"action\" value=\"delete_both\" type=\"submit\" style='width:25%;margin-right:5%' onclick=\"return deduplicateFormSubmit('delete_both')\">Delete Both</button>
                       <button name=\"action\" value=\"save\" type=\"submit\" style='width:25%;margin-right:5%'>Save Similarity</button>
                       <button name=\"action\" value=\"dismiss\" type=\"submit\" style='width:25%;margin-right:5%'>Dismiss Similarity</button><br/><br/>
                       <button name=\"action\" value=\"save_and_tag\" type=\"submit\" style=''>Save Similarity And Equalize Tags</button>
                    </td>
                    <td>
                       <button name=\"action\" value=\"merge_right\" type=\"submit\" onclick=\"return deduplicateFormSubmit('merge_right')\">Merge To Similar Post</button><br/><br/>
                       <button name=\"action\" value=\"delete_right\" type=\"submit\" onclick=\"return deduplicateFormSubmit('delete_right')\">Delete Similar Post</button><br/><br/>
                    </td>
                </tr>
                </table></form>

            <script defer type='text/javascript'>
                let comparer;
                document.addEventListener(\"DOMContentLoaded\", function(event) {
                    comparer = new ImageComparer();
                });
            </script>
                ";

        $page->add_block(new Block("Similar posts", $html));


        if (count($set->similar_posts)>1) {
            $html = make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<div id='other_similar_items' class='other_similar_item'><a href='" . (make_link("post/view/" . $left_post->id)) . "'>
                <img class='similar_post' src='" . $left_post->get_thumb_link() . "' /></a><br/>Base post</div>
                            <div class='other_similar_item'><br/>
                <input type='hidden' name='left_post' value='" . $set->post->id . "' />
                <input type='hidden' name='max_variance' value='$max_variance' />
               <button name=\"action\" value=\"dismiss_checked\" type=\"submit\">Dismiss Checked Similarities</button><br/><br/>
               <button name=\"action\" value=\"save_checked\" type=\"submit\">Save Checked Similarities</button><br/><br/>
               </div>
";

            foreach ($set->similar_posts as $other_post) {
                $html .= "<div class='other_similar_item'>
                            <a href='" . make_link("deduplicate/" .$left_post->id."/". $other_post->post->id, $query_args) . "'>
                                <img class='similar_post' src='" . $other_post->post->get_thumb_link() . "' />
                            </a><input type='checkbox' name='other_posts[]' value='{$other_post->post->id}' checked='checked' /><br/>{$other_post->similarity} ";

                $html .= "<a href='".make_link(Deduplicate::PAGE_NAME."/".$other_post->post->id, $query_args)."'>Load</a>";
                if ($other_post->post->id!==$right_post->id) {
                    $html .= " | <a href='".make_link(Deduplicate::PAGE_NAME."/".$other_post->post->id."/".$right_post->id, $query_args)."'>Switch</a>";
                }


                $html .= "</div>";
            }
            $html .= "</form>";
            $page->add_block(new Block("Other Similar Posts", $html));
        }
    }



    public function add_action_block(ComparisonSet $set, array $pools, string $default_pool, int $max_variance)
    {
        global $page;

        $html = "";

        $html .= "
        <label>Background<input id='imageComparisonBackgroundColorPicker' type='color' onchange='comparer.setBackgroundColor(this.value)' /></label>
        <label>Scaling<select id='imageComparisonScalingSelect' onchange='comparer.setScaling(this.value)'>
            <option value='window'>To Window</option>
            <option value='imageMatch'>To Largest Image</option>
            <option value='none'>None</option>
        </select></label><br/><br/>
        ".make_form(Deduplicate::PAGE_NAME, "GET") ."
            <label>Max variance<input type='number' name='max_variance' value='$max_variance' /></label>
            <button>Submit</button>
        </form>";


//
//        $html = make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "<br/>
//                <input type='hidden' name='left_post' value='" . $set->post->id . "'>
//                <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
//               <button name=\"action\" value=\"merge_left\" type=\"submit\">Merge To Left</button><br/><br/>
//               <button name=\"action\" value=\"merge_right\" type=\"submit\">Merge To Right</button><br/><br/>
//               <button name=\"action\" value=\"delete_left\" type=\"submit\">Delete Left</button><br/><br/>
//               <button name=\"action\" value=\"delete_right\" type=\"submit\">Delete Right</button><br/><br/>
//               <button name=\"action\" value=\"delete_both\" type=\"submit\">Delete Both</button><br/><br/>
//               <button name=\"action\" value=\"dismiss\" type=\"submit\">Dismiss Similarity</button><br/><br/>
//               <button name=\"action\" value=\"save\" type=\"submit\">Save Similarity</button><br/><br/>
//               </form>
//        ";
//
//        if (count($pools) > 0) {
//            $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . "
//                    <input type='hidden' name='left_post' value='" . $set->post->id . "'>
//                    <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
//                    <select name='target_pool' required='required'><option value=''></option>";
//            foreach ($pools as $pool) {
//                $html .= "<option value='" . $pool["id"] . "'  " . ($default_pool == $pool["id"] ? "selected='selected'" : "") . " >" . $pool["title"] . "</option>";
//            }
//            $html .= "</select>";
//            $html .= "<button name=\"action\" value=\"dismiss_to_pool\" type=\"submit\">Dismiss And Add To Pool</button><br/><br/></form>";
//            $html .= "<button name=\"action\" value=\"save_to_pool\" type=\"submit\">Save And Add To Pool</button><br/><br/></form>";
//        }
//
//        if (Extension::is_enabled(RelationshipsInfo::KEY)) {
//            $inputs = "
//                    <input type='hidden' name='left_post' value='" . $set->post->id . "'>
//                    <input type='hidden' name='right_post' value='" . $set->similar_posts[0]->post->id . "'>
//            ";
//            $buttons = "
//                        <button name=\"action\" value=\"dismiss_left_as_parent\" type=\"submit\">Dismiss And Set Left Parent</button><br/><br/>
//                        <button name=\"action\" value=\"dismiss_right_as_parent\" type=\"submit\">Dismiss And Set Right Parent</button><br/><br/>
//                        <button name=\"action\" value=\"save_left_as_parent\" type=\"submit\">Save And Set Left Parent</button><br/><br/>
//                        <button name=\"action\" value=\"save_right_as_parent\" type=\"submit\">Save And Set Right Parent</button><br/><br/>
//            ";
//
//            if ($set->parent!=null&&$set->parent->id!=$set->similar_posts[0]->post->id) {
//                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->parent->id . "'>";
//                $buttons .= "<button name=\"action\" value=\"dismiss_use_left_parent\" type=\"submit\">Dismiss And Use Left Parent</button><br/><br/>";
//                $buttons .= "<button name=\"action\" value=\"save_use_left_parent\" type=\"submit\">Save And Use Left Parent</button><br/><br/>";
//            }
//
//            if ($set->similar_posts[0]->parent!=null&&$set->post->id!=$set->similar_posts[0]->parent->id) {
//                $inputs .= "<input type='hidden' name='left_parent' value='" . $set->similar_posts[0]->post->id . "'>";
//                $buttons .= "<button name=\"action\" value=\"dismiss_use_right_parent\" type=\"submit\">Dismiss And Use Right Parent</button><br/><br/>";
//                $buttons .= "<button name=\"action\" value=\"save_use_right_parent\" type=\"submit\">Save And Use Right Parent</button><br/><br/>";
//            }
//
//
//            $html .= make_form(make_link(Deduplicate::PAGE_NAME . "/action")) . $inputs.$buttons."<br/><br/></form>";
//        }
//
//
        $page->add_block(new Block("Deduplicate", $html, "left"));
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

    public function get_search_help_html()
    {
        return '<p>Search for posts that have been marked as similar to a particular post.</p>
        <div class="command_example">
        <pre>similar=123</pre>
        <p>Returns posts that have been marked as similar to post 123.</p>
        </div>
        ';
    }

    public function get_help_html()
    {
        return '<p>Deduplication is the process of removing duplicate items. The Deduplication extension creates a
"perceptual hash" of a post, and then compares that against the perceptual hash of another post. If the perceptual
 hashes are close enough (as controlled by the limit set in the <a href="'.make_link("setup").'">setup screen</a>),
 then the two posts are flagged as "similar," with a number indicating how similar they are. Currently only image
 comparisons are supported. It is suggested to make use of the "Trash" extension in case it is determined that an incorrect
 duplicate was removed.
</p>
<h2>Scanning For Duplicates</h2>
<p>
Scanning for similar items is currently a manual process, performed via bulk operations. Search for the set of posts you
want to compare, or manually select them with the bulk action selector. Click "Scan for similar posts" to start the scan.
Since every post needs to be compared to every post, the amount of time it takes to scan scales  exponentially with the
number of posts selected. For instance, 10 items has to make 90 comparisons, 20 has to make 380 comparisons, 30 makes 870,
and so on. Pairs of posts that are already flagged as similar are not re-checked. Perceptual hashing is not perfect and
frequently has false matches. Keeping the similarity limit setting low will increase the chances that the detected
similarities are accurate, but increasing the limit can make it possible to catch images that are duplicates but have
some visual difference making an exact match difficult, such as a black-and-white version of a color image.
</p>
<p>
There is also a "Clear similar posts" bulk action that will erase any flagged similarities for the selected posts.
This does not restrict to similarities between the selected items and will clear any similarities the selected posts
have to any other post in the system unless that similarity has been saved. The "Clear saved" checkbox will cause the
action to also remove saved similarities.
</p>
<h2>Deduplication Screen</h2>
<p>
Posts flagged as similar can be reviewed at the <a href="'.make_link(Deduplicate::PAGE_NAME).'">Deduplication screen</a>.
By default this screen queries the post with the lowest ID that is flagged with a similarity, intending for it to be
the oldest, and uses it as the "base" post. It then queries the most similar post to the "base" post and presents it
for comparison. The center of the screen shows the images from the two similar posts with a slider that pans over the images, allowing
for easy image comparison. "Base" post information is shown on the left side, and the "similar" post information on the right.
Color-coding is used to indicate differences in the data:
<ul>
<li>The post with larger dimensions will have its dimensions in green, the smaller in red.</li>
<li>The post with a larger filesize will have its filesize in green, the smaller in red.</li>
<li>If one of the posts is using a lossless file format while the other is not, the lossless file\'s mime type will be green, and the non-lossless file\'s will be red.</li>
<li>Tags present in one post and not the other will be highlighted in green.</li>
</ul>

If a post is calculated to have a "green" attribute it is determined to be superior in that way. Superior attributes are
user to automatically caution the user if they attempt to delete a post that has one or more superior attributes. This
does not take tags into account.
</p>
<h3>Deduplication Controls</h3>
<p>
"Save similarity" will confirm the similarity and flag it as saved. Save similarities are excluded from the deduplication
screen, and from the "Clear similar posts" bulk action by default. Save similarities also are displayed on the post page
when the option "Show saved similar posts" is enabled in the <a href="'.make_link("setup").'">setup screen</a>.
</p>
<p>
"Save Similarity And Equalize Tags" will perform the same operation as "Save Similarity" as well as change the tags of
both posts to contain all of the tags from both posts.
</p>
<p>
"Dismiss Similarity" will clear the flagged similarity between the posts. This would be used to indicate that the two
posts are not actually similar.
</p>
<p>
"Delete Both" will delete both posts. This will confirm before executing.
</p>
<p>"Merge To Base Post" and "Merge To Similar Post" will delete the opposite post while copying all of the opposite posts\'
tags onto the specified post. This is useful when a complete duplicate is found, but the inferior file has the more
accurate tags. This option will warn the user if the post to be deleted is calculated to be superior in some way,
but will not warn the user otherwise.</p>
<p>
"Delete Base Post" and "Delete Similar Post" will delete the specified post. This option will warn the user if the post
to be deleted is calculated to be superior in some way, but will not warn the user otherwise.
</p>
<h3>Other Similar Posts</h3>
<p>
When the "base" post is similar to more than one post, a section will appear beneath the post comparison area showing the
most similar posts. The top-left-most image is the "base" post, and the similar posts continue rightwards, wrapping down
onto new rows as needed. The similar posts are ordered from most similar to least similar. Clicking on one of the similar
posts will force the deduplication screen to load that post as the current "similar" post, keeping the same "base" post.
Below each image is a number indicating the similarity to the base post, as well as a link labelled "Switch Base".
Clicking "Switch Base" will force the deduplication screen to load that post as the "base" post while keeping the same
"similar" post.
</p>
<p>
"Dismiss All Similarities" will clear the flagged similarities on the "base" post with all of the displayed "similar" posts.
It will not clear similarities with any posts not shown in the "Other Similar Posts" section.
</p>
<p>
"Save All Similarities will save the flagged similarities on the "base" post with all of the displayed "similar" posts.
It will not save similarities with any posts not shown in the "Other Similar Posts" section.
</p>
<p></p>
 ';
    }
}
