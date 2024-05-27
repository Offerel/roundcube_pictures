<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.4.17
 * @author Offerel
 * @copyright Copyright (c) 2024, Offerel
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
			include_once('config.inc.php.dist');
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

		if($rcmail->task == 'settings') {
			$this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
			$this->add_hook('preferences_list', array($this, 'preferences_list'));
			$this->add_hook('preferences_save', array($this, 'preferences_save'));
		}

		$this->add_hook('render_page', [$this, 'checkbroken']);
	}

	function preferences_sections_list($p) {		
		$p['list']['pictures'] = array('id' => 'pictures', 'section' => $this->gettext('pictures'));
		return($p);
	}

	function preferences_list($p) {
		if ($p['section'] != 'pictures') {
            return $p;
		}

		$rcmail = rcmail::get_instance();
		$p['blocks']['main']['name']=$this->gettext('pictures');

		$field_id='ptheme';
		$select   = new html_select(array('name' => 'ptheme', 'id' => $field_id));
		foreach (array("dark", "dynamic") as $m) {$select->add($this->gettext('ptheme'.$m), $m);}
		$p['blocks']['main']['options']['ptheme'] = array(
														'title'=> html::label($field_id, $this->gettext('ptheme')),
														'content'=> $select->show($rcmail->config->get('ptheme')));

		$field_id='thumbs_pr_page';
		$input = new html_inputfield(array('name' => 'thumbs_pr_page', 'id' => $field_id));
		$p['blocks']['main']['options']['thumbs_pr_page'] = array(
														'title'=> html::label($field_id, $this->gettext('thumbs_pr_page')),
														'content'=> $input->show($rcmail->config->get('thumbs_pr_page')));

		$field_id='pmargins';
		$input = new html_inputfield(array('name' => 'pmargins', 'id' => $field_id));
		$p['blocks']['main']['options']['pmargins'] = array(
														'title'=> html::label($field_id, $this->gettext('pmargins')),
														'content'=> $input->show($rcmail->config->get('pmargins')));

		return $p;
	}

	function preferences_save($p) {
		if ($p['section'] == 'pictures') {

            $p['prefs'] = array(
                'ptheme'		=> rcube_utils::get_input_value('ptheme', rcube_utils::INPUT_POST),
				'thumbs_pr_page'	=> intval(rcube_utils::get_input_value('thumbs_pr_page', rcube_utils::INPUT_POST)),
				'pmargins'	=> intval(rcube_utils::get_input_value('pmargins', rcube_utils::INPUT_POST))
            );
		}

        return $p;
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
	$exifArray = json_decode($json, true);
	$exifHTML = "";

	if (array_key_exists('1', $exifArray)) {
		if($exifArray[0] != "-" && $exifArray[8] != "-") $exifHTML.= "Camera: ".$exifArray[8]." - ".$exifArray[0]."<br>";
		if($exifArray[1] != "-") $exifHTML.= "FocalLength: ".$exifArray[1]."<br>";
		if($exifArray[3] != "-") $exifHTML.= "F-stop: ".$exifArray[3]."<br>";
		if($exifArray[4] != "-") $exifHTML.= "ISO: ".$exifArray[4]."<br>";
		if($exifArray[10] != "-") $exifHTML.= "Exposure: ".$exifArray[10]."<br>";
		if($exifArray[11] != "-") $exifHTML.= "Flash: ".flash2($exifArray[11])."<br>";
		if($exifArray[12] != "-") $exifHTML.= "Metering Mode: ".$exifArray[12]."<br>";
		if($exifArray[13] != "-") $exifHTML.= "Whitebalance: ".wb2($exifArray[13])."<br>";
	} elseif (array_key_exists('Model', $exifArray)) {
		if(array_key_exists('Make', $exifArray) && array_key_exists('Model', $exifArray)) 
			$camera = (strpos($exifArray['Model'], explode(" ",$exifArray['Make'])[0]) !== false) ? $exifArray['Model']:$exifArray['Make']." - ".$exifArray['Model'];			
		elseif(array_key_exists('Model', $exifArray))
			$camera = $exifArray['Model'];

		$exifHTML = (array_key_exists('Model', $exifArray)) ? "Camera: $camera<br>":"";
		$exifHTML.= (array_key_exists('LensID', $exifArray)) ? "Lens: ".$exifArray['LensID']."<br>":"";
		$exifHTML.= (array_key_exists('ExposureProgram', $exifArray)) ? "Mode: ".ep2($exifArray['ExposureProgram'])."<br>":"";
		$exifHTML.= (array_key_exists('MeteringMode', $exifArray)) ? "Metering Mode: ".mm2($exifArray['MeteringMode'])."<br>":"";
		$exifHTML.= (array_key_exists('ExposureTime', $exifArray)) ? "Exposure time: ".$exifArray['ExposureTime']."s<br>":"";
		$exifHTML.= (array_key_exists('TargetExposureTime', $exifArray)) ? "Shutter speed: ".$exifArray['TargetExposureTime']."s<br>":"";
		$exifHTML.= (array_key_exists('ISO', $exifArray)) ? "ISO: ".$exifArray['ISO']."<br>":"";
		$exifHTML.= (array_key_exists('FocalLength', $exifArray)) ? "Focal Length: ".$exifArray['FocalLength']."mm<br>":"";
		$exifHTML.= (array_key_exists('WhiteBalance', $exifArray)) ? "Whitebalance: ".wb2($exifArray['WhiteBalance'])."<br>":"";
		$exifHTML.= (array_key_exists('FNumber', $exifArray)) ? "Aperture: f".$exifArray['FNumber']."<br>":"";
		$exifHTML.= (array_key_exists('Flash', $exifArray)) ? "Flash: ".flash2($exifArray['Flash'])."<br>":"";

		if(isset($exifArray['Subject']) && is_array($exifArray['Subject'])) {
			$exifHTML.= "Keywords: ".implode(", ", $exifArray['Subject'])."<br>";
		} elseif (isset($exifArray['Subject']) && !is_array($exifArray['Subject'])) {
			$exifHTML.= "Keywords: ".$exifArray['Subject']."<br>";
		}
		
		$exifHTML.= (array_key_exists('Copyright', $exifArray)) ? "Copyright: ".str_replace("u00a9","&copy;",$exifArray['Copyright'])."<br>":"";
		
	}
	$exifSpan = (strlen($exifHTML > 0)) ? "<span id='exif_$imgid' class='exinfo'>$exifHTML</span>":"";
	return $exifSpan;
}

