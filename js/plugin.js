/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.4
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
window.rcmail && rcmail.addEventListener("init", function(a) {
	rcmail.register_command("editalbum", edit_album, !0);
	rcmail.register_command("rename_alb", rename_album, !0);
	rcmail.register_command("move_alb", move_album, !0);
	rcmail.register_command("sharepicture", selectShare, !0);
	rcmail.register_command("sharepic", sharepicture, !0);
	rcmail.register_command("delete_alb", delete_album, !0);
	rcmail.register_command("addalbum", add_album, !0);
	rcmail.register_command("add_alb", create_album, !0);
	rcmail.register_command("movepicture", mv_img, !0);
	rcmail.register_command("move_image", move_picture, !0);
	rcmail.register_command("delpicture", delete_picture, !0);

	document.getElementById('sname').addEventListener('input', function(e) {
		const snames = [];
		const slinks = [];
		let shares = document.getElementById("shares");
		for (i = 0; i < shares.length; i++) {
			snames.push(shares.options[i].text);
			slinks.push(shares.options[i].dataset.link);
		}

		if(!snames.includes(document.getElementById('sname').value) && slinks.includes(document.getElementById('slink').value)) {
			document.getElementById('slink').value = makeid(55);
			document.getElementById('sid').value = '';
		}
	});
});

function makeid(length) {
    let result = '';
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const charactersLength = characters.length;
    let counter = 0;
    while (counter < length) {
      result += characters.charAt(Math.floor(Math.random() * charactersLength));
      counter += 1;
    }
    return result;
}

function selectShare() {
	getshares();
	document.getElementById('slink').value = makeid(55);
	document.getElementById('sid').value = '';
	document.getElementById('sname').value = '';
	$("#share_edit").contents().find("h2").html(rcmail.gettext("share", "pictures"));
	document.getElementById("share_edit").style.display = "block";
}

function add_album() {
	var a = get_currentalbum();
	$("#album_edit").contents().find("h2").html(rcmail.gettext("new_album", "pictures"));
	$("#album_org").val(a);
	$("#album_name").val("");
	document.getElementById("mv_target").style.display = "none";
	document.getElementById("albedit").style.display = "none";
	document.getElementById("albadd").style.display = "block";
	document.getElementById("album_edit").style.display = "block";
}

function create_album() {
	var a = document.getElementById("album_org").value,
		b = document.getElementById("album_name").value;
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			alb_action: "create",
			target: b,
			src: a
		},
		success: function(b) {
			1 == b && (document.getElementById("album_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(a), getsubs())
		}
	})
}

function get_currentalbum() {
	var a = window.frames.picturescontentframe.location.search.substring(1).replace(/\+/g, "%20");
	if ("" != a)
		for (a = a.split("#")[0], a = a.split("&"); 0 < a.length;) return a = a[0].split("="), "p" == a[0] ? a[1] : !1;
	else return !1
}

function edit_album() {
	album = decodeURIComponent(get_currentalbum());
	var a = album.split("/");
	$("#album_edit").contents().find("h2").html("Album: " + a[a.length - 1]);
	$("#album_name").val(a[a.length - 1]);
	$("#album_org").val(album); - 1 !== document.getElementById("mv_target").innerHTML.indexOf("div") && getsubs();
	document.getElementById("albedit").style.display = "block";
	document.getElementById("albadd").style.display = "none";
	document.getElementById("mv_target").style.display = "block";
	document.getElementById("album_edit").style.display = "block";
}

function getsubs() {
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			getsubs: "1"
		},
		success: function(a) {
			$("#mv_target").html(a)
		}
	})
}

function getshares() {
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			getshares: "1"
		},
		success: function(a) {
			$("#share_target").html(a);
			document.getElementById('shares').addEventListener('change', function(name){
				document.getElementById('sname').value = name.target.selectedOptions[0].text;
				document.getElementById('sid').value = name.target.selectedOptions[0].value;
				document.getElementById('slink').value = name.target.selectedOptions[0].dataset.link;
			});
		}
	})
}

function rename_album() {
	var a = document.getElementById("album_org").value,
		b = document.getElementById("album_name").value;
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			alb_action: "rename",
			target: b,
			src: a
		},
		success: function(b) {
			1 == b && (document.getElementById("album_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(a), getsubs())
		}
	})
}

function move_album() {
	var a = document.getElementById("album_org").value,
		b = document.getElementById("target").value;
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			alb_action: "move",
			target: b,
			src: a
		},
		success: function(a) {
			1 == a && (document.getElementById("album_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(b), getsubs())
		}
	})
}

function delete_album() {
	console.log("L\u00f6schen");
	var a = document.getElementById("album_org").value;
	console.log(a);
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			alb_action: "delete",
			src: a
		},
		success: function(a) {
			1 == a && (document.getElementById("album_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php", getsubs())
		}
	})
}

function sharepicture() {
	var pictures = [];
	$("#picturescontentframe").contents().find(":checkbox:checked").each(function() {
		const urlParams = new URL($(this)[0].previousElementSibling.href).searchParams;
		pictures.push(urlParams.get('file'));
	});

	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			img_action: "share",
			images: pictures,
			shareid: document.getElementById('sid').value,
			sharename: document.getElementById('sname').value,
			sharelink: document.getElementById('slink').value
		},
		success: function(a) {
			//1 == a && (document.getElementById("img_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.reload(!0))
		}
	});
}

function move_picture() {
	var a = [],
		b = document.getElementById("album_org_img").value,
		c = document.getElementById("album_name_img").value,
		d = document.querySelector("#mv_target_img #target").value;
	$("#picturescontentframe").contents().find(":checkbox:checked").each(function() {
		a.push($(this).val())
	});
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			img_action: "move",
			images: a,
			orgPath: b,
			target: d,
			newPath: c
		},
		success: function(a) {
			1 == a && (document.getElementById("img_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.reload(!0))
		}
	})
}

function mv_img() {
	var a = window.frames.picturescontentframe.location.search.slice(1),
		b = "";
	if (a) {
		a = a.split("#")[0];
		a = a.split("&");
		for (b = 0; b < a.length; b++) {
			var c = a[b].split("=");
			if ("p" == c[0]) break
		}
		b = c[1]
	}
	b = decodeURI(b);
	$("#img_edit").contents().find("h2").html(rcmail.gettext("move_image", "pictures"));
	$("#album_name_img").attr("placeholder", rcmail.gettext("new_album", "pictures"));
	$("#album_org_img").val(b); - 1 !== document.getElementById("mv_target_img").innerHTML.indexOf("div") && $.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			getsubs: "1"
		},
		success: function(a) {
			$("#mv_target_img").html(a)
		}
	});
	document.getElementById("img_edit").style.display = "block"
}

function delete_picture() {
	var a = [],
		b = window.frames.picturescontentframe.location.search.slice(1),
		c = "";
	if (b) {
		b = b.split("#")[0];
		b = b.split("&");
		for (c = 0; c < b.length; c++) {
			var d = b[c].split("=");
			if ("p" == d[0]) break
		}
		c = d[1]
	}
	c = decodeURI(c);
	$("#picturescontentframe").contents().find(":checkbox:checked").each(function() {
		a.push($(this).val())
	});
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			img_action: "delete",
			images: a,
			orgPath: c
		},
		success: function(a) {
			1 == a && document.getElementById("picturescontentframe").contentWindow.location.reload(!0)
		}
	})
};
