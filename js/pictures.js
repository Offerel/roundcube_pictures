/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.17
 * @author Offerel
 * @copyright Copyright (c) 2024, Offerel
 * @license GNU General Public License, version 3
 */
var lightbox;
window.rcmail && rcmail.addEventListener("init", function(a) {
	rcmail.register_command("rename_alb", rename_album, !0);
	rcmail.register_command("move_alb", move_album, !0);
	rcmail.register_command("sharepicture", selectShare, !0);
	rcmail.register_command("uploadpicture", uploadpicture, !0);
	rcmail.register_command("sharepic", sharepicture, !0);
	rcmail.register_command("delete_alb", delete_album, !0);
	rcmail.register_command("addalbum", add_album, !0);
	rcmail.register_command("add_alb", create_album, !0);
	rcmail.register_command("movepicture", mv_img, !0);
	rcmail.register_command("move_image", move_picture, !0);
	rcmail.register_command("delpicture", delete_picture, !0);
});

window.onload = function(){
	if( $('#images').length ) {
		$('#images').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 7,
			border: 0,
			lastRow: 'nojustify',
			captions: false,
			randomize: false,
			selector: '.glightbox'
		});

		let observer = new IntersectionObserver(function(e) {
			let last = document.getElementById('last') ? false:true;
			if(e[0].isIntersecting && e[0].time > 700 && last) lazyload(true);
		}, {threshold: [0]});
		observer.observe(document.querySelector("#btm"));

		lightbox = GLightbox({
			plyr: {
				config: {
					muted: true,
				}
			},
			autoplayVideos: false,
			loop: false,
			videosWidth: '100%'
		});
	
		lightbox.on('close', () => {
			if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
		});
	
		lightbox.on('slide_changed', (data) => {
			document.querySelectorAll('exinfo').forEach(element => {
				element.classList.remove('eshow');
			});
			let imglink = new URL(data.current.slideConfig.href);
			let exinfo = 'exif_' + imglink.searchParams.get('p');
			if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
	
			if(document.getElementById(exinfo)) {
				if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
				let closebtn = document.querySelector('.gclose');
				let infobtn = document.createElement('button');
				infobtn.id = 'infbtn';
				infobtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.0\" viewBox=\"0 0 160 160\"><g fill=\"white\"><path d=\"M80 15c-35.88 0-65 29.12-65 65s29.12 65 65 65 65-29.12 65-65-29.12-65-65-65zm0 10c30.36 0 55 24.64 55 55s-24.64 55-55 55-55-24.64-55-55 24.64-55 55-55z\"/><path d=\"M89.998 51.25a11.25 11.25 0 1 1-22.5 0 11.25 11.25 0 1 1 22.5 0zm.667 59.71c-.069 2.73 1.211 3.5 4.327 3.82l5.008.1V120H60.927v-5.12l5.503-.1c3.291-.1 4.082-1.38 4.327-3.82V80.147c.035-4.879-6.296-4.113-10.757-3.968v-5.074L90.665 70\"/></g></svg>';
				infobtn.addEventListener('mouseover', function() {
					document.getElementById(exinfo).classList.add('eshow');
					document.getElementById(exinfo).addEventListener('mouseover', function() {document.getElementById(exinfo).classList.add('eshow')})
				});
				infobtn.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
				});
				infobtn.addEventListener('mouseout', function() {
					document.getElementById(exinfo).classList.remove('eshow');
					document.getElementById(exinfo).addEventListener('mouseout', function() {document.getElementById(exinfo).classList.remove('eshow')})
				});
	
				closebtn.before(infobtn);
			}
		});

		lightbox.on('slide_before_change', (data) => {
			let cindex = data.current.index + 1;
			let cimages = document.getElementsByClassName('glightbox').length;
			let last = document.getElementById('last') ? false:true;
			if(cindex == cimages && last) {
				setTimeout(lazyload, 100, true);
			}
		});
	
		var prevScrollpos = window.scrollY;
		var header = document.getElementById('header');

		window.onscroll = function() {
			var currentScrollPos = window.scrollY;
			if (prevScrollpos > currentScrollPos) {
				header.style.top = '0';
				(currentScrollPos > 150) ? header.classList.add('shadow'):header.classList.remove('shadow');
			} else {
				header.style.top = '-55px';
				header.classList.remove('shadow')
			}
			prevScrollpos = currentScrollPos;
		}
	}

	if (top.location!= self.location) {
		top.location = self.location.href;
	}

	window.addEventListener("contextmenu", function(e) {
		e.preventDefault();
		e.stopPropagation();
		return false;
	});

	if(document.getElementById('never')) document.getElementById('never').addEventListener('change', function(){
		if(this.checked != true){
			document.getElementById('expiredate').disabled = false;
			let someDate = new Date();
			document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + 30));
		} else {
			document.getElementById('expiredate').disabled = true;
			document.getElementById('expiredate').value = '';
		}
	});

	if(document.getElementById('rsh')) document.getElementById('rsh').addEventListener('click', function(e){
		e.preventDefault();
		e.stopPropagation();
		$.ajax({
			type: "POST",
			url: "plugins/pictures/photos.php",
			data: {
				img_action: "dshare",
				share: document.getElementById('shares').selectedOptions[0].value
			},
			success: function(response) {
				getshares();
				document.getElementById('sid').value = '';
				document.getElementById('sname').value = '';
				document.getElementById('expiredate').value = '';
				document.getElementById('expiredate').disabled = false;
				document.getElementById('rsh').disabled = true;
				document.getElementById('never').checked = false;
				document.getElementById('link').value = '';
				let someDate = new Date();
				document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + 30));
				return false;
			}
		})
	});
};

