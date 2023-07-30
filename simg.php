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
include INSTALL_PATH . 'program/include/iniset.php';
include_once('config.inc.php');
@ini_set('gd.jpeg_ignore_warning', 1);
$rcmail = rcmail::get_instance();
$dbh = $rcmail->get_dbh();

$picture = $_GET['p'];
$mode = $_GET['t'];
$query = "SELECT a.pictureID, a.picturePath, c.username FROM pic_pictures a INNER JOIN pic_shares b ON a.shareID = b.shareID INNER JOIN users c ON b.user_id = c.user_id WHERE a.pictureID = $picture";
$res = $dbh->query($query);
$rc = $dbh->num_rows($res);
$data = $dbh->fetch_assoc($res);

$username = $data['username'];
$image_basepath = rtrim(str_replace("%u", $username, $config['pictures_path']), '/');
$thumb_basepath = rtrim(str_replace("%u", $username, $config['thumb_path']), '/');

$imagepath = $image_basepath."/".$data['picturePath'];
$thumbpath = $thumb_basepath."/".$data['picturePath'].".jpg";

switch($mode) {
	case 1:
		$path = $thumbpath;
		break;
	case 2:
		$path = $imagepath;
		break;
	default:
		$path = $imagepath;
		break;
}

if(file_exists($path)) {
	$mimeType = mime_content_type($path);
	$pathparts = pathinfo($path);
	if(strpos($mimeType, 'video') !== false) {
		
		$ogvpath = $pathparts['dirname']."/.".$pathparts['filename'].".ogv";
		if(file_exists($ogvpath)) {
			$mimeType = mime_content_type($ogvpath);
			$path = $ogvpath;
		}
	}
	
	header('Content-disposition: inline;filename="'.$pathparts['basename'].'"');
	header("Content-Type: $mimeType");
	die(readfile($path));
}
die();
?>
