/**
 * Roundcube Photos Plugin
 *
 * @version 1.5.6
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
var lightbox, tagify, MastoStatus, clicks, intervalID;
window.rcmail && rcmail.addEventListener("init", function(a) {
	rcmail.register_command("rename_alb", rename_album, !0);
	rcmail.register_command("move_alb", move_album, !0);
	rcmail.register_command("sharepicture", selectShare, !0);
	rcmail.register_command("uploadpicture", uploadpicture, !0);
	rcmail.register_command("sharepic", sharepicture, !0);
	rcmail.register_command("delete_alb", delete_album, !0);
	rcmail.register_command("timeline", timeline, !0);
	rcmail.register_command("add_alb", create_album, !0);
	rcmail.register_command("movepicture", mv_img, !0);
	rcmail.register_command("move_image", move_picture, !0);
	rcmail.register_command("delpicture", delete_picture, !0);
	rcmail.register_command("searchphoto", searchform, !0);
	rcmail.register_command("edit_meta", metaform, !0);

	if(localStorage.getItem("pnav") == 'timeline') {
		document.getElementById('picturescontentframe').src = 'plugins/pictures/photos.php?f=1';
		document.getElementById('stimeline').classList.remove('time');
		document.getElementById('stimeline').classList.add('albums');
		document.getElementById('stimeline').title = rcmail.gettext('albums','pictures');
		document.getElementById('stimeline').querySelector('.inner').innerText = rcmail.gettext('albums','pictures');
		localStorage.setItem("pnav", 'timeline');
	}
	
});

window.onload = function(){
	if (top.location!= self.location) {
		top.location = self.location.href;
	}

	if(document.getElementById('never')) document.getElementById('never').addEventListener('change', function(){
		if(this.checked != true){
			document.getElementById('expiredate').disabled = false;
			let someDate = new Date();
			document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + rcmail.env.sdays));
		} else {
			document.getElementById('expiredate').disabled = true;
			document.getElementById('expiredate').value = '';
		}
	});

	if(document.getElementById('rsh')) document.getElementById('rsh').addEventListener('click', function(e){
		e.preventDefault();
		e.stopPropagation();

		sendRequest(shareDel, {
			share: document.getElementById('shares').selectedOptions[0].value
		});
	});

	if(document.getElementById('skeywords')) document.getElementById('skeywords').addEventListener('input', function(e) {
		if(document.getElementById('skeywords').value.length > 0) document.getElementById('spb').classList.remove('disabled');
	});

	if(document.getElementById('spb')) document.getElementById('spb').addEventListener('click', function() {
		dosearch()
	});
	if(document.getElementById('csb')) document.getElementById('csb').addEventListener('click', function(e) {
		document.getElementById('searchphotof').style.display='none';
	});

	if(document.getElementById('mec')) document.getElementById('mec').addEventListener('click', function() {
		document.getElementById('metadata').style.display='none';
	});

	if(document.getElementById('searchphotof')) {
		document.querySelector("#searchphotof form").addEventListener('submit', function(e) {
			e.preventDefault();
			dosearch();
		});
	
		document.getElementById('metitle').addEventListener('input', function(e) {
			if(document.getElementById('metitle').value.length > 0) document.getElementById('mes').classList.remove('disabled');
		});
		document.getElementById('medescription').addEventListener('input', function(e) {
			if(document.getElementById('medescription').value.length > 0) document.getElementById('mes').classList.remove('disabled');
		});
	
		const WhiteList = (rcmail.env.ptags != undefined) ? JSON.parse(rcmail.env.ptags):[];
		tagify = new Tagify(document.getElementById('mekeywords'), {
			whitelist: WhiteList,
			dropdown : {
				classname	: "color-blue",
				trim		: true,
				enabled		: 0,
				maxItems	: WhiteList.length,
				position	: "text",
				closeOnSelect : false,
				highlightFirst: true
			},
			trim: true,
			duplicates: false,
			enforceWhitelist: false,
			delimiters: ',|;'
		});
	
		tagify.on('add remove', function(e) {
			document.getElementById('mes').classList.remove('disabled');
		});
		if(document.getElementById('mes')) document.getElementById('mes').addEventListener('click', function() {
			save_meta(WhiteList)
		});

		MastoStatus = new Tagify(document.getElementById('pstatus'), {
			mode: 'mix',
			pattern: /@|#/,
			whitelist: [],
			validate(data){
				return !/[^a-zA-Z0-9 ]/.test(data.value)
			},
			dropdown : {
				enabled: 1,
				position: 'text',
				mapValueTo: 'text',
				highlightFirst: true
			},
			callbacks: {
				"change": (e) => calcLength(e.detail.value),
				"paste": (e) => calcLength(e.detail.event.target.innerText),
			}
    	})
	}

	if(document.getElementById('suser')) document.getElementById('suser').addEventListener('change', function(e) {
		sendRequest(validateUser, {
			user: this.value
		});
	});

	for (let e of document.querySelectorAll('.tab-bar button')) {
		e.addEventListener('click', b => {
			b.preventDefault();
			document.getElementById('sintern').style.display = 'none';
			document.getElementById('spublic').style.display = 'none';
			document.getElementById('spixelfed').style.display = 'none';
			document.getElementById(e.value).style.display = 'block';

			document.querySelector('[value="sintern"]').classList.remove('tab-active');
			document.querySelector('[value="spublic"]').classList.remove('tab-active');
			document.querySelector('[value="spixelfed"]').classList.remove('tab-active');
			document.querySelector('[value="'+e.value+'"]').classList.add('tab-active');
			
			if(e.value === 'spixelfed') {
				if(document.getElementById('max_attachments').value == 0) rcmail.display_message(rcmail.gettext('pf_conf_error','pictures'), 'error');
				MastoStatus.whitelist = document.getElementById('mstdtags').value.split(',');
				let text = rcmail.gettext('pftomuch','pictures');
				let max_attachments = document.getElementById('max_attachments').value;
				if(document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('input[type=\"checkbox\"]:checked').length > max_attachments) rcmail.display_message(text.replace('%max%', max_attachments), 'warning');
				if(parseInt(document.getElementById('mdchars').innerText) < 0) document.getElementById('sbtn').classList.add('disabled');
			} else {
				document.getElementById('sname').disabled = false;
				document.getElementById('rsh').disabled = false;
				if(document.getElementById('shares')) document.getElementById('shares').disabled = false;
				document.getElementById('sbtn').classList.remove('disabled');
			}
		});
	}

	document.querySelector('#spixelfed .tagify__input').addEventListener('input', s => {
		calcLength(s.target.innerText);
	});
};

function timeline() {
	let frame = document.getElementById('picturescontentframe');
	let url = new URL(frame.src);
	let btn = document.getElementById('stimeline');

	if(url.searchParams.get('f') == null) {
		frame.src = 'plugins/pictures/photos.php?f=1';
		btn.classList.remove('time');
		btn.classList.add('albums');
		btn.title = rcmail.gettext('albums','pictures');
		btn.querySelector('.inner').innerText = rcmail.gettext('albums','pictures');
		localStorage.setItem("pnav", 'timeline');
	} else {
		frame.src = 'plugins/pictures/photos.php';
		btn.classList.remove('albums');
		btn.classList.add('time');
		btn.title = rcmail.gettext('timeline','pictures');
		btn.querySelector('.inner').innerText = rcmail.gettext('timeline','pictures');
		localStorage.setItem("pnav", 'albums');
	}
}

function calcLength(text) {
	text = text.trim().replaceAll('[[{"value":"', '#').replaceAll('","prefix":"#"}]]', '');
	let diff = parseInt(document.getElementById('max_chars').value) - text.length;
	let mdchars = document.getElementById('mdchars');
	let btn = document.getElementById('sbtn');
	mdchars.innerText = diff;
	if(diff < 0) {
		mdchars.style.color = 'red';
		btn.classList.add('disabled')
	} else {
		mdchars.style.color = 'unset';
		btn.classList.remove('disabled')
	}
}

function getTags(response) {
	if(response.code == 200) {
		document.getElementById('mstdtags').value = response.tags;
	} else {
		rcmail.display_message(rcmail.gettext('pf_conf_error','pictures'), 'error');
	}
}

function validateUser(response) {
	let shares = document.getElementById('shares');
	let rsh = document.getElementById('rsh');
	let expiredate = document.getElementById('expiredate');
	let sbtn = document.getElementById('sbtn');
	let never = document.getElementById('never');
	let suser = document.getElementById('suser');

	if(response.code === 200) {
		shares.disabled = true;
		rsh.disabled = true;
		expiredate.disabled = true;
		expiredate.style.color = "lightgray";
		never.disabled = true;
		suser.style.color = 'green';
		suser.style.borderColor = 'green';
		suser.style.backgroundColor = 'honeydew';
		sbtn.title = rcmail.gettext('intsharetitle','pictures');
		document.getElementById('uid').value = response;
	} else {
		shares.disabled = false;
		rsh.disabled = false;
		expiredate.disabled = false;
		expiredate.style.color = "black";
		never.disabled = false;
		suser.style.color = 'orangered';
		suser.style.borderColor = 'red';
		suser.style.backgroundColor = 'antiquewhite';
		sbtn.title = rcmail.gettext('extlinktitle','pictures');
		document.getElementById('uid').value = '';
	}
}

function shareDel(response) {
	if(response.code === 200) {
		sendRequest(getshares);
		document.getElementById('sid').value = '';
		document.getElementById('sname').value = '';
		document.getElementById('expiredate').value = '';
		document.getElementById('expiredate').disabled = false;
		document.getElementById('rsh').disabled = true;
		document.getElementById('never').checked = false;
		document.getElementById('link').value = '';
		let someDate = new Date();
		document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + rcmail.env.sdays));
	}
	return false;
}

function dosearch() {
	let keywords = document.getElementById('skeywords').value.split(' ').filter(elm => elm);
	if (keywords.length <= 0) {
		document.getElementById('searchphotof').style.display='none';
		return false;
	}

	sendRequest(search, keywords);
}

function search(response) {
	document.getElementById('searchphotof').style.display='none';

	setTimeout(() => {
		if(response.images.length > 0) {
			let iframe = document.getElementById('picturescontentframe');
			let imgdiv = iframe.contentWindow.document.getElementById('images');
			let folder = iframe.contentWindow.document.getElementById('folders');

			let span = document.createElement('span');
			span.id = 'last';
			imgdiv.replaceChildren(span);

			response.images.forEach(element => {
				let div = document.createElement('div');
				let link = document.createElement('a');
				link.setAttribute("class","image glightbox");
				link.href = 'simg.php?file=' + element.path;

				let image = document.createElement('img');
				image.src = 'simg.php?file=' + element.path + '&t=1';
				image.width = element.dim[0];
				image.height = element.dim[1];
				image.setAttribute('alt', element.alt);
				link.appendChild(image);
				div.appendChild(link);

				let input = document.createElement('input');
				input.name = 'images';
				input.value = 'file';
				input.type = 'checkbox';
				input.addEventListener('change', count_checks);
				input.classname = 'icheckbox';
				div.appendChild(input);

				let caption = document.createElement('div');
				caption.id = element.path.split('/').pop();
				caption.setAttribute('class', 'exinfo');

				let e_el = '';

				for (let key in element.exif) {
					if(key !== 'map') {
						e_el += key + ': ' + element.exif[key] + '<br>';
					} else {
						e_el += '<img src="images/marker.png"><a class="mapl" href="' + element.exif[key]['osm'] + '" target="_blank">OSM</a> | <a class="mapl" href="' + element.exif[key]['google'] + '" target="_blank">Google Maps</a>';
					}
				}

				caption.innerHTML = e_el;
				div.appendChild(caption);

				imgdiv.appendChild(div);
			});

			folder.style.display = 'none';

			let header = iframe.contentWindow.document.querySelector('#header .breadcrumb');
			header.innerHTML = "<li>" + rcmail.gettext('searchfor','pictures') + response.keywords.join(', ') + "</li>";

			iframe.contentWindow.lightbox.reload();
			iframe.contentWindow.$('#images').justifiedGallery('norewind');
		} else {
			rcmail.display_message(rcmail.gettext('noresults','pictures'), 'error');
		}
	}, 50);
}

function searchform() {
	$("#searchphotof").contents().find("h2").html(rcmail.gettext('search','pictures'));
	document.getElementById("searchphotof").style.display = "block";
	document.getElementById('skeywords').focus();
}

function metaform() {
	let iframe = document.getElementById('picturescontentframe');
	let media = []
	for(e of iframe.contentWindow.document.querySelectorAll('.icheckbox:checked')) {
		let url = new URL(e.parentElement.firstChild.href);
		media.push(url.searchParams.get('file'));
	}

	sendRequest(getMetadata, {
		media: media
	});
}

function getMetadata(response) {
	if(response.mdata['keywords'] == 2) {
		tagify.DOM.input.setAttribute('data-placeholder', rcmail.gettext('mulval','pictures'));
		document.getElementById('mekeywords').value = '';
	} else {
		document.getElementById('mekeywords').value = response.mdata['keywords'];
	}

	if(response.mdata['title'] == 2) {
		document.getElementById('metitle').placeholder = rcmail.gettext('mulval','pictures');
		document.getElementById('metitle').value = '';
	} else {
		document.getElementById('metitle').value = response.mdata['title'];
	}

	if(response.mdata['description'] == 2) {
		document.getElementById('medescription').placeholder = rcmail.gettext('mulval','pictures');
		document.getElementById('medescription').value = '';
	} else {
		document.getElementById('medescription').value = response.mdata['description'];
	}

	document.getElementById("mdheader").innerText = rcmail.gettext('metadata','pictures');
	document.getElementById("metadata").style.display = "block";
	document.getElementById('mekeywords').focus();
}

function save_meta(WhiteList) {
	const iframe = document.getElementById('picturescontentframe');
	const nodes = iframe.contentWindow.document.querySelectorAll('input[type=\"checkbox\"]:checked');
	dloader('#metadata', mes, 'add');

	let files = []
	for (let i=0; i < nodes.length; i++) {
		let dir = new URLSearchParams(new URL(nodes[i].baseURI).search).get('p');
		files.push(dir + '/' + nodes[i].value);
	}

	const meta_data = {
		keywords:JSON.parse(document.getElementById('mekeywords').value).map(item => item.value), 
		title:document.getElementById('metitle').value, 
		description:document.getElementById('medescription').value,
		files: files
	};

	let new_keywords = meta_data.keywords.filter(x => !WhiteList.includes(x));

	sendRequest(setKeywords, {
		keywords: new_keywords
	});

	sendRequest(saveMetadata, meta_data);
}

function saveMetadata(response) {
	dloader('#metadata', mes, 'remove');
	document.getElementById('metadata').style.display='none';

	if (response.code === 200) {
		rcmail.display_message('Data saved', 'confirmation');
	} else {
		rcmail.display_message(response.message, 'error');
	}
}

function setKeywords(response) {
	tagify.whitelist = response.keywords;
}

function count_checks() {
	let checked = document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('input[type=\"checkbox\"]:checked').length;
	let scount = document.getElementById("picturescontentframe").contentWindow.document.getElementById('scount');
	if(checked > 0) {
		scount.innerText = checked + ' selected';
		scount.style.display = 'inline';
		document.getElementById('movepicture').classList.remove('disabled');
		document.getElementById('delpicture').classList.remove('disabled');
		document.getElementById('sharepicture').classList.remove('disabled');
		document.getElementById('editmeta').classList.remove('disabled');
	} else {
		scount.innerText = '';
		scount.style.display = 'none';
		document.getElementById('movepicture').classList.add('disabled');
		document.getElementById('delpicture').classList.add('disabled');
		document.getElementById('sharepicture').classList.add('disabled');
		document.getElementById('editmeta').classList.add('disabled');
	}
}

function rm_checks() {
	let checked = document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('input[type=\"checkbox\"]:checked');
	for(let i = 0; i < checked.length; i++){
		if(checked[i].checked){
			checked[i].checked = false;
		}
	}

	checked = document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('input[type=\"checkbox\"]:checked');
	if(checked.length < 1) {
		document.getElementById("picturescontentframe").contentWindow.document.getElementById('scount').innerText = '';
		document.getElementById("picturescontentframe").contentWindow.document.getElementById('scount').style.display = 'none';
		document.getElementById("sharepicture").classList.add('disabled');
		document.getElementById("delpicture").classList.add('disabled');
		document.getElementById("editmeta").classList.add('disabled');
		document.getElementById("movepicture").classList.add('disabled');
	}
}

function uploadpicture() {
	let ufrm = document.createElement('input');
	const folder = new URL(document.getElementById("picturescontentframe").contentWindow.location.href).searchParams.get("p");
	let progressBar = document.getElementById("picturescontentframe").contentWindow.document.getElementById('progress');
	ufrm.type = 'file';
	ufrm.multiple = 'multiple';
	ufrm.accept = 'image/webp, image/jpeg, image/png';
	ufrm.id = 'ufrm';
	ufrm.addEventListener('change', function() {
		xhr = new XMLHttpRequest();
		let formdata = new FormData();
		const files = ufrm.files;
		for (let i = 0; i < files.length; i++) {
			const file = files[i];
			formdata.append('galleryfiles[]', file, file.name);
		}
		formdata.append('folder',folder);

		xhr.upload.addEventListener('progress', function(event) {
			let percentComplete = Math.ceil(event.loaded / event.total * 100);
			progressBar.style.visibility = 'visible';
			progressBar.firstChild.innerHTML = percentComplete + '%';
			progressBar.firstChild.style.width = percentComplete + '%';
		});

		xhr.onload = function () {
			if(xhr.status === 200) {
				let data = JSON.parse(xhr.responseText);
				let bg = '';

				for (var i = 0; i < data.length; i++) {
					switch(data[i].type) {
						case 'error': bg = '#dc3545'; break;
						case 'warning': bg = '#ffc107'; break;
						default: bg = '#28a745';
					}

					progressBar.firstChild.style.backgroundColor = bg;
					console.log(data[i].message);
				}

				setTimeout(function() {
					progressBar.style.visibility = 'hidden';
					progressBar.firstChild.style.width = 0;
					progressBar.firstChild.style.backgroundColor = '#007bff';
					document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(folder);
					count_checks();
				}, 5000);
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
	let url = new URL(document.getElementById("picturescontentframe").contentWindow.document.documentURI);
	let currentName = url.searchParams.get('p') ? url.searchParams.get('p'):'';
	sendRequest(getshares);
	document.getElementById('sid').value = '';
	
	let sbtn = document.getElementById('sbtn');
	document.getElementById('sname').value = currentName;
	document.getElementById('expiredate').value = '';
	document.getElementById('expiredate').disabled = false;
	document.getElementById('rsh').disabled = true;
	document.getElementById('never').checked = false;
	document.getElementById('link').value = '';
	document.getElementById('link').style.display = "none";
	document.getElementById('pstatus').value = '';
	document.getElementById('pfvisibility').selectedIndex = 0;
	document.getElementById('pfsensitive').checked = false;
	sbtn.style.visibility = "visible";
	$("#share_edit").contents().find("h2").html(rcmail.gettext("share", "pictures"));
	document.getElementById("share_edit").style.display = "block";
	let someDate = new Date();
	document.getElementById('expiredate').valueAsDate = new Date(someDate.setDate(someDate.getDate() + rcmail.env.sdays));
	sbtn.tabIndex = 5;
	sbtn.title = rcmail.gettext('extlinktitle','pictures');

	document.querySelector('[value="spublic"]').click();
	sendRequest(getTags);

	document.getElementById('max_attachments').value = rcmail.env.pfm;
	document.getElementById('max_chars').value = rcmail.env.c;
	document.getElementById('type').value = rcmail.env.t;
	document.querySelector('[value="spixelfed"]').innerText = rcmail.env.t;
	
	if(document.getElementById('max_attachments').value > 0) {
		document.getElementById('sbtn').classList.remove('disabled');
	} else {
		document.getElementById('pstatus').disabled = true;
		document.getElementById('pfvisibility').disabled = true;
		document.getElementById('pfsensitive').disabled = true;
		document.getElementById('sbtn').classList.add('disabled');
	}
	
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
	sendRequest(albCreate, {
		target: document.getElementById("album_name").value,
		source: document.getElementById("album_org").value
	});
}

function albCreate(response) {
	document.getElementById("album_edit").style.display = "none";
	if(response.code === 200) {
		document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(response.source);
		count_checks();
		sendRequest(getSubs);

		let text = rcmail.gettext('alb_create_ok','pictures').replace('%t%', response.target);
		rcmail.display_message(text, 'confirmation');
	} else {
		let text = rcmail.gettext('alb_create_failed','pictures').replace('%t%', response.target);
		rcmail.display_message(text, 'error');
	}
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
	$("#album_org").val(album) - 1 !== document.getElementById("mv_target").innerHTML.indexOf("div") && sendRequest(getSubs);
	document.getElementById("albedit").style.display = "block";
	document.getElementById("albadd").style.display = "none";
	document.getElementById("mv_target").style.display = "block";
	document.getElementById("album_edit").style.display = "block";
	document.getElementById("album_name").focus();

	document.getElementById("dalb").removeEventListener('mouseover', btn_title);
	document.getElementById("mvb").removeEventListener('mouseover', btn_title);
	document.getElementById("rnb").removeEventListener('mouseover', btn_title);
	
	document.getElementById("dalb").addEventListener('mouseover', btn_title);
	document.getElementById("mvb").addEventListener('mouseover', btn_title);
	document.getElementById("rnb").addEventListener('mouseover', btn_title);
}

function btn_title() {
	let title = '';
	switch (this.id) {
		case 'dalb': title = rcmail.gettext('DelAlbum','pictures') + " '" + document.getElementById('album_org').value.split('/').pop() + "'"; break;
		case 'mvb': title = rcmail.gettext('MovAlbum','pictures') + " '" + document.getElementById('album_org').value.split('/').pop() + "' " + rcmail.gettext('to','pictures') + " '" + document.getElementById('target').value + "/" + document.getElementById('album_org').value.split('/').pop() + "'"; break;
		case 'rnb': title = rcmail.gettext('RenAlbum','pictures') + " '" + document.getElementById('album_org').value.split('/').pop() + "' " + rcmail.gettext('to','pictures') + " '" +document.getElementById('album_org').value.split('/').pop() + "'"; break;
		default: break;
	}

	this.title = title;
}

function sendRequest(action, dat = {}) {
	const data = JSON.stringify({
		action: action.name,
		data: dat
	});

	xhr = new XMLHttpRequest();
	xhr.onload = function() {
		action(this.response);		
	}
	xhr.open('POST', './plugins/pictures/photos.php');
	xhr.setRequestHeader('Content-type', 'application/json; charset=utf-8');
	xhr.responseType = 'json';
	xhr.send(data);
}

function getSubs(response) {
	let select = document.createElement('select');
	select.id = 'target';

	response.dirs.forEach(element => {
		let opt = document.createElement('option');
		opt.value = element;
		opt.text = element;
		select.appendChild(opt);
	});

	select.selectedIndex = 0;
	select.options[0].disabled = true;

	//document.getElementById('mv_target').firstChild.replaceWith(select);
	document.getElementById('mv_target_img').firstChild.replaceWith(select);
	setTimeout(document.getElementById('target').addEventListener('change', mvbtncl), 1000);
}

function mvbtncl() {
	document.getElementById('mvb').classList.remove('disabled');
	document.getElementById('album_name').value = document.getElementById('album_org').value.split("/").pop();
	document.getElementById('rnb').classList.add('disabled');

	let mvp = document.getElementById('mvp');
	let nfolder = document.getElementById('album_name_img').value;
	nfolder = (nfolder.length > 0) ? nfolder + '/':nfolder;
	mvp.classList.remove('disabled');
	mvp.title = rcmail.gettext("move", "pictures") + " " + rcmail.gettext("to", "pictures") + ": '" + document.getElementById('target').selectedOptions[0].value + '/' + nfolder + "'";
}

function getshares(response) {
	let url = new URL(document.getElementById("picturescontentframe").contentWindow.document.documentURI);
	let current = url.searchParams.get('p') ? url.searchParams.get('p'):'';

	document.getElementById('suser').value = "";
	document.getElementById('suser').style.borderColor = "#ddd";
	document.getElementById('sbtn').style.display = 'inline-block';
	document.getElementById('btnclp').style.display = 'none';

	let sselect = document.createElement('select');
	sselect.id = 'shares';
	sselect.tabIndex = -1;
	sselect.style.width = "calc(100% - 20px)";

	response.shares.forEach(share => {
		let opt = document.createElement('option');
		opt.value = share.id;
		opt.dataset.ep = share.exp;
		opt.dataset.dn = share.down;
		opt.text = share.name;

		if(share.name === current) {
			opt.selected = true;
			document.getElementById('sid').value = parseInt(share.id);
			document.getElementById('link').value = '';

			if(share.exp) {
				document.getElementById('never').checked = false;
				document.getElementById('expiredate').disabled = false;
				document.getElementById('expiredate').valueAsDate = new Date(share.exp * 1000)
			} else {
				document.getElementById('never').checked = true;
				document.getElementById('expiredate').disabled = true;
				document.getElementById('expiredate').value = '';
			}

			document.getElementById('download').checked = share.down;
		}

		sselect.appendChild(opt);
	})

	sselect.selectedIndex = 0;
	sselect.options[0].disabled = true;
	
	sselect.addEventListener('change', function(name){
		document.getElementById('sname').value = name.target.selectedOptions[0].text;
		document.getElementById('sid').value = parseInt(name.target.selectedOptions[0].value) ? name.target.selectedOptions[0].value:'';
		document.getElementById('link').value = '';
		document.getElementById('rsh').disabled = (parseInt(name.target.selectedOptions[0].value)) ? false:true;

		if(name.target.selectedOptions[0].dataset.dn == undefined || name.target.selectedOptions[0].dataset.dn) {
			document.getElementById('download').checked = true;
		} else {
			document.getElementById('download').checked = false;
		}

		if (name.target.selectedOptions[0].dataset.ep == undefined || name.target.selectedOptions[0].dataset.ep) {
			document.getElementById('never').checked = false;
			document.getElementById('expiredate').disabled = false;
			let someDate = new Date();
			document.getElementById('expiredate').valueAsDate = (name.target.selectedOptions[0].dataset.ep == undefined) ? new Date(someDate.setDate(someDate.getDate() + rcmail.env.sdays)):new Date(name.target.selectedOptions[0].dataset.ep * 1000);
		} else {
			document.getElementById('never').checked = true;
			document.getElementById('expiredate').value = '';
			document.getElementById('expiredate').disabled = true;
		}
	});

	document.getElementById('share_target').firstChild.replaceWith(sselect);
}

function albMove(response) {
	document.getElementById("album_edit").style.display = "none";
	if(response.code === 200) {
		document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(response.target);
		sendRequest(getSubs);
		count_checks();
		let text = rcmail.gettext('alb_move_ok','pictures').replace('%s%', response.source);
		text = text.replace('%t%', response.target);
		rcmail.display_message(text, 'confirmation');
	} else {
		if(response.image !== true) {
			let text = rcmail.gettext('alb_move_image','pictures').replace('%s%', response.source);
			text = text.replace('%t%', response.target);
			rcmail.display_message(text, 'error');
		} else if (response.thumbnail !== true) {
			let text = rcmail.gettext('alb_move_thumb','pictures').replace('%s%', response.target);
			text = text.replace('%t%', response.target);
			rcmail.display_message(text, 'error');
		}
	}
}

function albRename(response) {
	document.getElementById("album_edit").style.display = "none";
	if(response.code === 200) {
		document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(response.old);
		sendRequest(getSubs);
		let text = rcmail.gettext('alb_ren_ok','pictures').replace('%s%', response.old);
		text = text.replace('%t%', response.new);
		rcmail.display_message(text, 'confirmation');
	} else {
		let text = rcmail.gettext('alb_ren_fail','pictures').replace('%s%', response.old);
		text = text.replace('%t%', response.new);
		rcmail.display_message(text, 'error');
	}
}

function rename_album() {
	sendRequest(albRename, {
		target: document.getElementById("album_name").value,
		source: document.getElementById("album_org").value
	});
}

function move_album() {
	sendRequest(albMove, {
		target: document.getElementById("target").value,
		source: document.getElementById("album_org").value
	});
}

function delete_album() {
	var a = document.getElementById("album_org").value;
	if(confirm(rcmail.gettext("galdconfirm", "pictures"))) {
		let dalb = document.getElementById('dalb');
		dloader('#album_edit', dalb, 'add');
		
		sendRequest(albDel, {
			source: document.getElementById("album_org").value
		});
	}
}

function albDel(response) {
	dloader('#album_edit', dalb, 'remove');
	document.getElementById("album_edit").style.display = "none";
	if(response.code === 200) {
		document.getElementById("picturescontentframe").contentWindow.location.href = "plugins/pictures/photos.php";
		sendRequest(getSubs);
		count_checks();

		let text = rcmail.gettext('alb_del_ok','pictures').replace('%s%', response.path);
		text = text.replace('%t%', response.new);
		rcmail.display_message(text, 'confirmation');
	} else {
		let text = rcmail.gettext('alb_del_fail','pictures').replace('%s%', response.path);
		text = text.replace('%t%', response.new);
		rcmail.display_message(text, 'error');
	}
}

function sharepicture() {
	let type = document.querySelector('.tab-active').value.substring(1);
	if(type === 'pixelfed' && document.getElementById('pstatus').value == '') {
		document.getElementById('pstatus').style.borderColor = 'red';
		rcmail.display_message(rcmail.gettext('empty_status','pictures'), 'error')
		return false;
	}
	
	var pictures = [];
	let loader = document.createElement('div');
	loader.className = 'mv_target';
	loader.id = 'sloader';
	loader.style.width = '10em';
	loader.style.height = '10em';
	loader.style.top = '-30em';
	loader.style.zIndex = '2';
	document.getElementById('share_edit').appendChild(loader);
	dloader('#share_edit', sbtn, 'add');
	let link = document.getElementById('link');
	link.style.display = 'none';
	for(e of document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('.icheckbox:checked')) {
		const urlParams = new URL(e.previousElementSibling.firstChild.src).searchParams;
		pictures.push(urlParams.get('file'));
	};

	let text = document.getElementById('pstatus').value.replaceAll('[[{"value":"', '#');
	text = text.replaceAll('","prefix":"#"}]]', '');

	sendRequest(share, {
		images: pictures,
		shareid: document.getElementById('sid').value,
		sharename: document.getElementById('sname').value,
		download: document.getElementById('download').value,
		expiredate:	Math.floor(document.getElementById('expiredate').valueAsNumber / 1000),
		suser: document.getElementById('suser').value,
		uid: document.getElementById('uid').value,
		type: type,
		pf_text: text,
		pf_sens: document.getElementById('pfsensitive').checked,
		pf_vis: document.getElementById('pfvisibility').value,
		pf_max: document.getElementById('max_attachments').value
	});
}

function share(response) {
	let sbtn = document.getElementById('sbtn');
	let clpbtn = document.getElementById('btnclp');
	let link = document.getElementById('link');
	
	if(response.type === 'intern') {
		document.getElementById('expiredate').disabled = false;
		document.getElementById('expiredate').style.color = 'black';
		document.getElementById('never').disabled = false;
		document.getElementById('share_edit').style.display='none';
	}

	if(response.type === 'pixelfed') {
		let text = rcmail.gettext("shared_to", "pictures");
		if(response.pixelfed.uri) rcmail.display_message(text.replace('%type%', document.querySelector('#spixelfed #type').value), 'confirmation');
		document.getElementById('share_edit').style.display='none';
	}

	if(response.type === 'public') {
		const url = new URL(location.href);
		let nurl = url.protocol + '//' + url.hostname + url.pathname + '?_task=pictures&slink=' + response.link;
		$("#link").contents().get(0).nodeValue = nurl;
		clpbtn.addEventListener('click', e => {
			e.preventDefault();
			copyPageUrl(nurl);
			document.getElementById('share_edit').style.display='none';
			return false;
		});
		clpbtn.style.display = "block";
		link.style.visibility = "visible";
		link.style.display = 'block';
		sbtn.style.display = 'none';
		dloader('#share_edit', sbtn, 'remove');
	}
	
	dloader('#share_edit', sbtn, 'remove');
	document.getElementById('sloader').remove();
	rm_checks();
	count_checks();
}

async function copyPageUrl(text) {
	try {
	  await navigator.clipboard.writeText(text);
	} catch (err) {
	  console.error('Failed to copy: ', err);
	}
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

function move_picture() {
	let mvp = document.getElementById('mvp');
	dloader('#img_edit', mvp, 'add');
	let media = [];

	for(e of document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('.icheckbox:checked')) {
		url = new URL(e.parentElement.firstChild.href);
		media.push(url.searchParams.get('file'));
	}

	sendRequest(imgMove, {
		images: media,
		target: document.getElementById("target").selectedOptions[0].value,
		nepath: document.getElementById("album_name_img").value
	});
}

function imgMove(response) {
	if(response.code === 200) {
		document.getElementById("img_edit").style.display = "none";
		document.getElementById("picturescontentframe").contentWindow.location.reload(!0);
		count_checks();
		dloader('#img_edit', document.getElementById('mvp'), 'remove');
	}
}

function mv_img() {
	$("#img_edit").contents().find("h2").html(rcmail.gettext("move_image", "pictures"));
	$("#album_name_img").attr("placeholder", rcmail.gettext("new_album", "pictures"));
	let mvp = document.getElementById('mvp');
	mvp.classList.remove('disabled');

	document.getElementById('album_name_img').addEventListener('input', function() {
		let nfolder = document.getElementById('album_name_img').value;
		nfolder = (nfolder.length > 0) ? nfolder + '/':nfolder;
		mvp.title = rcmail.gettext("move", "pictures") + " " + rcmail.gettext("to", "pictures") + ": '" + document.getElementById('target').selectedOptions[0].value + '/' + nfolder + "'";
	});

	sendRequest(getSubs);

	if(document.getElementById("target")) document.getElementById("target").selectedIndex = 0;
	document.getElementById("album_name_img").value = "";
	document.getElementById("img_edit").style.display = "block";
	count_checks();
}

function delete_picture() {
	var images = [];

	for(e of document.getElementById("picturescontentframe").contentWindow.document.querySelectorAll('.icheckbox:checked')) {
		url = new URL(e.parentElement.firstChild.href);
		images.push(url.searchParams.get('file'));
	}

	if(confirm(rcmail.gettext("picdconfirm", "pictures").replace("%c", images.length))) {
		sendRequest(imgDel, {
			images: images,
		});
	} else {
		return false;
	}

	count_checks();
};

function imgDel(response) {
	if(response.code === 200) {
		document.getElementById("picturescontentframe").contentWindow.location.reload(!0);
		count_checks();
	}
}