<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.20
 * @author Offerel
 * @copyright Copyright (c) 2024, Offerel
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
$broken = array();
$thumbsize = 220;
$dfiles = $rcmail->config->get('dummy_files', false);
$mtime = $rcmail->config->get('dummy_time', false);
$hevc = $rcmail->config->get('convert_hevc', false);
$ccmd = $rcmail->config->get('convert_cmd', false);
$exif_mode = $rcmail->config->get('exif');
$db = $rcmail->get_dbh();
$result = $db->query("SELECT username, user_id FROM users;");
$rcount = $db->num_rows($result);
$images = array();
$tempstore = array();
$basepath = rtrim($rcmail->config->get('work_path', false), '/');
$pntfy = $rcmail->config->get('pntfy_sec');

for ($x = 0; $x < $rcount; $x++) {
	array_push($users, $db->fetch_assoc($result));
}

logm("Starting maintenance...");

foreach($users as $user) {
	$username = $user["username"];
	$uid = $user["user_id"];
	$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)), '/');
	$thumb_basepath = $basepath."/".$username."/photos";
	$webp_basepath =  $basepath."/".$username."/webp";
	$db->query("DELETE FROM `pic_broken` WHERE `user_id` = $uid");

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
			logm("Search media for $username");
			$tempstore = (file_exists($basepath."/tempstore.json")) ? json_decode(file_get_contents($basepath."/tempstore.json"),true):array();

			foreach($tempstore as $element) {
				if($uid == $element[1]) {
					$images[] = $element[0];
				}
			}

			if(count($images) > 0) logm("The last maintenance was not completed properly. ".count($images)." images found in temporary store", 2);
			read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath, $user["user_id"], $webp_basepath);
			logm("read_photos finished after ".etime($starttime), 4);
			
			if($exif_mode == 2 && count($images) > 0) {
				exiftool($images, $uid);
				$images = [];
				$tempstore = [];
				unlink($basepath."/tempstore.json");
				logm("Temporary list store removed", 4);
			}

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

$message = "Pictures maintenance finished in ".etime($starttime);
$message.= (count($broken) > 0) ? ". ".count($broken)." corrupt media found.":"";
logm($message);

$authHeader = base64_encode($rcmail->config->get('pntfy_usr').":".$rcmail->config->get('pntfy_pwd'));
$authHeader = (strlen($authHeader) > 4) ? "Authorization: Basic $authHeader\r\n":'';
$purl = $rcmail->config->get('pntfy_url');

if($pntfy && etime($starttime,true) > $pntfy && strlen($purl) > 4) {
	$rarr = json_decode(file_get_contents($purl, false, stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' =>
				"Content-Type: text/plain\r\n".
				$authHeader.
				"Title: Roundcube Pictures\r\n".
				"Priority: 3\r\n".
				"Tags: Roundcube,Pictures",
			'content' => $message."\r\n\r\nFor details please check maintenance.log"
		]
	])), true);

	if(isset($rarr['id'])) 
		logm("ntfy push succesfully send", 4);
	else
		logm("ntfy push failed.", 2);
}

function etime($starttime, $s = false) {
	if($s) return time() - $starttime;
	return gmdate("H:i:s", time() - $starttime);
}

