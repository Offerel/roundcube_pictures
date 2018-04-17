<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 0.9.0
 * @author Offerel
 * @copyright Copyright (c) 2018, Offerel
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
		error_log('Pictures Plugin: check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if(substr($thumb_path, -1) != '/') {
		error_log('Pictures Plugin: check $config[\'thumb_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_path))
	{
		if(!mkdir($pictures_path, 0774, true)) {
			error_log('Pictures Plugin: Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
}
else {
	error_log('Pictures Plugin: Login failed. User is not logged in.');
	die();
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

if(isset($_POST['getsubs'])) {
	$subdirs = getAllSubDirectories($pictures_path);
	$select = "<select name=\"target\" id=\"target\" size=\"10\" onchange=\"$('#mvb').removeClass('disabled');\">";
	foreach ($subdirs as $dir) {
		$dir = substr($dir,strlen($pictures_path));
		$select.= "<option>".$dir."</option>";
	}
	$select.="</select>";
	die($select);
}

if(isset($_POST['alb_action'])) {
	$action = $_POST['alb_action'];	
	$src = $pictures_path.$_POST['src'];
	
	error_log($action);

	switch($action) {
		case 'move':	$target = $pictures_path.$_POST['target'].basename($src); die(rename($src, $target)); break;
		case 'rename':	$target = dirname($src)."/".trim(trim($_POST['target']),"/"); die(rename($src, $target)); break;
		case 'delete':	die(removeDirectory($src)); break;
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
								mkdir($album_target.$newPath, 0777, true);
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
	echo showPage(showGallery($_GET['p']));
	die();
}
else {
	echo showPage(showGallery(""));
	die();
}

function showPage($thumbnails) {
	$page = "
	<!DOCTYPE html>
	<html>
		<head>
			
			<link rel=\"stylesheet\" href=\"css/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/main.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/lightgallery.min.css\" type=\"text/css\" />
			
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/jquery.justifiedGallery.min.js\"></script>
			<script src=\"js/lightgallery-all.min.js\"></script>";
	$page.= "</head><body onload=\"count_checks();\"><div id=\"galdiv\">";
	$page.= $thumbnails;
	$page.="
	<script>
		$('#folders').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 2,
			border: 0,
			rel: 'folders',
			lastRow: 'justify',
			captions: false,
			randomize: false
		});
	
		$('#images').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 2,
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
					path += album_arr[i];
					li_str += '<li><a onClick=\"document.getElementById(\'picturescontentframe\').contentWindow.location.href=\'plugins/pictures/photos.php?p=' + path + '\'\" href=\"#\">' + album_arr[i] + '</a></li>';
					path += '/';
				}
				li_str += '</ul>';
				console.log(li_str);
				$('#bcn', window.parent.document).html(li_str);
				window.parent.document.getElementById('editalbum').classList.remove('disabled');
			}
			else {
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
					
					if (file_exists($current_dir.'/'.$file.'/folder.jpg')) {
						$imgParams = http_build_query(
							array(
								'filename' => "$file/folder.jpg",
								'size' => $rcmail->config->get("thumb_size", false),
							),
							'',
							'&amp;'
						);
						
						$arr_params = array('p' => $file);
						$fparams = http_build_query($arr_params,'','&amp;');
						
						$imgUrl = "createthumb.php?$imgParams";
						
						$dirs[] = array("name" => $file,
										"date" => filemtime($current_dir."/".$file),
										"html" => "<a href=\"photos.php?$fparams\" onclick=\"album_w('$file')\" title=\"$file\"><img src=\"$imgUrl\" alt=\"$file\" /><span>$file</span></a>"
						);
					}
					else {
						unset($firstimage);					
						$firstimage = getfirstImage("$current_dir/".$file);
						
						if ($requestedDir)
							$path = "$requestedDir/$file/$firstimage";
						else
							$path = "$file/$firstimage";
						
						if ($firstimage != "") {
							$params = array('filename' 	=> $path,
											'size' 		=> $rcmail->config->get("thumb_size", false),
											'folder' 	=> '1');
							$imgParams = http_build_query($params);
							$imgUrl = "createthumb.php?$imgParams";
						}
						else {
							$imgUrl = "images/defaultimage.jpg";
						}
						
						if ($requestedDir)
							$path = "$requestedDir/$file";
						else
							$path = "$file";
						
						$arr_params = array('p' => $path);
						$fparams = http_build_query($arr_params,'','&amp;');
						
						$dirs[] = array("name" => $file,
										"date" => filemtime($current_dir."/".$file),
										"html" => "<a href=\"photos.php?$fparams\" onclick=\"album_w('$path')\" title=\"$path\"><img src=\"$imgUrl\" alt=\"$file\" /><span>$file</span></a>"
									);
					}
				}
			}
			
			// Gallery images
			if ($file != "." && $file != ".." && $file != "folder.jpg") {
				$filename_caption = "";
				
				$linkUrl = "dphoto.php?file=".str_replace('%2F','/',rawurlencode("$requestedDir/$file"));

				if (preg_match("/.jpeg$|.jpg$|.gif$|.png$/i", $file)) {
					if ($rcmail->config->get('display_exif', false) == 1 && preg_match("/.jpg$|.jpeg$/i", $file)) {
						$exifReaden = readEXIF($current_dir."/".$file);
						$img_captions[$file] = basename($file,".jpg").$exifReaden;
					} else {
						$img_captions[$file] = basename($file,".jpg");
						$exifReaden = null;
					}

					checkpermissions($current_dir."/".$file);
					
					$imgParams = http_build_query(array('filename' => "$requestedDir/$file", 
														'size' => $rcmail->config->get('thumb_size', false)));
					$imgUrl = "createthumb.php?$imgParams";
					
					if ($rcmail->config->get('lazyload', false) == 1) {
						$imgopts = "class=\"b-lazy\" src=data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw== data-src=\"$imgUrl\"";
					} else {
						$imgopts = "src=\"$imgUrl\"";
					}
					
					$exif_arr = explode(" | ",$exifReaden);

					$taken = $exif_arr[5];					
					
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						"html" => "<div><a class=\"image\" href=\"$linkUrl\"><img $imgopts alt=\"$file\" /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>");
				}
				
				// video files
				if (preg_match("/\.ogv$|\.mp4$|\.mpg$|\.mpeg$|\.mov$|\.avi$|\.wmv$|\.flv$|\.webm$/i", $file)) {
					$thmbParams = http_build_query(array('filename' => "$requestedDir/$file",
														 'size' => $rcmail->config->get('thumb_size', false)));
					$thmbUrl = "createthumb.php?$thmbParams";
					$videos[] = array("html" => "<div style=\"display: none;\" id=\"".pathinfo($file)['filename']."\"><video class=\"lg-video-object lg-html5\" controls preload=\"none\"><source src=\"$linkUrl\" type=\"video/mp4\"></video></div>");
					$files[] = array(
						"name" => $file,
						"date" => $taken,
						"size" => filesize($current_dir."/".$file),
						//"html" => "<a class=\"image\" data-html=\"#".pathinfo($file)['filename']."\"><img src=\"$thmbUrl\" alt=\"$file\" /></a>");
						"html" => "<div><a class=\"image\" data-html=\"#".pathinfo($file)['filename']."\"><img src=\"$thmbUrl\" alt=\"$file\" /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\"></div>");
				}
			}
			}
		}
		closedir($handle);
	}
	else {
		error_log('Pictures Plugin: Could not open \"'.htmlspecialchars(stripslashes($current_dir)).'\" folder for reading!');
		die("ERROR: Could not open \"".htmlspecialchars(stripslashes($current_dir))."\" folder for reading!");
	}
	/*
	$breadcrumb_nav = explode("/",$requestedDir);
	$path = "";
	$bcn = "<ul class=\"breadcrumb\"><li><a href='photos.php' onclick=\"album_w('')\" title=\"".$rcmail->gettext('home','pictures')."\">".$rcmail->gettext('home','pictures')."</a></li>";
	foreach ($breadcrumb_nav as $nav_part) {
		if($nav_part != "") {
			$path.= $nav_part."/";
			$bcn.= "<li><a href='photos.php?p=$path' title=\"$path\">$nav_part</a></li>";
		}
	}
	$bcn.= "</ul>";
	$thumbnails.= "<div id=\"bc\">$bcn</div>";
	*/
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
		array_multisort($dirs, $rcmail->config->get('sortdir_folders', false), $name);
		foreach ($dirs as $folder) {
			$thumbnails.= $folder["html"];
			$start++;
		}
		$thumbnails.= "</div>";
	}

	// sort images
	if (sizeof($files) > 0) {
		$thumbnails.= "<div id=\"images\">";
		foreach ($files as $key => $row) {
			if ($row["name"] == "") {
				unset($files[$key]);
				continue;
			}
			$name[$key] = strtolower($row['name']);
			$date[$key] = strtolower($row['date']);
			$size[$key] = strtolower($row['size']);
		}
		array_multisort($files, $rcmail->config->get('sortdir_files', false), $date);
		$start = 0;
		foreach ($files as $image) {
			$thumbnails.= "\n".$image["html"];
			$start++;
		}
		if(sizeof($videos) > 0){
			foreach($videos as $video) {
				$hidden_vid.= $video["html"];
			}
		}
		$thumbnails.= "</div>";
	}

	$thumbnails.= $hidden_vid;
	return $thumbnails;
}

