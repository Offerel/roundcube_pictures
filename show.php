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
require INSTALL_PATH.'program/include/iniset.php';
include_once('config.inc.php');
$rcmail = rcube::get_instance();

$link = $_GET['slink'];

$dbh = $rcmail->get_dbh();
$query = "SELECT a.shareID, a.shareName, a.expireDate, b.username FROM pic_shares a INNER JOIN users b ON a.user_id = b.user_id WHERE shareLink = '$link'";
$res = $dbh->query($query);
$rc = $dbh->num_rows($res);
$shares = $dbh->fetch_assoc($res);

$basepath = rtrim(str_replace("%u", $shares['username'], $config['pictures_path']), '/');

$shareID = $shares['shareID'];
$shareName = $shares['shareName'];
$query = "SELECT picturePath, pictureEXIF, pictureID FROM pic_pictures WHERE shareID = $shareID ORDER BY pictureTaken ASC";
$res = $dbh->query($query);
$rc = $dbh->num_rows($res);

for ($x = 0; $x < $rc; $x++) {
	$pictures[] = $dbh->fetch_array($result);
}

$thumbnails = "\n\t\t\t<div id=\"images\" class=\"justified-gallery shared\">";

foreach($pictures as $picture) {
	$type = explode('/',mime_content_type($basepath.'/'.$picture[0]))[0];
	$exifReaden = json_decode($picture[1]);
	$exifInfo = "";
	if($exifReaden[0] != "-" && $exifReaden[8] != "-")
		$exifInfo.= "Camera: ".$exifReaden[8]." - ".$exifReaden[0]."<br>";
	if($exifReaden[1] != "-")
		$exifInfo.= "FocalLength: ".$exifReaden[1]."<br>";
	if($exifReaden[3] != "-")
		$exifInfo.= "F-stop: ".$exifReaden[3]."<br>";
	if($exifReaden[4] != "-")
		$exifInfo.= "ISO: ".$exifReaden[4]."<br>";
	if($exifReaden[5] != "-")
		$exifInfo.= "Date: ".date("d.m.Y H:i", $exifReaden[5])."<br>";
	if($exifReaden[6] != "-")
		$exifInfo.= "Description: ".$exifReaden[6]."<br>";
	if($exifReaden[9] != "-")
		$exifInfo.= "Software: ".$exifReaden[9]."<br>";
	if($exifReaden[10] != "-")
		$exifInfo.= "Exposure: ".$exifReaden[10]."<br>";
	if($exifReaden[11] != "-")
		$exifInfo.= "Flash: ".$exifReaden[11]."<br>";
	if($exifReaden[12] != "-")
		$exifInfo.= "Metering Mode: ".$exifReaden[12]."<br>";
	if($exifReaden[13] != "-")
		$exifInfo.= "Whitebalance: ".$exifReaden[13]."<br>";
	if($exifReaden[14] != "-" && $exifReaden[15] != "-") {
		$osm_params = http_build_query(array(	'mlat' => str_replace(',','.',$exifReaden[14]),
												'mlon' => str_replace(',','.',$exifReaden[15])
											),'','&amp;');
		$exifInfo.= "<a href='https://www.openstreetmap.org/?".$osm_params."' target='_blank'>Show on map</a>";
	}

	$ecount = count($exifReaden);

	$imgid = $picture[2];
	$imgUrl = 'simg.php?p='.$imgid.'&t=1';
	$linkUrl = 'simg.php?p='.$imgid.'&t=2';

	if($ecount > 1) {
		$caption = "<span id='exif_$imgid' class='exinfo'>$exifInfo</span>";
	} else {
		$caption = "";
	}

	$file = pathinfo($picture[0]);
	$img_name = $file['basename'];

	$thumbnails.= "\n\t\t\t\t<a class='glightbox' href='$linkUrl' data-type='$type'><img src='$imgUrl' alt='$img_name' /></a>$caption";
}

$thumbnails.= "\n\t\t\t</div>";

$page = "<!DOCTYPE html>
	<html>
		<head>
			<meta charset='UTF-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=Edge'>
			<meta name='viewport' content='width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no'>

			<link rel='apple-touch-icon' sizes='180x180' href='images/apple-touch-icon.png'>
			<link rel='icon' type='image/png' sizes='32x32' href='images/favicon-32x32.png'>
			<link rel='icon' type='image/png' sizes='16x16' href='images/favicon-16x16.png'>

			<title>$shareName</title>
			<link rel='stylesheet' href='css/justifiedGallery.min.css' type='text/css' />
			<link rel='stylesheet' href='css/main.min.css' type='text/css' />
			<link rel='stylesheet' href='js/glightbox/glightbox.min.css' type='text/css' />
			<link rel='stylesheet' href='js/plyr/plyr.css' type='text/css' />

			<script src='../../program/js/jquery.min.js'></script>
			<script src='js/jquery.justifiedGallery.min.js'></script>
			<script src='js/glightbox/glightbox.min.js'></script>
			<script src='js/plyr/plyr.js'></script>
			<script src='js/plugin.min.js'></script>
			";
	$page.= "\n\t\t</head>\n\t\t<body>";
	$page.= "\n\t\t\t<div id='header'><h2>$shareName</h2>";
	$page.= "\n\t\t\t</div>";
	$page.= $thumbnails;
	$page.= "\n\t\t</body>\n\t</html>";
	echo $page;
?>