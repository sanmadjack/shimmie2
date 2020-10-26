<?php declare(strict_types=1);
use function MicroHTML\INPUT;

class FavoritesTheme extends Themelet
{
    public function get_voter_html(Post $image, $is_favorited)
    {
        $name  = $is_favorited ? "unset" : "set";
        $label = $is_favorited ? "Un-Favorite" : "Favorite";
        return SHM_SIMPLE_FORM(
            "change_favorite",
            INPUT(["type"=>"hidden", "name"=>"image_id", "value"=>$image->id]),
            INPUT(["type"=>"hidden", "name"=>"favorite_action", "value"=>$name]),
            INPUT(["type"=>"submit", "value"=>$label]),
        );
    }

    public function display_people($username_array)
    {
        global $page;

        $i_favorites = count($username_array);
        $html = "$i_favorites people:";

        reset($username_array); // rewind to first element in array.

        foreach ($username_array as $row) {
            $username = html_escape($row);
            $html .= "<br><a href='".make_link("user/$username")."'>$username</a>";
        }

        $page->add_block(new Block("Favorited By", $html, "left", 25));
    }

    public function get_help_html()
    {
        return '<p>Search for images that have been favorited a certain number of times, or favorited by a particular individual.</p>
        <div class="command_example">
        <pre>favorites=1</pre>
        <p>Returns images that have been favorited once.</p>
        </div>
        <div class="command_example">
        <pre>favorites>0</pre>
        <p>Returns images that have been favorited 1 or more times</p>
        </div>
        <p>Can use &lt;, &lt;=, &gt;, &gt;=, or =.</p>
        <div class="command_example">
        <pre>favorited_by:username</pre>
        <p>Returns images that have been favorited by "username". </p>
        </div>
        <div class="command_example">
        <pre>favorited_by_userno:123</pre>
        <p>Returns images that have been favorited by user 123. </p>
        </div>
        ';
    }
}