function logm($message, $mmode = 3) {
	global $rcmail;
	$dtime = date("Y-m-d H:i:s");
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
	global $exif_mode;
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') continue;
				if(is_dir($path."/".$file."/")) {
					logm("Check directory $path/$file/", 4);
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath, $user, $webp_basepath);
				} else {
					$pathparts = pathinfo($path."/".$file);
					if(isset($pathparts['extension']) && in_array(strtolower($pathparts['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg' && filesize($path."/".$file) > 0) {
						$exifArr = createthumb("$path/$file", $thumb_basepath, $pictures_basepath, $user);
						
						if(is_array($exifArr)) {
							if (in_array(strtolower($pathparts['extension']), array("jpg", "jpeg"))) create_webp($path."/".$file, $pictures_basepath, $webp_basepath, $exifArr);
							if($exif_mode != 2) todb($path."/".$file, $user, $pictures_basepath, $exifArr);
						}
						
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
				read_thumbs($path."/".$file, $webp_basepath, $picture_basepath);
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
	$webp_res = array(1920,1080);
	$webp_file = str_replace($pictures_basepath, $webp_basepath, $ofile).'.webp';
	logm("Create webp $webp_file", 4);
	$otime = filemtime($ofile);
	if($otime == filemtime($webp_file)) return false;
	list($owidth, $oheight) = getimagesize($ofile);
	$image = imagecreatefromjpeg($ofile);

	switch($exif['Orientation']) {
		case 3:
			$degrees = 180;
			break;
		case 6:
			$degrees = 270;
			break;
		case 8:
			$degrees = 90;
			break;
		default:
			$degrees = 0;
	}

	if($degrees > 0) $image = imagerotate($image, $degrees, 0);		

	if ($owidth > $webp_res[0] || $oheight > $webp_res[1]) {
		$nwidth = ($owidth > $oheight) ? $webp_res[0]:ceil($owidth/($oheight/$webp_res[1]));
		$img = imagescale($image, $nwidth);
	} else {
		$img = $image;
	}

	$directory = dirname($webp_file);
	if(!file_exists($directory)) mkdir($directory, 0755 ,true);
	imagewebp($img, $webp_file, 70);
	imagedestroy($img);
	imagedestroy($image);
	touch($webp_file, $otime);
}

function images($image, $uid) {
	global $basepath, $images, $tempstore;
	$tempstore[] = array($image, $uid);
	$images[] = $image;
	file_put_contents($basepath."/tempstore.json", json_encode($tempstore, true));
}

function createthumb($image, $thumb_basepath, $pictures_basepath, $uid) {
	global $thumbsize, $ffmpeg, $dfiles, $hevc, $broken, $ccmd, $exif_mode, $ffprobe;
	$org_pic = realpath($image);

	$thumb_pic = str_replace($pictures_basepath, $thumb_basepath, $org_pic);
	$thumb_parts = pathinfo($thumb_pic);
	$thumb_pic = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.jpg';

	if($dfiles) deldummy($org_pic);

	$otime = filemtime($org_pic);
	if(file_exists($thumb_pic)) {
		if($otime == filemtime($thumb_pic)) {
			logm("Ignore $org_pic", 4);
			return false;
		} else {
			logm("ReParse $org_pic", 4);
		}
	} else {
		logm("$thumb_pic does not exists", 4);
	}
	
	$mimetype = mime_content_type($org_pic);
	images($org_pic, $uid);
	$target = "";
	$degrees = 0;
	$ppath = "";
	$type = explode('/', $mimetype)[0];
	
	if(!in_array($type, ['image','video'])) {
		logm("Unsupported: $org_pic", 4);
		return false;
	}
	
	$thumbpath = $thumb_parts['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			logm("Thumbnail subfolder creation failed ($thumbpath). Please check your directory permissions.", 2);
		}
	}

	$exifArr = [];
	$exifArr['MIMEType'] = $mimetype;
	logm("Create thumbnail $thumb_pic", 4);

	if ($type == "image") {
		list($width, $height, $itype) = getimagesize($org_pic, $info);
		$newwidth = ceil($width * $thumbsize / $height);
		if($newwidth <= 0) logm("Calculate width failed.", 2);

		switch ($itype) {
			case 1: $source = @imagecreatefromgif($org_pic); break;
			case 2: $source = @imagecreatefromjpeg($org_pic); break;
			case 3: $source = @imagecreatefrompng($org_pic); break;
			default: logm("Unsupported file $org_pic", 1);
		}

		if ($source) {
			$target = imagescale($source, $newwidth, -1, IMG_GENERALIZED_CUBIC);
			imagedestroy($source);

			if($exif_mode == 1) {
				$exifArr = readEXIF($org_pic);
				$ort = $exifArr['Orientation'];
			} else {
				$exif_data = @exif_read_data($org_pic);
				$ort = $exif_data['Orientation'];
				$exifArr['Orientation'] = $ort;
			}

			switch ($ort) {		
				case 3: $degrees = 180; break;		
				case 4: $degrees = 180; break;		
				case 5: $degrees = 270; break;		
				case 6: $degrees = 270; break;		
				case 7: $degrees = 90; break;		
				case 8: $degrees = 90; break;		
				default: $degrees = 0;		
			}		
					
			if ($degrees != 0) $target = imagerotate($target, $degrees, 0);		

			if(is_writable($thumbpath)) {
				imagejpeg($target, $thumb_pic, 90);
				imagedestroy($target);
				touch($thumb_pic, $otime);
			} else {
				logm("Can't write Thumbnail to $thumbpath, please check directory permissions", 1);
			}
		} else {
			$ppath = str_replace($pictures_basepath, '', $org_pic);
			$broken[] = $ppath;
			corrupt_thmb($thumbsize, $thumb_pic);
			logm("Can't create thumbnail $ppath. Picture seems corrupt",1);
		}
	} elseif ($type == "video") {
		if(!empty($ffmpeg)) {
			exec("$ffmpeg -y -v error -i \"".$org_pic."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=$thumbsize\" \"$thumb_pic\" 2>&1", $output, $error);
			if($error == 0) {
				touch($thumb_pic, $otime);
			} else {
				logm("Video $org_pic seems corrupt. ".$output[0], 2);
				$ppath = str_replace($pictures_basepath, '', $org_pic);
				$broken[] = $ppath;
				corrupt_thmb($thumbsize, $thumb_pic);
			}
			
			exec("$ffprobe -y -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$org_pic\" 2>&1", $output, $error);
			if($hevc && $output[0] != "hevc") return $exifArr;

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
	$exifArr['MIMEType'] = $mimetype;
	return $exifArr;
}

function corrupt_thmb($thumbsize, $thumb_pic) {
	$cdir = dirname(__FILE__);
	$sign = imagecreatefrompng($cdir.'/images/error2.png');
	$background = imagecreatefromjpeg($cdir.'/images/defaultimage.jpg');

	$sx = imagesx($sign);
	$sy = imagesy($sign);
	$ix = imagesx($background);
	$iy = imagesy($background);

	$size = 120;
	imagecopyresampled($background, $sign, ($ix-$size)/2, ($iy-$size)/2, 0, 0, $size, $size, $sx, $sy);
	$nw = ceil(($thumbsize/$ix)*$iy);
	$thumbsize = ceil($thumbsize);
	$image_new = imagecreatetruecolor($nw, $thumbsize);
	imagecopyresampled($image_new, $background, 0, 0, 0, 0, $nw, $thumbsize, $ix, $iy);

	imagejpeg($image_new, $thumb_pic, 95);
	imagedestroy($sign);
	imagedestroy($background);
	imagedestroy($image_new);
}

function exiftool($images, $uid) {
	global $pictures_basepath;

	$tags = "-Model -FocalLength# -FNumber# -ISO# -DateTimeOriginal -ImageDescription -Make -Software -Flash# -ExposureProgram# -ExifIFD:MeteringMode# -WhiteBalance# -GPSLatitude# -GPSLongitude# -Orientation# -ExposureTime -TargetExposureTime -LensID -MIMEType -CreateDate -Artist -Description -Title -Copyright -Subject";
	$options = "-q -j -d '%s'";

	if (`which exiftool`) {
		$cimages = array_chunk($images, 1500, true);

		foreach ($cimages as $key => $chunk) {
			$files = implode("' '", $chunk);
			exec("exiftool $options $tags '$files' 2>&1", $output, $error);
			$joutput = implode("", $output);
			$mdarr = json_decode($joutput, true);

			foreach ($mdarr as &$element) {
				todb($element['SourceFile'], $uid, $pictures_basepath, $element);
			}
		}
	} else {
		logm("Exiftool seems to be not installed. Database cant be updated.", 1);
	}
}

function todb($file, $user, $pictures_basepath, $exif) {
	global $rcmail, $ffprobe, $db;
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');
	$result = $db->query("SELECT count(*), `pic_id` FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	$rarr = $db->fetch_array($result);
	$count = $rarr[0];
	$id = $rarr[1];

	unset($exif['SourceFile']);
	if(strlen($exif['ImageDescription']) < 1) unset($exif['ImageDescription']);
	if(strlen($exif['Copyright']) < 1) unset($exif['Copyright']);
	
	$exifj = "'".json_encode($exif,  JSON_HEX_APOS)."'";	
	$type = explode("/", $exif['MIMEType'])[0];

	if($type == 'image') {
		$taken = (isset($exif['DateTimeOriginal']) && is_int($exif['DateTimeOriginal'])) ? $exif['DateTimeOriginal']:filemtime($file);
	} else {
		if(isset($exif['DateTimeOriginal']) && $exif['DateTimeOriginal'] > 0 && is_int($exif['DateTimeOriginal'])) {
			$taken = $exif['DateTimeOriginal'];
		} elseif (isset($exif['CreateDate']) && $exif['CreateDate'] > 0 && is_int($exif['CreateDate'])) {
			$taken = $exif['CreateDate'];
		} else {
			$taken = filemtime($file);
		}
	}

	if($count == 0) {
		logm("Add $file to database", 4);
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES ('$ppath','$type',$taken,$exifj,$user)";
	} else {
		logm("Update database for $file", 4);
		$query = "UPDATE `pic_pictures` SET `pic_taken` = $taken, `pic_EXIF` = $exifj WHERE `pic_id` = $id";
	}

	$db->startTransaction();
	$db->query($query);
	if($db->is_error()) {
		sleep(1);
		$db->query($query);
		$db->endTransaction();
	} else {
		$db->endTransaction();
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
	logm("Remove expired shares from DB");
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
	$exif_arr = array();
	$exif_data = @exif_read_data($file);

	$res = getimagesize($file, $info);

	if($exif_data && count($exif_data) > 0) {
		(isset($exif_data['Model'])) ? $exif_arr['Model'] = $exif_data['Model']:null;
		(isset($exif_data['FocalLength'])) ? $exif_arr['FocalLength'] = parse_fraction($exif_data['FocalLength']):null;
		(isset($exif_data['FNumber'])) ? $exif_arr['FNumber'] = parse_fraction($exif_data['FNumber'],2):null;
		(isset($exif_data['ISOSpeedRatings'])) ? $exif_arr['ISO'] = $exif_data['ISOSpeedRatings']:null;
		(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['DateTimeOriginal'] = strtotime($exif_data['DateTimeOriginal']):filemtime($file);
		(isset($exif_data['ImageDescription'])) ? $exif_arr['ImageDescription'] = $exif_data['ImageDescription']:null;
		(isset($exif_data['Make'])) ? $exif_arr['Make'] = $exif_data['Make']:null;
		(isset($exif_data['Software'])) ? $exif_arr['Software'] = $exif_data['Software']:null;
		(isset($exif_data['Flash'])) ? $exif_arr['Flash'] = $exif_data['Flash']:null;
		(isset($exif_data['ExposureProgram'])) ? $exif_arr['ExposureProgram'] = $exif_data['ExposureProgram']:null;
		(isset($exif_data['MeteringMode'])) ? $exif_arr['MeteringMode'] = $exif_data['MeteringMode']:null;
		(isset($exif_data['WhiteBalance'])) ? $exif_arr['WhiteBalance'] = $exif_data['WhiteBalance']:null;
		(isset($exif_data["GPSLatitude"])) ? $exif_arr['GPSLatitude'] = gps($exif_data['GPSLatitude'],$exif_data['GPSLatitudeRef']):null;
		(isset($exif_data["GPSLongitude"])) ? $exif_arr['GPSLongitude'] = gps($exif_data['GPSLongitude'],$exif_data['GPSLongitudeRef']):null;
		(isset($exif_data['Orientation'])) ? $exif_arr['Orientation'] = $exif_data['Orientation']:null;
        (isset($exif_data['ExposureTime'])) ? $exif_arr['ExposureTime'] = $exif_data['ExposureTime']:null;
        (isset($exif_data['ShutterSpeedValue'])) ? $exif_arr['TargetExposureTime'] = shutter($exif_data['ShutterSpeedValue']):null;
		(isset($exif_data['UndefinedTag:0xA434'])) ? $exif_arr['LensID'] = $exif_data['UndefinedTag:0xA434']:null;
		(isset($exif_data['MimeType'])) ? $exif_arr['MIMEType'] = $exif_data['MimeType']:null;
		(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['CreateDate'] = strtotime($exif_data['DateTimeOriginal']):null;
		(isset($exif_data['Keywords'])) ? $exif_arr['Keywords'] = $exif_data['Keywords']:null;
		(isset($exif_data['Artist'])) ? $exif_arr['Artist'] = $exif_data['Artist']:null;
		(isset($exif_data['Copyright'])) ? $exif_arr['Copyright'] = $exif_data['Copyright']:null;
		$exif_arr['Subject'] = (isset($info['APP13'])) ? iptcparse($info['APP13'])["2#025"]:null;
		(isset($exif_data['Description'])) ? $exif_arr['Description'] = $exif_data['Description']:null;
		(isset($exif_data['Title'])) ? $exif_arr['Title'] = $exif_data['Title']:null;
	}
	return $exif_arr;
}

function shutter($value) {
	$pos = strpos($value, '/');
	$a = (float) substr($value, 0, $pos);
	$b = (float) substr($value, $pos + 1);
	$apex = ($b == 0) ? ($a) : ($a / $b);
	$shutter = pow(2, -$apex);
	if ($shutter == 0) return false;
	if ($shutter >= 1) return round($shutter);
	return '1/'.round(1 / $shutter);
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

function gps($exifCoord, $hemi) {
	$degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
	$minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
	$seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;

	$flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
	return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
}

function gps2Num($coordPart) {
	$parts = explode('/', $coordPart);
	if (count($parts) <= 0) return 0;
	if (count($parts) == 1) return $parts[0];

    $f = floatval($parts[0]);
    $s = floatval($parts[1]);

    $e = ($s == 0) ? 0:$f/$s;
	return $e;
}
?>