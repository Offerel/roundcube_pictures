<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.8
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
require INSTALL_PATH.'program/include/clisetup.php';
$starttime = time();
$mode = $argv[1];

$rcmail = rcube::get_instance();
$ffprobe = exec("which ffprobe");
$ffmpeg = exec("which ffmpeg");
$users = array();
$thumbsize = $rcmail->config->get('thumb_size', false);
$dfiles = $rcmail->config->get('dummy_files', false);
$mtime = $rcmail->config->get('dummy_time', false);
$cvideo = $rcmail->config->get('convert_video', false);
$hevc = $rcmail->config->get('convert_hevc', false);

$db = $rcmail->get_dbh();
$result = $db->query("SELECT username, user_id FROM users;");
$rcount = $db->num_rows($result);

for ($x = 0; $x < $rcount; $x++) {
	array_push($users, $db->fetch_assoc($result));
}

foreach($users as $user) {
	$username = $user["username"];
	$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)), '/');
	$thumb_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('thumb_path', false)), '/');	
	$broken = array();
	switch($mode) {
		case "add":
			logm("Maintenance for $username with mode add");
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $user["user_id"]);
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
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $user["user_id"]);
			if(is_dir($thumb_basepath)) {
				$path = $thumb_basepath;
				read_thumbs($path, $thumb_basepath, $pictures_basepath);
			}
			rmexpires();
			break;
	}
	
	if(count($broken) > 0) {
		$headers['From'] = "Pictures<noreply@pictures.org>";
		$headers['Content-Type'] = "text/plain; charset=\"UTF-8\"";
		$message = "There was an error processing your pictures. The following pictures appear to be corrupted. Please delete or fix the image:\n\n";
		$message = wordwrap($message, 70);
		foreach($broken as $picture) {
			$message.= $picture."\n";
		}
		mail($username, 'Pictures Error', $message, $headers);
	}
}

$endtime = time();
$tdiff = gmdate("H:i:s", $endtime - $starttime);
logm("Maintenance finished after $tdiff.");
die();

function logm($message, $mmode = 3) {
	global $rcmail, $mode;
	$dtime = date("d.m.Y H:i:s");
	$logfile = $rcmail->config->get('log_dir', false)."/maintenance.log";
	switch($mmode) {
		case 1: $msmode = " [ERRO] "; break;
		case 2: $msmode = " [WARN] "; break;
		case 3: $msmode = " [INFO] "; break;
		case 4: $msmode = " [DBUG] "; break;
	}

	if($mode != 'debug' && $mmode > 3) {
		return;
	} else {
		$line = $dtime.$msmode.$message."\n";
	}
	echo $line;
	file_put_contents($logfile, $line, FILE_APPEND);
}

