<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.6
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_path = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$thumb_path = str_replace("%u", $username, $rcmail->config->get('thumb_path', false));
	$thumbsize = $rcmail->config->get('thumb_size', false);
	
	if(substr($pictures_path, -1) != '/') {
		error_log('Pictures Plugin(Photos): check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if(substr($thumb_path, -1) != '/') {
		error_log('Pictures Plugin(Photos): check $config[\'thumb_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_path)) {
		if(!mkdir($pictures_path, 0755, true)) {
			error_log('Pictures Plugin(Photos): Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
} else {
	error_log('Pictures Plugin(Photos): Login failed. User is not logged in.');
	http_response_code(403);
	header('location: ../../');
	die('Login failed. User is not logged in.');
}

$page_navigation = "";
$breadcrumb_navigation = "";
$thumbnails = "";
$new = "";
$images = "";
$exif_data = "";
$messages = "";
$comment = "";
$requestedDir = null;
$label_max_length = $rcmail->config->get('label_max_length', false);
$skip_objects = $rcmail->config->get('skip_objects', false);
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

	foreach($_FILES['galleryfiles']['error'] as $key => $error) {
		$err = 0;
		if ($error == UPLOAD_ERR_OK) {
			$tmp_name = $_FILES['galleryfiles']['tmp_name'][$key];
			$name = basename($_FILES["galleryfiles"]["name"][$key]);
			
			if (!in_array($_FILES['galleryfiles']['type'][$key], $aAllowedMimeTypes)) {
				$errmsg = 'The filetype of \"$name\" is not supported by this script.';
				$test[] = array('message' => $errmsg, 'type' => 'error');
				error_log("Pictures Plugin(Photos): $errmsg");
				$err = 1;
			}
			
			if(file_exists($pictures_path.$folder."/".$name)) {
				$errmsg = 'The file "'.$folder."/".$name.'" already exists.';
				$test[] = array('message' => $errmsg, 'type' => 'warning');
				error_log("Pictures Plugin(Photos): $errmsg");
				$err = 1;
			}

			if($err == 0) {
				if(!move_uploaded_file($tmp_name, "$pictures_path$folder/$name")) {
					$errmsg = 'Upload of "'.$folder."/".$name.'" failed. Please check permissions.';
					$test[] = array('message' => $errmsg, 'type' => 'error');
					error_log("Pictures Plugin(Photos): $errmsg");
				} else {
					$errmsg = 'Upload successfully.';
					$test[] = array('message' => $errmsg, 'type' => 'info');
					createthumb("$pictures_path$folder/$name", $pictures_path);
					todb("$pictures_path$folder/$name", $rcmail->user->ID, $pictures_path);
				}
			}
		}
		else {
			$errmsg = 'There was some error during upload. Please check your configuration. Exiting now.';
			error_log("Pictures Plugin(Photos): $errmsg");
			$test[] = array('message' => $errmsg, 'type' => 'error');
			break;
		}
	}
	die(json_encode($test));
}

if(isset($_POST['alb_action'])) {
	$action = $_POST['alb_action'];	
	$src = $pictures_path.$_POST['src'];
	$target = dirname($src).'/'.$_POST['target'];
	$mtarget = $pictures_path.$_POST['target'];
	$oldpath = str_replace($pictures_path,'',$src);
	$newPath = str_replace($pictures_path,'',$target);
	$nnewPath = str_replace($pictures_path,'',$mtarget);

	switch($action) {
		case 'move':	mvdb("$oldpath | $nnewPath"); die(rename($src, $mtarget)); break;
		case 'rename':	mvdb($oldpath, $newPath); die(rename($src, $target)); break;
		case 'delete':	die(removeDirectory($src, $rcmail->user->ID)); break;
		case 'create':	die(mkdir(dirname($src)."/".trim(trim($_POST['target']),"/"), 0755, true)); break;
	}
	die();
}

if(isset($_POST['img_action'])) {
	global $rcmail, $pictures_path;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;
	$action = $_POST['img_action'];	
	$images = $_POST['images'];
	$org_path = urldecode($_POST['orgPath']);
	$album_target = trim($_POST['target'],'/');	

	switch($action) {
		case 'move':	if($_POST['newPath'] != "") {
							$newPath = $_POST['newPath'];
							if (!is_dir($pictures_path.$album_target.$newPath)) mkdir($pictures_path.$album_target.'/'.$newPath, 0755, true);
						}
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
						$sharename = (empty($_POST['sharename'])) ? "Unkown-$cdate": filter_var($_POST['sharename'], FILTER_SANITIZE_STRING);
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
	$dir = $_GET['p'];
	guardAgainstDirectoryTraversal($dir);
	echo showPage(showGallery($dir), $dir);
	die();
} else {
	echo showPage(showGallery(""), '');
	die();
}

function showPage($thumbnails, $dir) {
	$maxfiles = ini_get("max_file_uploads");
	$page = "
	<!DOCTYPE html>
	<html>
		<head>
			<title>$dir</title>
			<link rel=\"stylesheet\" href=\"js/justifiedGallery/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel='stylesheet' href='skins/main.min.css' type='text/css' />
			<link rel='stylesheet' href='js/glightbox/glightbox.min.css' type='text/css' />
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/justifiedGallery/jquery.justifiedGallery.min.js\"></script>
			<script src='js/glightbox/glightbox.min.js'></script>
			";

	$aarr = explode('/',$dir);
	$path = "";
	$albumnav = "<a class='breadcrumbs__item' href='?p='>Start</a>";
	foreach ($aarr as $folder) {
		$path = $path.'/'.$folder;
		if(strlen($folder) > 0) $albumnav.= "<a class='breadcrumbs__item' href='?p=$path'>$folder</a>";
	}

	$page.= "</head>
	\t\t<body class='picbdy' onload='count_checks();'>
	\t\t\t<div id='header' style='position: absolute; top: -8px;'>
	\t\t\t\t$albumnav
	\t\t\t</div>
	\t\t\t<div id=\"galdiv\">";
	$page.= $thumbnails;
	$page.="
	<script>
		$('#folders').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 7,
			border: 0,
			rel: 'folders',
			lastRow: 'justify',
			captions: false,
			randomize: false
		});
	
		$('#images').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 7,
			border: 0,
			rel: 'gallery',
			lastRow: 'justify',
			captions: true,
			randomize: false
		});

		const lightbox = GLightbox({
			plyr: {
				config: {
					
					muted: true,
				}
			},
			autoplayVideos: false,
			loop: false,
		});

		lightbox.on('slide_changed', (data) => {
			let file = new URL(data.current.slideConfig.href).searchParams.get('file').split('/').slice(-1)[0];
			if(document.getElementById(file)) {
				let closebtn = document.querySelector('.gclose');
				let infobtn = document.createElement('button');
				infobtn.id = 'infbtn';
				infobtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.0\" viewBox=\"0 0 160 160\"><g fill=\"white\"><path d=\"M80 15c-35.88 0-65 29.12-65 65s29.12 65 65 65 65-29.12 65-65-29.12-65-65-65zm0 10c30.36 0 55 24.64 55 55s-24.64 55-55 55-55-24.64-55-55 24.64-55 55-55z\"/><path d=\"M89.998 51.25a11.25 11.25 0 1 1-22.5 0 11.25 11.25 0 1 1 22.5 0zm.667 59.71c-.069 2.73 1.211 3.5 4.327 3.82l5.008.1V120H60.927v-5.12l5.503-.1c3.291-.1 4.082-1.38 4.327-3.82V80.147c.035-4.879-6.296-4.113-10.757-3.968v-5.074L90.665 70\"/></g></svg>';
				infobtn.addEventListener('mouseover', function() {
					document.getElementById(file).classList.add('eshow');
					document.getElementById(file).addEventListener('mouseover', function() {document.getElementById(file).classList.add('eshow')})
				});
				infobtn.addEventListener('mouseout', function() {
					document.getElementById(file).classList.remove('eshow');
					document.getElementById(file).addEventListener('mouseout', function() {document.getElementById(file).classList.remove('eshow')})
				});
				closebtn.before(infobtn);
			}
		});
		
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
		
		function count_checks() {
			if(document.querySelectorAll('input[type=\"checkbox\"]:checked').length > 0) {
				window.parent.document.getElementById('movepicture').classList.remove('disabled');
				window.parent.document.getElementById('delpicture').classList.remove('disabled');
				window.parent.document.getElementById('sharepicture').classList.remove('disabled');
			}
			else {
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
			this.classList.add('mmn-drop');
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
			folder = decodeURIComponent(folder);
			var progressBar = document.getElementById('' + folder + '').getElementsByClassName('progress')[0];
			if (files.length > maxfiles) {
				console.log('You try to upload more than the max count of allowed files(' + maxfiles + ')');
				return false;
			}
			else {
				for (var i = 0; i < files.length; i++) {
					if (mimeTypes.indexOf(files.item(i).type) == -1) {
						console.log('Unsupported filetype(' + files.item(i).name + '), exiting');
						return false;
				} 
				else {
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

function showGallery($requestedDir) {
	$aallowed = ['image','video'];
	$files = array();
	$hidden_vid = "";
	$pnavigation = "";
	
	global $pictures_path, $rcmail, $label_max_length;
	$dbh = rcmail_utils::db();
	$thumbdir = $pictures_path.$requestedDir;
	$current_dir = $thumbdir;
	$forbidden = $rcmail->config->get('skip_objects', false);
	
	if (is_dir($current_dir) && $handle = opendir("${current_dir}")) {
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
								"html" => "\n\t\t\t\t\t\t<a id=\"$requestedDir/$file\" href=\"photos.php?$fparams\" onclick=\"album_w('$requestedDir/$file')\" title=\"$file\"><img src=\"$imgUrl\" alt=\"$file\" /><span class=\"dropzone\">$file</span><div class=\"progress\"><div class=\"progressbar\"></div></div></a>"
								);
				}
			}
			
			// Gallery images
			$allowed = (in_array(explode('/', mime_content_type($current_dir."/".$file))[0], $aallowed)) ? true:false;
			if ($file != "." && $file != ".." && $file != "folder.jpg" && $allowed) {
				$filename_caption = "";
				$requestedDir = trim($requestedDir,'/').'/';
				$linkUrl = "simg.php?file=".rawurlencode("$requestedDir/$file");

				$fullpath = $current_dir."/".$file;
				$dbpath = str_replace($pictures_path, '', $fullpath);
				$uid = $rcmail->user->ID;
				$query = "SELECT `pic_id`, `pic_EXIF`, `pic_taken` FROM `pic_pictures` WHERE `pic_path` = \"$dbpath\" AND user_id = $uid";
				$result = $dbh->query($query);
				$pdata = $dbh->fetch_assoc($result);
				$exifReaden = ($rcmail->config->get('display_exif', false) == 1 && preg_match("/.jpg$|.jpeg$/i", $file)) ? json_decode($pdata['pic_EXIF']):NULL;
				$taken = isset($pdata['pic_taken']) ? $pdata['pic_taken']:NULL;

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
						"html" => "\n<div><a class=\"image glightbox\" href='$linkUrl' data-type='image'><img src=\"$imgUrl\" alt=\"$file\" /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\">$caption</div>");
				}
				
				// video files
				if (preg_match("/\.ogv$|\.mp4$|\.mpg$|\.mpeg$|\.mov$|\.avi$|\.wmv$|\.flv$|\.webm$/i", $file)) {
					$thmbParams = http_build_query(array('file' => "$requestedDir/$file", 't' => 1));
					$thmbUrl = "simg.php?$thmbParams";
					$videos[] = array("html" => "<div style=\"display: none;\" id=\"".pathinfo($file)['filename']."\"><video class=\"lg-video-object lg-html5\" controls preload=\"none\"><source src=\"$linkUrl\" type=\"video/mp4\"></video></div>");
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "<div><a class=\"video glightbox\" href='$linkUrl' data-type='video' data-html=\"#".pathinfo($file)['filename']."\"><img src=\"$thmbUrl\" alt=\"$file\" /><span class='video'></span></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>");
				}
			}
			}
		}
		closedir($handle);
	} else {
		error_log('Pictures Plugin(Photos): Could not open "'.htmlspecialchars(stripslashes($current_dir)).'" folder for reading!');
		die("ERROR: Please check server error log");
	}

	$thumbnails = "";
	$start = 0;

	// sort folders
	if (isset($dirs) && sizeof($dirs) > 0) {
		$thumbnails.= "\n\t\t\t\t\t<div id=\"folders\">";
		foreach ($dirs as $key => $row) {
			if ($row["name"] == "") {
				unset($dirs[$key]);
				continue;
			}
			$name[$key] = strtolower($row['name']);
			$date[$key] = strtolower($row['date']);
		}
		$sorting_folders = $rcmail->config->get('sorting_folders', false);
		array_multisort($dirs, $rcmail->config->get('sortdir_folders', false), $$sorting_folders);
		foreach ($dirs as $folder) {
			$thumbnails.= $folder["html"];
			$start++;
		}
		$thumbnails.= "\n\t\t\t\t\t</div>";
	}

	// sort images
	$thumbnails.= "\n\t\t\t\t\t<div id=\"images\" class=\"justified-gallery\">";
	if (sizeof($files) > 0) {
		foreach ($files as $key => $row) {
			if ($row["name"] == "") {
				unset($files[$key]);
				continue;
			}
			$name[$key] = strtolower($row['name']);
			$date[$key] = isset($row['date']) ? strtolower($row['date']):null;
			$size[$key] = strtolower($row['size']);
		}
		$sorting_files = $rcmail->config->get('sorting_files', false);
		@array_multisort($$sorting_files, $rcmail->config->get('sortdir_files', false), $files);
		
		$thumbs_pr_page = $rcmail->config->get("thumbs_pr_page", false);
		if(isset($_GET['page'])) {
			$offset_start = ($_GET["page"] * $thumbs_pr_page) - $thumbs_pr_page;
		} else {
			$offset_start = 0;
		}
		
		$pages = ceil(sizeof($files)/$thumbs_pr_page);
		$gal = ltrim($_GET['p'],'/');
		$offset_end = $offset_start + $thumbs_pr_page;
		
		for ($y = $offset_start; $y < $offset_end; $y++) {
			if(isset($files[$y]["html"])) $thumbnails.= "\n".$files[$y]["html"];
		}
		
		$pnavigation = "<div id=\"blindspot\"><div id=\"pnavigation\">";
		for ($page = 1; $page <= $pages; $page++) {
			$pnavigation.= "<a href=\"?p=$gal&page=$page\">$page</a>";
		}
		$pnavigation.= "</div></div>";

		if(isset($videos) && sizeof($videos) > 0){
			foreach($videos as $video) {
				$hidden_vid.= $video["html"];
			}
		}
	}
	$thumbnails.= "\n\t\t\t\t\t</div>";
	$thumbnails.= $hidden_vid.$pnavigation;
	return $thumbnails;
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
	error_log('Pictures Plugin(Photos): PHP EXIF is not available. Set display_exif = 0; in config to remove this message');
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

	if(count($exif_data) > 0) {
		//0
		if(isset($exif_data['Model']))
			$exif_arr[] = $exif_data['Model'];
		else
			$exif_arr[] = "-";
		
		//1
		if(isset($exif_data['FocalLength']))
			$exif_arr[] = parse_fraction($exif_data['FocalLength']) . "mm";
		else
			$exif_arr[] = "-";
		
		//2
		if(isset($exif_data['FocalLength']))
			$exif_arr[] = parse_fraction($exif_data['FocalLength'], 2) . "s";
		else
			$exif_arr[] = "-";
		
		//3
		if(isset($exif_data['FNumber']))
			$exif_arr[] = "f" . parse_fraction($exif_data['FNumber']);
		else
			$exif_arr[] = "-";
		
		//4
		if(isset($exif_data['ISOSpeedRatings']))
			$exif_arr[] = $exif_data['ISOSpeedRatings'];
		else
			$exif_arr[] = "-";
		
		//5	
		if(isset($exif_data['DateTimeDigitized']))
			$exif_arr[] = strtotime($exif_data['DateTimeDigitized']);
		else
			$exif_arr[] = filemtime($file);
		
		//6	
		if(isset($exif_data['ImageDescription']))
			$exif_arr[] = $exif_data['ImageDescription'];
		else
			$exif_arr[] = "-";
		
		//7	
		if(isset($exif_data['CALC-GPSLATITUDE-SIG']))
			$exif_arr[] = $exif_data['CALC-GPSLATITUDE-SIG'];
		else
			$exif_arr[] = "-";
		
		//8	
		if(isset($exif_data['Make']))
			$exif_arr[] = $exif_data['Make'];
		else
			$exif_arr[] = "-";
		
		//9	
		if(isset($exif_data['Software']))
			$exif_arr[] = $exif_data['Software'];
		else
			$exif_arr[] = "-";
		
		//10
		if(isset($exif_data['ExposureProgram'])) {
			switch ($exif_data['ExposureProgram']) {
				case 0: $exif_arr[] = $rcmail->gettext('exif_undefined','pictures'); break;
				case 1: $exif_arr[] = $rcmail->gettext('exif_manual','pictures'); break;
				case 2: $exif_arr[] = $rcmail->gettext('exif_exposure_auto','pictures'); break;
				case 3: $exif_arr[] = $rcmail->gettext('exif_time_auto','pictures'); break;
				case 4: $exif_arr[] = $rcmail->gettext('exif_shutter_auto','pictures'); break;
				case 5: $exif_arr[] = $rcmail->gettext('exif_creative_auto','pictures'); break;
				case 6: $exif_arr[] = $rcmail->gettext('exif_action_auto','pictures'); break;
				case 7: $exif_arr[] = $rcmail->gettext('exif_portrait_auto','pictures'); break;
				case 8: $exif_arr[] = $rcmail->gettext('exif_landscape_auto','pictures'); break;
				case 9: $exif_arr[] = $rcmail->gettext('exif_bulb','pictures'); break;
			}
		} else
			$exif_arr[] = "-";
		
		//11
		if(isset($exif_data['Flash']))
			$exif_arr[] = $exif_data['Flash'];
		else
			$exif_arr[] = "-";
		
		//12
		if(isset($exif_data['MeteringMode'])) {
			switch ($exif_data['MeteringMode']) {
				case 0: $exif_arr[] = $rcmail->gettext('exif_unkown','pictures'); break;
				case 1: $exif_arr[] = $rcmail->gettext('exif_average','pictures'); break;
				case 2: $exif_arr[] = $rcmail->gettext('exif_middle','pictures'); break;
				case 3: $exif_arr[] = $rcmail->gettext('exif_spot','pictures'); break;
				case 4: $exif_arr[] = $rcmail->gettext('exif_multi-spot','pictures'); break;
				case 5: $exif_arr[] = $rcmail->gettext('exif_multi','pictures'); break;
				case 6: $exif_arr[] = $rcmail->gettext('exif_partial','pictures'); break;
				case 255: $exif_arr[] = $rcmail->gettext('exif_other','pictures'); break;
			}
		} else
			$exif_arr[] = "-";
		
		//13
		if(isset($exif_data['WhiteBalance']))
			$exif_arr[] = $exif_data['WhiteBalance'];
		else
			$exif_arr[] = "-";
		
		//14
		if(isset($exif_data["GPSLatitude"]))
			$exif_arr[] = gps($exif_data["GPSLatitude"], $exif_data['GPSLatitudeRef']);
		else
			$exif_arr[] = "-";
		
		//15
		if(isset($exif_data["GPSLongitude"]))
			$exif_arr[] = gps($exif_data["GPSLongitude"], $exif_data['GPSLongitudeRef']);
		else
			$exif_arr[] = "-";
	}
	return $exif_arr;
}
	