function lazyload(slide = false) {
	$.ajax({
		type: 'POST',
		url: window.location.href,
		async: false,
		data: {
			s: $('.glightbox').length
		},success: function(response) {
			$('#images').append(response);
			$('#images').justifiedGallery('norewind');
			const html = new DOMParser().parseFromString(response, 'text/html');
			html.body.childNodes.forEach(element => {
				if (element.classList && element.classList.contains('glightbox')) {
					lightbox.insertSlide({
						'href': element.href,
						'type': element.dataset.type
					});
				}
			});
			lightbox.reload();
			return response;
		}
	});
}

function uploadpicture() {
	let ufrm = document.createElement('input');
	const folder = decodeURI(new URL(document.getElementById("picturescontentframe").contentWindow.document.getElementById('header').lastElementChild.href).search.substring(3));
	ufrm.type = 'file';
	ufrm.multiple = 'multiple';
	ufrm.accept = 'image/webp, image/jpeg, image/png';
	ufrm.id = 'ufrm';
	ufrm.addEventListener('change', function() {
		document.getElementById("picturescontentframe").contentWindow.document.getElementById('loader').style.visibility = 'visible';
		xhr = new XMLHttpRequest();
		let formdata = new FormData();
		const files = ufrm.files;
		for (let i = 0; i < files.length; i++) {
			const file = files[i];
			formdata.append('galleryfiles[]', file, file.name);
		}
		formdata.append('folder',folder);
		xhr.onload = function () {
			if(xhr.status === 200) {
				document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(folder);
			}
		}
		xhr.open('POST', './plugins/pictures/photos.php');
		xhr.send(formdata);
	})
	let ifrm = document.getElementById("picturescontentframe").contentWindow.document.body;
	ifrm.appendChild(ufrm);
	ufrm.click();
}

function selectShare() {
	getshares();
	document.getElementById('sid').value = '';
	document.getElementById('sname').value = '';
	document.getElementById('expiredate').value = '';
	document.getElementById('expiredate').disabled = false;
	document.getElementById('rsh').disabled = true;
	document.getElementById('never').checked = false;
	document.getElementById('link').value = '';
	document.getElementById('link').style.visibility = "hidden";
	document.getElementById('sbtn').style.visibility = "visible";
	$("#share_edit").contents().find("h2").html(rcmail.gettext("share", "pictures"));
	document.getElementById("share_edit").style.display = "block";
	let someDate = new Date();
	document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + 30));
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
	document.getElementById("album_name").focus();
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
			if(b == 1 && (document.getElementById("album_edit").style.display = "none")) {
				document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(a);
				getsubs();
			} else {
				alert(b);
			}
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
	document.getElementById("album_name").focus();
}

function getsubs() {
	$.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			getsubs: "1"
		},
		success: function(a) {
			$("#mv_target").html(a);
			setTimeout(document.getElementById('target').addEventListener('change', mvbtncl), 1000);
		}
	})
}

