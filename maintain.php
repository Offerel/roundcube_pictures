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
$rcmail = rcube::get_instance();
$users = array();
$thumbsize = 300;
$webp_res = array(1920,1080);
$hevc = $rcmail->config->get('convert_hevc', false);
$pictures_path = $rcmail->config->get('pictures_path');
$basepath = rtrim($rcmail->config->get('work_path'), '/');
$exif_mode = $rcmail->config->get('exif');
$pntfy = $rcmail->config->get('pntfy_sec');
$mtime = $rcmail->config->get('dummy_time', false);
$supported= array("jpg", "png", "jpeg", "gif", "tif", "mov", "wmv", "avi", "mpg", "mp4", "3gp");
$etags = "-Model -FocalLength# -FNumber# -ISO# -DateTimeOriginal -ImageDescription -Make -Software -Flash# -ExposureProgram# -ExifIFD:MeteringMode# -WhiteBalance# -GPSLatitude# -GPSLongitude# -Orientation# -ExposureTime -TargetExposureTime -LensID -MIMEType -CreateDate -Artist -Description -Title -Copyright -Subject -ExifImageWidth -ExifImageHeight";
$eoptions = "-q -j -d '%s'";
$bc = 0;
$db = $rcmail->get_dbh();

$result = $db->query("SELECT username, user_id FROM users;");
$rcount = $db->num_rows($result);
for ($x = 0; $x < $rcount; $x++) {
	array_push($users, $db->fetch_assoc($result));
}

foreach($users as $user) {
	$utime = time();
	$username = $user["username"];
	$uid = $user["user_id"];
	$pictures_basepath = rtrim(str_replace("%u", $username, $pictures_path), '/');
	$thumb_basepath = $basepath."/".$username."/photos";
	$webp_basepath =  $basepath."/".$username."/webp";
	$broken = array();
	logm("Search media for $username");
	scanGallery($pictures_basepath, $pictures_basepath, $thumb_basepath, $webp_basepath, $uid);
	$bcount = count($broken);
	$bc = $bc + $bcount;
	if($bcount > 0) {
		foreach($broken as $picture) {
			$db->query("INSERT INTO `pic_broken` (`pic_path`, `user_id`) VALUES (\"$picture\",$uid)");
		}
	}

	logm("Search orphaned thumbnail files");
	read_assets($thumb_basepath, $thumb_basepath, $pictures_basepath, 'thumbnail');
	logm("Search orphaned webp files");
	read_assets($webp_basepath, $webp_basepath, $pictures_basepath, 'webp');
	expired_shares();

	logm("$username finished in ".etime($utime)." with $bcount corrupt media");
}

$message = "Maintenance finished in ".etime($starttime);
$message.= ($bc > 0) ? ". $bc corrupt media found.":"";
logm($message);
if($pntfy && etime($starttime, true) > $pntfy) pntfy($rcmail->config->get('pntfy_usr'), $rcmail->config->get('pntfy_pwd'), $rcmail->config->get('pntfy_url'), $message);


