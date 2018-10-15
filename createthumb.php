<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.0
 * @author Offerel
 * @copyright Copyright (c) 2018, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
@ini_set('gd.jpeg_ignore_warning', 1);
$rcmail = rcmail::get_instance();

// Login
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
	
	if (!is_dir($pictures_basepath))
	{
		if(!mkdir($pictures_basepath, 0755, true)) {
			error_log('Pictures Plugin(Thumbs): Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
}
else {
	error_log('Pictures Plugin(Thumbs): User is not logged in.');
	die();
}

$get_filename = $_GET['filename'];
if (preg_match("/^\/.*/i", $get_filename)) {
	error_log("Pictures Plugin(Thumbs): Unauthorized access! Filepath ($get_filename) has potential risky chars inside.");
	die("Unauthorized access!");
}

$get_filename = $pictures_basepath.$get_filename;

if (preg_match("/.jpe?g$/i", $get_filename)) {
	$get_filename_type = "JPG";
}

if (preg_match("/.gif$/i", $get_filename)) {
	$get_filename_type = "GIF";
}

if (preg_match("/.png$/i", $get_filename)) {
	$get_filename_type = "PNG";
}

if (preg_match("/.mp4$/i", $get_filename)) {
	$get_filename_type = "MP4";
}

function folderimg($image, $size) {
	global $thumb_basepath, $pictures_basepath;
	$thmb_path = substr($image, strlen($pictures_basepath)).".jpg";
	$thumbname = $thumb_basepath.$thmb_path;

	if (file_exists($thumbname)) {
		$fd = fopen($thumbname, "r");
		$cacheContent = fread($fd, filesize($thumbname));
		fclose($fd);
		header("Content-Type: image/jpeg");
		echo ($cacheContent);
		exit;
	}
	else {
		$tsize = $size;
		$image = imagecreatefromstring(file_get_contents($image));
		$oldW = ImageSX($image);
		$oldH = ImageSY($image);
		$limiting_dim = 0;
		if( $oldH > $oldW ){
			$newW = $tsize;
			$newH = $oldH * ( $tsize / $newW );
			$limiting_dim = $oldW;
		} else 
		{
			$newH = $tsize;
			$newW = $oldW * ( $tsize / $newH );
			$limiting_dim = $oldH;
		}

		$new = imageCreateTrueColor($tsize,$tsize);
		imagecopyresampled( $new , $image , 0 , 0 , ($oldW-$limiting_dim )/2 , ( $oldH-$limiting_dim )/2 , $tsize , $tsize , $limiting_dim , $limiting_dim );
		header("Content-Type: image/jpeg");
		ImageJpeg($new);
		imagedestroy($new);
		imagedestroy($image);
	}
	die();
}

if (isset($_GET['folder'])) {
	folderimg($get_filename, $get_size);
}

if (!is_dir($thumb_basepath) && is_writable($thumb_basepath)) {
	mkdir($thumb_basepath, 0700, true);
	error_log("Pictures Plugin(Thumbs): Thumbnail folder does not exist, creating folder ($thumb_basepath) automatically.");
}

$thumbname = $thumb_basepath.substr($get_filename, strlen($pictures_basepath)).".jpg";
if (file_exists($thumbname)) {
	$fd = fopen($thumbname, "r");
	$cacheContent = fread($fd, filesize($thumbname));
	fclose($fd);
	header("Content-Type: image/jpeg");
	echo ($cacheContent);
	exit;
}

if (!is_file($get_filename)) {
	error_log("Pictures Plugin(Thumbs): Image ($get_filename) not found.");
	exit;
}

if (!is_readable($get_filename)) {
	error_log("Pictures Plugin(Thumbs): Image ($get_filename) can't be opened.");
	exit;
}

$target = "";
$xoord = 0;
$yoord = 0;

if (preg_match("/.jpg$|.jpeg$|.png$|.tif$/i", $get_filename)) {
	$imgsize = getimagesize($get_filename);
	$width = $imgsize[0];
	$height = $imgsize[1];
	
	if ($width > $height) {
		$xoord = ceil(($width - $height) / 2);
	} else {
		$yoord = ceil(($height - $width) / 2);
	}

	$degrees = 0;
	$flip = '';

	if (function_exists('exif_read_data') && function_exists('imagerotate')) {
		if (preg_match("/.jpg$|.jpeg$/i", $get_filename)) {
			$exif = @exif_read_data($get_filename, 0, true);
			if(isset($exif['IFD0']['Orientation'])) {
				$ort = $exif['IFD0']['Orientation'];
				switch ($ort) {
					case 3:	// 180 rotate right
						$degrees = 180;
						break;
					case 6:	// 90 rotate right
						$degrees = 270;
						break;
					case 8:	// 90 rotate left
						$degrees = 90;
						break;
					case 2:	// flip vertical
						$flip = 'vertical';
						break;
					case 7:	// flipped
						$degrees = 90;
						$flip = 'vertical';
						break;
					case 5:	// flipped
						$degrees = 270;
						$flip = 'vertical';
						break;
					case 4:	// flipped
						$degrees = 180;
						$flip = 'vertical';
						break;
				}
			}
		}
	} 
	else {
		error_log("Pictures Plugin(Thumbs): PHP functions exif_read_data() and imagerotate() are not available, check your PHP installation.");
	}
	
	$newwidth = ceil($width / ($height / $get_size));
	if($newwidth <= 0) {
		error_log("Pictures Plugin(Thumbs): Calculating the width ($newwidth) of \"$get_filename\" failed.");
	}

	$target = imagecreatetruecolor($newwidth, $get_size);
}

if (in_array($get_filename_type, array("GIF", "PNG"))) {
	$backgroundColor = imagecolorallocate($target, 255, 255, 255);
	imagefill($target, 0, 0, $backgroundColor);
}

if ($get_filename_type == "JPG") {
	$source = imagecreatefromjpeg($get_filename);
}

if ($get_filename_type == "GIF") {
	$source = imagecreatefromgif($get_filename);
}

if ($get_filename_type == "PNG") {
	$source = imagecreatefrompng($get_filename);
}

if (in_array($get_filename_type, array("GIF", "PNG", "JPG"))) {
	imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $get_size, $width, $height);
	imagedestroy($source);
}

if ($degrees != 0) {
	$target = imagerotate($target, $degrees, 0);
}

if ($flip == 'vertical') {
	ImageJPEG(imageflip($target, IMG_FLIP_VERTICAL),null,80);
}

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
}
else {
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
