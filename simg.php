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

$picture = filter_var($_GET['p'], FILTER_SANITIZE_NUMBER_INT);
$mode = filter_var($_GET['t'], FILTER_SANITIZE_NUMBER_INT);
$file = filter_var($_GET['file'], FILTER_SANITIZE_STRING);


if(isset($file) && !empty($file)) {
	if (!empty($rcmail->user->ID)) {
		$username = $rcmail->user->get_username();

		switch($mode) {
			case 1:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('thumb_path', false)),'/').'/';
				$ext = ".jpg";
				break;
			case 2:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
				$ext = "";
				break;
			default:
				$pictures_basepath = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)),'/').'/';
				$ext = "";
				break;
		}
	} else {
		error_log('Pictures Plugin(Picture): Login failed. User is not logged in.');
		die();
	}

	$file = html_entity_decode($pictures_basepath.$file.$ext, ENT_QUOTES);

	if (file_exists("$file")) {
		$mtype = mime_content_type($file);
		$pathparts = pathinfo($file);
		if(strpos($mtype, 'video') !== false) {
			$ogvpath = $pathparts['dirname']."/.".$pathparts['filename'].".ogv";
			if(file_exists($ogvpath)) {
				$mimeType = mime_content_type($ogvpath);
				$file = $ogvpath;
			}
		}
		header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
		header("Content-Type: $mtype");
		header('Content-disposition: inline;filename="'.basename($file).'"');
		die(readfile($file));
	} else {
		die('Nicht gfunden'."$file");
	}
	die();
} else {
	$query = "SELECT a.`shared_pic_id`, a.`pic_path`, c.`username` FROM `pic_shared_pictures` a INNER JOIN `pic_shares` b ON a.`share_id` = b.`share_id` INNER JOIN `users` c ON b.`user_id` = c.`user_id` WHERE a.`shared_pic_id` = $picture";
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

		header('Content-disposition: inline; filename="'.$pathparts['basename'].'"');
		header("Content-Type: $mimeType");
		die(readfile($path));
	}
	die();
}
?>
