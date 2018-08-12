<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.1.1
 * @author Offerel
 * @copyright Copyright (c) 2018, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_basepath = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	
	if(substr($pictures_basepath, -1) != '/') {
		error_log('Pictures Plugin(Picture): check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_basepath))
	{
		if(!mkdir($pictures_basepath, 0755, true)) {
			error_log('Pictures Plugin(Picture): Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
}
else {
	error_log('Pictures Plugin(Picture): Login failed. User is not logged in.');
	die();
}

$get_filename = $_GET['file'];
if (preg_match("/^\/.*/i", $get_filename)) {
	error_log("Pictures Plugin(Picture): Unauthorized access! filepath ($get_filename) has forbidden chars inside.");
	die("Unauthorized access!");
}
$file = $pictures_basepath.$get_filename;
 
if (file_exists($file)) {
	$mtype = mime_content_type($file);
	$fp = @fopen($file, 'rb');
	$size = filesize($file);
	$length = $size;
	$start = 0;
	$end = $size - 1;
	header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
	header("Content-Type: $mtype");
	if($mtype != "image/jpeg") {
		header("Accept-Ranges: bytes");
		
		if (isset($_SERVER['HTTP_RANGE'])) {
			$c_start = $start;
			$c_end = $end;
			list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
			
			if (strpos($range, ',') !== false) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header("Content-Range: bytes $start-$end/$size");
				exit;
			}
			
			if ($range == '-') {
				$c_start = $size - substr($range, 1);
			}
			else{
				$range  = explode('-', $range);
				$c_start = $range[0];
				$c_end   = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
			}
			
			$c_end = ($c_end > $end) ? $end : $c_end;
			
			if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header("Content-Range: bytes $start-$end/$size");
				exit;
			}
			
			$start = $c_start;
			$end = $c_end;
			$length = $end - $start + 1;
			fseek($fp, $start);
			header('HTTP/1.1 206 Partial Content');
		}
		header("Content-Range: bytes $start-$end/$size");
		header("Content-Length: ".$length);
		$buffer = 1024 * 5;
		
		while(!feof($fp) && ($p = ftell($fp)) <= $end) {
			if ($p + $buffer > $end) {
				$buffer = $end - $p + 1;
			}
			
			set_time_limit(0);
			echo fread($fp, $buffer);
			flush();
		}
	}
	else {
		$filename = basename($file);
		header("Content-Disposition: inline; filename=$filename");
		$degrees = 0;
		$flip = '';
		
		if (function_exists('exif_read_data') && function_exists('imagerotate')) {
			$exif = @exif_read_data($file,0,true);
			
			if(isset($exif['IFD0']['Orientation'])) {
				$or = $exif['IFD0']['Orientation'];
				switch ($or) {
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
		else {
			error_log("Pictures Plugin(Picture): PHP functions exif_read_data() and imagerotate() are not available, check your PHP installation.");
		}
		
		$target = imagecreatefromjpeg($file);
		
		if ($degrees != 0) {
			$target = imagerotate($target, $degrees, 0);
		}
		
		if ($flip == 'vertical') {
			imagejpeg(imageflip($target, IMG_FLIP_VERTICAL),null,80);
		}
		else {
			imagejpeg($target, null, 80);
		}
		
		imagedestroy($target);
	}
	
	fclose($fp);
	exit;
}
?>