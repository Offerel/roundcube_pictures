<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.5.1
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
$swidth = 1920;
$theight = 220;
$workpath = $config['work_path'];
$pictures_path = $config['pictures_path'];

$m = ($mode == 1) ? "Thumbnail":"Picture";

if(isset($file) && !empty($file)) {
	if (!empty($rcmail->user->ID)) {
		$username = $rcmail->user->get_username();
		switch($mode) {
			case 1:
				$pictures_basepath = "$workpath/$username/photos/";
				$path_parts = pathinfo($pictures_basepath.$file);
				$file = $path_parts['dirname'].'/'.$path_parts['filename'].'.webp';
				break;
			default:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
				$file = $pictures_basepath.$file;
				break;
		}
	} else {
		error_log('Login failed. User is not logged in.');
		die();
	}
	
	$file = html_entity_decode($file, ENT_QUOTES);
} else {
	$dbh = $rcmail->get_dbh();
	$res = $dbh->query("SELECT a.`shared_pic_id`, d.`pic_path`, c.`username` FROM `pic_shared_pictures` a INNER JOIN `pic_shares` b ON a.`share_id` = b.`share_id` INNER JOIN `users` c ON b.`user_id` = c.`user_id` INNER JOIN `pic_pictures` d ON a.`pic_id` = d.`pic_id` WHERE a.`shared_pic_id` = $picture");
	$data = $dbh->fetch_assoc($res);
	
	$username = $data['username'];
	$image_basepath = rtrim(str_replace("%u", $username, $config['pictures_path']), '/');
	$thumb_basepath = "$workpath/$username/photos";

	$imagepath = $image_basepath."/".$data['pic_path'];
	$thumbpath = $thumb_basepath."/".$data['pic_path'];
	$thumb_parts = pathinfo($thumbpath);
	$thumbpath = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';

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

	if (strpos($mimeType, 'video/') !== false){
		$type = 1;
	}

	if($m == "Thumbnail") $type = 1;

	$webpfile = str_replace(str_replace('%u', $username, $pictures_path), "$workpath/$username/webp/", $file).".webp";
	
	if((!$type && file_exists($webpfile)) || $type == 5) {
		$type = 5;
		$file = $webpfile;
	}
	
	switch($type) {
		case 1:
			sendHeaders($file, $mimeType, $pathparts['basename'], 'inline');
			readfile($file);
			break;
		case 3:
			sendHeaders($file, 'application/octet-stream', $pathparts['basename'], 'attachment');
			readfile($file);
			break;
		case 4:
			$source = @imagecreatefromjpeg($file);
			sendHeaders($file, 'application/octet-stream', $pathparts['filename'].'.jpg', 'attachment');
			imagejpeg($source, null, 95);
			imagedestroy($source);
			break;
		case 5:
			sendHeaders($file, 'image/webp', $pathparts['filename'].'.webp', 'inline');
			readfile($file);
			break;
		case 6:
			list($owidth, $oheight) = getimagesize($file);
			$image = @imagecreatefromjpeg($file);
			$pres = array(1200,630);
			if ($owidth > $pres[0] || $oheight > $pres[1]) {
				$nwidth = ($owidth > $oheight) ? $pres[0]:ceil($owidth/($oheight/$pres[1]));
				$nheight = ceil($oheight/($owidth/$nwidth));
				$image = imagescale($image, $nwidth);
			}

			$image = imagecrop($image, ['x' => 0, 'y' => ($nheight - $pres[1])/2, 'width' => $pres[0], 'height' => $pres[1]]);
			sendHeaders($file, 'image/jpeg', $pathparts['filename'].'.jpg', 'inline');
			imagejpeg($image, null, 75);
			break;
		default:
			list($owidth, $oheight) = getimagesize($file);
			$image = @imagecreatefromjpeg($file);
			$webp_res = array(1920,1080);

			if ($owidth > $webp_res[0] || $oheight > $webp_res[1]) {
				$nwidth = ($owidth > $oheight) ? $webp_res[0]:ceil($owidth/($oheight/$webp_res[1]));
				$image = imagescale($image, $nwidth);
			}
			
			sendHeaders($file, 'image/jpeg', $pathparts['filename'].'.webp', 'inline');
			imagewebp($image, null, 60);
			imagedestroy($image);
			break;
	}
} else {
	error_log("$m not found: $file");
	die('Not found '."$file");
}

function sendHeaders($file, $mimeType, $filename, $disposition) {
	header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
	header("Cache-Control: private");
	header("Pragma: private");
	header("Content-Type: $mimeType");
	header("Content-Disposition: $disposition; filename=\"$filename\"");
}
?>