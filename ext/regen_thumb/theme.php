<?php declare(strict_types=1);
use function MicroHTML\INPUT;

class RegenThumbTheme extends Themelet
{
    /**
     * Show a form which offers to regenerate the thumb of an image with ID #$image_id
     */
    public function get_buttons_html(int $image_id): string
    {
        return (string)SHM_SIMPLE_FORM(
            "regen_thumb/one",
            INPUT(["type"=>'hidden', "name"=>'image_id', "value"=>$image_id]),
            SHM_SUBMIT('Regenerate Thumbnail')
        );
    }

    /**
     * Show a link to the new thumbnail.
     */
    public function display_results(Page $page, Image $image)
    {
        $page->set_title("Thumbnail Regenerated");
        $page->set_heading("Thumbnail Regenerated");
        $page->add_html_header("<meta http-equiv=\"cache-control\" content=\"no-cache\">");
        $page->add_block(new NavBlock());
        $page->add_block(new Block("Thumbnail", $this->build_thumb_html($image)));
    }

    public function bulk_html()
    {
        return "<label><input type='checkbox' name='bulk_regen_thumb_missing_only' id='bulk_regen_thumb_missing_only' style='width:13px' value='true' />Only missing thumbs</label>";
    }

    public function display_admin_block()
    {
        global $page, $database;

        $types = [];
        $results = $database->get_all("SELECT ext, count(*) count FROM images group by ext");
        foreach ($results as $result) {
            array_push($types, "<option value='".$result["ext"]."'>".$result["ext"]." (".$result["count"].")</option>");
        }

        $html = "
            Will only regenerate missing thumbnails, unless force is selected. Force will override the limit and will likely take a very long time to process.
			<p>".make_form(make_link("admin/regen_thumbs"))."
				<table class='form'>
                <tr><th><label for='regen_thumb_force'>Force</label></th><td><input type='checkbox' name='regen_thumb_force' id='regen_thumb_force' value='true' /></td></tr>
                <tr><th><label for='regen_thumb_limit'>Limit</label></th><td><input type='number' name='regen_thumb_limit' id='regen_thumb_limit' value='1000' /></td></tr>
                <tr><th><label for='regen_thumb_type'>Type</label></th><td>
                    <select name='regen_thumb_type' id='regen_thumb_type'>
                        <option value=''>All</option>
                        ".implode($types)."
                    </select>
                </td></tr>
                <tr><td colspan='2'><input type='submit' value='Regenerate Thumbnails'></td></tr>
				</table>
			</form></p>
			<p>".make_form(make_link("admin/delete_thumbs"), "POST", false, "", "return confirm('Are you sure you want to delete all thumbnails?')")."
				<table class='form'>
                    <tr><th><label for='delete_thumb_type'>Type</label></th><td>
                        <select name='delete_thumb_type' id='delete_thumb_type'>
                            <option value=''>All</option>
                            ".implode($types)."
                        </select>
                    </td></tr>
					<tr><td colspan='2'><input type='submit' value='Delete Thumbnails'></td></tr>
				</table>
            </form></p>
            		";
        $page->add_block(new Block("Regen Thumbnails", $html));
    }
}
