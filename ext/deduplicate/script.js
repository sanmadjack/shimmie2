// Shamelessly taken from https://www.w3schools.com/howto/howto_js_image_comparison.asp and modified to hell


function calculateAspectRatioFit(srcWidth, srcHeight, maxWidth, maxHeight) {

    let ratio = Math.min(maxWidth / srcWidth, maxHeight / srcHeight);

    return { width: srcWidth*ratio, height: srcHeight*ratio };
}

function deduplicateFormSubmit(action) {
    let output = false;

    try {
        let leftPost = document.getElementById("left-post");
        let rightPost = document.getElementById("right-post");

        let leftWidth = parseInt(leftPost.dataset["width"]);
        let leftHeight = parseInt(leftPost.dataset["height"]);
        let leftFileSize = parseInt(leftPost.dataset["filesize"]);
        let leftLossless = leftPost.dataset["lossless"];
        leftLossless = (leftLossless=="1"||leftLossless=="true");
        let leftPixelCount = leftWidth * leftHeight;

        let rightWidth = parseInt(rightPost.dataset["width"]);
        let rightHeight = parseInt(rightPost.dataset["height"]);
        let rightFileSize = parseInt(rightPost.dataset["filesize"]);
        let rightLossless = rightPost.dataset["lossless"];
        rightLossless = (rightLossless=="1"||rightLossless=="true");
        let rightPixelCount = rightWidth * rightHeight;

        let prompted = false;
        switch (action) {
            case "merge_left":
            case "delete_right":
                if(leftPixelCount < rightPixelCount) {
                    prompted = true;
                    output = window.confirm("The left post has fewer pixels than the right post. Are you sure you want to delete the right post?")
                } else {
                    console.log("Left pixel count " + leftPixelCount + ", right pixel count " + rightPixelCount);
                }
                if((!prompted || output) && leftFileSize < rightFileSize) {
                    prompted = true;
                    output = window.confirm("The left post has a smaller file size than the right post. Are you sure you want to delete the right post?")
                } else {
                    console.log("Left file size " + leftFileSize + ", right pixel count " + rightFileSize);
                }
                if((!prompted || output) && rightLossless && !leftLossless) {
                    prompted = true;
                    output = window.confirm("The right post is a lossless format, while the left is not. Are you sure you want to delete the right post?")
                } else {
                    console.log("Left lossless: " + leftLossless + " and right lossless " + rightLossless);
                }
                if(!prompted) {
                    output = true;
                }
                break;
            case "merge_right":
            case "delete_left":
                if(rightPixelCount < leftPixelCount) {
                    prompted = true;
                    output = window.confirm("The right post has fewer pixels than the left post. Are you sure you want to delete the left post?")
                } else {
                    console.log("Right pixel count " + rightPixelCount + ", left pixel count " + leftPixelCount);
                }
                if((!prompted || output) && rightFileSize < leftFileSize) {
                    prompted = true;
                    output = window.confirm("The right post has a smaller file size than the left post. Are you sure you want to delete the left post?")
                } else {
                    console.log("Right file size " + rightFileSize + ", left pixel count " + leftFileSize);
                }
                if((!prompted || output) && leftLossless && !rightLossless) {
                    prompted = true;
                    output = window.confirm("The left post is a lossless format, while the right is not. Are you sure you want to delete the left post?")
                } else {
                    console.log("Left lossless: " + leftLossless + " and right lossless " + rightLossless);
                }
                if(!prompted) {
                    output = true;
                }
                break;
            case "delete_both":
                return window.confirm("Are you sure you want to delete both posts?")
        }
    } catch(e) {
        console.log(e);
        output = false;
    }
    return output;
}


