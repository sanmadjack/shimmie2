<?php declare(strict_types=1);

class TagPalletTheme extends Themelet
{
    public function build_tagger(Page $page, PostListBuildingEvent $event)
    {
        // Initialization code
        $base_href = get_base_href();

        $body = "
                <button onclick='TagPallet.toggle()'>Toggle Pallet</button>
                <button onclick='showTagLists()'>Toggle Tag Lists</button>
            ";

        $block = new Block("Tag Pallet", $body, "left", 30);
        $page->add_block($block);

        $script = AutoCompleteTheme::generate_autocomplete_enable_script('.tag_pallet_tags');

        $page->add_html_header("<script src='$base_href/ext/tag_pallet/webtoolkit.drag.js' type='text/javascript'></script>");
        $page->add_block(new Block(
            null,
            "<script type='text/javascript'>
				document.addEventListener('DOMContentLoaded', () => {
				    $script
					TagPallet.initialize('".make_link(GQL_PATH)."');
				});
			</script>",
            "main",
            1000
        ));

        // Tagger block
        $page->add_block(new Block(
            null,
            $this->html($event->search_terms),
            "main"
        ));
    }


    private function html(array $query_tags)
    {
        global $config;
        $h_query = isset($_GET['search'])? $h_query= "search=".url_escape($_GET['search']) : "";

        $delay = $config->get_string("ext_tagger_search_delay", "250");

        $url_form = make_link("tag_edit/set");

        $html = "
<div id='tag_pallet' style='position:fixed; display:block; top:25px; left:25px;'>
	<div class='title'>Tag Pallet<a href='javascript:TagPallet.close()'>X</a></div>
    ".make_form(make_link("api/internal/image/{id}/tags", "tags={tags}"))."
	<table>";

        if (!empty($query_tags)) {
            $tags = "";
            foreach ($query_tags as $tag) {
                if (substr($tag[0], 0, 1)=="-") {
                    $tags .= substr($tag, 1)." ";
                } else {
                    $tags .= "-".$tag." ";
                }
            }

            $html .= "<tr class='search_tags'>
                        <td><details><input placeholder='Tags' class='tag_pallet_tags' autocomplete='off' name='query_tags' readonly='readonly' value='".$tags."' /></details></td>
                    </tr>";
        }
        $html .= "
                <tr>
                    <td><input placeholder='Tags' class='tag_pallet_tags' autocomplete='off' name='pallet_tags' /></td>
                    <td><button type=\"button\" onclick='TagPallet.clear()'>Clear</button></td>
                </tr>
                <tr>
                    <td colspan='2'>
                    <button type=\"button\" onclick='TagPallet.writeTags()'>Save Changes</button>
<progress id='tag_write_progress'></progress>
                    </td>
                </tr>
            </table>
            </form>
        </div>";
        return $html;
    }
}
