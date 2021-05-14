<?php declare(strict_types=1);

class RatingsMatrixTheme extends Themelet
{
    public function get_rater_html(PostRatingsMatrixScale $scale, string $rating, bool $can_rate): string
    {
        global $user;

        $human_rating = $scale->getHumanValue($rating);
        $html = "
			<tr>
				<th>{$scale->name}</th>
				<td>
		".($can_rate ? "
					<span class='view'>$human_rating</span>
					<span class='edit'>
						".$this->get_selection_rater_html($scale, $user, [$rating], false, false)."
					</span>
		" : "
					$human_rating
		")."
				</td>
			</tr>
		";
        return $html;
    }

    public function display_form(PostRatingsMatrixScale $scale, array $current_ratings)
    {
        global $page;

        $html = make_form(make_link("admin/update_ratings_matrix_".$scale->code))."<table class='form'><tr>
        <th>Change</th><td><select name='rating_old' required='required'><option></option>";
        foreach ($current_ratings as $key=>$value) {
            $html .= "<option value='$key'  title='$value'>$value</option>";
        }
        $html .= "</select></td></tr>
        <tr><th>To</th><td><select name='rating_new'  required='required'><option></option>";
        foreach ($scale->getSortedValues() as $value) {
            $html .= "<option value='$value->code' title='$value->description'>$value->name</option>";
        }
        $html .= "</select></td></tr>
        <tr><td colspan='2'><input type='submit' value='Update'></td></tr></table>
        </form>\n";
        $page->add_block(new Block("Update {$scale->name} Ratings", $html));
    }

    public function get_bulk_selection_rater_html(array $scales): string
    {
        global $user;
        $output = "";
        foreach ($scales as $scale) {
            $output .= $scale->name." ";
            $output .= $this->get_selection_rater_html($scale, $user, [""], true, false);
        }
        return $output;
    }
    public function get_selection_rater_html(PostRatingsMatrixScale $scale, User $user, array $selected_options, bool $includeNoChange, bool $multiple): string
    {
        $output = "<select name='{$scale->database_field}".($multiple ? "[]' multiple='multiple'" : "' ")." >";

        $options = $scale->getSortedValues();

        if ($includeNoChange) {
            $output .= "<option value=''>No Change</option>";
        }

        foreach ($options as $option) {
            $available_options = RatingsMatrixConfig::getUserClassPrivileges($scale->code, $user->class->name);
            if ($available_options!=null && !in_array($option->code, $available_options)) {
                continue;
            }

            $output .= "<option value='".$option->code."' title='$option->description' ".
                (in_array($option->code, $selected_options) ? "selected='selected'": "")
                .">".$option->name."</option>";
        }
        return $output."</select>";
    }

    public function get_help_html(array $ratings)
    {
        $output =  '<p>Search for posts with one or more possible ratings.</p>
        <div class="command_example">
        <pre>rating:'.$ratings[0]->search_term.'</pre>
        <p>Returns posts with the '.$ratings[0]->name.' rating.</p>
        </div>
        <p>Ratings can be abbreviated to a single letter as well</p>
        <div class="command_example">
        <pre>rating:'.$ratings[0]->code.'</pre>
        <p>Returns posts with the '.$ratings[0]->name.' rating.</p>
        </div>
        <p>If abbreviations are used, multiple ratings can be searched for.</p>
        <div class="command_example">
        <pre>rating:'.$ratings[0]->code.$ratings[1]->code.'</pre>
        <p>Returns posts with the '.$ratings[0]->name.' or '.$ratings[1]->name.' rating.</p>
        </div>
        <p>Available ratings:</p>
        <table>
        <tr><th>Name</th><th>Search Term</th><th>Abbreviation</th><th>Description</th></tr>
        ';
        foreach ($ratings as $rating) {
            $output .= "<tr><td>{$rating->name}</td><td>{$rating->search_term}</td><td>{$rating->code}</td><td>$rating->description</td></tr>";
        }
        $output .= "</table>";
        return $output;
    }

    public function get_user_options(User $user, array $selected_ratings, array $available_ratings): string
    {
        $html = "
                <p>".make_form(make_link("user_admin/default_ratings"))."
                    <input type='hidden' name='id' value='$user->id'>
                    <table style='width: 300px;'>
                        <thead>
                            <tr><th colspan='2'></th></tr>
                        </thead>
                        <tbody>
                        <tr><td>This controls the default rating search results will be filtered by, and nothing else. To override in your search results, add rating:* to your search.</td></tr>
                            <tr><td>
                                ".$this->get_selection_rater_html($selected_ratings, true, $available_ratings)."
                            </td></tr>
                        </tbody>
                        <tfoot>
                            <tr><td><input type='submit' value='Save'></td></tr>
                        </tfoot>
                    </table>
                </form>
            ";
        return $html;
    }
}