function initComparisons() {
    var leftPost = document.getElementById("left-post");
    var rightPost = document.getElementById("right-post");
    var comparisonContainer = document.getElementById("img-comp-container");


    var right_post_hidden = document.getElementsByName("right_post");

    for(i = 0; i < right_post_hidden.length; i++) {
        right_post_hidden[i].value = rightPost.dataset["id"];
    }


    var w, h;


    var x, i;
    /* Find all elements with an "overlay" class: */
    x = document.getElementsByClassName("img-comp-overlay");

    setViewerSize();

    for (i = 0; i < x.length; i++) {
        /* Once for each "overlay" element:
        pass the "overlay" element as a parameter when executing the comparePosts function: */
        comparePosts(x[i]);
    }


    function setViewerSize() {

        var maxHeight = window.innerHeight - 300;
        var maxWidth= window.innerWidth - 700;

        var leftDimensions = calculateAspectRatioFit(leftPost.dataset["width"], leftPost.dataset["height"], maxWidth, maxHeight);

        var rightDimensions = calculateAspectRatioFit(rightPost.dataset["width"], rightPost.dataset["height"], maxWidth, maxHeight);

        w = Math.max(leftDimensions.width, rightDimensions.width);
        h = Math.max(leftDimensions.height, rightDimensions.height);
        leftPost.style.height = h + "px";
        leftPost.style.width = w + "px";
        rightPost.style.height = h + "px";
        rightPost.style.width = w + "px";
        comparisonContainer.style.height = h + "px";
        comparisonContainer.style.width = w + "px";

    }

    function comparePosts(img) {
        var slider, img, clicked = 0;

        var animating = true;
        var animationDirection = 1;
        var animationSpeed = 3;
        var internvalId = setInterval(frame, 10);

        /* Get the width and height of the img element */
        /* Set the width of the img element to 50%: */
        img.style.width = (w / 2) + "px";
        /* Create slider: */
        slider = document.createElement("DIV");
        slider.setAttribute("class", "img-comp-slider");
        /* Insert slider */
        img.parentElement.insertBefore(slider, img);
        /* Position the slider in the middle: */
        slider.style.top = (h / 2) - (slider.offsetHeight / 2) + "px";
        slider.style.left = (w / 2) - (slider.offsetWidth / 2) + "px";
        /* Execute a function when the mouse button is pressed: */
        slider.addEventListener("mousedown", slideReady);
        /* And another function when the mouse button is released: */
        window.addEventListener("mouseup", slideFinish);
        /* Or touched (for touch screens: */
        slider.addEventListener("touchstart", slideReady);
        /* And released (for touch screens: */
        window.addEventListener("touchstop", slideFinish);

        window.addEventListener("resize", setViewerSize);

        function slideReady(e) {
            if(animating)
                return;
            /* Prevent any other actions that may occur when moving over the image: */
            e.preventDefault();
            /* The slider is now clicked and ready to move: */
            clicked = 1;
            /* Execute a function when the slider is moved: */
            window.addEventListener("mousemove", slideMove);
            window.addEventListener("touchmove", slideMove);
        }
        function slideFinish() {
            if(animating)
                return;
            /* The slider is no longer clicked: */
            clicked = 0;
        }

        function frame() {
            if (!animating) {
                clearInterval(internvalId);
            } else {
                var pos = currentPos.slice(0);
                pos[0] = pos[0] + (animationDirection*animationSpeed);

                if (pos[0] < 0) {
                    pos[0] = 0;
                    animationDirection = 1;
                }
                if (pos[0] > w) {
                    pos[0] = w;
                    animationDirection = -1;
                }

                slide(pos);
            }
        }

        function slideMove(e) {
            if(animating)
                return;

            var pos;
            /* If the slider is no longer clicked, exit this function: */
            if (clicked == 0) return false;
            /* Get the cursor's x position: */
            pos = getCursorPos(e)
            /* Prevent the slider from being positioned outside the image: */
            if (pos[0] < 0) pos[0] = 0;
            if (pos[1] < 0) pos[1] = 0;
            if (pos[0] > w) pos[0] = w;
            if (pos[1] > (h - slider.offsetHeight)) pos[1] = h - slider.offsetHeight;

            /* Execute a function that will resize the overlay image according to the cursor: */
            slide(pos);
        }
        function getCursorPos(e) {
            var a, x = 0, y= 0;
            e = e || window.event;
            /* Get the x positions of the image: */
            a = img.getBoundingClientRect();
            /* Calculate the cursor's x coordinate, relative to the image: */
            x = e.pageX - a.left;
            y = e.pageY - a.top;
            /* Consider any page scrolling: */
            x = x - window.pageXOffset;
            y = y - window.pageYOffset;

            return [x,y];
        }

        var currentPos = [w,0];
        function slide(pos) {
            currentPos = pos;
            /* Resize the image: */
            img.style.width = pos[0] + "px";
            /* Position the slider: */
            slider.style.left = img.offsetWidth - (slider.offsetWidth / 2) + "px";
            slider.style.top =  pos[1] + "px";
            if(animating) {
                slider.style.visibility = "hidden";
            } else {
                slider.style.visibility = "visible";
            }
        }
    }
}
