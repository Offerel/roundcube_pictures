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
include_once('config.inc.php');

if(isset($_SERVER['HTTP_REFERER'])) {
	if(!strpos($_SERVER['HTTP_REFERER'],$_SERVER['HTTP_HOST'])) {
		die(http_response_code(405));
	}
}

@ini_set('gd.jpeg_ignore_warning', 1);
$rcmail = rcmail::get_instance();

if($rcmail->config->get('debug', false)) error_log(print_r($_GET, true));

$picture = isset($_GET['p']) ? filter_var($_GET['p'], FILTER_SANITIZE_NUMBER_INT):NULL;
$mode = isset($_GET['t']) ? filter_var($_GET['t'], FILTER_SANITIZE_NUMBER_INT):NULL;
$file = isset($_GET['file']) ? filter_var($_GET['file'], FILTER_SANITIZE_FULL_SPECIAL_CHARS):NULL;
$type = isset($_GET['w']) ? filter_var($_GET['w'], FILTER_SANITIZE_NUMBER_INT):0;
$swidth = $rcmail->config->get('swidth', false);
$theight = $rcmail->config->get('thumb_size', false);
$workpath = $rcmail->config->get('work_path', false);
$pictures_path = $rcmail->config->get('pictures_path', false);

$m = ($mode == 1) ? "Thumbnail":"Picture";

if(isset($file) && !empty($file)) {
	if (!empty($rcmail->user->ID)) {
		$username = $rcmail->user->get_username();
		switch($mode) {
			case 1:
				$pictures_basepath = "$workpath/$username/photos/";
				$ext = ".jpg";
				break;
			case 2:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
				$ext = "";
				break;
			default:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
				$ext = "";
				break;
		}
	} else {
		error_log('Pictures: Login failed. User is not logged in.');
		die();
	}

	$file = html_entity_decode($pictures_basepath.$file.$ext, ENT_QUOTES);
} else {
	$dbh = $rcmail->get_dbh();
	$res = $dbh->query("SELECT a.`shared_pic_id`, d.`pic_path`, c.`username` FROM `pic_shared_pictures` a INNER JOIN `pic_shares` b ON a.`share_id` = b.`share_id` INNER JOIN `users` c ON b.`user_id` = c.`user_id` INNER JOIN `pic_pictures` d ON a.`pic_id` = d.`pic_id` WHERE a.`shared_pic_id` = $picture");
	$data = $dbh->fetch_assoc($res);
	
	$username = $data['username'];
	$image_basepath = rtrim(str_replace("%u", $username, $config['pictures_path']), '/');
	$thumb_basepath = rtrim(str_replace("%u", $username, $config['thumb_path']), '/');

	$imagepath = $image_basepath."/".$data['pic_path'];
	$thumbpath = $thumb_basepath."/".$data['pic_path'].".jpg";

	switch($mode) {
		case 1:
			$file = $thumbpath;
			break;
		case 2:
			$file = $imagepath;
			break;
		default:
			$file = $imagepath;
			break;
	}
}

if(file_exists($file)) {
	$mimeType = mime_content_type($file);
	$pathparts = pathinfo($file);
	if(strpos($mimeType, 'video') !== false) {
		$hvpath = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
		if(file_exists($hvpath)) {
			$mimeType = mime_content_type($hvpath);
			$file = $hvpath;
		}
	}
	
	if(strpos($mimeType, 'image/jpeg') === false){
		$type = 0;
	}

	if($m == "Thumbnail") $type = 1;

	//todo: if exists > view generated webp (5), if not > actual default 
	
	switch($type) {
		case 1:	// Load generated Thumbnail
			$filesize = filesize($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Cache-Control: private");
			header("Pragma: private");
			header("Content-Type: $mimeType");
			header('Content-Disposition: inline; filename="'.$pathparts['filename'].'"');
			header("Content-length: $filesize");
			readfile($file);
			break;
		case 3:	// Download in Album
			$filesize = filesize($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Cache-Control: private");
			header("Pragma: private");
			header("Content-Type: $mimeType");
			header('Content-Disposition: attachment; filename="'.$pathparts['basename'].'"');
			header("Content-length: $filesize");
			readfile($file);
			break;
		case 4:	// Download in Share
			$source = @imagecreatefromjpeg($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Cache-Control: private");
			header("Pragma: private");
			header("Content-Type: image/jpeg");
			header('Content-Disposition: attachment; filename="'.$pathparts['filename'].'.jpg"');
			ob_start();
			imagejpeg($source, null, 100);
			header("Content-length: ".ob_get_length());
			ob_flush();
			imagedestroy($source);
			break;
		case 5:	// view generated webp
			$pictures_path = str_replace('%u',$username,$pictures_path);
			$file = str_replace($pictures_path,"$workpath/$username/webp/",$file).".webp";
			$filesize = filesize($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Cache-Control: private");
			header("Pragma: private");
			header("Content-Type: image/webp");
			header('Content-Disposition: inline; filename="'.$pathparts['filename'].'.webp"');
			header("Content-length: $filesize");
			readfile($file);
			break;
		default: // View scaled
			list($width, $height) = getimagesize($file);
			$nwidth = ($width > $height) ? $swidth:$width / ($height / $swidth);
			$source = @imagecreatefromjpeg($file);
			$target = imagescale($source, ceil($nwidth), -1);
			imagedestroy($source);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Cache-Control: private");
			header("Pragma: private");
			header("Content-Type: image/jpeg");
			header('Content-Disposition: inline; filename="'.$pathparts['filename'].'.jpg"');
			ob_start();
			imagejpeg($target, null, 50);
			header("Content-length: ".ob_get_length());
			ob_flush();
			imagedestroy($target);
			break;
	}
} else {
	error_log("Pictures: $m not found: $file");
	die('Not found '."$file");
}
?>