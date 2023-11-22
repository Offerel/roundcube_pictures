<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.15
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
require INSTALL_PATH.'program/include/clisetup.php';
$starttime = time();
$mode = isset($argv[1]) ? $argv[1]:"";
$rcmail = rcube::get_instance();
$ffprobe = exec("which ffprobe");
$ffmpeg = exec("which ffmpeg");
$users = array();
$thumbsize = $rcmail->config->get('thumb_size', false);
$dfiles = $rcmail->config->get('dummy_files', false);
$mtime = $rcmail->config->get('dummy_time', false);
$hevc = $rcmail->config->get('convert_hevc', false);
$ccmd = $rcmail->config->get('convert_cmd', false);
$db = $rcmail->get_dbh();
$result = $db->query("SELECT username, user_id FROM users;");
$rcount = $db->num_rows($result);

for ($x = 0; $x < $rcount; $x++) {
	array_push($users, $db->fetch_assoc($result));
}

logm("Starting maintenance...");

foreach($users as $user) {
	$username = $user["username"];
	$uid = $user["user_id"];
	$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)), '/');
	$thumb_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('thumb_path', false)), '/');
	$webp_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('webp_path', false)), '/');
	$db->query("DELETE FROM `pic_broken` WHERE `user_id` = $uid");
	$broken = array();
	switch($mode) {
		case "add":
			logm("Checking $username with mode 'add'");
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $user["user_id"], $webp_basepath);
			break;
		case "clean":
			logm("Checking $username with mode 'clean'");
			if(is_dir($thumb_basepath)) {
				$path = $thumb_basepath;
				read_thumbs($path, $thumb_basepath, $pictures_basepath);
			}

			if(is_dir($webp_basepath)) {
				$path = $webp_basepath;
				read_webp($path, $webp_basepath, $pictures_basepath);
			}
			break;
		default:
			logm("Search pictures for $username");
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $user["user_id"], $webp_basepath);

			if(is_dir($thumb_basepath)) {
				$path = $thumb_basepath;
				read_thumbs($path, $thumb_basepath, $pictures_basepath);
			}

			if(is_dir($webp_basepath)) {
				$path = $webp_basepath;
				read_webp($path, $webp_basepath, $pictures_basepath);
			}
			
			rmexpires();
			break;
	}
	
	if(count($broken) > 0) {
		foreach($broken as $picture) {
			$db->query("INSERT INTO `pic_broken` (`pic_path`, `user_id`) VALUES (\"$picture\",$uid)");
		}
	}
}

$endtime = time();
$tdiff = gmdate("H:i:s", $endtime - $starttime);
logm("Maintenance finished after $tdiff.");
die();

function logm($message, $mmode = 3) {
	global $rcmail;
	$dtime = date("d.m.Y H:i:s");
	$logfile = $rcmail->config->get('log_dir', false)."/maintenance.log";
	$debug = $rcmail->config->get('debug', false);
	switch($mmode) {
		case 1: $msmode = " [ERRO] "; break;
		case 2: $msmode = " [WARN] "; break;
		case 3: $msmode = " [INFO] "; break;
		case 4: $msmode = " [DBUG] "; break;
	}

	if(!$debug && $mmode > 3) {
		return;
	} else {
		$line = $dtime.$msmode.$message."\n";
	}
	echo $line;
	file_put_contents($logfile, $line, FILE_APPEND);
}

function read_photos($path, $thumb_basepath, $pictures_basepath, $user, $webp_basepath) {
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') continue;
				if(is_dir($path."/".$file."/")) {
					logm("Parse directory $path/$file/", 4);
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath, $user, $webp_basepath);
				} else {
					$pathparts = pathinfo($path."/".$file);
					if(isset($pathparts['extension']) && in_array(strtolower($pathparts['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg' && filesize($path."/".$file) > 0) {
						$exifArr = createthumb($path."/".$file, $thumb_basepath, $pictures_basepath);
						if (in_array(strtolower($pathparts['extension']), array("jpg", "jpeg"))) create_webp($path."/".$file, $pictures_basepath, $webp_basepath, $exifArr);
						todb($path."/".$file, $user, $pictures_basepath, $exifArr);
						checkorphaned($path."/".$file);
					}
				}
			}
			closedir($handle);
		}
	}
}

function read_thumbs($path, $webp_basepath, $picture_basepath) {
	if($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') continue;
			
			if(is_dir($path."/".$file."/")) {
				read_thumbs($path."/".$file, $thumb_basepath, $picture_basepath);
				if(count(glob($path."/".$file."/*")) === 0) {
					rmdir($path."/".$file);
				}
			} else {
				delete_asset($path."/".$file, $thumb_basepath, $picture_basepath);
			}
		}
		closedir($handle);
	}
}

function read_webp($path, $webp_basepath, $picture_basepath) {
	if($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') continue;
			
			if(is_dir($path."/".$file."/")) {
				read_webp($path."/".$file, $webp_basepath, $picture_basepath);
				if(count(glob($path."/".$file."/*")) === 0) {
					rmdir($path."/".$file);
				}
			} else {
				delete_asset($path."/".$file, $webp_basepath, $picture_basepath);
			}
		}
		closedir($handle);
	}
}

function delete_asset($image, $asset_basepath, $picture_basepath) {
	$image = str_replace('//','/',$image);
	$org_pinfo = pathinfo(str_replace($asset_basepath, $picture_basepath, $image));
	if(!file_exists($org_pinfo['dirname']."/".$org_pinfo['filename'])) {
		unlink($image);
		logm("Delete thumbnail/webp $image", 4);
	}
}

