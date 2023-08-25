<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.14
 * @author Offerel
 * @copyright Copyright (c) 2023, Offerel
 * @license GNU General Public License, version 3
 */
class pictures extends rcube_plugin {
	public $task = '?(?!login|logout).*';
	public function onload() {
		$rcmail = rcmail::get_instance();

		if (count($_GET) == 2 && isset($_GET['_task']) && $_GET['_task'] == 'pictures' && isset($_GET['fsync'])) {
			$dtime = date("d.m.Y H:i:s");
			$fsdata = json_decode(file_get_contents('php://input'), true);
			$logfile = $rcmail->config->get('log_dir', false)."/fssync.log";
			if(isset($fsdata['syncStatus'])) {
				$line = $dtime." FolderSync ".$fsdata['folderPairName']." ".$fsdata['syncStatus']."\n";
				file_put_contents($logfile, $line, FILE_APPEND);
			}			
			die(http_response_code(204));
		}

		if (count($_GET) == 2 && isset($_GET['_task']) && $_GET['_task'] == 'pictures' && isset($_GET['slink'])) {
			include_once('config.inc.php');
			$link = filter_var($_GET['slink'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			$dbh = $rcmail->get_dbh();
			$query = "SELECT a.`share_id`, a.`share_name`, a.`expire_date`, b.`username` FROM `pic_shares` a INNER JOIN `users` b ON a.`user_id` = b.`user_id` WHERE a.`share_link` = '$link'";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);
			$shares = $dbh->fetch_assoc($res);
			$basepath = rtrim(str_replace("%u", $shares['username'], $config['pictures_path']), '/');
			$shareID = $shares['share_id'];
			$query = "SELECT b.`pic_path`, b.`pic_EXIF`, a.`shared_pic_id` FROM `pic_shared_pictures` a INNER JOIN `pic_pictures` b ON a.`pic_id`= b.`pic_id` WHERE a.`share_id` = $shareID ORDER BY b.`pic_taken` ASC";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);

			for ($x = 0; $x < $rc; $x++) {
				$pictures[] = $dbh->fetch_array($res);
			}

			$thumbnails = "\n\t\t\t<div id='images' class='justified-gallery shared'>";

			$x = isset($_POST['s']) ? filter_var($_POST['s'], FILTER_SANITIZE_NUMBER_INT):0;
			$mthumbs = isset($_POST['s']) ? $x + $config['thumbs_pr_page']:$config['thumbs_pr_page'];
			$mthumbs = ($rc < $mthumbs) ? $rc:$mthumbs;
			$thumbnails2 = "";
			$shp = ($x === 0) ? true:false;

			for ($x; $x < $mthumbs; $x++) {
				$fullpath = $basepath.'/'.$pictures[$x][0];
				if(file_exists($fullpath)) {
					$type = getIType($fullpath);
					$id = $pictures[$x][2];
					$exifSpan = getEXIFSpan($pictures[$x][1], $id);
					$img_name = pathinfo($fullpath)['basename'];
					$imgUrl = "plugins/pictures/simg.php?p=$id&t=1";
					$linkUrl =	"plugins/pictures/simg.php?p=$id&t=2";
					$thumbnails2.= "\n\t\t\t\t<a class='glightbox' href='$linkUrl' data-type='$type'><img src='$imgUrl' alt='$img_name' /></a>$exifSpan";
				}
			}

			$thumbnails2.= ($mthumbs == $rc) ? "<span id='last'></span>":"";
			$thumbnails.= $thumbnails2."\n\t\t\t</div>";

			if(!$shp) {
				die($thumbnails2);
			} else {
				showShare($thumbnails, array(
					"name" => $shares['share_name'],
					"expires" => $shares['expire_date'],
				));
			}
		}
	}

