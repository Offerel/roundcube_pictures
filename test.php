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

$modes = array("clean","add","all");
if(!in_array($argv[1], $modes)) {
    $message = "No working mode given, please specify one mode. Allowed modes are \"add\", \"clean\" or \"all\".\n";
    logm($message);
	die();
} else {
	$mode = $argv[1];
}

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

    $message = "Maintenance for $username with mode $mode";
    logm($message);

    switch($mode) {
        case "add":
            read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath);
            break;
        case "clean":
            if(is_dir($thumb_basepath)) {
                $path = $thumb_basepath;
                read_thumbs($path, $thumb_basepath, $pictures_basepath);
            }
            break;
        case "all":
            read_photos($pictures_basepath, $thumb_basepath, $pictures_basepath);
            if(is_dir($thumb_basepath)) {
                $path = $thumb_basepath;
                read_thumbs($path, $thumb_basepath, $pictures_basepath);
            }
            break;
        default:
            $message = "Unknown Mode. Exit Script.";
            logm($message, 2);
            die();
            break;
    }
}

logm("Maintenance finished.");
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
    $logfile = $rcmail->config->get('log_dir', false)."maintenance.log";
    file_put_contents($logfile, $line, FILE_APPEND);
}

function read_photos($path, $thumb_basepath, $pictures_basepath) {
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	if(file_exists($path)) {
		if($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if($file === '.' || $file === '..') {
					continue;
				}
				
				if(is_dir($path."/".$file."/")) {
                    $message = "Change to directory $path/$file/";
                    logm($message);
					read_photos($path."/".$file, $thumb_basepath, $pictures_basepath);
				}
				else {
					if(in_array(strtolower(pathinfo($file)['extension']), $support_arr ) && basename(strtolower($file)) != 'folder.jpg') {
						createthumb($path."/".$file, $thumb_basepath, $pictures_basepath);
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
	$xoord = 0;
	$yoord = 0;
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
		if ($width > $height) {
			$xoord = ceil(($width - $height) / 2);
		}
		else {
			$yoord = ceil(($height - $width) / 2);
		}
		
		if (function_exists('exif_read_data') && function_exists('imagerotate')) {
			if (preg_match("/.jpg$|.jpeg$/i", $org_pic)) {
				$exif = @exif_read_data($org_pic, 0, true);
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
			logm("PHP functions exif_read_data() and imagerotate() are not available, check your PHP installation.", 2);
		}
		
		$newwidth = ceil($width / ($height / $thumbsize));
		if($newwidth <= 0) {
            logm("Calculating the width ($newwidth) of \"$get_filename\" failed.", 2);
		}

		$target = imagecreatetruecolor($newwidth, $thumbsize);
		
		switch ($type) {
			case 1: $source = @imagecreatefromgif($org_pic); break;
			case 2: $source = @imagecreatefromjpeg($org_pic); break;
			case 3: $source = @imagecreatefrompng($org_pic); break;
			default: logm("Unsupported fileformat ($org_pic $type).", 2); die();
		}
		
		imagecopyresampled($target, $source, 0, 0, 0, 0, $newwidth, $thumbsize, $width, $height);
		imagedestroy($source);
		
		if ($degrees != 0) {
			$target = imagerotate($target, $degrees, 0);
		}
		
		if(is_writable($thumbpath)) {
			if ($flip == 'vertical') {
				imagejpeg(imageflip($target, IMG_FLIP_VERTICAL),$thumb_pic,80);
			}
			else {
				imagejpeg($target, $thumb_pic, 80);
			}
		}
		else {
			logm("Can't write Thumbnail ($thumbpath). Please check your directory permissions.", 2);
		}
	} elseif(preg_match("/.mp4$|.mpg$|.3gp$/i", $org_pic)) {
		$avconv = exec("which avconv");
		if($avconv == "" ) {
			$avconv = exec("which ffmpeg");
		}

		if(file_exists($avconv)) {
			$cmd = $avconv." -i \"".$org_pic."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumb_pic."\" 2>&1";
			exec($cmd);
		}
		else {
			logm("ffmpeg or avconv is not installed, so video formats are not supported.", 2);
		}
	}
}
?>
