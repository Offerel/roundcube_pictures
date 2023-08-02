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
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $username);
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

function read_photos($path, $thumb_basepath, $pictures_basepath, $user) {
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') {
					continue;
				}
				
				if(is_dir($path."/".$file."/")) {
					logm("Change to directory $path/$file/");
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath, $user);
				} else {
					if(in_array(strtolower(pathinfo($file)['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg') {
						createthumb($path."/".$file, $thumb_basepath, $pictures_basepath);
						todb($path."/".$file, $user);
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

function todb($file, $user) {
	global $rcmail;
	$dbh = $rcmail->get_dbh();
	$query = "SELECT `user_id` FROM `users` WHERE `username` = '$user'";
	$res = $dbh->query($query);
	$userid = $dbh->fetch_assoc($res)['user_id'];

	$type = explode('/',mime_content_type($file))[0];
	if($type == 'image') {
		$exif = readEXIF($file);
		$taken = (is_int($exif[5])) ? $exif[5]:filemtime($file);
		$exif = "'".json_encode($exif)."'";
	} else {
		$exif = NULL;
		$ffmpeg = exec("which ffmpeg");
		$meta = shell_exec($ffmpeg." -i ".$file." 2>&1");
		foreach(preg_split("/((\r?\n)|(\r\n?))/", $meta) as $line){
			if(strpos($line, 'creation_time')) $taken = strtotime(explode(' : ', $line)[1]); break;
		}
	}

	$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES (\"$file\",'$type',$taken,$exif,$userid)";
	//echo $query."\n";
	file_put_contents("/tmp/insert.log", $query."\n", FILE_APPEND);
	//$ret = $dbh->query($query);
	//$dbh->insert_id("pic_shares");
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
			$exif_arr[] = strtotime($exif_data['DateTimeDigitized']);
		else
			$exif_arr[] = filemtime($file);
		
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
		} else
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
		} else
			$exif_arr[] = "-";
		
		//13
		if(isset($exif_data['WhiteBalance']))
			$exif_arr[] = $exif_data['WhiteBalance'];
		else
			$exif_arr[] = "-";
		
		//14
		if(isset($exif_data["GPSLatitude"]))
			$exif_arr[] = gps($exif_data["GPSLatitude"], $exif_data['GPSLatitudeRef']);
		else
			$exif_arr[] = "-";
		
		//15
		if(isset($exif_data["GPSLongitude"]))
			$exif_arr[] = gps($exif_data["GPSLongitude"], $exif_data['GPSLongitudeRef']);
		else
			$exif_arr[] = "-";
	}
	return $exif_arr;
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

function gps($coordinate, $hemisphere) {
	if (is_string($coordinate)) {
	  $coordinate = array_map("trim", explode(",", $coordinate));
	}
	for ($i = 0; $i < 3; $i++) {
	  $part = explode('/', $coordinate[$i]);
	  if (count($part) == 1) {
		$coordinate[$i] = $part[0];
	  } else if (count($part) == 2) {
		$coordinate[$i] = floatval($part[0])/floatval($part[1]);
	  } else {
		$coordinate[$i] = 0;
	  }
	}
	list($degrees, $minutes, $seconds) = $coordinate;
	$sign = ($hemisphere == 'W' || $hemisphere == 'S') ? -1 : 1;
	return $sign * ($degrees + $minutes/60 + $seconds/3600);
  }
?>