function getAllSubDirectories($directory, $directory_seperator = "/") {
	$dirs = array_map(function($item)use($directory_seperator){return $item.$directory_seperator;},array_filter(glob($directory.'*' ),'is_dir'));
	foreach($dirs AS $dir)
	{
		$dirs = array_merge($dirs,getAllSubDirectories($dir,$directory_seperator) );
	}
	asort($dirs);
	return $dirs;
}

if (!function_exists('exif_read_data') && $rcmail->config->get('display_exif', false) == 1) {
	error_log('Pictures Plugin: PHP EXIF is not available. Set display_exif = 0; in config to remove this message');
}

function padstring($name, $length) {
	global $label_max_length;
	if (!isset($length)) {
		$length = $label_max_length;
	}
	if (strlen($name) > $length) {
		return substr($name, 0, $length) . "...";
	}
	return $name;
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
			$exif_arr[] = date("d.m.Y H:i",strtotime($exif_data['DateTimeDigitized']));
		else
			$exif_arr[] = date("d.m.Y H:i",filemtime($file));
		
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
		
		if (count($exif_arr) > 0) {
			$exif_str = "::".implode(" | ", $exif_arr);
			return $exif_str;
		}
	}
	return $exif_arr;
}

function checkpermissions($file) {
	global $messages;

	if (!is_readable($file)) {
		error_log('Pictures Plugin: Can\'t read image $file, check your permissions.');
	}
}

function guardAgainstDirectoryTraversal($path) {
    $pattern = "/^(.*\/)?(\.\.)(\/.*)?$/";
    $directory_traversal = preg_match($pattern, $path);

    if ($directory_traversal === 1) {
		error_log('Pictures Plugin: Could not open \"'.htmlspecialchars(stripslashes($current_dir)).'\" for reading!');
        die("ERROR: Could not open directory \"".htmlspecialchars(stripslashes($current_dir))."\" for reading!");
    }
}

$thumbdir = rtrim($pictures_path.$requestedDir,'/');
$current_dir = $thumbdir;

guardAgainstDirectoryTraversal($current_dir);
?>