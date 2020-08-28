/*jshint bitwise:true, curly:true, forin:false, noarg:true, noempty:true, nonew:true, undef:true, strict:false, browser:true, jquery:true */


function framerAddFramer(inWidth, inHeight, color) {
    var image_elements = document.querySelectorAll(".shm-image-list a img");
    image_elements.forEach(function(item) {
        var parent = item.parentElement;
        parent.style.position = "relative";

        var frameWidth = inWidth;
        var frameHeight = inHeight;

        var width = parseInt(parent.dataset["width"]);
        var height = parseInt(parent.dataset["height"]);
        var ratio = width/height;
        var newWidth, newHeight;

        if(width > height) {
            newWidth = item.offsetWidth;
            newHeight = newWidth * (1/ratio);
        } else {
            newHeight = item.offsetHeight;
            newWidth = newHeight * ratio;
        }

        var offsetX = (item.offsetWidth - newWidth)/2;
        var offsetY = (item.offsetHeight - newHeight)/2;

        var scaleX = newWidth / frameWidth;
        var scaleY = newHeight / frameHeight;
        var scale = scaleX;
        if(scaleY<scaleX)  {
            scale = scaleY;
        }
        frameWidth = frameWidth * scale;
        frameHeight = frameHeight * scale;

        offsetX = offsetX + ((newWidth - frameWidth)/2);
        offsetY = offsetY + ((newHeight - frameHeight)/2);

        console.log("test");
        var frame = $("<div class='frame" + inWidth + "x" + inHeight + "' style='position:absolute; left:" + offsetX + "px; top:" + offsetY + "px; width:" + frameWidth + "px; height:" + frameHeight + "px;outline:solid 1px " + color + ";color:" + color + ";vertical-align: bottom; '><div style='position:absolute; left:0; bottom:0;'>" + inWidth + ":" + inHeight + "</div></div>");
        $(parent).append(frame);
    });
}

function framerRemoveFrame(width, height) {
    $("#frame" + inWidth + "x" + inHeight).remove();
}

document.addEventListener('DOMContentLoaded', () => {
    framerAddFramer(8,9, "red");
    framerAddFramer(16,9, "green");
    framerAddFramer(8,3, "blue");
    framerAddFramer(1,2, "yellow");
    //framerAddFramer(3,2, "orange");
});