function mvbtncl() {
	document.getElementById('mvb').classList.remove('disabled');
	document.getElementById('album_name').value = document.getElementById('album_org').value.split("/").pop();
	document.getElementById('rnb').classList.add('disabled');
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
				document.getElementById('sid').value = parseInt(name.target.selectedOptions[0].value) ? name.target.selectedOptions[0].value:'';
				document.getElementById('link').value = '';
				document.getElementById('rsh').disabled = (parseInt(name.target.selectedOptions[0].value)) ? false:true;

				if (name.target.selectedOptions[0].dataset.ep == undefined || name.target.selectedOptions[0].dataset.ep) {
					document.getElementById('never').checked = false;
					document.getElementById('expiredate').disabled = false;
					let someDate = new Date();
					document.getElementById('expiredate').valueAsDate = (name.target.selectedOptions[0].dataset.ep == undefined) ? new Date(someDate.setDate(someDate.getDate() + 30)):new Date(name.target.selectedOptions[0].dataset.ep * 1000);
				} else {
					document.getElementById('never').checked = true;
					document.getElementById('expiredate').value = '';
					document.getElementById('expiredate').disabled = true;
				}
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
	var a = document.getElementById("album_org").value;
	if(confirm(rcmail.gettext("galdconfirm", "pictures"))) {
		let dalb = document.getElementById('dalb');
		dloader('#album_edit', dalb, 'add');
		$.ajax({
			type: "POST",
			url: "plugins/pictures/photos.php",
			data: {
				alb_action: "delete",
				src: a
			},
			success: function(a) {
				dloader('#album_edit', dalb, 'remove');
				1 == a && (document.getElementById("album_edit").style.display = "none", document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php", getsubs())
			}
		})
	}
}

function sharepicture() {
	var pictures = [];
	let sbtn = document.getElementById('sbtn');
	dloader('#share_edit', sbtn, 'add');
	let link = document.getElementById('link');
	link.style.visibility = "hidden";
	$("#picturescontentframe").contents().find(":checkbox:checked").each(function() {
		const urlParams = new URL($(this)[0].previousElementSibling.firstChild.src).searchParams;
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
			expiredate:	Math.floor(document.getElementById('expiredate').valueAsNumber / 1000)
		},
		success: function(a) {
			const url = new URL(location.href);
			let nurl = url.protocol + '//' + url.hostname + url.pathname + '?_task=pictures&slink=' + a;
			$("#link").contents().get(0).nodeValue = nurl;
			link.style.visibility = "visible";
			dloader('#share_edit', sbtn, 'remove');
			sbtn.style.visibility = "hidden";
		}
	});
}

function dloader(dialogid, button, mode) {
	if(document.getElementById('mdark')) document.getElementById('mdark').remove();

	let dialog = document.querySelector(dialogid + ' .modal-content');
	let gdiv = document.createElement('div');
	gdiv.id = 'mdark';
	if(mode == 'add') {
		dialog.appendChild(gdiv);
		button.classList.add('loading');
	} else {
		button.classList.remove('loading');
	}
}

function copyLink() {
	let link = document.getElementById('link').innerText;
	console.log("copylink", link);
	navigator.clipboard.writeText(link);
	document.getElementById('share_edit').style.display='none';
	window.parent.document.getElementById('info').style.display = 'none';
	return false;
}

function move_picture() {
	var a = [],
		b = document.getElementById("album_org_img").value,
		c = document.getElementById("album_name_img").value,
		d = document.getElementById("target").selectedOptions[0].value;
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
	document.getElementById('album_name_img').addEventListener('input', function() {
		document.getElementById('rpath').innerText = document.getElementById('target').selectedOptions[0].value + '/' + document.getElementById('album_name_img').value
	});
	$("#album_org_img").val(b); - 1 !== document.getElementById("mv_target_img").innerHTML.indexOf("div") && $.ajax({
		type: "POST",
		url: "plugins/pictures/photos.php",
		data: {
			getsubs: "1"
		},
		success: function(a) {
			$("#mv_target_img").html(a);
			document.getElementById('target').addEventListener('change', function() {
				document.getElementById('mvp').classList.remove('disabled');
				document.getElementById('rpath').innerText = document.getElementById('target').selectedOptions[0].value + '/' + document.getElementById('album_name_img').value
			});
		}
	});
	document.getElementById("rpath").innerHTML = "&nbsp;";
	if(document.getElementById("target")) document.getElementById("target").selectedIndex = 0;
	document.getElementById("album_name_img").value = "";
	document.getElementById("img_edit").style.display = "block";
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

	let ctext = rcmail.gettext("picdconfirm", "pictures").replace("%c", a.length);
	if(confirm(ctext)) {
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
	} else {
		return false;
	}
};
