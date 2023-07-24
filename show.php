<?php
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
$query = "SELECT picturePath, pictureEXIF FROM pic_pictures WHERE shareID = $shareID ORDER BY pictureTaken ASC";
$res = $dbh->query($query);
$rc = $dbh->num_rows($res);

/*
for ($x = 0; $x < $rc; $x++) {
	$pictures[] = $dbh->fetch_array($result)[0];
}
*/

for ($x = 0; $x < $rc; $x++) {
	$pictures[] = $dbh->fetch_array($result);
}

//file_put_contents("/tmp/erg.txt", print_r($pictures), FILE_APPEND);
//die();

/*
foreach($pictures as $picture) {
	$file = pathinfo($picture);
	$taken = 123456789;
	$caption = "Bild";
	$files[] = array(
		"name" => $file['basename'],
		"date" => $taken,
		"size" => filesize($picture),
		"html" => "<div><a class=\"image\" href=\"$linkUrl\" data-sub-html=\"$caption\"><img src=\"$imgUrl\" alt=\"$file\" /></a></div>"
	);
}
*/

$thumbnails = "\t\t\t\t<div id=\"images\" class=\"justified-gallery\">";

foreach($pictures as $picture) {
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
		$exifInfo.= "<a href='https://www.openstreetmap.org/?".$osm_params."' target='_blank'><img src='images/marker.png'>Show on map</a>";
	}
		
	if(count(exifReaden) > 0) {
		$caption = $file['basename']."<span class='exname'><img src='images/info.png'><div class='exinfo'>$exifInfo</div></span>";
	} else {
		$caption = $file['basename'];
	}

	$file = pathinfo($picture[0]);
	$img_name = $file['basename'];
	$params = rawurlencode($picture[0]);
	$imgUrl = "createthumb2.php?filename=$params";
	$linkUrl = "dphoto.php?file=".str_replace('%2F','/',rawurlencode($picture[0]));
	$thumbnails.= "\n\t\t\t\t\t<div><a class=\"image\" href=\"$linkUrl\" data-sub-html=\"$caption\"><img src=\"$imgUrl\" alt=\"$img_name\" /></a></div>";
}

$thumbnails.= "\n\t\t\t\t</div>";

$page = "<!DOCTYPE html>
	<html>
		<head>
			<title>$shareName</title>
			<link rel=\"stylesheet\" href=\"css/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/main.min.css\" type=\"text/css\" />
			<link rel=\"stylesheet\" href=\"css/lightgallery.min.css\" type=\"text/css\" />
			
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/jquery.justifiedGallery.min.js\"></script>
			<script src=\"js/lightgallery-all.min.js\"></script>";
	$page.= "\n\t\t</head>\n\t\t<body>\n\t\t\t<div id=\"galdiv\">\n";
	$page.= $thumbnails;
	$page.="
	<script>	
		$('#images').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: 7,
			border: 0,
			rel: 'gallery',
			lastRow: 'justify',
			captions: true,
			randomize: false
		}).on('jg.complete', function () {
			$('#images').lightGallery({
				share: false,
				download: true,
				fullScreen: false,
				pager: false,
				autoplay: false,
				selector: '.image'
			});
		});
    </script>
	";	
	$page.= "</div></body></html>";

	echo $page;
?>