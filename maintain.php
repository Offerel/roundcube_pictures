<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.5.1
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
$ccmd = $rcmail->config->get('ffmpeg_cmd');
$pictures_path = $rcmail->config->get('pictures_path');
$basepath = rtrim($rcmail->config->get('work_path'), '/');
$logdir = $rcmail->config->get('log_dir');
$exif_mode = $rcmail->config->get('exif');
$pntfy = $rcmail->config->get('pntfy_sec');
$mtime = $rcmail->config->get('dummy_time', false);
$spictures = array("jpg", "jpeg", "png", "gif", "tif");
$svideos = array("mov", "wmv", "avi", "mpg", "mp4", "3gp", "ogv", "webm");
$supported = array_merge($spictures, $svideos);
$etags = "-Model -FocalLength# -FNumber# -ISO# -DateTimeOriginal -ImageDescription -Make -Software -Flash# -ExposureProgram# -ExifIFD:MeteringMode# -WhiteBalance# -GPSLatitude# -GPSLongitude# -Orientation# -ExposureTime -TargetExposureTime -LensID -MIMEType -CreateDate -Artist -Description -Title -Copyright -Subject -ExifImageWidth -ExifImageHeight";
$eoptions = "-q -j -d '%s'";
$bc = 0;
$db = $rcmail->get_dbh();

if(isset($argv[1]) && $argv[1] === "trigger") {
	$lines = file("$logdir/fssync.log");
	$last_line = $lines[count($lines)-1];
	if (strpos($last_line, "Failed") !== false) {
		die();
	}
}

logm("--- Start maintenance ---");
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

	logm("Finished pictures in ".etime($utime));

	logm("Search orphaned thumbnail files");
	read_assets($thumb_basepath, $thumb_basepath, $pictures_basepath, 'thumbnail');

	logm("Search orphaned webp files");
	read_assets($webp_basepath, $webp_basepath, $pictures_basepath, 'webp');

	expired_shares();

	$message = "$username finished in ".etime($utime);
	$message.= ($bcount > 0) ? " with $bcount corrupt media":"";
	logm($message);
}

$message = "Maintenance finished in ".etime($starttime);
$message.= ($bc > 0) ? ". $bc corrupt media found.":"";
logm($message);
if($pntfy && etime($starttime, true) > $pntfy) pntfy($rcmail->config->get('pntfy_usr'), $rcmail->config->get('pntfy_pwd'), $rcmail->config->get('pntfy_url'), $message);


