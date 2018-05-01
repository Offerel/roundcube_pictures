/**
 * Roundcube Pictures Plugin
 *
 * @version 1.0.0
 * @author Offerel
 * @copyright Copyright (c) 2018, Offerel
 * @license GNU General Public License, version 3
 */
window.rcmail && rcmail.addEventListener('init', function(evt) {
	rcmail.register_command('editalbum', edit_album, true);
	rcmail.register_command('rename_alb', rename_album, true);
	rcmail.register_command('move_alb', move_album, true);
	rcmail.register_command('delete_alb', delete_album, true);
	
	rcmail.register_command('movepicture', mv_img, true);
	rcmail.register_command('move_image', move_picture, true);
	rcmail.register_command('delpicture', delete_picture, true);
});

function edit_album() {
	var queryString = window.frames['picturescontentframe'].location.search.slice(1);
	var album = "";
	
	if (queryString) {
		queryString = queryString.split('#')[0];
		var arr = queryString.split('&');
		for (var i=0; i<arr.length; i++) {
			var a = arr[i].split('=');
		}
		album = a[1];
	}

	album = decodeURIComponent(album.replace(/\+/g, ' '));
	
	var arr_album = album.split("/")	
	var la = arr_album.length - 1;
	
	$('#album_edit').contents().find("h2").html("Album: " + arr_album[arr_album.length - 1]);
	$('#album_name').val(arr_album[arr_album.length - 1]);
	$('#album_org').val(album);
	
	if(document.getElementById('mv_target').innerHTML.indexOf('div') !== -1) {
		getsubs();
	}

	document.getElementById('album_edit').style.display = "block";
}

function getsubs() {
	$.ajax({
			type: "POST"
			,url: "plugins/pictures/photos.php"
			,data: {
				'getsubs': "1"
			}
			,success: function(data){
				$('#mv_target').html(data);
			}
	});
}

function rename_album() {
	var album_org = document.getElementById('album_org').value;
	var album_name = document.getElementById('album_name').value;
	
	$.ajax({
		type: "POST"
		,url: "plugins/pictures/photos.php"
		,data: {
			'alb_action': 'rename',
			'target':	album_name,
			'src': album_org
		}
		,success: function(data){
			if(data == 1) {
				document.getElementById('album_edit').style.display = "none";
				document.getElementById('picturescontentframe').contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(album_org);
				getsubs();
			}
		}
	});
}

function move_album() {
	var album_org = document.getElementById('album_org').value;
	var album_target = document.getElementById('target').value;
	
	$.ajax({
		type: "POST"
		,url: "plugins/pictures/photos.php"
		,data: {
			'alb_action': 'move',
			'target':	album_target,
			'src': album_org
		}
		,success: function(data){
			if(data == 1) {
				document.getElementById('album_edit').style.display = "none";
				document.getElementById('picturescontentframe').contentWindow.location.href = "plugins/pictures/photos.php?p=" + encodeURIComponent(album_target);
				getsubs();
			}
		}
	});
}

function delete_album() {
	console.log("Löschen");
	var album_org = document.getElementById('album_org').value;
	console.log(album_org);
	
	$.ajax({
		type: "POST"
		,url: "plugins/pictures/photos.php"
		,data: {
			'alb_action': 'delete',
			'src': album_org
		}
		,success: function(data){
			if(data == 1) {
				document.getElementById('album_edit').style.display = "none";
				document.getElementById('picturescontentframe').contentWindow.location.href = "plugins/pictures/photos.php";
				getsubs();
			}
		}
	});
}

function move_picture() {
	var selected = new Array();
	var org_path = document.getElementById('album_org_img').value;
	var new_path = document.getElementById('album_name_img').value;
	var album_target = document.querySelector('#mv_target_img #target').value;

	$("#picturescontentframe").contents().find(':checkbox:checked').each(function(){
		selected.push($(this).val());
	});
	
	$.ajax({
		type: "POST"
		,url: "plugins/pictures/photos.php"
		,data: {
			'img_action': 'move',
			'images': selected,
			'orgPath': org_path,
			'target': album_target,
			'newPath': new_path
		}
		,success: function(data){
			if(data == 1) {
				document.getElementById('img_edit').style.display = "none";
				document.getElementById('picturescontentframe').contentWindow.location.reload(true);
			}
		}
	});
}

function mv_img() {
	var queryString = window.frames['picturescontentframe'].location.search.slice(1);
	var album = "";
	
	if (queryString) {
		queryString = queryString.split('#')[0];
		var arr = queryString.split('&');
		for (var i=0; i<arr.length; i++) {
			var a = arr[i].split('=');
			if (a[0] == 'p')
				break;
		}
		album = a[1];
	}
	album = decodeURI(album);
	
	$('#img_edit').contents().find("h2").html(rcmail.gettext('move_image', 'pictures'));
	$('#album_name_img').attr("placeholder", rcmail.gettext('new_album', 'pictures'));
	$('#album_org_img').val(album);
	
	if(document.getElementById('mv_target_img').innerHTML.indexOf('div') !== -1) {
		$.ajax({
			type: "POST"
			,url: "plugins/pictures/photos.php"
			,data: {
				'getsubs': "1"
			}
			,success: function(data){
				$('#mv_target_img').html(data);
			}
		});
	}

	document.getElementById('img_edit').style.display = "block";
}

function delete_picture() {
	var selected = new Array();	
	var queryString = window.frames['picturescontentframe'].location.search.slice(1);
	var album = "";
	
	if (queryString) {
		queryString = queryString.split('#')[0];
		var arr = queryString.split('&');
		for (var i=0; i<arr.length; i++) {
			var a = arr[i].split('=');
			if (a[0] == 'p')
				break;
		}
		album = a[1];
	}
	album = decodeURI(album);
	
	$("#picturescontentframe").contents().find(':checkbox:checked').each(function(){
		selected.push($(this).val());
	});

	$.ajax({
		type: "POST"
		,url: "plugins/pictures/photos.php"
		,data: {
			'img_action': 'delete',
			'images': selected,
			'orgPath': album
		}
		,success: function(data){
			if(data == 1) {
				document.getElementById('picturescontentframe').contentWindow.location.reload(true);
			}
		}
	});
}