function gps($coordinate, $hemisphere) {
  if (is_string($coordinate)) {
	$coordinate = array_map("trim", explode(",", $coordinate));
  }
  for ($i = 0; $i < 3; $i++) {
	$part = explode('/', $coordinate[$i]);
	if (count($part) == 1) {
	  $coordinate[$i] = $part[0];
	} else if (count($part) == 2) {
	  $coordinate[$i] = floatval($part[0])/floatval($part[1]);
	} else {
	  $coordinate[$i] = 0;
	}
  }
  list($degrees, $minutes, $seconds) = $coordinate;
  $sign = ($hemisphere == 'W' || $hemisphere == 'S') ? -1 : 1;
  return $sign * ($degrees + $minutes/60 + $seconds/3600);
}

function checkpermissions($file) {
	global $messages;

	if (!is_readable($file)) {
		error_log('Pictures Plugin(Photos): Can\'t read image $file, check your permissions.');
	}
}

function guardAgainstDirectoryTraversal($path) {
	$pattern = "/^(.*\/)?(\.\.)(\/.*)?$/";
	$directory_traversal = preg_match($pattern, $path);

	if ($directory_traversal === 1) {
		error_log('Pictures Plugin(Photos): Could not open \"'.htmlspecialchars(stripslashes($current_dir)).'\" for reading!');
		die("ERROR: Could not open directory \"".htmlspecialchars(stripslashes($current_dir))."\" for reading!");
	}
}