function create_webp($ofile, $pictures_basepath, $webp_basepath, $exif) {
	global $rcmail;
	$swidth = rtrim(str_replace("%u", $username, $rcmail->config->get('swidth', false)), '/');
	
	$webp_file = str_replace($pictures_basepath, $webp_basepath, $ofile).'.webp';

	if(file_exists($webp_file)) return false;

	list($owidth, $oheight) = getimagesize($ofile);
	$image = imagecreatefromjpeg($ofile);
	//$exif = exif_read_data($ofile);
	
	switch($exif['16']) {
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
			$degrees = 0;
			$rotate = false;
	}
	
	if($rotate) $image = imagerotate($image, $degrees, 0);
	
	if($owidth > $swidth) {
		$mult = $owidth/$swidth;
		$img = (!$rotate) ? imagescale($image, $swidth):imagescale($image, round($oheight/$mult));
	} else {
		$img = $image;
	}

	
	$directory = dirname($webp_file);
	if(!file_exists($directory)) mkdir($directory, 0755 ,true);
	imagewebp($img, $webp_file, 80);
}

function createthumb($image, $thumb_basepath, $pictures_basepath) {
	global $thumbsize, $ffmpeg, $dfiles, $hevc, $broken, $ccmd;
	$org_pic = str_replace('//','/',$image);
	$thumb_pic = str_replace($pictures_basepath,$thumb_basepath,$org_pic).".jpg";
	if($dfiles) deldummy($org_pic);

	if(file_exists($thumb_pic)) {
		logm("Ignoring: $org_pic > Thumbnail exists", 4);
		return false;
	}

	$target = "";
	$degrees = 0;
	$ppath = "";
	$type = explode('/',mime_content_type($org_pic))[0];

	if(!in_array($type, ['image','video'])) {
		logm("Unsupported: $org_pic", 4);
		return false;
	}
	
	$thumbpath = pathinfo($thumb_pic)['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			logm("Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.", 2);
		}
	}

	$exifArr = [];

	if ($type == "image") {
		logm("createthumb: $org_pic is image", 4);
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
			$exifArr = readEXIF($org_pic);
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
		logm("createthumb: $org_pic is video", 4);
		if(!empty($ffmpeg)) {
			exec($ffmpeg." -i \"".$org_pic."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumb_pic."\" 2>&1");
			$vcodec = exec("ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$org_pic\"");
			if($hevc && "$vcodec" != "hevc") return false;
			$pathparts = pathinfo($org_pic);
			$hidden_vid = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
			if(!file_exists($hidden_vid)) {
				$startconv = time();
				logm("Convert to $hidden_vid", 4);
				$ccmd = str_replace("%f", $ffmpeg, str_replace("%i", $org_pic, str_replace("%o", $hidden_vid, $ccmd)));
				exec($ccmd);
				$diff = time() - $startconv;
				$cdiff = gmdate("H:i:s", $diff);
				logm("Video file ($org_pic) converted within $cdiff ($diff sec)", 4);
			}
		} else {
			logm("ffmpeg is not installed, so video formats are not supported.", 1);
		}
	}
	return $exifArr;
}

function todb($file, $user, $pictures_basepath, $exif) {
	global $rcmail, $ffprobe, $db;
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');
	$result = $db->query("SELECT count(*) FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	if($db->fetch_array($result)[0] == 0) {
		logm("Add $file to db", 4);
		$type = explode('/',mime_content_type($file))[0];
		if($type == 'image') {
			//$exif = readEXIF($file);
			$taken = (isset($exif[5]) && is_int($exif[5])) ? $exif[5]:filemtime($file);
			$exif = "'".json_encode($exif,  JSON_HEX_APOS)."'";
		} else {
			$exif = 'NULL';
			$taken = shell_exec("$ffprobe -v quiet -select_streams v:0  -show_entries stream_tags=creation_time -of default=noprint_wrappers=1:nokey=1 \"$file\"");
			$taken = (empty($taken)) ? filemtime($file):strtotime($taken);
		}

		$db->startTransaction();
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES ('$ppath','$type',$taken,$exif,$user)";
		$db->query($query);
		if($db->is_error()) {
			sleep(1);
			$db->query($query);
			$db->endTransaction();
		} else {
			$db->endTransaction();
		}
	} else {
		logm("Ignoring: $file > Exists in db", 4);
	}
}

function checkorphaned($file) {
	$pathparts = pathinfo("$file");
	$filename = $pathparts['basename'];
	if (strpos($filename, '.') === 0) {
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

		if(isset($exif_data['DateTimeDigitized']) && strpos($exif_data['DateTimeDigitized'], '0000') !== 0) {
			$exif_arr[5] = strtotime($exif_data['DateTimeDigitized']);
		} elseif (isset($exif_data['DateTimeOriginal']) && strpos($exif_data['DateTimeOriginal'], '0000') !== 0) {
			$exif_arr[5] = strtotime($exif_data['DateTimeOriginal']);
		} elseif (isset($exif_data['DateTime']) && strpos($exif_data['DateTime'], '0000') !== 0) {
			$exif_arr[5] = strtotime($exif_data['DateTime']);
		} else {
			$exif_arr[5] = $exif_data['FileDateTime'];
		}

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
		$exif_arr[16] = (isset($exif_data['Orientation'])) ? $exif_data['Orientation']:"-";
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