function scanGallery($dir, $base, $thumb, $webp, $user) {
	global $supported, $etags, $eoptions, $exif_mode, $basepath, $mtime;
	$images = array();
    foreach (new DirectoryIterator($dir) as $fileInfo) {
        if (!$fileInfo->isDot()) {
            if ($fileInfo->isDir()) {
                scanGallery($fileInfo->getPathname(), $base, $thumb, $webp, $user);
            } else {
            	$filename = pathinfo($fileInfo->getFilename());
            	if(isset($filename['extension']) && in_array(strtolower($filename['extension']), $supported)) {
					$images[] = $dir.'/'.$filename['basename'];
				}
			}
        }
    }

    if (count($images) > 0) {
		foreach ($images as $key => $image) {
			$thumbp = str_replace($base, $thumb, $image);
			$thumb_parts = pathinfo($thumbp);
			$thumbp = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.jpg';
			$otime = filemtime($image);
			$ttime = @filemtime($thumbp);
			if($otime == $ttime) unset($images[$key]);
			if(filesize($image) < 1) {
				if($mtime > 0) del_dummy($image, $mtime);
				unset($images[$key]);
			}
			$basename = basename($image);
			if($basename == "folder.jpg") unset($images[$key]);
			if($basename[0] == ".") {
				check_hidden($image);
				unset($images[$key]);
			}

		}

		$chunks = array_chunk($images, 1000, true);
		if(count($chunks) > 0) {
			foreach ($chunks as $key => $chunk) {
				$imgarr = array();
				if($exif_mode == 1) {
					foreach ($chunk as $image) {
						$exif_data = @exif_read_data($image);
						$gis = getimagesize($image, $info);
						$exif_arr = array();
						$exif_arr['SourceFile'] = $image;

						if(is_array($exif_data)) {
							(isset($exif_data['Model'])) ? $exif_arr['Model'] = $exif_data['Model']:null;
							(isset($exif_data['FocalLength'])) ? $exif_arr['FocalLength'] = parse_fraction($exif_data['FocalLength']):null;
							(isset($exif_data['FNumber'])) ? $exif_arr['FNumber'] = parse_fraction($exif_data['FNumber'],2):null;
							(isset($exif_data['ISOSpeedRatings'])) ? $exif_arr['ISO'] = $exif_data['ISOSpeedRatings']:null;
							(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['DateTimeOriginal'] = strtotime($exif_data['DateTimeOriginal']):filemtime($image);
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
							$exif_arr['Keywords'] = (isset($info['APP13']) && isset($info['APP13']["2#025"])) ? iptcparse($info['APP13']["2#025"]):null;
							(isset($exif_data['Artist'])) ? $exif_arr['Artist'] = $exif_data['Artist']:null;
							(isset($exif_data['Copyright'])) ? $exif_arr['Copyright'] = $exif_data['Copyright']:null;
							//$exif_arr['Subject'] = (isset($info['APP13'])) ? iptcparse($info['APP13'])["2#025"]:null;
							$exif_arr['Subject'] = (isset($info['APP13']) && isset($info['APP13']["2#025"])) ? iptcparse($info['APP13'])["2#025"]:null;
							(isset($exif_data['Description'])) ? $exif_arr['Description'] = $exif_data['Description']:null;
							(isset($exif_data['Title'])) ? $exif_arr['Title'] = $exif_data['Title']:null;
							(isset($exif_data['COMPUTED']['Width'])) ? $exif_arr['ExifImageWidth'] = $exif_data['COMPUTED']['Width']:null;
							(isset($exif_data['COMPUTED']['Height'])) ? $exif_arr['ExifImageHeight'] = $exif_data['COMPUTED']['Height']:null;
						} else {
							if(!is_bool($gis)) {
								$exif_arr['MIMEType'] = $gis['mime'];
								$exif_arr['ExifImageWidth'] = $gis[0];
								$exif_arr['ExifImageHeight'] = $gis[1];
							} else {
								$exif_arr['MIMEType'] = mime_content_type($image);
							}
							
						}
						$imgarr[]=$exif_arr;
					}
				} elseif ($exif_mode == 2) {
					$files = implode("' '", $chunk);
					exec("exiftool $eoptions $etags '$files' 2>&1", $output, $error);
					$joutput = implode("", $output);
					$imgarr = json_decode($joutput);
				}

				foreach($imgarr as $file) {
					$rthumb = create_thumb($file, $thumb, $base);
					$rwebp = create_webp($file, $webp, $base);
					if(todb($file, $base, $user) == 0 && $rthumb[0] > 0) {
						touch($rthumb[1], $rthumb[0]);
						if($rwebp[0] > 0) touch($rwebp[1], $rwebp[0]);
					}
				}
			}
		}
	}
}

function create_thumb($file, $thumb, $base) {
	global $thumbsize, $broken, $hevc;
	$image = realpath($file['SourceFile']);
	$thumb_image = str_replace($base, $thumb, $image);
	$thumb_parts = pathinfo($thumb_image);
	$thumb_image = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.jpg';
	logm("Create thumbnail $thumb_image", 4);
	$otime = filemtime($image);

	$file['MIMEType'] = (!isset($file['MIMEType'])) ? mime_content_type($image):$file['MIMEType'];
	
	if(isset($file['MIMEType'])) {
		$type = explode('/', $file['MIMEType'])[0];
	} else {
		logm("Missing MimeType in $image", 2);
		return array(0, $thumb_image);
	}

	$thumbpath = $thumb_parts['dirname'];
		
	if (!is_dir("$thumbpath")) {
		if(!mkdir("$thumbpath", 0755, true)) {
			logm("Thumbnail subfolder creation failed ($thumbpath). Please check directory permissions.", 1);
			return array(0, $thumb_image);
		}
	}

	if ($type == "image") {
		$newwidth = ceil($file['ExifImageWidth'] * $thumbsize / $file['ExifImageWidth']);
		if($newwidth <= 0) {
			logm("Get width failed.", 2);
			return array(0, $thumb_image);
		}

		switch ($file['MIMEType']) {
			case 'image/gif': $source = @imagecreatefromgif($image); break;
			case 'image/jpeg': $source = @imagecreatefromjpeg($image); break;
			case 'image/png': $source = @imagecreatefrompng($image); break;
			default: logm("Unsupported file $image", 1);
		}

		if ($source) {
			$target = imagescale($source, $newwidth, -1, IMG_GENERALIZED_CUBIC);
			imagedestroy($source);
			
			$ort = (isset($file['Orientation'])) ? $file['Orientation']:0;
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
				imagejpeg($target, $thumb_image, 90);
				imagedestroy($target);
			} else {
				logm("Can't write Thumbnail to $thumbpath, please check directory permissions", 1);
				return array(0, $thumb_image);
			}
		} else {
			$broken[] = str_replace($base, '', $image);
			corrupt_thmb($thumbsize, $thumb_image);
			logm("Can't create thumbnail $thumb_image. Picture seems corrupt | ".$file['MIMEType'],1);
			return array(0, $thumb_image);
		}
	} elseif ($type == "video") {
		exec("ffmpeg -y -v error -i \"".$image."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=$thumbsize\" \"$thumb_image\" 2>&1", $output, $error);
		if($error != 0) {
			logm("Video $image seems corrupt. ".$output[0], 2);
			$broken[] = str_replace($base, '', $image);
			corrupt_thmb($thumbsize, $thumb_image);
			return array(0, $thumb_image);
		}

		exec("ffprobe -y -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 \"$image\" 2>&1", $output, $error);
		if($hevc && $output[0] != "hevc") return array($otime, $thumb_image);

		$pathparts = pathinfo($image);
		$hidden_vid = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
		if(!file_exists($hidden_vid)) {
			$startconv = time();
			logm("Convert to $hidden_vid", 4);
			exec("ffmpeg -y -loglevel quiet -i \"$image\" -c:v h264_v4l2m2m -b:v 8M -c:a copy -movflags +faststart \"$hidden_vid\" 2>&1", $output, $error);
			if($error == 0) {
				logm("Video $image converted in $cdiff".gmdate("H:i:s", time() - $startconv), 4);
			} else {
				logm("Video $image is corrupt".$output[0], 1);
				$broken[] = str_replace($base, '', $image);
			}
		}
	
	}
	return array($otime, $thumb_image);
}