function createthumb($image) {
	global $thumbsize, $pictures_path, $thumb_path;
	$idir = str_replace($pictures_path, '', $image);
	$thumbnailpath = $thumb_path.$idir.".jpg";
	if(file_exists($thumbnailpath)) return false;
	
	$thumbpath = pathinfo($thumbnailpath)['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			error_log("Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.");
		}
	}

	if (preg_match("/.jpg$|.jpeg$|.png$/i", $image)) {
		list($width, $height, $type) = getimagesize($image);
		$newwidth = ceil($width * $thumbsize / $height);
		if($newwidth <= 0) error_log("Calculating the width failed.");
		$target = imagecreatetruecolor($newwidth, $thumbsize);
		
		switch ($type) {
			case 1: $source = @imagecreatefromgif($image); break;
			case 2: $source = @imagecreatefromjpeg($image); break;
			case 3: $source = @imagecreatefrompng($image); break;
			default: error_log("Unsupported fileformat ($type)."); die();
		}
		
		imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $thumbsize, $width, $height);
		imagedestroy($source);
		$exif = @exif_read_data($org_pic, 0, true);
		$ort = (isset($exif['IFD0']['Orientation'])) ? $ort = $exif['IFD0']['Orientation']:NULL;
		switch ($ort) {
			case 3:
				$degrees = 180;
				break;
			case 4:
				$degrees = 180;
				break;
			case 5:
				$degrees = 270;
				break;
			case 6:
				$degrees = 270;
				break;
			case 7:
				$degrees = 90;
				break;
			case 8:
				$degrees = 90;
				break;
		}
		if ($degrees != 0) $target = imagerotate($target, $degrees, 0);
		
		if(is_writable($thumbpath)) {
			imagejpeg($target, $thumbnailpath, 85);
		} else {
			error_log("Can't write Thumbnail. Please check your directory permissions.");
		}
	} elseif(preg_match("/.mp4$|.mpg$|.3gp$/i", $image)) {
		$ffmpeg = exec("which ffmpeg");
		if(file_exists($ffmpeg)) {
			$pathparts = pathinfo($image);
			exec($ffmpeg." -i \"".$image."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumbnailpath."\" 2>&1");
			$startconv = time();
			$ogv = $pathparts['dirname']."/.".$pathparts['filename'].".ogv";
			exec("$ffmpeg -loglevel quiet -i $image -c:v libtheora -q:v 7 -c:a libvorbis -q:a 4 $ogv");
		} else {
			error_log("ffmpeg is not installed, so video formats are not supported.");
		}
	}
}

