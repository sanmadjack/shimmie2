function byId(id) {
	return document.getElementById(id);
}


function showTagLists() {
    var eles = document.querySelectorAll(".tag_pallet_tags_list");
    for(var i = 0; i<eles.length; i++) {
        eles[i].style.display = "block";
    }
}

function hideTagLists() {
    var eles = document.querySelectorAll(".tag_pallet_tags_list");
    for(var i = 0; i<eles.length; i++) {
        eles[i].style.display = "block";
    }
}

var TagPallet = {
    tagData: {},
    unsavedChanges: false,

    initialize : function (images_api_path) {
        console.log("initializing tag pallet");
        var pallet = this;
        this.setupDragAndDrop(this);

        this.container = byId('tag_pallet');
        this.titlebar  = this.container.querySelector(".title");
        this.input = this.container.querySelector("[name=pallet_tags]");
        this.palletElement = $("#tag_pallet [name=pallet_tags]");
        this.imagesApiPath = images_api_path;

        this.tagElements = this.container.querySelectorAll("ul.tagit")

        DragHandler.attach(this.titlebar);
        this.container.querySelectorAll("ul.tagit li.tagit-choice").forEach(this._addDragToTagNode);

        const config = { childList: true };
        this.tagElements.forEach(function(ele) {
            var observer = new MutationObserver(function(mutationsList, observer) {
                for(let mutation of mutationsList) {
                    if (mutation.type === 'childList') {
                        for(var i = 0; i < mutation.addedNodes.length; i++) {
                            var node = mutation.addedNodes[i];
                            pallet._addDragToTagNode(node);
                        }
                    }
                }
            });
            observer.observe(ele, config);
        });


        var tags = window.localStorage.getItem('tag_pallet-tags');
        if(tags) {
            tags = JSON.parse(tags);

            tags.forEach(function(tag) {
                pallet.palletElement.tagit("createTag", tag);
            });
        }

        $("#tag_pallet [name=query_tags]").tagit({
            readOnly : true
        });



        // positioning
        this.position.container = this.container;
        this.position.load();

        var v = window.localStorage.getItem("tag_pallet-visibility");
        if(v) {
            this.container.style.display = v;
        }

        // dragging
        DragHandler.attach(this.titlebar);

        // events
        window.onunload = function () {
            pallet.save();
        };
        window.onbeforeunload = function (e) {
            if(pallet.unsavedChanges) {
                return "You have unsaved tag changes, are you sure you wish to leave?";
            }
        };
    },
    setupDragAndDrop: function(pallet) {
        var image_elements = document.querySelectorAll(".shm-image-list a img");
        image_elements.forEach(function(item) {
            var parent = item.parentElement;
            parent.style.position = "relative";

            var currentTagElement = document.createElement("div");
            currentTagElement.classList.add("tag_pallet_tags_list")
            var list = document.createElement("ul");
            list.classList.add("current_tags");
            currentTagElement.appendChild(list);
            parent.dataset["tags"].split(" ").forEach(function(tag) {
                var ele = document.createElement("li")
                ele.innerText = tag;
                list.appendChild(ele);
            });
            parent.appendChild(currentTagElement);

            list = document.createElement("ul");
            list.classList.add("tag_changes");
            currentTagElement.appendChild(list);

            item.addEventListener("dragover",function(ev) {
                ev.preventDefault();
            });
            item.addEventListener("drop",function(ev) {
                var tag = ev.dataTransfer.getData("pallet_tag");
                if(tag===""){
                    return;
                }
                var parent = ev.target.parentElement;
                var id = parent.dataset["postId"];
                console.log("dropped " + tag + " on " + id);

                if(pallet.setTag(id,tag)) {
                    var modificationList = parent.querySelector(".tag_changes");
                    modificationList.innerHTML = "";
                    pallet.tagData[id].forEach(function (tag) {
                        var ele = document.createElement("li")
                        ele.innerText = tag;
                        modificationList.appendChild(ele);
                    });
                }

                ev.target.classList.remove("tag_pallet_target");
            });
            item.addEventListener("dragenter",function(ev) {
                if(!ev.dataTransfer.types.includes("pallet_tag")){
                    return;
                }
                ev.target.classList.add("tag_pallet_target");
            });
            item.addEventListener("dragleave",function(ev) {
                ev.target.classList.remove("tag_pallet_target");
            });
        });
    },

    _addDragToTagNode: function (node) {
        node.draggable = true;
        node.addEventListener("dragstart",function(ev) {
            ev.dataTransfer.setData("pallet_tag", ev.target.innerText);
        });
    },

    isOpen: function() {
        return this.container.style.display == "block";
    },
    toggle: function() {
        if(this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    },
    open: function() {
        this.container.style.display = "block";
        // dragging
    },
    close: function() {
        this.container.style.display = "none";
    },

    save: function() {
        this.position.save();
        var data = this.palletElement.tagit("assignedTags");
        data = JSON.stringify(data);
        window.localStorage.setItem("tag_pallet-tags",data);
        window.localStorage.setItem('tag_pallet-visibility', this.container.style.display);
    },
    clear: function () {
        this.palletElement.tagit("removeAll");
    },

    position : {
        set : function (x,y) {
            if (!x || !y) {
                this.container.style.top = "25px";
                this.container.style.left = "25px";
                this.container.style.right = "";
                this.container.style.bottom = "";

                var xy = this.get();
                x = xy[0];
                y = xy[1];
            }
            if(x<0) {
                x = 0;
            }
            if(y<0) {
                y = 0;
            }
            if(x>window.innerWidth) {
                x = 25;
            }
            if(y>window.innerHeight) {
                y = 25;
            }

            this.container.style.top = y+"px";
            this.container.style.left = x+"px";
            this.container.style.right = "";
            this.container.style.bottom = "";
        },

        get : function () {
            var rect = this.container.getBoundingClientRect();
            var left = rect.left;
            var top  = rect.top;
            return [left,top];
        },

        save : function (x,y) {
            if (!x || !y) {
                var xy = this.get();
                x = xy[0];
                y = xy[1];
            }

            window.localStorage.setItem('tag_pallet-position', [x,y]);
        },

        load : function () {
            var p = window.localStorage.getItem("tag_pallet-position");
            if(p) {
                p = p.split(",")
                this.set(p[0],p[1]);
            } else {
                this.set();
            }
        }
    },
    setTag: function(id, tag) {
        if(!this.tagData[id]) {
            this.tagData[id] = [];
        }
        if(!this.tagData[id].includes(tag)) {
            this.tagData[id].push(tag);
            this.unsavedChanges = true;
            return true;
        }
    },
    writeTags: async function() {
        var form = $(this.container).find("form");
        var path = form.attr('action');
        var progress = $("#tag_write_progress");
        progress.attr('max',Object.keys(this.tagData).length);
        progress.attr('value',0);
        progress.show();

        var i = 0;
        for(var id in this.tagData) {
            console.log(id);
            console.log(this.tagData[id]);

            var currentPath = path.replace("{id}", id).replace("{tags}",encodeURIComponent(JSON.stringify(this.tagData[id])));
            console.log(currentPath);
            var result = await $.ajax({
                 url: currentPath,
                 type: 'POST'
             });
            console.log(result);
            this.tagData[id] = [];
            progress.attr('value',i++);
        }
        this.unsavedChanges = false;
        progress.hide();

    }

};