function create_webp($file, $webp, $base) {
	global $webp_res;
	$image = realpath($file['SourceFile']);
	$file['MIMEType'] = (!isset($file['MIMEType'])) ? mime_content_type($image):$file['MIMEType'];
	$webp_image = str_replace($base, $webp, $image);
	$webp_parts = pathinfo($webp_image);
	$webp_image = $webp_parts['dirname'].'/'.$webp_parts['filename'].'.webp';
	if($file['MIMEType'] != "image/jpeg") return array(0, $webp_image);
	logm("Create webp $webp_image", 4);
	$otime = filemtime($image);
	if($otime == @filemtime($webp_image)) return array($otime, $webp_image);
	$owidth = $file['ExifImageWidth'];
	$oheight = $file['ExifImageHeight'];
	$imaget = @imagecreatefromjpeg($image);
	if($imaget === false) return array(0, $webp_image);
	
	if(isset($file['Orientation'])) {
		switch($file['Orientation']) {
			case 3: $degrees = 180; break;		
			case 4: $degrees = 180; break;		
			case 5: $degrees = 270; break;		
			case 6: $degrees = 270; break;		
			case 7: $degrees = 90; break;		
			case 8: $degrees = 90; break;		
			default: $degrees = 0;
		}
	} else {
		$degrees = 0;
	}

	if($degrees > 0) $imaget = imagerotate($imaget, $degrees, 0);		

	if ($owidth > $webp_res[0] || $oheight > $webp_res[1]) {
		$nwidth = ($owidth > $oheight) ? $webp_res[0]:ceil($owidth/($oheight/$webp_res[1]));
		$imaget = imagescale($imaget, $nwidth);
	}

	$directory = dirname($webp_image);
	if(!file_exists($directory)) mkdir($directory, 0755 ,true);
	imagewebp($imaget, $webp_image, 70);
	imagedestroy($imaget);
	return array($otime, $webp_image);
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

function todb($file, $base, $user) {
	global $db;
	$image = realpath($file['SourceFile']);
	$ppath = trim(str_replace($base, '', $image),'/');
	$query = "SELECT count(*), `pic_id` FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user";
	$result = $db->query($query);
	$rarr = $db->fetch_array($result);
	$count = $rarr[0];
	$id = $rarr[1];

	unset($file['SourceFile']);
	unset($file['ExifImageWidth']);
	unset($file['ExifImageHeight']);
	if(isset($file['ImageDescription']) && strlen($file['ImageDescription']) < 1) unset($file['ImageDescription']);
	if(isset($file['Copyright']) && strlen($file['Copyright']) < 1) unset($file['Copyright']);
	
	$file['MIMEType'] = (!isset($file['MIMEType'])) ? mime_content_type($image):$file['MIMEType'];
	$exif = "'".json_encode($file,  JSON_HEX_APOS)."'";
	$type = explode("/", $file['MIMEType'])[0];

	if($type == 'image') {
		$taken = (isset($file['DateTimeOriginal']) && is_int($file['DateTimeOriginal']) && $file['DateTimeOriginal'] > 0) ? $file['DateTimeOriginal']:filemtime($image);
	} else {
		if(isset($file['DateTimeOriginal']) && $file['DateTimeOriginal'] > 0 && is_int($file['DateTimeOriginal']) && $file['DateTimeOriginal'] > 0) {
			$taken = $file['DateTimeOriginal'];
		} elseif (isset($file['CreateDate']) && $file['CreateDate'] > 0 && is_int($file['CreateDate'])) {
			$taken = $file['CreateDate'];
		} else {
			$taken = filemtime($image);
		}
	}

	if($count == 0) {
		logm("Add $image to database", 4);
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES ('$ppath','$type',$taken,$exif,$user)";
	} else {
		logm("Update database for $image", 4);
		$query = "UPDATE `pic_pictures` SET `pic_taken` = $taken, `pic_EXIF` = $exif WHERE `pic_id` = $id";
	}

	$db->startTransaction();
	$db->query($query);
	if($db->is_error()) {
		sleep(2);
		$db->query($query);
		$db->endTransaction();
		if($db->is_error()) {
			logm($db->is_error(), 1);
			return $db->is_error();
		}
	} else {
		$db->endTransaction();
	}
	return 0;
}

function read_assets($path, $assets, $base, $type) {
	if($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') continue;
			if(is_dir("$path/$file")) {
				read_assets("$path/$file", $assets, $base, $type);
				if(count(glob("$path/$file/*")) === 0) rmdir("$path/$file");
			} else {
				delete_asset("$path/$file", $assets, $base, $type);
			}
		}
		closedir($handle);
	}
}