function flash2($val) {
	switch($val) {
		case 0: $str = 'No Flash'; break;
		case 1: $str = 'Fired'; break;
		case 5: $str = 'Fired, Return not detected'; break;
		case 7: $str = 'Fired, Return detected'; break;
		case 9: $str = 'Flash, Compulsory'; break;
		case 13: $str = 'Flash, Compulsory, No Strobe Return'; break;
		case 15: $str = 'Flash, Compulsory, Strobe Return'; break;
		case 16: $str = 'No Flash, Compulsory'; break;
		case 24: $str = 'No Flash, Auto'; break;
		case 25: $str = 'Flash, Auto'; break;
		case 29: $str = 'Flash, Auto, No Strobe Return'; break;
		case 31: $str = 'Flash, Auto, Strobe Return'; break;
		case 32: $str = 'No Flash Function'; break;
		case 65: $str = 'Flash, Red-eyes'; break;
		case 69: $str = 'Flash, Red-eye, No Strobe Return'; break;
		case 71: $str = 'Flash, Red-eye, Strobe Return'; break;
		case 73: $str = 'Flash, Compulsory, Red-eye'; break;
		case 77: $str = 'Flash, Compulsory, Red-eye, No Strobe Return'; break;
		case 79: $str = 'Flash, Compulsory, Red-eye, Strobe Return'; break;
		case 89: $str = 'Flash, Auto, Red-eye'; break;
		case 93: $str = 'Flash, Auto, No Strobe Return, Red-eye'; break;
		case 95: $str = 'Flash, Auto, Strobe Return, Red-eye'; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function wb2($val) {
	switch($val) {
		case 0: $str = "Auto"; break;
		case 1: $str = "Daylight"; break;
		case 2: $str = "Fluorescent"; break;
		case 3: $str = "Incandescent"; break;
		case 4: $str = "Flash"; break;
		case 9: $str = "Fine Weather"; break;
		case 10: $str = "Cloudy"; break;
		case 11: $str = "Shade"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function mm2($val) {
	switch ($val) {
		case 0: $str = "Unknown"; break;
		case 1: $str = "Average"; break;
		case 2: $str = "Center"; break;
		case 3: $str = "Spot"; break;
		case 4: $str = "Multi-Spot"; break;
		case 5: $str = "Multi-field"; break;
		case 6: $str = "Partial"; break;
		case 255: $str = "Other"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function ep2($val) {
	switch ($val) {
		case 0: $str = "Undefined"; break;
		case 1: $str = "Manual"; break;
		case 2: $str = "Auto"; break;
		case 3: $str = "Auto Time"; break;
		case 4: $str = "Auto Shutter"; break;
		case 5: $str = "Creative (Slow)"; break;
		case 6: $str = "Action (High speed)"; break;
		case 7: $str = "Portrait"; break;
		case 8: $str = "Landscape"; break;
		case 9: $str = "Bulb"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
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
	$page.= "\n\t\t\t<div id='header' style='position: fixed; width: 100%'><h2 style='align-items: center; display: inline-flex; padding-left: 20px;text-shadow: 1px 1px 3px rgba(15,15,15,1);color: white;'>$head</h2>";
	$page.= "\n\t\t\t</div>";
	$page.= $thumbnails;
	$page.= "\n\t\t\t<div id='btm'></div>";
	$page.= "\n\t\t</body>\n\t</html>";
	die($page);
}

function checkDB() {
	$dbh = rcmail_utils::db();
	$engine = $dbh->db_provider;
	$fname = "sql/$engine.initial.sql";
	if ($sql = @file_get_contents($fname)) {
		$dbh->exec_script($sql);
	}

	$atime = time();
	$result = $dbh->query("DELETE FROM `pic_shares` WHERE `expire_date` < $atime");
}