function read_photos($path, $thumb_basepath, $pictures_basepath, $user) {
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp","ogv");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') continue;
				if(is_dir($path."/".$file."/")) {
					logm("Parse directory $path/$file/", 4);
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath, $user);
				} else {
					$pathparts = pathinfo($path."/".$file);
					if(isset($pathparts['extension']) && in_array(strtolower($pathparts['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg' && filesize($path."/".$file) > 0) {
						createthumb($path."/".$file, $thumb_basepath, $pictures_basepath);
						todb($path."/".$file, $user, $pictures_basepath);
						checkogv($path."/".$file);
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
			if($file === '.' || $file === '..') continue;
			
			if(is_dir($path."/".$file."/")) {
				read_thumbs($path."/".$file, $thumb_basepath, $picture_basepath);
				if(count(glob($path."/".$file."/*")) === 0) {
					rmdir($path."/".$file);
				}
			} else {
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
		logm("Delete thumbnail $thumbnail", 4);
	}
}

function createthumb($image, $thumb_basepath, $pictures_basepath) {
	global $thumbsize, $ffmpeg, $dfiles, $cvideo, $hevc, $broken;
	$org_pic = str_replace('//','/',$image);
	$thumb_pic = str_replace($pictures_basepath,$thumb_basepath,$org_pic).".jpg";
	if($dfiles) deldummy($org_pic);
	if(file_exists($thumb_pic)) return false;
	$target = "";
	$degrees = 0;
	$ppath = "";
	$type = explode('/',mime_content_type($org_pic))[0];
	if(!in_array($type, ['image','video'])) return false;
	
	$thumbpath = pathinfo($thumb_pic)['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			logm("Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.", 2);
		}
	}

	if ($type == "image") {
		list($width, $height, $type) = getimagesize($org_pic);

		$newwidth = ceil($width * $thumbsize / $height);
		if($newwidth <= 0) logm("Calculating the width failed.", 2);
		$target = imagecreatetruecolor($newwidth, $thumbsize);
		switch ($type) {
			case 1: $source = @imagecreatefromgif($org_pic); break;
			case 2: $source = @imagecreatefromjpeg($org_pic); break;
			case 3: $source = @imagecreatefrompng($org_pic); break;
			default: logm("Unsupported fileformat ($org_pic $type).", 1); die();
		}

		logm("Create thumbnail for: $org_pic", 4);
		if ($source) {
			imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $thumbsize, $width, $height);
			imagedestroy($source);
			$exif = @exif_read_data($org_pic, 0, true);
			$ort = (isset($exif['IFD0']['Orientation'])) ? $ort = $exif['IFD0']['Orientation']:NULL;
			switch ($ort) {
				case 3: $degrees = 180; break;
				case 4: $degrees = 180; break;
				case 5: $degrees = 270; break;
				case 6: $degrees = 270; break;
				case 7: $degrees = 90; break;
				case 8: $degrees = 90; break;
			}
			if ($degrees != 0) $target = imagerotate($target, $degrees, 0);
			if(is_writable($thumbpath)) {
				imagejpeg($target, $thumb_pic, 80);
			} else {
				logm("Can't write Thumbnail ($thumbpath). Please check your directory permissions.", 1);
			}
		} else {
			$ppath = str_replace($pictures_basepath, '', $org_pic);
			$broken[] = $ppath;
			logm("Can't create thumbnail for $ppath. Picture is broken",1);
		}
	} elseif ($type == "video") {
		if(!empty($ffmpeg)) {
			exec($ffmpeg." -i \"".$org_pic."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumb_pic."\" 2>&1");
			if($cvideo) {
				$vcodec = exec("ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$org_pic\"");
				if($hevc && "$vcodec" != "hevc") return false;
				$pathparts = pathinfo($org_pic);
				$ogv = $pathparts['dirname']."/.".$pathparts['filename'].".ogv";
				if(!file_exists($ogv)) {
					$startconv = time();
					logm("Convert to $ogv", 4);
					exec("$ffmpeg -loglevel quiet -i \"$org_pic\" -c:v libtheora -q:v 7 -c:a libvorbis -q:a 4 \"$ogv\"");
					$diff = time() - $startconv;
					$cdiff = gmdate("H:i:s", $diff);
					logm("OGV file ($org_pic) converted within $cdiff ($diff sec)", 4);
				}
			}
		} else {
			logm("ffmpeg is not installed, so video formats are not supported.", 1);
		}
	}
	return $ppath;
}

function todb($file, $user, $pictures_basepath) {
	global $rcmail, $ffprobe, $db;
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');

	$result = $db->query("SELECT count(*) FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	if($db->fetch_array($result)[0] == 0) {
		$type = explode('/',mime_content_type($file))[0];
		if($type == 'image') {
			$exif = readEXIF($file);
			$taken = (is_int($exif[5])) ? $exif[5]:filemtime($file);
			$exif = "'".json_encode($exif,  JSON_HEX_APOS)."'";
		} else {
			$exif = 'NULL';
			$taken = shell_exec("$ffprobe -v quiet -select_streams v:0  -show_entries stream_tags=creation_time -of default=noprint_wrappers=1:nokey=1 \"$file\"");
			$taken = (empty($taken)) ? filemtime($file):strtotime($taken);
		}

		if ($db->db_provider == 'sqlite') $db->startTransaction();
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES (\"$ppath\",'$type',$taken,$exif,$user)";
		$db->query($query);
		if ($db->db_provider == 'sqlite') $db->endTransaction();
	}
}

function checkogv($file) {
	$pathparts = pathinfo("$file");
	if($pathparts['extension'] == 'ogv') {
		$ofile = $pathparts['dirname'].'/'.ltrim($pathparts['filename'],'.').'.*';
		$flist = glob($ofile);
		if (count($flist) == 0) {
			logm("Delete orphaned file $file");
			unlink($file);
		}
	}
}

function rmexpires() {
	global $db;
	$atime = time();
	$result = $db->query("DELETE FROM `pic_shares` WHERE `expire_date` < $atime");
}

function deldummy($file) {
	global $mtime;
	$dtime = time() - filemtime($file);
	$fsize = filesize($file);
	if ($dtime > $mtime && $fsize < 1) {
		unlink($file);
		logm("Delete dummy file $file ($fsize bytes)", 4);
	}
}

function readEXIF($file) {
	global $rcmail;
	$exif_arr = array();
	$exif_data = @exif_read_data($file);

	if($exif_data && count($exif_data) > 0) {
		$exif_arr[0] = (isset($exif_data['Model'])) ? $exif_data['Model']:"-";
		$exif_arr[1] = (isset($exif_data['FocalLength'])) ? parse_fraction($exif_data['FocalLength']) . "mm":"-";
		$exif_arr[2] = (isset($exif_data['FocalLength'])) ? parse_fraction($exif_data['FocalLength'], 2) . "s":"-";
		$exif_arr[3] = (isset($exif_data['FNumber'])) ? "f" . parse_fraction($exif_data['FNumber']):"-";
		$exif_arr[4] = (isset($exif_data['ISOSpeedRatings'])) ? $exif_data['ISOSpeedRatings']:"-";
		$exif_arr[5] = (isset($exif_data['DateTimeDigitized'])) ? strtotime($exif_data['DateTimeDigitized']):filemtime($file);
		$exif_arr[6] = (isset($exif_data['ImageDescription'])) ? $exif_data['ImageDescription']:"-";
		$exif_arr[7] = (isset($exif_data['CALC-GPSLATITUDE-SIG'])) ? $exif_data['CALC-GPSLATITUDE-SIG']:"-";
		$exif_arr[8] = (isset($exif_data['Make'])) ? $exif_data['Make']:"-";
		$exif_arr[9] = (isset($exif_data['Software'])) ? $exif_data['Software']:"-";
		
		if(isset($exif_data['ExposureProgram'])) {
			switch ($exif_data['ExposureProgram']) {
				case 0: $exif_arr[10] = $rcmail->gettext('exif_undefined','pictures'); break;
				case 1: $exif_arr[10] = $rcmail->gettext('exif_manual','pictures'); break;
				case 2: $exif_arr[10] = $rcmail->gettext('exif_exposure_auto','pictures'); break;
				case 3: $exif_arr[10] = $rcmail->gettext('exif_time_auto','pictures'); break;
				case 4: $exif_arr[10] = $rcmail->gettext('exif_shutter_auto','pictures'); break;
				case 5: $exif_arr[10] = $rcmail->gettext('exif_creative_auto','pictures'); break;
				case 6: $exif_arr[10] = $rcmail->gettext('exif_action_auto','pictures'); break;
				case 7: $exif_arr[10] = $rcmail->gettext('exif_portrait_auto','pictures'); break;
				case 8: $exif_arr[10] = $rcmail->gettext('exif_landscape_auto','pictures'); break;
				case 9: $exif_arr[10] = $rcmail->gettext('exif_bulb','pictures'); break;
			}
		} else
			$exif_arr[10] = "-";

		$exif_arr[11] = (isset($exif_data['Flash'])) ? $exif_data['Flash']:"-";
		
		if(isset($exif_data['MeteringMode'])) {
			switch ($exif_data['MeteringMode']) {
				case 0: $exif_arr[12] = $rcmail->gettext('exif_unkown','pictures'); break;
				case 1: $exif_arr[12] = $rcmail->gettext('exif_average','pictures'); break;
				case 2: $exif_arr[12] = $rcmail->gettext('exif_middle','pictures'); break;
				case 3: $exif_arr[12] = $rcmail->gettext('exif_spot','pictures'); break;
				case 4: $exif_arr[12] = $rcmail->gettext('exif_multi-spot','pictures'); break;
				case 5: $exif_arr[12] = $rcmail->gettext('exif_multi','pictures'); break;
				case 6: $exif_arr[12] = $rcmail->gettext('exif_partial','pictures'); break;
				case 255: $exif_arr[12] = $rcmail->gettext('exif_other','pictures'); break;
			}
		} else
			$exif_arr[12] = "-";
		
		$exif_arr[13] = (isset($exif_data['WhiteBalance'])) ? $exif_data['WhiteBalance']:"-";
		$exif_arr[14] = (isset($exif_data["GPSLatitude"])) ? gps($exif_data["GPSLatitude"], $exif_data['GPSLatitudeRef']):"-";
		$exif_arr[15] = (isset($exif_data["GPSLongitude"])) ? gps($exif_data["GPSLongitude"], $exif_data['GPSLongitudeRef']):"-";
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