function delete_asset($image, $asset, $base, $type) {
	$image = realpath($image);
	$path_parts = pathinfo(str_replace($asset, $base, $image));

	if(count(glob($path_parts['dirname']."/".$path_parts['filename']."*")) == 0) {
		logm("Delete $type $image", 4);
		unlink($image);
	}
}

function check_hidden($hidden) {
	$path_parts = pathinfo($hidden);
	$filename = $path_parts['basename'];
	if (count(glob($path_parts['dirname'].'/'.ltrim($path_parts['filename'].'.').'*')) == 0) {
		logm("Delete hidden file $hidden");
		unlink($hidden);
	}
}

function expired_shares() {
	global $db;
	logm("Remove expired shares from DB");
	$result = $db->query("DELETE FROM `pic_shares` WHERE `expire_date` < ".time());
}

function del_dummy($nullsize, $mtime) {
	$dtime = time() - filemtime($nullsize);
	if ($dtime > $mtime && filesize($nullsize) < 1) {
		logm("Delete 0-size $nullsize", 4);
		unlink($nullsize);
	}
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

function pntfy($user, $password, $purl, $message) {
	$authHeader = base64_encode("$user:$password");
	$authHeader = (strlen($authHeader) > 4) ? "Authorization: Basic $authHeader\r\n":'';
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
		logm("ntfy succesfully", 4);
	else
		logm("ntfy failed.", 2);
}

function etime($start, $s = false) {
	if($s) return time() - $start;
	return gmdate("H:i:s", time() - $start);
}
?>