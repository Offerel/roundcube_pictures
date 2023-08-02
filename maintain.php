<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.4
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
require INSTALL_PATH.'program/include/clisetup.php';
$starttime = time();
$mode = $argv[1];

$rcmail = rcube::get_instance();
$users = array();
$thumbsize = $rcmail->config->get('thumb_size', false);

$db = $rcmail->get_dbh();
$result = $db->query("SELECT username FROM users;");
$rcount = $db->num_rows($result);

for ($x = 0; $x < $rcount; $x++) {
	$users[] = $db->fetch_array($result)[0];
}

foreach($users as $username) {
	$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)), '/');
	$thumb_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('thumb_path', false)), '/');	

	switch($mode) {
		case "add":
			logm("Maintenance for $username with mode add");
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath);
			break;
		case "clean":
			logm("Maintenance for $username with mode clean");
			if(is_dir($thumb_basepath)) {
				$path = $thumb_basepath;
				read_thumbs($path, $thumb_basepath, $pictures_basepath);
			}
			break;
		default:
			logm("Complete Maintenance for $username");
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath);
			if(is_dir($thumb_basepath)) {
				$path = $thumb_basepath;
				read_thumbs($path, $thumb_basepath, $pictures_basepath);
			}
			break;
	}
}

$endtime = time();
$tdiff = gmdate("H:i:s", $endtime - $starttime);
logm("Maintenance finished after $tdiff.");
die();

function logm($message, $mode = 4) {
	global $rcmail;
	$dtime = date("d.m.Y H:i:s");
	switch($mode) {
		case 1: $mode = " [ERRO] ";
				break;
		case 2: $mode = " [WARN] ";
				break;
		default: $mode = " [INFO] ";
				break;
	}
	echo $message."\n";
	$line = $dtime.$mode.$message."\n";
	$logfile = $rcmail->config->get('log_dir', false)."/maintenance.log";
	file_put_contents($logfile, $line, FILE_APPEND);
}

function read_photos($path, $thumb_basepath, $pictures_basepath) {
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') {
					continue;
				}
				
				if(is_dir($path."/".$file."/")) {
					logm("Change to directory $path/$file/");
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath);
				} else {
					if(in_array(strtolower(pathinfo($file)['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg') {
						createthumb($path."/".$file, $thumb_basepath, $pictures_basepath);
					}
				}
			}
			closedir($handle);
		}
	}
}

function read_thumbs($path, $thumb_basepath, $picture_basepath) {
	if($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') {
				continue;
			}
			
			if(is_dir($path."/".$file."/")) {
				read_thumbs($path."/".$file, $thumb_basepath, $picture_basepath);
				if(count(glob($path."/".$file."/*")) === 0) {
					rmdir($path."/".$file);
				}
			}
			else {
				deletethumb($path."/".$file, $thumb_basepath, $picture_basepath);
			}
		}
		closedir($handle);
	}
}

function deletethumb($thumbnail, $thumb_basepath, $picture_basepath) {
	$thumbnail = str_replace('//','/',$thumbnail);
	
	$org_pinfo = pathinfo(str_replace($thumb_basepath, $picture_basepath, $thumbnail));
	if(!file_exists($org_pinfo['dirname']."/".$org_pinfo['filename'])) {
		unlink($thumbnail);
	}
}

function createthumb($image, $thumb_basepath, $pictures_basepath) {
	global $thumbsize;
	$org_pic = str_replace('//','/',$image);
	$thumb_pic = str_replace($pictures_basepath,$thumb_basepath,$org_pic).".jpg";
	if(file_exists($thumb_pic)) {
		return false;
	}
	$target = "";
	$degrees = 0;
	$flip = '';
	
	$thumbpath = pathinfo($thumb_pic)['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			logm("Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.", 2);
		}
	}

	if (preg_match("/.jpg$|.jpeg$|.png$/i", $org_pic)) {
		list($width, $height, $type) = getimagesize($org_pic);		
		$newwidth = ceil($width * $thumbsize / $height);
		if($newwidth <= 0) logm("Calculating the width failed.", 2);

		$target = imagecreatetruecolor($newwidth, $thumbsize);
		
		switch ($type) {
			case 1: $source = @imagecreatefromgif($org_pic); break;
			case 2: $source = @imagecreatefromjpeg($org_pic); break;
			case 3: $source = @imagecreatefrompng($org_pic); break;
			default: logm("Unsupported fileformat ($org_pic $type).", 2); die();
		}
		
		imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $thumbsize, $width, $height);
		imagedestroy($source);
		
		if(is_writable($thumbpath)) {
			imagejpeg($target, $thumb_pic, 80);
		} else {
			logm("Can't write Thumbnail ($thumbpath). Please check your directory permissions.", 2);
		}
	} elseif(preg_match("/.mp4$|.mpg$|.3gp$/i", $org_pic)) {
		$ffmpeg = exec("which ffmpeg");
		if(file_exists($ffmpeg)) {
			$pathparts = pathinfo($org_pic);
			$ogv = $pathparts['dirname']."/.".$pathparts['filename'].".ogv";			
			exec($ffmpeg." -i \"".$org_pic."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumb_pic."\" 2>&1");
			$startconv = time();
			exec("$ffmpeg -loglevel quiet -i $org_pic -c:v libtheora -q:v 7 -c:a libvorbis -q:a 4 $ogv");
			$diff = time() - $startconv;
			$cdiff = gmdate("H:i:s", $diff);
			logm("OGV file converted within $diff ($cdiff)");
		} else {
			logm("ffmpeg is not installed, so video formats are not supported.", 2);
		}
	}
}
?>
