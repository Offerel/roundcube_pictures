<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.16
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

$m = ($mode == 1) ? "Thumbnail":"Picture";

if(isset($file) && !empty($file)) {
	if (!empty($rcmail->user->ID)) {
		$username = $rcmail->user->get_username();
		switch($mode) {
			case 1:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('thumb_path', false)),'/').'/';
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
	
	if(strpos($mimeType, 'image/jp') === false){
		$type = 0;
	}
	
	switch($type) {
		case 1:
			// JPEG
			$filesize = filesize($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: $mimeType");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$filesize);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die(readfile($file));
			break;
		case 2:
			// JPEG, 80 Quality
			$image = imagecreatefromjpeg($file);
			ob_start();
			imagejpeg($image, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/jpeg");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die($ImageData);
			break;
		case 3:
			// webp, 80 Quality
			$image = imagecreatefromjpeg($file);
			ob_start();
			imagewebp($image, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/webp");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die($ImageData);
			break;
		case 4:
			//imagick
			if(class_exists('Imagick')) {
				$image = new Imagick();
				$image->readImage($file);
				$image->setImageFormat('webp');
				$image->setImageCompressionQuality(80);
				$image->setOption('webp:lossless', 'true');
				header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
				header("Content-Type: image/webp");
				header('Accept-Ranges: bytes');
				header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
				die($image);
			} else {
				die("Imagick Not supported");
			}
			break;
		case 5:
			$image = imagecreatefromjpeg($file);
			$img = imagescale($image, $swidth);
			
			ob_start();
			imagejpeg($img, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/jpeg");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die($ImageData);
			break;
		case 6:
			//Rescale, webpb
			list($owidth, $oheight) = getimagesize($file);
			
			$image = imagecreatefromjpeg($file);
			$exif = exif_read_data($file);
			
			switch($exif['Orientation']) {
				case 3:
					$degrees = 180;
					$rotate = true;
					break;
				case 6:
					$degrees = 270;
					$rotate = true;
					break;
				case 8:
					$degrees = 90;
					$rotate = true;
					break;
				default:
					$degrees = 90;
					$rotate = false;
			}
			
			if($rotate) {
				$image = imagerotate($image, $degrees, 0);
			}
			
			if($owidth > $swidth) {
				$mult = $owidth/$swidth;
				$img = (!$rotate) ? imagescale($image, $swidth):imagescale($image, round($oheight/$mult));
			}

			ob_start();
			imagewebp($img, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/webp");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file).'.webp','.').'"');
			die($ImageData);
			break;
		case 7:
			// imagecopyresampled, jpeg
			list($owidth, $oheight) = getimagesize($file);
			$mult = $owidth / $swidth;
			$sheight = round($oheight / $mult);
			
			$image_p = imagecreatetruecolor($swidth, $sheight);
			$image = imagecreatefromjpeg($file);
			imagecopyresampled($image_p, $image, 0, 0, 0, 0, $swidth, $sheight, $owidth, $oheight);
			
			ob_start();
			imagejpeg($image_p, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/jpeg");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die($ImageData);
			break;
		case 8:
			// imagecopyresampled, webp
			list($owidth, $oheight) = getimagesize($file);
			$mult = $owidth / $swidth;
			$sheight = round($oheight / $mult);
			
			$image_p = imagecreatetruecolor($swidth, $sheight);
			$image = imagecreatefromjpeg($file);
			imagecopyresampled($image_p, $image, 0, 0, 0, 0, $swidth, $sheight, $owidth, $oheight);
			
			ob_start();
			imagewebp($image_p, null, 80);
			$ImageData = ob_get_contents();
			$ImageDataLength = ob_get_length();
			ob_end_clean();
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header("Content-Type: image/webp");
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$ImageDataLength);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die($ImageData);
			break;
		default:
			$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
			$webp_path = rtrim(str_replace("%u", $username, $rcmail->config->get('webp_path', false)),'/').'/';
			$webp_file = str_replace($pictures_basepath, $webp_path, $file).".webp";

			if(file_exists($webp_file)) {
				$file = $webp_file;
				header("Content-Type: image/webp");
			} else {
				header("Content-Type: imag/jpeg");
			}
			
			$filesize = filesize($file);
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
			header('Accept-Ranges: bytes');
			header("Content-Length: ".$filesize);
			header('Content-disposition: inline;filename="'.ltrim(basename($file),'.').'"');
			die(readfile($file));
			break;
	}
} else {
	error_log("Pictures: $m not found: $file");
	die('Not found '."$file");
}
?>