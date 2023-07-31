<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.4
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
class pictures extends rcube_plugin {
	public $task = '?(?!login|logout).*';
	public function onload() {
		$rcmail = rcmail::get_instance();

		if (count($_GET) == 2 && isset($_GET['_task']) && $_GET['_task'] == 'pictures' && isset($_GET['slink'])) {
			include_once('config.inc.php');
			$link = filter_var($_GET['slink'],FILTER_SANITIZE_STRING);
			$dbh = $rcmail->get_dbh();
			$query = "SELECT a.shareID, a.shareName, a.expireDate, b.username FROM pic_shares a INNER JOIN users b ON a.user_id = b.user_id WHERE shareLink = '$link'";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);
			$shares = $dbh->fetch_assoc($res);
			$basepath = rtrim(str_replace("%u", $shares['username'], $config['pictures_path']), '/');
			$shareID = $shares['shareID'];
			$shareName = $shares['shareName'];
			$query = "SELECT picturePath, pictureEXIF, pictureID FROM pic_shared_pictures WHERE shareID = $shareID ORDER BY pictureTaken ASC";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);

			for ($x = 0; $x < $rc; $x++) {
				$pictures[] = $dbh->fetch_array($result);
			}

			$thumbnails = "\n\t\t\t<div id='images' class='justified-gallery shared'>";
			foreach($pictures as $picture) {
				$fullpath = $basepath.'/'.$picture[0];
				if(file_exists($fullpath)) {
					$type = getIType($fullpath);
					$exifSpan = getEXIFSpan($picture[1], $picture[2]);
					$img_name = pathinfo($fullpath)['basename'];
					$imgUrl = 'plugins/pictures/simg.php?p='.$picture[2].'&t=1';
					$linkUrl =	'plugins/pictures/simg.php?p='.$picture[2].'&t=2';
					$thumbnails.= "\n\t\t\t\t<a class='glightbox' href='$linkUrl' data-type='$type'><img src='$imgUrl' alt='$img_name' /></a>$exifSpan";
				}
			}
			$thumbnails.= "\n\t\t\t</div>";
			showShare($thumbnails, $shareName);
		}
	}

	public function init() {
		$rcmail = rcmail::get_instance();
		$this->load_config();
		$this->add_texts('localization/', true);
		$this->include_stylesheet($this->local_skin_path().'/pictures.css');
		
		$this->register_task('pictures');
		
		$this->add_button(array(
			'label'	=> 'pictures.pictures',
			'command'	=> 'pictures',
			'id'		=> 'a4c4b0cb-087b-4edd-a746-f3bacb5dd04e',
			'class'		=> 'button-pictures',
			'classsel'	=> 'button-pictures button-selected',
			'innerclass'=> 'button-inner',
			'type'		=> 'link'
		), 'taskbar');

		if ($rcmail->task == 'pictures') {
			$this->register_action('index', array($this, 'action'));
			$this->register_action('gallery', array($this, 'change_requestdir'));
			$rcmail->output->set_env('refresh_interval', 0);
		}
	}
	
	function change_requestdir() {
		$rcmail = rcmail::get_instance();
		if(isset($_GET['dir'])) {
			$dir = $_GET['dir'];
		}
		$rcmail->output->send('pictures.template');
	}
	
	function action() {
		$rcmail = rcmail::get_instance();	
		$rcmail->output->add_handlers(array('picturescontent' => array($this, 'content'),));
		$rcmail->output->set_pagetitle($this->gettext('pictures'));
		$rcmail->output->send('pictures.template');
	}
	
	function content($attrib) {
		$rcmail = rcmail::get_instance();
		$this->include_script('js/pictures.js');
		$attrib['src'] = 'plugins/pictures/photos.php';
		if (empty($attrib['id']))
			$attrib['id'] = 'rcmailpicturescontent';
		$attrib['name'] = $attrib['id'];
		return $rcmail->output->frame($attrib);
	}
}

function getIType($path) {
	return explode('/',mime_content_type($path))[0];
}

function getEXIFSpan($json, $imgid) {
	$exifArray = json_decode($json);
	$exifHTML = "";
	if($exifArray[0] != "-" && $exifArray[8] != "-")
		$exifHTML.= "Camera: ".$exifArray[8]." - ".$exifArray[0]."<br>";
	if($exifArray[1] != "-")
		$exifHTML.= "FocalLength: ".$exifArray[1]."<br>";
	if($exifArray[3] != "-")
		$exifHTML.= "F-stop: ".$exifArray[3]."<br>";
	if($exifArray[4] != "-")
		$exifHTML.= "ISO: ".$exifArray[4]."<br>";
	if($exifArray[5] != "-")
		$exifHTML.= "Date: ".date("d.m.Y H:i", $exifArray[5])."<br>";
	if($exifArray[6] != "-")
		$exifHTML.= "Description: ".$exifArray[6]."<br>";
	if($exifArray[9] != "-")
		$exifHTML.= "Software: ".$exifArray[9]."<br>";
	if($exifArray[10] != "-")
		$exifHTML.= "Exposure: ".$exifArray[10]."<br>";
	if($exifArray[11] != "-")
		$exifHTML.= "Flash: ".$exifArray[11]."<br>";
	if($exifArray[12] != "-")
		$exifHTML.= "Metering Mode: ".$exifArray[12]."<br>";
	if($exifArray[13] != "-")
		$exifHTML.= "Whitebalance: ".$exifArray[13]."<br>";
	if($exifArray[14] != "-" && $exifArray[15] != "-") {
		$osm_params = http_build_query(array(	'mlat' => str_replace(',','.',$exifArray[14]),
												'mlon' => str_replace(',','.',$exifArray[15])
											),'','&amp;');
		$exifHTML.= "<a href='https://www.openstreetmap.org/?".$osm_params."' target='_blank'>Show on map</a>";
	}

	$exifSpan = (count($exifArray) > 1) ? "<span id='exif_$imgid' class='exinfo'>$exifHTML</span>":"";

	return $exifSpan;
}

function showShare($thumbnails, $shareName) {
	$page = "<!DOCTYPE html>
	<html>
		<head>
			<meta charset='UTF-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=Edge'>
			<meta name='viewport' content='width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no'>
			<!-- <link rel='apple-touch-icon' sizes='180x180' href='images/apple-touch-icon.png'>
			<link rel='icon' type='image/png' sizes='32x32' href='images/favicon-32x32.png'>
			<link rel='icon' type='image/png' sizes='16x16' href='images/favicon-16x16.png'> -->
			<title>$shareName</title>
			<link rel='stylesheet' href='plugins/pictures/js/justifiedGallery/justifiedGallery.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/skins/main.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/js/glightbox/glightbox.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/js/plyr/plyr.css' type='text/css' />
			<script src='program/js/jquery.min.js'></script>
			<script src='plugins/pictures/js/justifiedGallery/jquery.justifiedGallery.min.js'></script>
			<script src='plugins/pictures/js/glightbox/glightbox.min.js'></script>
			<script src='plugins/pictures/js/plyr/plyr.js'></script>
			<script src='plugins/pictures/js/pictures.js'></script>
			";
	$page.= "\n\t\t</head>\n\t\t<body>";
	$page.= "\n\t\t\t<div id='header'><h2>$shareName</h2>";
	$page.= "\n\t\t\t</div>";
	$page.= $thumbnails;
	$page.= "\n\t\t</body>\n\t</html>";
	die($page);
}