	public function init() {
		$rcmail = rcmail::get_instance();
		$this->load_config();
		$this->add_texts('localization/', true);
		$this->include_stylesheet($this->local_skin_path().'/pictures.css');
		$this->register_task('pictures');
		checkDB();
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

		$this->add_hook('render_page', [$this, 'checkbroken']);
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

	function checkbroken() {
		$rcmail = rcmail::get_instance();
		$uid = $rcmail->user->ID;
		$dbh = $rcmail->get_dbh();
		$res = $dbh->query("SELECT `pic_path` FROM `pic_broken` WHERE `user_id` = $uid");
		$rc = $dbh->num_rows($res);
		$broken = array();
		$bpictures = "";

		for ($x = 0; $x < $rc; $x++) {
			array_push($broken, $dbh->fetch_assoc($res)['pic_path']);
		}

		foreach($broken as $bpicture) {
			$bpictures.= "$bpicture\n";
		}

		if (count($broken) > 0) {
			$this->add_texts('localization');
			$rcmail->output->add_footer(html::tag('div', [
				'id'     => 'picturesinfo',
				'class'  => 'formcontent',
			], rcube::Q($this->gettext('pictures.corpics')."\n\n".$bpictures)
			));
			
			$title  = rcube::JQ($this->gettext('pictures.pictures'));
			$script = "
var picturesinfo = rcmail.show_popup_dialog($('#picturesinfo'), '$title', [], {
	resizable: false,
	closeOnEscape: true,
	width: 500,
	open: function() { $('#picturesinfo').show(); }
});
rcube_webmail.prototype.pictures_dialog_close = function() { picturesinfo.dialog('destroy'); };
";
			$rcmail->output->add_script($script, 'docready');
		}
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

function showShare($thumbnails, $share) {
	$shareName = $share['name'];
	$head = array_key_exists("expires", $share) ? "$shareName<span>(Expires ".date('D, d.m.Y',$share['expires']).")</span>":"";
	$page = "<!DOCTYPE html>
	<html>
		<head>
			<meta charset='UTF-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=Edge'>
			<meta name='viewport' content='width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no'>
			<link rel='apple-touch-icon' sizes='180x180' href='plugins/pictures/images/apple-touch-icon.png'>
			<link rel='icon' type='image/png' sizes='32x32' href='plugins/pictures/images/favicon-32x32.png'>
			<link rel='icon' type='image/png' sizes='16x16' href='plugins/pictures/images/favicon-16x16.png'>
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
	$page.= "\n\t\t</head>\n\t\t<body class='picbdy'>";
	$page.= "\n\t\t\t<div id='header' style='width: 100%'><h2 style='align-items: center; display: inline-flex; padding-left: 20px;text-shadow: 1px 1px 3px rgba(15,15,15,1);color: white;'>$head</h2>";
	$page.= "\n\t\t\t</div>";
	$page.= $thumbnails;
	$page.= "\n\t\t\t<div id='btm'></div>";
	$page.= "\n\t\t</body>\n\t</html>";
	die($page);
}

function checkDB() {
	$dbh = rcmail_utils::db();
	$query = "SELECT count(*) as count FROM `sqlite_master` WHERE type='table' AND `name` IN ('pic_pictures','pic_shares','pic_shared_pictures')";
	$dbh->query($query);
	$count = $dbh->fetch_array()[0];
	if($count != 2) {
		$dbh->query("CREATE TABLE IF NOT EXISTS `pic_pictures` (`pic_id` INTEGER, `pic_path`	TEXT NOT NULL, `pic_type` TEXT NOT NULL, `pic_taken` INTEGER NOT NULL, `pic_EXIF` TEXT, `user_id` INTEGER NOT NULL, PRIMARY KEY(`pic_id` AUTOINCREMENT), UNIQUE(`pic_path`,`user_id`), FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE)");
		$dbh->query("CREATE TABLE IF NOT EXISTS `pic_shares` (`share_id` INTEGER,`share_name` TEXT NOT NULL,`share_link` TEXT NOT NULL, `expire_date` INTEGER, `user_id` INTEGER NOT NULL, PRIMARY KEY(`share_id` AUTOINCREMENT), FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE)");
		$dbh->query("CREATE TABLE IF NOT EXISTS `pic_shared_pictures` (`shared_pic_id`	INTEGER, `share_id`	INTEGER NOT NULL, `user_id`	INTEGER, `pic_id` INTEGER, UNIQUE(`share_id`,`pic_id`), PRIMARY KEY(`shared_pic_id` AUTOINCREMENT), FOREIGN KEY(`pic_id`) REFERENCES `pic_pictures`(`pic_id`) ON DELETE CASCADE, FOREIGN KEY(`share_id`) REFERENCES `pic_shares`(`share_id`) ON DELETE CASCADE)");
		$dbh->query("CREATE TABLE IF NOT EXISTS `pic_broken` (`broken_id`	INTEGER, `user_id`	INTEGER NOT NULL, `pic_path` TEXT NOT NULL, PRIMARY KEY(`broken_id` AUTOINCREMENT), UNIQUE(`pic_path`,`user_id`), FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE)");
	}
	$atime = time();
	$result = $dbh->query("DELETE FROM `pic_shares` WHERE `expire_date` < $atime");
}