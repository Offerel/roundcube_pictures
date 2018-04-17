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
/*
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
*/
error_log("Logged IN!!!!");
die();

error_reporting(-1);
require_once "config-default.php";
include_once "config.php";
set_error_handler("myErrorHandler");
$start_t = time();

$photos = "photos";
$thumbs = dirname(__FILE__)."/thumbs";

if ($argv[1] == "clean") {
	$del_files = array();
	$del_files = clean_thumbs($thumbs);
	$end_t = time();
	$diff_t = $end_t - $start_t;
	if(count($del_files) > 0) {
		$message = "Hello,\n\nThe thumbnail clean-up script was run from ".date("d.m.Y H:i:s",$start_t)." until ".date("d.m.Y H:i:s",$end_t)." and took ".date("H:i:s",$diff_t).". The script has found ".count($del_files)." orphaned thumbnails and deleted them. For details, which thumbnails are deleted, you can look in the logfile at ".$log_path;
		mail($receiver, "Thumbnail clean-up", $message);
	}
}
elseif ($argv[1] == "add") {
	$files = array();
	$errff = array();
	$errff = read_photos($photos);
	$end_t = time();

	$datetime1 = new DateTime("@$start_t");
	$datetime2 = new DateTime("@$end_t");
	$interval = date_diff($datetime1, $datetime2);
	$timestr = "";
	foreach ($interval as $key => $val){
		if( $val ) {
		$timestr.= " ".$val."%".$key;
		}
	}
	$timestr = str_replace ( "%d", " days" , $timestr );
	$timestr = str_replace ( "%h", " hours" , $timestr );
	$timestr = str_replace ( "%i", " minutes" , $timestr );
	$timestr = str_replace ( "%s", " seconds" , $timestr );
	
	if(count($errff) > 0) {
		$errcount = count($errff);
		$errortext = "For $errcount pictures the thumbnails couldn't be created. Attached you find a list of this pictures.";
		$attfile = "add_errors.log";
		file_put_contents($attfile, implode("\r\n", $errff));
	}
	$message = "Hello,\r\n\r\nThe thumbnail add script was run from ".date("d.m.Y H:i:s",$start_t)." until ".date("d.m.Y H:i:s",$end_t)." and took $timestr. $errortext\r\n\r\nYou can find the logfile at $log_path";

	mail_att("sebastian@pfohlnet.de", "Thumbnail Script", $message, "add_errors.log");
}

function clean_thumbs($path) {
	global $del_files;
	$files = array();
	
	if ($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') {
				continue;
			}
			if(is_dir($path."/".$file."/")) {
				$files[$file] = clean_thumbs($path."/".$file."");
			}
			else {
				$files[] = $path."/".$file;
				$npath = str_replace("thumbs/","",$path)."/".pathinfo($file)['filename'].".*";
				$img_match = glob($npath);
				if(count($img_match) < 1) {
					$del_files[] = $path."/".$file;
					unlink($path."/".$file);
					errorlog("Thumbnail (".$path."/".$file.") was deleted by cleanup Script","cleanup");
				}
			}
		}
		closedir($handle);
	}
	return $del_files;
}

function read_photos($path) {
	$files = array();
	global $errff;
	chdir(dirname(__FILE__));
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if ($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') {
				continue;
			}
			
			if(is_dir($path."/".$file."/")) {
				$files[$file] = read_photos($path."/".$file."");
			}
			else {
				if ( in_array( strtolower(pathinfo($file)['extension']), $support_arr ) ) {
					$files[] = $path."/".$file;
					if (exec("/usr/bin/php ".dirname(__FILE__)."/createthumb.php \"".$path."/".$file."\" 220")) {
						$errff[] = $path."/".$file;
					}
				}
			}
		}
		closedir($handle);
	}
	return $errff;
}

function mail_att($to, $subject, $message, $dateien) {   
   if(!is_array($dateien)) {
      $dateien = array($dateien);
   }   
   
   $attachments = array();
   foreach($dateien AS $key => $val) {
      if(is_int($key)) {
        $datei = $val;
        $name = basename($datei);
     } else {
        $datei = $key;
        $name = basename($val);
     }
     
      $size = filesize($datei);
      $data = file_get_contents($datei);
      $type = mime_content_type($datei);
     
      $attachments[] = array("name"=>$name, "size"=>$size, "type"=>$type, "data"=>$data);
   }
 
   $mime_boundary = "-----=" . md5(uniqid(microtime(), true));
 
   $header = "MIME-Version: 1.0\r\n";
   $header.= "Content-Type: multipart/mixed;\r\n";
   $header.= " boundary=\"".$mime_boundary."\"\r\n";
 
   $encoding = mb_detect_encoding($message, "utf-8, iso-8859-1, cp-1252");
   $content = "This is a multi-part message in MIME format.\r\n\r\n";
   $content.= "--".$mime_boundary."\r\n";
   $content.= "Content-Type: text/plain; charset=\"$encoding\"\r\n";
   $content.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
   $content.= $message."\r\n";
 
   foreach($attachments AS $dat) {
         $data = chunk_split(base64_encode($dat['data']));
         $content.= "--".$mime_boundary."\r\n";
         $content.= "Content-Disposition: attachment;\r\n";
         $content.= "\tfilename=\"".$dat['name']."\";\r\n";
         $content.= "Content-Length: .".$dat['size'].";\r\n";
         $content.= "Content-Type: ".$dat['type']."; name=\"".$dat['name']."\"\r\n";
         $content.= "Content-Transfer-Encoding: base64\r\n\r\n";
         $content.= $data."\r\n";
   }
   $content .= "--".$mime_boundary."--"; 
   
   return mail($to, $subject, $content, $header);
}
?>