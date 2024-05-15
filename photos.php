<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.17
 * @author Offerel
 * @copyright Copyright (c) 2024, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_path = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$thumb_path = str_replace("%u", $username, $rcmail->config->get('thumb_path', false));
	$webp_path = str_replace("%u", $username, $rcmail->config->get('webp_path', false));
	$thumbsize = $rcmail->config->get('thumb_size', false);
	
	if(substr($pictures_path, -1) != '/') {
		error_log('Pictures: check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if(substr($thumb_path, -1) != '/') {
		error_log('Picturesv check $config[\'thumb_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_path)) {
		if(!mkdir($pictures_path, 0755, true)) {
			error_log('Pictures: Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
} else {
	http_response_code(403);
	die('Login failed. User is not logged in.');
}

$page_navigation = "";
$thumbnails = "";
$new = "";
$images = "";
$exif_data = "";
$messages = "";
$comment = "";
$requestedDir = null;
$label_max_length = $rcmail->config->get('label_max_length', false);
$skip_objects = $rcmail->config->get('skip_objects', false);
$hevc = $rcmail->config->get('convert_hevc', false);
$ccmd = $rcmail->config->get('convert_cmd', false);
$ffprobe = exec("which ffprobe");

if(isset($_POST['getsubs'])) {
	$subdirs = getAllSubDirectories($pictures_path);
	$select = "<select name='target' id='target'><option selected='true' disabled='disabled'>".$rcmail->gettext('selalb','pictures')."</option>";
	foreach ($subdirs as $dir) {
		$dir = trim(substr($dir,strlen($pictures_path)),'/');
		if(!strposa($dir, $skip_objects))
			$select.= "<option>$dir</option>";
	}
	$select.="</select>";
	die($select);
}

if(isset($_POST['getshares'])) {
	$shares = getExistingShares();
	$select = "<select id='shares' style='width: calc(100% - 20px);'><option selected='true'>".$rcmail->gettext('selshr','pictures')."</option>";
	foreach ($shares as $share) {
		$name = $share['share_name'];
		$id = $share['share_id'];
		$expd = $share['expire_date'];
		$select.= "<option value='$id' data-ep='$expd'>$name</option>";
	}
	$select.="</select>";
	die($select);
}

if(isset($_FILES['galleryfiles'])) {
	$files = $_FILES['galleryfiles'];
	$folder = $_POST['folder'];
	$aAllowedMimeTypes = [ 'image/jpeg', 'video/mp4' ];

	$cFiles = count($_FILES['galleryfiles']['name']);

	for($i = 0; $i < $cFiles; $i++) {
		$fname = $_FILES['galleryfiles']['name'][$i];
		if($_FILES['galleryfiles']['size'][$i] > 0 && in_array($_FILES['galleryfiles']['type'][$i], $aAllowedMimeTypes) && !file_exists($pictures_path.$folder."/$fname")) {
			if(move_uploaded_file($_FILES['galleryfiles']['tmp_name'][$i], "$pictures_path$folder/$fname")) {
				if($fname == 'folder.jpg') {
					rsfolderjpg("$pictures_path$folder/$fname");
				} else {
					$exif = createthumb("$pictures_path$folder/$fname", $pictures_path);
					todb("$pictures_path$folder/".$fname, $rcmail->user->ID, $pictures_path, $exif);
				}
				
				$test[] = array('message' => 'Upload successful.', 'type' => 'info');
			} else {
				error_log("Pictures: Uploaded picture could not moved into target folder");
				$test[] = array('message' => 'Upload failed. Permission error', 'type' => 'error');
			}
		} else {
			error_log("Pictures: Uploaded picture internal error (size, mimetype, already existing");
			$test[] = array('message' => 'Upload failed. Internal Error', 'type' => 'error');
		}
	}
	die(json_encode($test));
}

if(isset($_POST['alb_action'])) {
	$action = $_POST['alb_action'];	
	$src = rtrim($pictures_path,'/').'/'.filter_var($_POST['src'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	$target = urldecode($src.'/'.filter_var($_POST['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
	$mtarget = $pictures_path.$_POST['target'];
	$oldpath = str_replace($pictures_path,'',$src);
	$newPath = str_replace($pictures_path,'',$target);
	$nnewPath = str_replace($pictures_path,'',$mtarget);

	switch($action) {
		case 'move':	mvdb("$oldpath | $nnewPath"); die(rename($src, $mtarget)); break;
		case 'rename':	mvdb($oldpath, $newPath); die(rename($src, $target)); break;
		case 'delete':	die(removeDirectory($src, $rcmail->user->ID)); break;
		case 'create':
			if (!@mkdir($target, 0755, true)) {
				$error = error_get_last();
				error_log("Pictures: ".$error['message'].$target);
				die($error['message']);
			} else {
				die(1);
			}
			break;
	}
	die();
}

if(isset($_POST['img_action'])) {
	global $rcmail, $pictures_path;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;
	$action = $_POST['img_action'];
	$images = $_POST['images'];
	$org_path = isset($_POST['orgPath']) ? urldecode($_POST['orgPath']):'';
	$album_target = isset($_POST['target']) ? html_entity_decode(trim(filter_var($_POST['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS),'/')):"";

	switch($action) {
		case 'move':	$newPath = (isset($_POST['newPath']) && $_POST['newPath'] != "") ? filter_var($_POST['newPath'], FILTER_SANITIZE_FULL_SPECIAL_CHARS):"";
						if (!is_dir($pictures_path.$album_target.$newPath)) mkdir($pictures_path.$album_target.'/'.$newPath, 0755, true);

						foreach($images as $image) {
							mvimg($pictures_path.$org_path.'/'.$image, $pictures_path.$album_target.'/'.$newPath.'/'.$image);
							mvdb($org_path.'/'.$image, $album_target.'/'.$newPath.$image);
						}
						die(true);
						break;
		case 'delete':	foreach($images as $image) {
							delimg($pictures_path.$org_path.'/'.$image);
							rmdb($org_path.'/'.$image, $user_id);
						}
						die(true);
						break;
		case 'share':	$shareid = filter_var($_POST['shareid'], FILTER_SANITIZE_NUMBER_INT);
						$cdate = date("Y-m-d");
						$sharename = (empty($_POST['sharename'])) ? "Unkown-$cdate": filter_var($_POST['sharename'], FILTER_UNSAFE_RAW);
						$sharelink = bin2hex(random_bytes(25));
						$edate = filter_var($_POST['expiredate'], FILTER_SANITIZE_NUMBER_INT);
						$expiredate = ($edate > 0) ? $edate:"NULL";

						if(empty($shareid)) {
							$query = "INSERT INTO `pic_shares` (`share_name`,`share_link`,`expire_date`,`user_id`) VALUES ('$sharename','$sharelink',$expiredate,$user_id)";
							$ret = $dbh->query($query);
							$shareid = ($ret === false) ? "":$dbh->insert_id("pic_shares");
						} else {
							$query = "UPDATE `pic_shares` SET `share_name`= '$sharename', `expire_date` = $expiredate WHERE `share_id`= $shareid AND `user_id` = $user_id";
							$ret = $dbh->query($query);
						}

						foreach($images as $image) {
							$query = "SELECT `pic_id` FROM `pic_pictures` WHERE `pic_path` = '$image' AND `user_id` = $user_id";
							$ret = $dbh->query($query);
							$pic_id = $dbh->fetch_assoc()['pic_id'];
							$query = "INSERT INTO `pic_shared_pictures` (`share_id`,`user_id`,`pic_id`) VALUES ('$shareid',$user_id,$pic_id)";
							$ret = $dbh->query($query);
						}

						$query = "SELECT `share_link` FROM `pic_shares` WHERE `share_id` = $shareid";
						$dbh->query($query);
						$sharelink = $dbh->fetch_assoc()['share_link'];
						die($sharelink);
						break;
		case 'dshare':	$share = filter_var($_POST['share'], FILTER_SANITIZE_NUMBER_INT);
						$query = "DELETE FROM `pic_shares` WHERE `share_id` = $share AND `user_id` = $user_id";
						$ret = $dbh->query($query);
						die("0");
						break;
	}
	die();
}

if( isset($_GET['g']) ) {
	$dir = $_POST['g'];
	$offset = filter_var($_POST['s'], FILTER_SANITIZE_NUMBER_INT);
	$thumbnails = showGallery($dir, $offset);
	die($thumbnails);
}

function removeDirectory($path, $user) {
	$files = glob($path . '/*');
	foreach ($files as $file) {
		is_dir($file) ? removeDirectory($file, $user):unlink($file);
		rmdb($file, $user);
	}
	rmdir($path);
	return true;
}

if( isset($_GET['p']) ) {
	$dir = html_entity_decode(urldecode(filter_var($_GET['p'],FILTER_SANITIZE_FULL_SPECIAL_CHARS)));
	guardAgainstDirectoryTraversal($dir);
	echo showPage(showGallery($dir), $dir);
	die();
} else {
	echo showPage(showGallery(""), '');
	die();
}

function rsfolderjpg($filename) {
	global $thumbsize;
	list($owidth, $oheight) = getimagesize($filename);
	if($owidth > $oheight) {
		$new_height = $thumbsize;
		$factor = $oheight/$thumbsize;
		$new_width = round($owidth/$factor);
	} else {
		$new_width = $thumbsize;
		$factor = $owidth/$thumbsize;
		$new_height = round($oheight/$factor);
	}

	$image = imagecreatefromjpeg($filename);
	$image_p = imagecreatetruecolor($new_width, $new_height);
	imagecopyresampled($image_p, $image, 0, 0, 0, 0, $new_width, $new_height, $owidth, $oheight);
	imagejpeg($image_p, $filename, 100);
}

function showPage($thumbnails, $dir) {
	$rcmail = rcmail::get_instance();
	$theme = $rcmail->config->get('ptheme');
	$pmargins = $rcmail->config->get('pmargins');
	$dir = ltrim(rawurldecode($dir), '/');
	$gal = ltrim($dir, '/');
	$maxfiles = ini_get("max_file_uploads");
	$page = "<!DOCTYPE html>
	<html>
		<head>
			<title>$gal</title>
			<link rel=\"stylesheet\" href=\"js/justifiedGallery/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel='stylesheet' href='skins/main.min.css' type='text/css' />
			<link rel='stylesheet' href='skins/pth_$theme.css' type='text/css' />
			<link rel='stylesheet' href='js/glightbox/glightbox.min.css' type='text/css' />
			<link rel='stylesheet' href='js/plyr/plyr.css' type='text/css' />
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/justifiedGallery/jquery.justifiedGallery.min.js\"></script>
			<script src='js/glightbox/glightbox.min.js'></script>
			<script src='js/plyr/plyr.js'></script>
			";
	$aarr = explode('/',$dir);
	$path = "";
	$albumnav = "<li><a href='?p='>Start</a></li>";
	foreach ($aarr as $folder) {
		$path = $path.'/'.$folder;
		if(strlen($folder) > 0) $albumnav.= "<li><a href='?p=$path'>$folder</a></li>";
	}
	$page.= "</head>
	\t\t<body class='picbdy' onload='count_checks();'>
	\t\t\t<div id='loader' class='lbg'><div class='db-spinner'></div></div>
	<!-- \t\t\t<div id='header' style='position: absolute; top: -8px;'> -->
	\t\t\t<div id='header'>
	\t\t\t\t<ul class='breadcrumb'>
	\t\t\t\t\t$albumnav
	\t\t\t\t</ul>
	\t\t\t</div>
	\t\t\t<div id=\"galdiv\">";
	$page.= $thumbnails;
	$page.="
	<script>
		document.onreadystatechange = function() {
			let ntitle = '$gal';
			let btitle = ntitle.split('/');
			let ttitle = (ntitle.length > 0) ? 'Fotos - ' + btitle[btitle.length - 1 ]:'Fotos';
			window.parent.document.title = ttitle;
			if (document.readyState !== 'complete') {
				aLoader('hidden');
			}
			
			let headerN = document.querySelector('#header .breadcrumb');
			
			headerN.lastElementChild.addEventListener('click', function(e){
				if(headerN.childElementCount > 1) {
					e.preventDefault();
					window.parent.edit_album();
				}
			});

			Array.from(document.getElementsByClassName('folder')).forEach(
				function(e,i,a) { e.addEventListener('click', function() {aLoader()}) }
			);
		}

		$('#folders').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: $pmargins,
			border: 0,
			rel: 'folders',
			lastRow: 'justify',
			captions: false,
			randomize: false,
		});
	
		$('#images').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: $pmargins,
			border: 0,
			rel: 'gallery',
			lastRow: 'nojustify',
			captions: false,
			randomize: false,
		});
		
		$(window).scroll(function() {
			lazyload();
		});
		
		lightbox = GLightbox({
			plyr: {
				config: {
					
					muted: true,
				}
			},
			autoplayVideos: false,
			loop: false,
			closeOnOutsideClick: false
		});

		lightbox.on('slide_changed', (data) => {
			let file = new URL(data.current.slideConfig.href).searchParams.get('file').split('/').slice(-1)[0];
			if(document.getElementById(file)) {
				if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
				if(document.getElementById('fbtn')) document.getElementById('fbtn').remove();
				let closebtn = document.querySelector('.gclose');
				let infobtn = document.createElement('button');
				let fbtn = document.createElement('button');
				infobtn.id = 'infbtn';
				fbtn.id = 'fbtn';
				fbtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 14 14\"><path fill=\"#fff\" fill-rule=\"evenodd\" d=\"M2 9H0v5h5v-2H2V9ZM0 5h2V2h3V0H0v5Zm12 7H9v2h5V9h-2v3ZM9 0v2h3v3h2V0H9Z\"/></svg>';
				infobtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.0\" viewBox=\"0 0 160 160\"><g fill=\"white\"><path d=\"M80 15c-35.88 0-65 29.12-65 65s29.12 65 65 65 65-29.12 65-65-29.12-65-65-65zm0 10c30.36 0 55 24.64 55 55s-24.64 55-55 55-55-24.64-55-55 24.64-55 55-55z\"/><path d=\"M89.998 51.25a11.25 11.25 0 1 1-22.5 0 11.25 11.25 0 1 1 22.5 0zm.667 59.71c-.069 2.73 1.211 3.5 4.327 3.82l5.008.1V120H60.927v-5.12l5.503-.1c3.291-.1 4.082-1.38 4.327-3.82V80.147c.035-4.879-6.296-4.113-10.757-3.968v-5.074L90.665 70\"/></g></svg>';				
				infobtn.addEventListener('mouseover', function() {
					document.getElementById(file).classList.add('eshow');
					document.getElementById(file).addEventListener('mouseover', function() {document.getElementById(file).classList.add('eshow')})
				});
				infobtn.addEventListener('mouseout', function() {
					document.getElementById(file).classList.remove('eshow');
					document.getElementById(file).addEventListener('mouseout', function() {document.getElementById(file).classList.remove('eshow')})
				});
				fbtn.addEventListener('click', e => {
					if(document.fullscreenElement){ 
						document.exitFullscreen() 
					} else { 
						document.getElementById('glightbox-body').requestFullscreen();
					}
				});

				closebtn.before(infobtn);
				closebtn.before(fbtn);
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
		
		checkboxes();

		function aLoader(mode = 'visible') {
			document.getElementById('loader').style.visibility = mode;
		}

		function lazyload(slide = false) {
			if(document.getElementById('last') && !slide) return false;
			if(document.getElementsByClassName('glightbox').length <= 0 && !slide) return false;

			let wheight = $(document).height() - 10;
			let wposition = Math.ceil($(window).scrollTop() + $(window).height());

			if(wposition > wheight || slide) {
				$.ajax({
					type: 'POST',
					url: 'photos.php?g=1',
					async: false,
					beforeSend: aLoader('visible'),
					data: {
						g: '$gal',
						s: $('.glightbox').length
					},
					success: function(response) {
						aLoader('hidden');
						$('#images').append(response);
						$('#images').justifiedGallery('norewind');
						const html = new DOMParser().parseFromString(response, 'text/html');
						html.body.childNodes.forEach(element => {		
						if(element.children !== undefined && element.children.count > 0 && element.children[0].classList.contains('glightbox')) {
								lightbox.insertSlide({
									'href': element.children[0].href,
									'type': element.children[0].dataset.type
								});
						}
							
						});
						lightbox.reload();
						checkboxes();
						return false;
					}
				});
			}
		}

		function checkboxes() {
			var chkboxes = $('.icheckbox');
			var lastChecked = null;
			chkboxes.click(function(e) {
				if(!lastChecked) {
					lastChecked = this;
					return;
				}
	
				if(e.shiftKey) {
					var start = chkboxes.index(this);
					var end = chkboxes.index(lastChecked);
					chkboxes.slice(Math.min(start,end), Math.max(start,end)+ 1).prop('checked', lastChecked.checked);
	
				}
				lastChecked = this;
			});
		}
		
		function count_checks() {
			if(document.querySelectorAll('input[type=\"checkbox\"]:checked').length > 0) {
				window.parent.document.getElementById('movepicture').classList.remove('disabled');
				window.parent.document.getElementById('delpicture').classList.remove('disabled');
				window.parent.document.getElementById('sharepicture').classList.remove('disabled');
			} else {
				window.parent.document.getElementById('movepicture').classList.add('disabled');
				window.parent.document.getElementById('delpicture').classList.add('disabled');
				window.parent.document.getElementById('sharepicture').classList.add('disabled');
			}
		}
		
		var dropZones = document.getElementsByClassName('dropzone');
		
		for (var i = 0; i < dropZones.length; i++) {
			dropZones[i].addEventListener('dragover', handleDragOver, false);
			dropZones[i].addEventListener('dragleave', handleDragLeave, false);
			dropZones[i].addEventListener('drop', handleDrop, false);
		}
		
		function handleDragOver(event){
			event.preventDefault();
			if(this.localName != 'body') {
				this.classList.add('mmn-drop');
			}
		}
		
		function handleDragLeave(event){
			event.preventDefault();
			this.classList.remove('mmn-drop');
		}
		
		function handleDrop(event){
			event.stopPropagation();
			event.preventDefault();
			this.classList.remove('mmn-drop');
			var url_parameters = this.parentElement.href.split('?')[1];
			var params_arr = url_parameters.split('&');
			var folder = '';
			for (var i = 0; i < params_arr.length; i++) {
				var tmparr = params_arr[i].split('=');
				if(tmparr[0]=='p') {
					folder = tmparr[1];
					break;
				}
			}
			startUpload(event.dataTransfer.files, folder);
		}
		
		function startUpload(files, folder) {
			var formdata = new FormData();
			xhr = new XMLHttpRequest();
			var maxfiles = $maxfiles;
			var mimeTypes = ['image/jpeg', 'video/mp4'];
			folder = decodeURIComponent((folder + '').replace(/\+/g, '%20'));
			var progressBar = document.getElementById('' + folder + '').getElementsByClassName('progress')[0];
			if (files.length > maxfiles) {
				console.log('You try to upload more than the max count of allowed files(' + maxfiles + ')');
				return false;
			} else {
				for (var i = 0; i < files.length; i++) {
					if (mimeTypes.indexOf(files.item(i).type) == -1) {
						console.log('Unsupported filetype(' + files.item(i).name + '), exiting');
						return false;
					} else {
						formdata.append('galleryfiles[]', files.item(i), files.item(i).name);
						formdata.append('folder',folder);
					}
				}
				
				xhr.upload.addEventListener('progress', function(event) {
					var percentComplete = Math.ceil(event.loaded / event.total * 100);
					progressBar.style.width = percentComplete + '%';
					progressBar.style.visibility = 'visible';
					progressBar.firstChild.innerHTML = percentComplete + '%';
				});

				xhr.onload = function() {
					if (xhr.status === 200) {
						data = JSON.parse(xhr.responseText);
						var message = '';
						for (var i = 0; i < data.length; i++) {
							
							if(data[i].type == 'error') {
								progressBar.style.background = 'red';
								console.log(data[i].message);
								message+='<span style=\'text-transform: capitalize; color: red;\'>' + data[i].type + ':&nbsp;</span><span style=\'color: red;\'>' + data[i].message + '</span></br>';
								window.parent.document.getElementById('info').style.display = 'block';
								return false;
							}
							if(data[i].type == 'warning') {
								progressBar.style.background = 'orange';
								message+='<span style=\'text-transform: capitalize; color: orange;\'>' + data[i].type + ':&nbsp;</span><span style=\'color: orange;\'>' + data[i].message + '</span></br>';
								window.parent.document.getElementById('info').style.display = 'block';
								console.log(data[i].message);
							}
							else {
								message+='<span style=\'text-transform: capitalize; color: green;\'>' + data[i].type + ':&nbsp;</span><span style=\'color: green;\'>' + data[i].message + '</span></br>';
								progressBar.style.background = 'green';
								console.log(data[i].message);
							}
								
						}
						window.parent.document.getElementById('mheader').innerHTML = 'Upload Messages';
						window.parent.document.getElementById('modal-body').classList.add('modal-body-text');
						window.parent.document.getElementById('modal-body').innerHTML = message;
					}
				}
				
				xhr.open('POST', 'photos.php');
				xhr.send(formdata);
			}
		}
	</script>
	";

	$page.= "</div></body></html>";
	return $page;
}

function showGallery($requestedDir, $offset = 0) {
	$ballowed = ['jpg','jpeg','mp4'];
	$files = array();
	$hidden_vid = "";
	$pnavigation = "";
	
	global $pictures_path, $rcmail, $label_max_length;
	$dbh = rcmail_utils::db();
	$requestedDir = ltrim(rawurldecode($requestedDir), '/');
	$thumbdir = $pictures_path.$requestedDir;
	$current_dir = $thumbdir;
	$forbidden = $rcmail->config->get('skip_objects', false);
	
	if (is_dir($current_dir) && $handle = @opendir($current_dir)) {
		$query = "SELECT * FROM `pic_pictures` WHERE `pic_path` LIKE \"$requestedDir%\" AND user_id = ".$rcmail->user->ID;
		$result = $dbh->query($query);
		$rows = $dbh->num_rows();
		$pdata = [];
		for ($i = 0; $i < $rows; $i++) {
			array_push($pdata, $dbh->fetch_assoc());
		}
		while (false !== ($file = readdir($handle))) {
			if(!in_array($file, $forbidden)) {
			// Gallery folders
			if (is_dir($current_dir."/".$file)) {
				if ($file != "." && $file != "..") {
					checkpermissions($current_dir."/".$file);
					$requestedDir = trim($requestedDir,"/");
					$npath = trim($requestedDir.'/'.$file,'/');
					$arr_params = array('p' => $npath);
					$fparams = http_build_query($arr_params,'','&amp;');
					
					if (file_exists($current_dir.'/'.$file.'/folder.jpg')) {
						$imgUrl = "simg.php?file=".urlencode($requestedDir.'/'.$file."/folder.jpg");
					} else {
						unset($firstimage);					
						$firstimage = getfirstImage("$current_dir/".$file);
						
						if ($firstimage != "") {
							$params = array('file' 	=> "$requestedDir/$file/$firstimage", 't' => 1);
							$imgParams = http_build_query($params);
							$imgUrl = "simg.php?$imgParams";
						} else {
							$imgUrl = "images/defaultimage.jpg";
						}
					}
					
					$dirs[] = array("name" => $file,
								"date" => filemtime($current_dir."/".$file),
								"html" => "\n\t\t\t\t\t\t<a id='".trim("$requestedDir/$file", '/')."' class='folder' href='photos.php?$fparams' title='$file'><img src='$imgUrl' alt='$file' /><span class='dropzone'>$file</span><div class='progress'><div class='progressbar'></div></div></a>"
								);
				}
			}
			
			// Gallery images
			$allowed = (in_array(strtolower(substr($file, strrpos($file,".")+1)), $ballowed)) ? true:false;
			$fullpath = $current_dir."/".$file;
			$fs = filesize($fullpath);
			
			if ($file != "." && $file != ".." && $file != "folder.jpg" && $allowed && $fs > 0 && strpos($file, '.') !== 0) {
				$filename_caption = "";
				$requestedDir = trim($requestedDir,'/').'/';
				$linkUrl = "simg.php?file=".rawurlencode("$requestedDir/$file");
				$dbpath = str_replace($pictures_path, '', $fullpath);
				$key = array_search("$requestedDir$file", array_column($pdata, 'pic_path'));
				$exifReaden = ($rcmail->config->get('display_exif', false) == 1 && preg_match("/.jpg$|.jpeg$/i", $file) && isset($pdata[$key]['pic_EXIF'])) ? json_decode($pdata[$key]['pic_EXIF']):NULL;
				$taken = $pdata[$key]['pic_taken'];

				if (preg_match("/.jpeg$|.jpg$|.gif$|.png$/i", $file)) {
					checkpermissions($current_dir."/".$file);
					$imgParams = http_build_query(array('file' => "$requestedDir$file", 't' => 1));
					$imgUrl = "simg.php?$imgParams";

					$exifInfo = "";
					if(is_array($exifReaden)) {
						if($exifReaden[0] != "-" && $exifReaden[8] != "-")
							$exifInfo.= $rcmail->gettext('exif_camera','pictures').": ".$exifReaden[8]." - ".$exifReaden[0]."<br>";
						if($exifReaden[1] != "-")
							$exifInfo.= $rcmail->gettext('exif_focalength','pictures').": ".$exifReaden[1]."<br>";
						if($exifReaden[3] != "-")
							$exifInfo.= $rcmail->gettext('exif_fstop','pictures').": ".$exifReaden[3]."<br>";
						if($exifReaden[4] != "-")
							$exifInfo.= $rcmail->gettext('exif_ISO','pictures').": ".$exifReaden[4]."<br>";
						if($exifReaden[5] != "-") {
							$dformat = $rcmail->config->get('date_format', '')." ".$rcmail->config->get('time_format', '');
							$exifInfo.= $rcmail->gettext('exif_date','pictures').": ".date($dformat, $exifReaden[5])."<br>";
						}
						if($exifReaden[6] != "-")
							$exifInfo.= $rcmail->gettext('exif_desc','pictures').": ".$exifReaden[6]."<br>";
						if($exifReaden[9] != "-")
							$exifInfo.= $rcmail->gettext('exif_sw','pictures').": ".$exifReaden[9]."<br>";
						if($exifReaden[10] != "-")
							$exifInfo.= $rcmail->gettext('exif_expos','pictures').": ".$exifReaden[10]."<br>";
						if($exifReaden[11] != "-")
							$exifInfo.= $rcmail->gettext('exif_flash','pictures').": ".$exifReaden[11]."<br>";
						if($exifReaden[12] != "-")
							$exifInfo.= $rcmail->gettext('exif_meter','pictures').": ".$exifReaden[12]."<br>";
						if($exifReaden[13] != "-")
							$exifInfo.= $rcmail->gettext('exif_whiteb','pictures').": ".$exifReaden[13]."<br>";
						if($exifReaden[14] != "-" && $exifReaden[15] != "-") {
							$osm_params = http_build_query(array(	'mlat' => str_replace(',','.',$exifReaden[14]),
																	'mlon' => str_replace(',','.',$exifReaden[15])
																),'','&amp;');
							$exifInfo.= "<a href='https://www.openstreetmap.org/?".$osm_params."' target='_blank'><img src='images/marker.png'>".$rcmail->gettext('exif_geo','pictures')."</a>";
						}
					}
					
					if(is_array($exifReaden) && count($exifReaden) > 0) {
						$caption = "<div id='$file' class='exinfo'>$exifInfo</div>";
					} else {
						$caption = "";
					}
					
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "\t\t\t\t\t\t<div><a class=\"image glightbox\" href='$linkUrl' data-type='image'><img src=\"$imgUrl\" alt=\"$file\" /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\">$caption</div>"
					);
				}
				
				// video files
				if (preg_match("/\.mp4$|\.mpg$|\.mpeg$|\.mov$|\.avi$|\.wmv$|\.flv$|\.webm$/i", $file)) {
					$thmbParams = http_build_query(array('file' => "$requestedDir/$file", 't' => 1));
					$thmbUrl = "simg.php?$thmbParams";
					$videos[] = array("html" => "<div style=\"display: none;\" id=\"".pathinfo($file)['filename']."\"><video class=\"lg-video-object lg-html5\" controls preload=\"none\"><source src=\"$linkUrl\" type=\"video/mp4\"></video></div>");
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "\t\t\t\t\t\t<div><a class=\"video glightbox\" href='$linkUrl' data-type='video'><img src=\"$thmbUrl\" alt=\"$file\" /><span class='video'></span></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>"
					);
				}
			}
			}
		}
		closedir($handle);
	} else {
		error_log('Pictures: Could not open "'.htmlspecialchars(stripslashes($current_dir)).'" folder for reading!');
		die("ERROR: Please check server error log");
	}

	$thumbnails = "";
	$start = 0;

	// sort folders
	if (isset($dirs) && sizeof($dirs) > 0) {
		$thumbnails.= "\n\t\t\t\t\t<div id='folders'>";
		array_walk($dirs, function (&$row) {
			$row['name'] = $row['name'] ?? null;
		});
		$keys = array_column($dirs, 'name');
		array_multisort($keys, SORT_ASC, $dirs);
		foreach ($dirs as $folder) {
			$thumbnails.= $folder["html"];
			$start++;
		}
		$thumbnails.= "\n\t\t\t\t\t</div>";
	}

	// sort images
	$thumbnails.= "\n\t\t\t\t\t<div id='images' class='justified-gallery'>";
	$thumbnails2 = "";
	$offset_end = 0;

	if (sizeof($files) > 0) {
		array_walk($files, function (&$row) {
			$row['date'] = $row['date'] ?? null;
		});
		$keys = array_column($files, 'date');
		array_multisort($keys, SORT_ASC, $files);
		
		$offset_end = $offset + $rcmail->config->get("thumbs_pr_page", false);
		$offset_end = (count($files) < $offset_end) ? count($files):$offset_end;
		
		for ($y = $offset; $y < $offset_end; $y++) {
			$thumbnails2.= "\n".$files[$y]["html"];
		}

		if(isset($videos) && sizeof($videos) > 0){
			foreach($videos as $video) {
				$hidden_vid.= $video["html"];
			}
		}
	}
	$thumbnails.= $thumbnails2;
	$thumbnails.= "\n\t\t\t\t\t</div>";
	$thumbnails.= $hidden_vid;

	if($offset_end == count($files)) $thumbnails2.= "<span id='last'></span>";

	if($offset > 0) {
		die($thumbnails2);
	} else {
		return $thumbnails;
	}
}

function getAllSubDirectories($directory, $directory_seperator = "/") {
	global $rcmail;
	$dirs = array_map(function($item)use($directory_seperator){return $item.$directory_seperator;},array_filter(glob($directory.'*' ),'is_dir'));
	foreach($dirs AS $dir) {
		$dirs = array_merge($dirs,getAllSubDirectories($dir,$directory_seperator) );
	}
	asort($dirs);
	return $dirs;
}

function getExistingShares() {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;
	$query = "SELECT `share_id`, `share_name`, `expire_date` FROM `pic_shares` WHERE `user_id` = 1 ORDER BY `share_name` ASC";
	$erg = $dbh->query($query);
	$rowc = $dbh->num_rows();
	$shares = [];
	for ($i = 0; $i < $rowc; $i++) {
		array_push($shares, $dbh->fetch_assoc());
	}
	return $shares;
}

if (!function_exists('exif_read_data') && $rcmail->config->get('display_exif', false) == 1) {
	error_log('Pictures: PHP EXIF is not available. Set display_exif = 0; in config to remove this message');
}

function strposa($haystack, $needle, $offset=0) {
	if(!is_array($needle)) $needle = array($needle);
	foreach($needle as $query) {
		if(strpos($haystack, $query, $offset) !== false) return true;
	}
	return false;
}

function getfirstImage($dirname) {
	$imageName = false;
	$extensions = array("jpg", "png", "jpeg", "gif");
	if ($handle = opendir($dirname)) {
		while (false !== ($file = readdir($handle))) {
			if ($file[0] == '.') {
				continue;
			}
			$pathinfo = pathinfo($file);
			if (empty($pathinfo['extension'])) {
				continue;
			}
			$ext = strtolower($pathinfo['extension']);

			if(filesize("$dirname/$file") <= 0) {
				continue;
			}

			if (in_array($ext, $extensions)) {
				$imageName = $file;
				break;
			}
		}
		closedir($handle);
	}
	return $imageName;
}

function parse_fraction($v, $round = 0) {
	list($x, $y) = array_map('intval', explode('/', $v));
	if (empty($x) || empty($y)) {
		return $v;
	}
	if ($x % $y == 0) {
		return $x / $y;
	}
	if ($y % $x == 0) {
		return "1/" . $y / $x;
	}
	return round($x / $y, $round);
}

function readEXIF($file) {
	global $rcmail;
	$exif_arr = array();
	$exif_data = @exif_read_data($file);

	if($exif_data && count($exif_data) > 0) {
		$exif_arr[0] = (isset($exif_data['Model'])) ? $exif_data['Model']:"-";
		$exif_arr[1] = (isset($exif_data['FocalLength'])) ? parse_fraction($exif_data['FocalLength']) . "mm":"-";
		$exif_arr[2] = (isset($exif_data['FocalLength'])) ? parse_fraction($exif_data['FocalLength'], 2) . "s":"-";
		$exif_arr[3] = (isset($exif_data['FNumber'])) ? "f" . parse_fraction($exif_data['FNumber']):"-";
		$exif_arr[4] = (isset($exif_data['ISOSpeedRatings'])) ? $exif_data['ISOSpeedRatings']:"-";
		$exif_arr[5] = (isset($exif_data['DateTimeDigitized'])) ? strtotime($exif_data['DateTimeDigitized']):filemtime($file);
		$exif_arr[6] = (isset($exif_data['ImageDescription'])) ? $exif_data['ImageDescription']:"-";
		$exif_arr[7] = (isset($exif_data['CALC-GPSLATITUDE-SIG'])) ? $exif_data['CALC-GPSLATITUDE-SIG']:"-";
		$exif_arr[8] = (isset($exif_data['Make'])) ? $exif_data['Make']:"-";
		$exif_arr[9] = (isset($exif_data['Software'])) ? $exif_data['Software']:"-";
		
		if(isset($exif_data['ExposureProgram'])) {
			switch ($exif_data['ExposureProgram']) {
				case 0: $exif_arr[10] = $rcmail->gettext('exif_undefined','pictures'); break;
				case 1: $exif_arr[10] = $rcmail->gettext('exif_manual','pictures'); break;
				case 2: $exif_arr[10] = $rcmail->gettext('exif_exposure_auto','pictures'); break;
				case 3: $exif_arr[10] = $rcmail->gettext('exif_time_auto','pictures'); break;
				case 4: $exif_arr[10] = $rcmail->gettext('exif_shutter_auto','pictures'); break;
				case 5: $exif_arr[10] = $rcmail->gettext('exif_creative_auto','pictures'); break;
				case 6: $exif_arr[10] = $rcmail->gettext('exif_action_auto','pictures'); break;
				case 7: $exif_arr[10] = $rcmail->gettext('exif_portrait_auto','pictures'); break;
				case 8: $exif_arr[10] = $rcmail->gettext('exif_landscape_auto','pictures'); break;
				case 9: $exif_arr[10] = $rcmail->gettext('exif_bulb','pictures'); break;
			}
		} else
			$exif_arr[10] = "-";

		$exif_arr[11] = (isset($exif_data['Flash'])) ? $exif_data['Flash']:"-";
		
		if(isset($exif_data['MeteringMode'])) {
			switch ($exif_data['MeteringMode']) {
				case 0: $exif_arr[12] = $rcmail->gettext('exif_unkown','pictures'); break;
				case 1: $exif_arr[12] = $rcmail->gettext('exif_average','pictures'); break;
				case 2: $exif_arr[12] = $rcmail->gettext('exif_middle','pictures'); break;
				case 3: $exif_arr[12] = $rcmail->gettext('exif_spot','pictures'); break;
				case 4: $exif_arr[12] = $rcmail->gettext('exif_multi-spot','pictures'); break;
				case 5: $exif_arr[12] = $rcmail->gettext('exif_multi','pictures'); break;
				case 6: $exif_arr[12] = $rcmail->gettext('exif_partial','pictures'); break;
				case 255: $exif_arr[12] = $rcmail->gettext('exif_other','pictures'); break;
			}
		} else
			$exif_arr[12] = "-";
		
		$exif_arr[13] = (isset($exif_data['WhiteBalance'])) ? $exif_data['WhiteBalance']:"-";
		$exif_arr[14] = (isset($exif_data["GPSLatitude"])) ? gps($exif_data["GPSLatitude"], $exif_data['GPSLatitudeRef']):"-";
		$exif_arr[15] = (isset($exif_data["GPSLongitude"])) ? gps($exif_data["GPSLongitude"], $exif_data['GPSLongitudeRef']):"-";
		$exif_arr[16] = (isset($exif_data['Orientation'])) ? $exif_data['Orientation']:"-";
	}
	return $exif_arr;
}
	
function gps($exifCoord, $hemi) {
	$degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
	$minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
	$seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;
	$flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
	return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
}

function gps2Num($coordPart) {
	$parts = explode('/', $coordPart);
	if (count($parts) <= 0) return 0;
	if (count($parts) == 1) return $parts[0];

    $f = floatval($parts[0]);
    $s = floatval($parts[1]);

    $e = ($s == 0) ? 0:$f/$s;
	return $e;
}

function checkpermissions($file) {
	if (!is_readable($file)) {
		error_log('Pictures: Can\'t read image $file, check your permissions.');
	}
}

function guardAgainstDirectoryTraversal($path) {
	$pattern = "/^(.*\/)?(\.\.)(\/.*)?$/";
	$directory_traversal = preg_match($pattern, $path);

	if ($directory_traversal === 1) {
		error_log('Pictures: Could not open \"'.htmlspecialchars(stripslashes($current_dir)).'\" for reading!');
		die("ERROR: Could not open directory \"".htmlspecialchars(stripslashes($current_dir))."\" for reading!");
	}
}

function createthumb($image) {
	global $thumbsize, $pictures_path, $thumb_path, $hevc, $ccmd;
	$idir = str_replace($pictures_path, '', $image);
	$thumbnailpath = $thumb_path.$idir.".jpg";

	if(file_exists($thumbnailpath) && filemtime($image) == filemtime($thumbnailpath)) return false;

	$thumbpath = pathinfo($thumbnailpath)['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			error_log("Pictures: Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.");
		}
	}

	$exif = [];
	$mimetype = mime_content_type($file);
	$mtype = explode('/', $mimetype)[0];

	if ($mtype == "image") {
		list($width, $height, $type) = getimagesize($image);
		$newwidth = ceil($width * $thumbsize / $height);
		if($newwidth <= 0) error_log("Pictures: Calculating the width failed.");
		$target = imagecreatetruecolor($newwidth, $thumbsize);
		
		switch ($type) {
			case 1: $source = @imagecreatefromgif($image); break;
			case 2: $source = @imagecreatefromjpeg($image); break;
			case 3: $source = @imagecreatefrompng($image); break;
			default:
				corrupt_thmb($thumbsize, $thumbpath);
				error_log("Pictures: Unsupported fileformat ($type).");
				die();
		}
		
		imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $thumbsize, $width, $height);
		imagedestroy($source);

		$exif = readEXIF($image);
		$ort = (isset($exifArr['16'])) ? $ort = $exifArr['16']:NULL;

		switch ($ort) {
			case 3: $degrees = 180; break;
			case 4: $degrees = 180; break;
			case 5: $degrees = 270; break;
			case 6: $degrees = 270; break;
			case 7: $degrees = 90; break;
			case 8: $degrees = 90; break;
			default: $degrees = 0;
		}
		if ($degrees != 0) $target = imagerotate($target, $degrees, 0);
		
		if(is_writable($thumbpath)) {
			imagejpeg($target, $thumbnailpath, 100);
			touch($thumbnailpath, filemtime($image));
		} else {
			error_log("Pictures: Can't write Thumbnail. Please check your directory permissions.");
		}
	} elseif ($type == "video") {
		$ffmpeg = exec("which ffmpeg");
		if(file_exists($ffmpeg)) {
			$pathparts = pathinfo($image);
			exec($ffmpeg." -y -v error -i \"".$image."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumbnailpath."\" 2>&1", $output, $error);
			if($error != 0) {
				corrupt_thmb($thumbsize, $thumbnailpath);
				return $exif;
			}
			touch($thumbnailpath, filemtime($image));
			$vcodec = exec_shell("ffprobe -y -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$org_pic\"");
			if ($hevc && "$vcodec" != "hevc") return false;
			$out = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
			$ccmd = str_replace("%f", $ffmpeg, str_replace("%i", $image, str_replace("%o", $out, $ccmd)));
			exec($ccmd);
		} else {
			error_log("Pictures: ffmpeg is not installed, so video formats are not supported.");
		}
	}

	$exif['17'] = $mimetype;
	return $exif;
}

function corrupt_thmb($thumbsize, $thumbpath) {
	$sign = imagecreatefrompng('images/error2.png');
	$background = imagecreatefromjpeg('images/defaultimage.jpg');

	$sx = imagesx($sign);
	$sy = imagesy($sign);
	$ix = imagesx($background);
	$iy = imagesy($background);

	$size = 120;
	imagecopyresampled($background, $sign, ($ix-$size)/2, ($iy-$size)/2, 0, 0, $size, $size, $sx, $sy);
	$nw = ($thumbsize/$ix)*$iy;

	$image_new = imagecreatetruecolor($nw, $thumbsize);
	imagecopyresampled($image_new, $background, 0, 0, 0, 0, $nw, $thumbsize, $ix, $iy);

	imagejpeg($image_new, $thumbpath, 100);
	imagedestroy($sign);
	imagedestroy($background);
	imagedestroy($image_new);
}

function todb($file, $user, $pictures_basepath, $exif) {
	global $rcmail, $ffprobe;
	$dbh = rcmail_utils::db();
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');
	$result = $dbh->query("SELECT count(*), `pic_id` FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	$rarr = $db->fetch_array($result);
	$count = $rarr[0];
	$id = $rarr[1];

	$type = explode('/',$exif[17])[0];
	if($type == 'image') {
		$taken = (is_int($exif[5])) ? $exif[5]:filemtime($file);
	} else {
		$taken = strtotime(shell_exec("$ffprobe -v quiet -select_streams v:0  -show_entries stream_tags=creation_time -of default=noprint_wrappers=1:nokey=1 \"$file\""));
		$taken = (empty($taken)) ? filemtime($file):$taken;
	}

	$exif = "'".json_encode($exif,  JSON_HEX_APOS)."'";

	if($count == 0) {
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES (\"$ppath\",'$type',$taken,$exif,$user)";
	} else {
		$query = "UPDATE `pic_pictures` SET `pic_taken` = $taken, `pic_EXIF` = $exif WHERE `pic_id` = $id";
	}

	$dbh->query($query);
}

function rmdb($file, $user) {
	$dbh = rcmail_utils::db();
	$query = "DELETE FROM `pic_pictures` WHERE `pic_path` like \"$file%\" AND `user_id` = $user";
	$ret = $dbh->query($query);
}

function mvdb($oldpath, $newPath) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;

	$query = "SELECT `pic_id`, `pic_path` FROM `pic_pictures` WHERE `pic_path` like \"$oldpath%\" AND `user_id` = $user_id";
	$ret = $dbh->query($query);
	$rowc = $dbh->num_rows();

	$images = [];
	for ($i = 0; $i < $rowc; $i++) {
		array_push($images, $dbh->fetch_assoc($ret));
	}

	foreach ($images as $image) {
		$pic_id = $image['pic_id'];
		$nnewPath = str_replace($oldpath, $newPath, $image['pic_path']);
		$query = "UPDATE `pic_pictures` SET `pic_path` = \"$nnewPath\" WHERE `pic_id` = $pic_id";
		$ret = $dbh->query($query);
	}
}

function mvimg($oldpath, $newPath) {
	global $rcmail, $pictures_path, $thumb_path;
	$dfiles = $rcmail->config->get('dummy_files', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($oldpath);

	if($dfiles && substr_count($oldpath, $dfolder) > 0) {
		rename($oldpath, $newPath);
		touch($oldpath, $ftime);
	} else {
		rename($oldpath, $newPath);
	}

	$thumbnailpath = $thumb_path.str_replace($pictures_path, '', $oldpath).".jpg";
	if(file_exists($thumbnailpath)) unlink($thumbnailpath);
}

function delimg($file) {
	global $rcmail, $pictures_path, $thumb_path, $webp_path;
	$dfiles = $rcmail->config->get('dummy_files', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($file);

	if($dfiles && substr_count($file, $dfolder) > 0) {
		if(unlink($file)) touch($file, $ftime);
	} else {
		unlink($file);
	}
	
	$pathparts = pathinfo($file);
	$hiddenvid = $pathparts['dirname'].'/.'.$pathparts['filename'].'mp4';
	if(file_exists($hiddenvid)) unlink($hiddenvid);

	$thumbnailpath = $thumb_path.str_replace($pictures_path, '', $file).".jpg";
	if(file_exists($thumbnailpath)) unlink($thumbnailpath);

	$webp = str_replace($pictures_path, $webp_path, $file).".webp";
	if(file_exists($webp)) unlink($webp);
}

$thumbdir = rtrim($pictures_path.$requestedDir,'/');
$current_dir = $thumbdir;
guardAgainstDirectoryTraversal($current_dir);
?>