function scanGallery($dir, $base, $thumb, $webp, $user) {
	global $supported, $svideos, $etags, $eoptions, $exif_mode, $basepath, $mtime;
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
			$thumbp = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';
			$otime = filemtime($image);
			$ttime = @filemtime($thumbp);

			if($otime == $ttime) {
				$image_parts = pathinfo($image);
				$hiddenv = $image_parts['dirname'].'/.'.$image_parts['filename'].'.mp4';
				if(in_array(strtolower($image_parts['extension']), $svideos) && !file_exists($hiddenv)) {
					logm("Hidden video missing, continue $image", 3);
					continue;
				}
				unset($images[$key]);
				logm("No change, Ignore $image", 4);
				continue;
			}

			if(filesize($image) < 1) {
				if($mtime > 0) del_dummy($image, $mtime);
				unset($images[$key]);
				logm("O-Byte, Ignore $image", 4);
				continue;
			}

			if(basename($image) === "folder.jpg") {
				unset($images[$key]);
				logm("Ignore $image", 4);
				continue;
			}

			if(basename($image)[0] == ".") {
				check_hidden($image);
				unset($images[$key]);
				logm("Ignore hidden $image", 4);
				continue;
			}
		}

		$chunks = array_chunk($images, 1000, true);
		if(count($chunks) > 0) {
			foreach ($chunks as $key => $chunk) {
				$imgarr = array();
				if($exif_mode == 1) {
					foreach ($chunk as $image) {
						logm("Get exif data for $image", 3);
						ini_set('exif.decode_unicode_motorola','UCS-2LE');
						$exif_data = @exif_read_data($image);
						$gis = getimagesize($image, $info);
						$exif_arr = array();
						$exif_arr['SourceFile'] = $image;

						if(is_array($exif_data)) {
							(isset($exif_data['Model'])) ? $exif_arr['Model'] = $exif_data['Model']:"";
							(isset($exif_data['FocalLength'])) ? $exif_arr['FocalLength'] = parse_fraction($exif_data['FocalLength']):"";
							(isset($exif_data['FNumber'])) ? $exif_arr['FNumber'] = parse_fraction($exif_data['FNumber'],2):"";
							(isset($exif_data['ISOSpeedRatings'])) ? $exif_arr['ISO'] = $exif_data['ISOSpeedRatings']:"";
							(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['DateTimeOriginal'] = strtotime($exif_data['DateTimeOriginal']):filemtime($image);
							(isset($exif_data['ImageDescription'])) ? $exif_arr['ImageDescription'] = $exif_data['ImageDescription']:"";
							(isset($exif_data['Make'])) ? $exif_arr['Make'] = $exif_data['Make']:"";
							(isset($exif_data['Software'])) ? $exif_arr['Software'] = $exif_data['Software']:"";
							(isset($exif_data['Flash'])) ? $exif_arr['Flash'] = $exif_data['Flash']:"";
							(isset($exif_data['ExposureProgram'])) ? $exif_arr['ExposureProgram'] = $exif_data['ExposureProgram']:"";
							(isset($exif_data['MeteringMode'])) ? $exif_arr['MeteringMode'] = $exif_data['MeteringMode']:"";
							(isset($exif_data['WhiteBalance'])) ? $exif_arr['WhiteBalance'] = $exif_data['WhiteBalance']:"";
							(isset($exif_data["GPSLatitude"])) ? $exif_arr['GPSLatitude'] = gps($exif_data['GPSLatitude'],$exif_data['GPSLatitudeRef']):"";
							(isset($exif_data["GPSLongitude"])) ? $exif_arr['GPSLongitude'] = gps($exif_data['GPSLongitude'],$exif_data['GPSLongitudeRef']):"";
							(isset($exif_data['Orientation'])) ? $exif_arr['Orientation'] = $exif_data['Orientation']:"";
							(isset($exif_data['ExposureTime'])) ? $exif_arr['ExposureTime'] = $exif_data['ExposureTime']:"";
							(isset($exif_data['ShutterSpeedValue'])) ? $exif_arr['TargetExposureTime'] = shutter($exif_data['ShutterSpeedValue']):"";
							(isset($exif_data['UndefinedTag:0xA434'])) ? $exif_arr['LensID'] = $exif_data['UndefinedTag:0xA434']:"";
							(isset($exif_data['MimeType'])) ? $exif_arr['MIMEType'] = $exif_data['MimeType']:"";
							(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['CreateDate'] = strtotime($exif_data['DateTimeOriginal']):"";
							$exif_arr['Keywords'] = (isset($info['APP13'])) ? iptc_keywords($info['APP13']):"";
							(isset($exif_data['Artist'])) ? $exif_arr['Artist'] = $exif_data['Artist']:"";
							(isset($exif_data['Copyright'])) ? $exif_arr['Copyright'] = $exif_data['Copyright']:"";
							(isset($exif_data['Description'])) ? $exif_arr['Description'] = $exif_data['Description']:"";
							(isset($exif_data['Title'])) ? $exif_arr['Title'] = $exif_data['Title']:"";
							(isset($exif_data['COMPUTED']['Width'])) ? $exif_arr['ExifImageWidth'] = $exif_data['COMPUTED']['Width']:"";
							(isset($exif_data['COMPUTED']['Height'])) ? $exif_arr['ExifImageHeight'] = $exif_data['COMPUTED']['Height']:"";
						} else {
							if(!is_bool($gis)) {
								$exif_arr['MIMEType'] = $gis['mime'];
								$exif_arr['ExifImageWidth'] = $gis[0];
								$exif_arr['ExifImageHeight'] = $gis[1];
							} else {
								$exif_arr['MIMEType'] = mime_content_type($image);
							}
							
						}

						$imgarr[] = array_filter($exif_arr);
					}
				} elseif ($exif_mode == 2) {
					$files = implode("' '", $chunk);
					exec("exiftool $eoptions $etags '$files' 2>&1", $output, $error);
					$joutput = implode("", $output);
					$imgarr = json_decode($joutput);
				}

				new_keywords($imgarr, $user);

				foreach($imgarr as $file) {
					$rthumb = create_thumb($file, $thumb, $base);
					$rwebp = create_webp($file, $webp, $base);
					if(todb($file, $base, $user) == 0 && $rthumb[0] > 0) {
						logm("Set time for thumbnail ".$rthumb[1]." to ".date('Y-m-d H:i:s', $rthumb[0]), 4);
						touch($rthumb[1], $rthumb[0]);
						if($rwebp[0] > 0) touch($rwebp[1], $rwebp[0]);
					}
				}
			}
		}
	}
}

function new_keywords($arr, $uid) {
	global $db;
	$kw = array();
	foreach ($arr as $key => $image) {
		$kw = array_merge($kw, explode(', ', $image['Keywords']));
	}
	$kw = array_unique($kw);

	$tags = array();
	$query = "SELECT `tag_name` FROM `pic_tags` WHERE `user_id` = $uid  ORDER BY `tag_name`;";
	$result = $db->query($query);
	$rcount = $db->num_rows($result);

	for ($x = 0; $x < $rcount; $x++) {
		array_push($tags, $db->fetch_assoc($result)['tag_name']); //	"('".$db->fetch_assoc($result)['tag_name']."', '$uid)"
	}

	$diff = array_diff($kw, $tags);
	if(is_array($diff) && count($diff) > 0) {
		$vals = "";
		foreach ($diff as $key => $value) {
			$vals.= "('".$value."', $uid),";
		}
		$vals = substr($vals, 0, -1);
		$query = "INSERT INTO `pic_tags` (`tag_name`, `user_id`) VALUES $vals;";
		$db->query($query);
	}
}