function todb($file, $user, $pictures_basepath) {
	global $rcmail, $ffprobe;
	$dbh = rcmail_utils::db();
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');
	$result = $dbh->query("SELECT count(*) FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	if($dbh->fetch_array($result)[0] == 0) {
		$type = explode('/',mime_content_type($file))[0];
		if($type == 'image') {
			$exif = readEXIF($file);
			$taken = (is_int($exif[5])) ? $exif[5]:filemtime($file);
			$exif = "'".json_encode($exif,  JSON_HEX_APOS)."'";
		} else {
			$exif = 'NULL';
			$taken = strtotime(shell_exec("$ffprobe -v quiet -select_streams v:0  -show_entries stream_tags=creation_time -of default=noprint_wrappers=1:nokey=1 \"$file\""));
			$taken = (empty($taken)) ? filemtime($file):$taken;
		}
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES (\"$ppath\",'$type',$taken,$exif,$user)";
		$dbh->query($query);
	}
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
	global $rcmail;
	$dfiles = $rcmail->config->get('dummy_files', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($oldpath);

	if($dfiles && substr_count($oldpath, $dfolder) > 0) {
		if(rename($oldpath, $newPath)) touch($oldpath, $ftime);
	} else {
		rename($oldpath, $newPath);
	}
}

function delimg($file) {
	global $rcmail;
	$dfiles = $rcmail->config->get('dummy_files', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($file);

	if($dfiles && substr_count($file, $dfolder) > 0) {
		if(unlink($file)) touch($file, $ftime);
	} else {
		unlink($file);
	}
}

$thumbdir = rtrim($pictures_path.$requestedDir,'/');
$current_dir = $thumbdir;
guardAgainstDirectoryTraversal($current_dir);
?>
