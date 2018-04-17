<?php
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

// Login
if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_basepath = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	
	if(substr($pictures_basepath, -1) != '/') {
		error_log('Pictures Plugin: check $config[\'pictures_path\'], the path must end with a backslash.');
		die();
	}
	
	if (!is_dir($pictures_basepath))
	{
		if(!mkdir($pictures_basepath, 0774, true)) {
			error_log('Pictures Plugin: Creating subfolders for $config[\'pictures_path\'] failed. Please check your directory permissions.');
			die();
		}
	}
}
else {
	error_log('Pictures Plugin: Login failed. User is not logged in.');
	die();
}

$get_filename = $_GET['file'];
if (preg_match("/^\/.*/i", $get_filename)) {
	error_log("Pictures Plugin: Unauthorized access! filepath ($get_filename) has forbidden chars inside.");
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
	header("Content-Type: $mtype");
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
	fclose($fp);
	exit;
}
?>