function iptc_keywords($iptcdata) {
	if(isset(iptcparse($iptcdata)['2#025'])) {
		$keywords = implode(', ', iptcparse($iptcdata)['2#025']);
	} else {
		$keywords = null;
	}
	return $keywords;
}

function create_thumb($file, $thumb, $base) {
	global $thumbsize, $broken, $ccmd;
	$image = realpath($file['SourceFile']);
	$thumb_image = str_replace($base, $thumb, $image);
	$thumb_parts = pathinfo($thumb_image);
	$thumb_image = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';
	logm("Create thumbnail $thumb_image", 3);
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
		$newwidth = ($file['ExifImageWidth'] > $file['ExifImageHeight']) ? ceil($file['ExifImageWidth']/($file['ExifImageHeight']/$thumbsize)):$thumbsize;
		if($newwidth <= 0) {
			logm("Get width failed.", 2);
			return array(0, $thumb_image);
		}

		switch ($file['MIMEType']) {
			case 'image/gif': $source = @imagecreatefromgif($image); break;
			case 'image/jpeg': $source = @imagecreatefromjpeg($image); break;
			case 'image/png': $source = @imagecreatefrompng($image); break;
			case 'image/bmp': $source = @imagecreatefrombmp($image); break;
			case 'image/webp': $source = @imagecreatefromwebp($image); break;
			case 'image/avif': $source = @imagecreatefromavif($image); break;
			default: logm("Unsupported media format $image", 1);
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
				imagewebp($target, $thumb_image, 60);
				imagedestroy($target);
			} else {
				logm("Can't write Thumbnail to $thumbpath, please check directory permissions", 1);
				return array(0, $thumb_image);
			}
		} else {
			$broken[] = str_replace($base, '', $image);
			corrupt_thmb($thumb_image);
			logm("Can't create thumbnail $thumb_image. Picture seems corrupt | ".$file['MIMEType'],1);
			return array(0, $thumb_image);
		}
	} elseif ($type == "video") {
		exec("ffmpeg -y -v error -i \"".$image."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=$thumbsize\" \"$thumb_image\" 2>&1", $output, $error);
		if($error != 0) {
			logm("Video $image seems corrupt. ".$output[0], 2);
			$broken[] = str_replace($base, '', $image);
			corrupt_thmb($thumb_image);
			return array(0, $thumb_image);
		}
		
		if(strlen($ccmd) > 1) {
			$pathparts = pathinfo($image);
			$hidden_vid = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
			logm("Convert to $hidden_vid", 3);
			$startconv = time();
			$command = str_replace('%o', $hidden_vid, str_replace('%i', $image, $ccmd));
			exec($command, $output, $error);
			logm($command, 4);

			if($error == 0) {
				logm("Video $image converted in ".gmdate("H:i:s", time() - $startconv), 4);
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
	logm("Create webp $webp_image", 3);
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
	imagewebp($imaget, $webp_image, 60);
	imagedestroy($imaget);
	return array($otime, $webp_image);
}

function corrupt_thmb($thumb_pic) {
	global $thumbsize;
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

	imagewebp($image_new, $thumb_pic, 60);
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
		logm("Add $image to database", 3);
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES ('$ppath','$type',$taken,$exif,$user)";
	} else {
		logm("Update database for $image", 3);
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

function read_assets($dir, $a_base, $p_base, $type) {
	global $supported;
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
	foreach ($iterator as $file) {
		if ($file->isDir()) {
			if(count(glob($file)) === 0) {
				logm("Delete empty directory $file");
				rmdir($file);
			}
		}

		$thumb_path = $file->getPathname();
		$pext = pathinfo($thumb_path, PATHINFO_EXTENSION);

		if($pext == 'webp') {
			$picture_path = str_replace($a_base, $p_base, $thumb_path);
			$path_parts = pathinfo($picture_path);
			$psearch = $path_parts['dirname'].'/'.$path_parts['filename'];

			$extensions = array();
			foreach($supported as $ext) {
				$extensions[] = $ext;
				$extensions[] = strtoupper($ext);
			}

			if(count(glob("$psearch.{".implode(',', $extensions)."}", GLOB_BRACE)) === 0) {
				logm("Delete $type $file");
				unlink($file);
			}

		}
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
		logm("Delete 0-byte $nullsize", 3);
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
	global $rcmail, $logdir;
	$dtime = date("Y-m-d H:i:s");
	$logfile = "$logdir/maintenance.log";
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