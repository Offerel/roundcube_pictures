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
include INSTALL_PATH . 'program/include/iniset.php';
include_once('config.inc.php');
@ini_set('gd.jpeg_ignore_warning', 1);
$rcmail = rcmail::get_instance();
$dbh = $rcmail->get_dbh();
/*
if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_basepath = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$thumb_basepath = str_replace("%u", $username, $rcmail->config->get('thumb_path', false));
	$get_size = $rcmail->config->get('thumb_size', false);
	
	if(substr($pictures_basepath, -1) != '/') {
		error_log('Pictures Plugin(Thumbs): check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if(substr($thumb_basepath, -1) != '/') {
		error_log('Pictures Plugin(Thumbs): check $config[\'thumb_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_basepath)) {
		if(!mkdir($pictures_basepath, 0755, true)) {
			error_log('Pictures Plugin(Thumbs): Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
} else {
	error_log('Pictures Plugin(Thumbs): User is not logged in.');
	die();
}
*/
$picture = $_GET['p'];
$mode = $_GET['t'];
$query = "SELECT a.pictureID, a.picturePath, c.username FROM pic_pictures a INNER JOIN pic_shares b ON a.shareID = b.shareID INNER JOIN users c ON b.user_id = c.user_id WHERE a.pictureID = $picture";
$res = $dbh->query($query);
$rc = $dbh->num_rows($res);
$data = $dbh->fetch_assoc($res);

$username = $data['username'];
$image_basepath = rtrim(str_replace("%u", $username, $config['pictures_path']), '/');
$thumb_basepath = rtrim(str_replace("%u", $username, $config['thumb_path']), '/');

$imagepath = $image_basepath."/".$data['picturePath'];
$thumbpath = $thumb_basepath."/".$data['picturePath'].".jpg";

//$imageparts = pathinfo($imagepath);
//$thumbparts = pathinfo($thumbpath);

switch($mode) {
	case 1:
		$path = $thumbpath;
		break;
	case 2:
		$path = $imagepath;
		break;
	default:
		die('Unsupported Mode');
		break;
}

if(file_exists($path)) {
	$content = file_get_contents($path);
	$ct = mime_content_type($path);
	header("Content-Type: $ct");
	die($content);
}
die();
if ($get_filename_type != "MP4") {
	ob_start();
	header("Content-Type: image/jpeg");
	@imagejpeg($target, null, 80);
	@imagedestroy($target);
	$cachedImage = ob_get_contents();
	ob_end_flush();

	$path = pathinfo($thumbname)['dirname'];

	if (!is_dir($path))
	{
		if(!mkdir($path, 0755, true)) {
			error_log("Pictures Plugin(Thumbs): Thumbnail subfolder creation failed ($path). Please check your directory permissions.");
		}
	}
	
	if(is_writable(dirname($thumbname))) {
		$fd = fopen($thumbname, "w");
		if ($fd) {
			fwrite($fd, $cachedImage);
			fclose($fd);
		}
	}
	else 
	{
		error_log("Pictures Plugin(Thumbs): Can't write Thumbnail (".dirname($thumbname)."). Please check your directory permissions.");
		die("Can't write Thumbnail (".dirname($thumbname)."). Please check your directory permissions.");
	}
} else {
	$avconv = exec("which avconv");
	if($avconv == "" ) {
		$avconv = exec("which ffmpeg");
	}

	if(file_exists($avconv)) {
		$cmd = $avconv." -i \"".$get_filename."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$get_size."\" \"".$thumbname."\" 2>&1";
		exec($cmd);
		if(!$cli) {
			imagecreatefromjpeg($thumbname);
			header("Content-Type: image/jpeg");
			imagejpeg($thumbnail);
			imagedestroy($thumbnail);
		}
	}
	else 
	{
		error_log("Pictures Plugin(Thumbs): ffmpeg or avconv not installed, so video formats are supported.");
	}
}
