function joinUrlSegments(base, query) {
    let  separatorChar = "?";
    if(base.includes("?")) {
        separatorChar = "&";
    }
    return base + separatorChar + query;
}

document.addEventListener('DOMContentLoaded', () => {
	if(document.location.hash.length > 3) {
		var query = document.location.hash.substring(1);

		$('LINK#prevlink').attr('href', function(i, attr) {
			return joinUrlSegments(attr,query);
		});
		$('LINK#nextlink').attr('href', function(i, attr) {
            return joinUrlSegments(attr,query);
		});
		$('A#prevlink').attr('href', function(i, attr) {
            return joinUrlSegments(attr,query);
		});
		$('A#nextlink').attr('href', function(i, attr) {
            return joinUrlSegments(attr,query);
		});
        $('span#image_delete_form form').attr('action', function(i, attr) {
            return joinUrlSegments(attr,query);
        });
        $('span#image_info form').attr('action', function(i, attr) {
            return joinUrlSegments(attr,query);
        });
	}

	// Set up some keyboard shortcuts
    window.addEventListener('keyup', function (e) {
        if (e.key === "Delete") {
            let button = document.getElementById("image_delete_button");
            if(button) {
                button.click();
            }
        }
    }, false);

});
