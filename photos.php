<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.2
 * @author Offerel
 * @copyright Copyright (c) 2021, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

// Login
if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_path = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$thumb_path = str_replace("%u", $username, $rcmail->config->get('thumb_path', false));
	
	if(substr($pictures_path, -1) != '/') {
		error_log('Pictures Plugin(Photos): check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if(substr($thumb_path, -1) != '/') {
		error_log('Pictures Plugin(Photos): check $config[\'thumb_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_path))
	{
		if(!mkdir($pictures_path, 0755, true)) {
			error_log('Pictures Plugin(Photos): Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
}
else {
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

if(isset($_POST['getsubs'])) {
	$subdirs = getAllSubDirectories($pictures_path);
	$select = "<select name=\"target\" id=\"target\" size=\"10\" onchange=\"$('#mvb').removeClass('disabled');\">";
	foreach ($subdirs as $dir) {
		$dir = substr($dir,strlen($pictures_path));
		if(!strposa($dir, $skip_objects))
			$select.= "<option>".$dir."</option>";
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
				}
				else {
					$errmsg = 'Upload successfully.';
					$test[] = array('message' => $errmsg, 'type' => 'info');
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

	switch($action) {
		case 'move':	$target = $pictures_path.$_POST['target'].basename($src); die(rename($src, $target)); break;
		case 'rename':	$target = dirname($src)."/".trim(trim($_POST['target']),"/"); die(rename($src, $target)); break;
		case 'delete':	die(removeDirectory($src)); break;
		case 'create':	die(mkdir(dirname($src)."/".trim(trim($_POST['target']),"/"), 0755, true)); break;
	}
	die();
}

if(isset($_POST['img_action'])) {
	$action = $_POST['img_action'];	
	$images = $_POST['images'];
	$org_path = $pictures_path.urldecode($_POST['orgPath']);
	$album_target = $pictures_path.$_POST['target'];

	switch($action) {
		case 'move':	if($_POST['newPath'] != "") {
							$newPath = $_POST['newPath']."/";
							if (!is_dir($album_target.$newPath)) {
								mkdir($album_target.$newPath, 0755, true);
							}
						}
						else {
							$newPath = "";
						}
						
						foreach($images as $image) {
							rename($org_path."/".$image, $album_target.$newPath.$image);
						}
						die(true);
						break;
		case 'delete':	foreach($images as $image) {
							unlink($org_path."/".$image);
						}
						die(true);
						break;
	}
	die();
}

function removeDirectory($path) {
	$files = glob($path . '/*');
	foreach ($files as $file) {
		is_dir($file) ? removeDirectory($file) : unlink($file);
	}
	rmdir($path);
	return true;
}

if( isset($_GET['p']) ) {
	$dir = $_GET['p'];
	guardAgainstDirectoryTraversal($dir);
	echo showPage(showGallery($dir), $dir);
	die();
}
else {
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
			<link rel=\"stylesheet\" href=\"css/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/main.min.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/lightgallery.min.css\" type=\"text/css\" />
			
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/jquery.justifiedGallery.min.js\"></script>
			<script src=\"js/lightgallery-all.min.js\"></script>";
	$page.= "</head><body onload=\"count_checks(); album_w('$dir');\"><div id=\"galdiv\">";
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
		}).on('jg.complete', function () {
			$('#images').lightGallery({
				share: false,
				download: false,
				fullScreen: false,
				pager: false,
				autoplay: false,
				selector: '.image'
			});
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
		
		function album_w(album) {
			if(album != '') {
				var album_arr = album.split('/');
				var len = album_arr.length;
				var li_str = '<ul class=\"breadcrumb\"><li><a onClick=\"document.getElementById(\'picturescontentframe\').contentWindow.location.href=\'plugins/pictures/photos.php\'\" href=\"#\">Start</a></li>';
				var path = '';
				for (var i = 0; i < len; i++) {	
					path += encodeURIComponent(album_arr[i]);
					li_str += '<li><a onClick=\"document.getElementById(\'picturescontentframe\').contentWindow.location.href=\'plugins/pictures/photos.php?p=' + path + '\'\" href=\"#\">' + album_arr[i] + '</a></li>';
					path += '/';
				}
				li_str += '</ul>';
				$('#bcn', window.parent.document).html(li_str);
				window.parent.document.getElementById('editalbum').classList.remove('disabled');
			}
			else {
				$('#bcn', window.parent.document).html('');
				window.parent.document.getElementById('editalbum').classList.add('disabled');
			}
		}
		
		function count_checks() {
			if(document.querySelectorAll('input[type=\"checkbox\"]:checked').length > 0) {
				window.parent.document.getElementById('movepicture').classList.remove('disabled');
				window.parent.document.getElementById('delpicture').classList.remove('disabled');
			}
			else {
				window.parent.document.getElementById('movepicture').classList.add('disabled');
				window.parent.document.getElementById('delpicture').classList.add('disabled');
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
	global $pictures_path, $rcmail, $label_max_length;
	$thumbdir = rtrim($pictures_path.$requestedDir,'/');
	$current_dir = $thumbdir;
	$forbidden = $rcmail->config->get('skip_objects', false);
	
	if (is_dir($current_dir) && $handle = opendir("${current_dir}")) {
		while (false !== ($file = readdir($handle))) {
			
			if(!in_array($file, $forbidden))
			{
			// Gallery folders
			if (is_dir($current_dir."/".$file)) {
				if ($file != "." && $file != "..") {
					checkpermissions($current_dir."/".$file);
					
					if($requestedDir != "") {
						$requestedDir = rtrim($requestedDir,"/")."/";
					}
					else
						$requestedDir = "";
					
					$arr_params = array('p' => $requestedDir.$file);
					$fparams = http_build_query($arr_params,'','&amp;');
					
					if (file_exists($current_dir.'/'.$file.'/folder.jpg')) {
						$imgParams = http_build_query(array('filename' => "$requestedDir$file/folder.jpg"
															), '', '&amp;');
						
						$imgUrl = "createthumb.php?$imgParams";
					}
					else {
						unset($firstimage);					
						$firstimage = getfirstImage("$current_dir/".$file);
						
						if ($firstimage != "") {
							$params = array('filename' 	=> "$requestedDir$file/$firstimage",
											'folder' 	=> '1');
							$imgParams = http_build_query($params);
							$imgUrl = "createthumb.php?$imgParams";
						}
						else {
							$imgUrl = "images/defaultimage.jpg";
						}
					}
					
					$dirs[] = array("name" => $file,
								"date" => filemtime($current_dir."/".$file),
								"html" => "<a id=\"$requestedDir$file\" href=\"photos.php?$fparams\" onclick=\"album_w('$requestedDir$file')\" title=\"$file\"><img src=\"$imgUrl\" alt=\"$file\" /><span class=\"dropzone\">$file</span><div class=\"progress\"><div class=\"progressbar\"></div></div></a>"
								);
				}
			}
			
			// Gallery images
			if ($file != "." && $file != ".." && $file != "folder.jpg") {
				$filename_caption = "";
				
				$linkUrl = "dphoto.php?file=".str_replace('%2F','/',rawurlencode("$requestedDir/$file"));

				if (preg_match("/.jpeg$|.jpg$|.gif$|.png$/i", $file)) {
					if ($rcmail->config->get('display_exif', false) == 1 && preg_match("/.jpg$|.jpeg$/i", $file)) {
						$exifReaden = readEXIF($current_dir."/".$file);
					} else {
						$exifReaden = array();
					}

					checkpermissions($current_dir."/".$file);
					$imgParams = http_build_query(array('filename' => "$requestedDir/$file"));
					$imgUrl = "createthumb.php?$imgParams";

					$taken = $exifReaden[5];
					$exifInfo = "";
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
					
					if(count(exifReaden) > 0) {
						$caption = "$file<span class='exname'><img src='images/info.png'><div class='exinfo'>$exifInfo</div></span>";
					}
					else {
						$caption = "$file";
					}
					
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "<div><a class=\"image\" href=\"$linkUrl\" data-sub-html=\"$caption\"><img src=\"$imgUrl\" alt=\"$file\" /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>");
				}
				
				// video files
				if (preg_match("/\.ogv$|\.mp4$|\.mpg$|\.mpeg$|\.mov$|\.avi$|\.wmv$|\.flv$|\.webm$/i", $file)) {
					$thmbParams = http_build_query(array('filename' => "$requestedDir/$file"));
					$thmbUrl = "createthumb.php?$thmbParams";
					$videos[] = array("html" => "<div style=\"display: none;\" id=\"".pathinfo($file)['filename']."\"><video class=\"lg-video-object lg-html5\" controls preload=\"none\"><source src=\"$linkUrl\" type=\"video/mp4\"></video></div>");
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "<div><a class=\"image\" data-html=\"#".pathinfo($file)['filename']."\"><img src=\"$thmbUrl\" alt=\"$file\" /><span class='video'></span></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>");
				}
			}
			}
		}
		closedir($handle);
	}
	else {
		error_log('Pictures Plugin(Photos): Could not open "'.htmlspecialchars(stripslashes($current_dir)).'" folder for reading!');
		die("ERROR: Could not open \"".htmlspecialchars(stripslashes($current_dir))."\" folder for reading!");
	}

	// sort folders
	if (sizeof($dirs) > 0) {
		$thumbnails.= "<div id=\"folders\">";
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
		$thumbnails.= "</div>";
	}

	// sort images
	$thumbnails.= "<div id=\"images\" class=\"justified-gallery\">";
	if (sizeof($files) > 0) {
		//$thumbnails.= "<div id=\"images\" class=\"justified-gallery\">";
		foreach ($files as $key => $row) {
			if ($row["name"] == "") {
				unset($files[$key]);
				continue;
			}
			$name[$key] = strtolower($row['name']);
			$date[$key] = strtolower($row['date']);
			$size[$key] = strtolower($row['size']);
		}
		$sorting_files = $rcmail->config->get('sorting_files', false);
		array_multisort($$sorting_files, $rcmail->config->get('sortdir_files', false), $files);
		
		$thumbs_pr_page = $rcmail->config->get("thumbs_pr_page", false);
		if(isset($_GET['page'])) {
			$offset_start = ($_GET["page"] * $thumbs_pr_page) - $thumbs_pr_page;
		} else {
			$offset_start = 0;
		}
		
		$pages = ceil(sizeof($files)/$thumbs_pr_page);
		$gal= $_GET['p'];
		$offset_end = $offset_start + $thumbs_pr_page;
		
		for ($y = $offset_start; $y < $offset_end; $y++) {
			$thumbnails.= "\n".$files[$y]["html"];
		}
		
		$pnavigation = "<div id=\"blindspot\"><div id=\"pnavigation\">";
		for ($page = 1; $page <= $pages; $page++) {
			$pnavigation.= "<a href=\"?p=$gal&page=$page\">$page</a>";
		}
		$pnavigation.= "</div></div>";

		if(sizeof($videos) > 0){
			foreach($videos as $video) {
				$hidden_vid.= $video["html"];
			}
		}
		//$thumbnails.= "</div>";
	}
	$thumbnails.= "</div>";
	$thumbnails.= $hidden_vid.$pnavigation;
	return $thumbnails;
}

function getAllSubDirectories($directory, $directory_seperator = "/") {
	global $rcmail;
	$dirs = array_map(function($item)use($directory_seperator){return $item.$directory_seperator;},array_filter(glob($directory.'*' ),'is_dir'));
	foreach($dirs AS $dir)
	{
		$dirs = array_merge($dirs,getAllSubDirectories($dir,$directory_seperator) );
	}
	asort($dirs);
	return $dirs;
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
		}
		else
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
		}
		else
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
$thumbdir = rtrim($pictures_path.$requestedDir,'/');
$current_dir = $thumbdir;
guardAgainstDirectoryTraversal($current_dir);
?>