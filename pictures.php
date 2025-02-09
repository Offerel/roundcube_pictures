<?php
/**
 * Roundcube Photos Plugin
 *
 * @version 1.5.6
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
class pictures extends rcube_plugin {
	public $task = '?(?!login|logout).*';
	public function onload() {
		$rcmail = rcmail::get_instance();

		if (isset($_GET['_task']) && $_GET['_task'] == 'pictures') {
			$json = json_decode(file_get_contents('php://input'), true);

			if(json_last_error() == JSON_ERROR_NONE) {
				$logfile = $rcmail->config->get('log_dir', false)."/fssync.log";
				$pidfile = $rcmail->config->get('log_dir', false)."/maintenance.pid";

				if(isset($json['syncStatus'])) {
					$line = date("d.m.Y H:i:s")." FolderSync ".$json['folderPairName']." ".$json['syncStatus']."\n";
					file_put_contents($logfile, $line, FILE_APPEND);
					$code = ($json['syncStatus'] == 'SyncOK') ? 202:500;
					$message = $json['syncStatus'];

					if(file_exists($pidfile)) {
						$code = 300;
						$message = 'Maintenance already running';
					}

					$response = [
						'code' => $code,
						'message' => $message,
						'logfile' => "$logfile"
					];

					http_response_code($code);
					header('Content-Type: application/json');
					die(json_encode($response, JSON_NUMERIC_CHECK|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
				}
			}
		}

		if (count($_GET) == 2 && isset($_GET['_task']) && $_GET['_task'] == 'pictures' && isset($_GET['slink'])) {
			include_once('config.inc.php.dist');
			include_once('config.inc.php');
			$link = filter_var($_GET['slink'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			$dbh = $rcmail->get_dbh();
			$query = "SELECT a.`share_id`, a.`share_name`, a.`expire_date`, a.`share_down`, b.`username` FROM `pic_shares` a INNER JOIN `users` b ON a.`user_id` = b.`user_id` WHERE a.`share_link` = '$link'";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);
			$shares = $dbh->fetch_assoc($res);
			$basepath = rtrim(str_replace("%u", $shares['username'], $config['pictures_path']), '/');
			$thumbbase = $config['work_path'].'/'.$shares['username'].'/photos';
			$shareID = $shares['share_id'];
			$dlpic= ($shares['share_down'] == 1) ? 'dl':'';
			$query = "SELECT b.`pic_path`, b.`pic_EXIF`, a.`shared_pic_id` FROM `pic_shared_pictures` a INNER JOIN `pic_pictures` b ON a.`pic_id`= b.`pic_id` WHERE a.`share_id` = $shareID ORDER BY b.`pic_taken` ASC";
			$res = $dbh->query($query);
			$rc = $dbh->num_rows($res);

			for ($x = 0; $x < $rc; $x++) {
				$pictures[] = $dbh->fetch_array($res);
			}

			$thumbnails = "\n\t\t\t<div id='images' class='justified-gallery shared $dlpic'>";

			$x = isset($_POST['s']) ? filter_var($_POST['s'], FILTER_SANITIZE_NUMBER_INT):0;
			$mthumbs = isset($_POST['s']) ? $x + $config['thumbs_pr_page']:$config['thumbs_pr_page'];
			$mthumbs = ($rc < $mthumbs) ? $rc:$mthumbs;
			$thumbnails2 = "";
			$shp = ($x === 0) ? true:false;

			for ($x; $x < $mthumbs; $x++) {
				$fullpath = $basepath.'/'.$pictures[$x][0];
				$thumb_path = str_replace($basepath, $thumbbase, $fullpath);
				$path_parts = pathinfo($thumb_path);
				$thumb_path = $path_parts['dirname'].'/'.$path_parts['filename'].'.webp';
				if(file_exists($fullpath)) {
					$type = getIType($fullpath);
					$id = $pictures[$x][2];
					$lang = $rcmail->config->get('language', false);
					$exifSpan = ($config['exif'] != 0) ? getEXIFSpan($pictures[$x][1], $id, $lang):"";
					$img_name = pathinfo($fullpath)['basename'];
					$imgUrl = "plugins/pictures/simg.php?p=$id&t=1";
					$linkUrl =	"plugins/pictures/simg.php?p=$id&t=2";
					$gis = getimagesize($thumb_path)[3];

					$thumbnails2.= "\n\t\t\t\t<a class='glightbox' href='$linkUrl' data-type='$type'><img src='$imgUrl' $gis alt='$img_name' /></a>$exifSpan";
				}
			}

			$thumbnails2.= ($mthumbs == $rc) ? "<span id='last'></span>":"";
			$thumbnails.= $thumbnails2."\n\t\t\t</div>";

			if($shp === false) {
				die($thumbnails2);
			} else {
				showShare($thumbnails, array(
					"name" => $shares['share_name'],
					"expires" => $shares['expire_date'],
					"first" => $pictures[0][2]
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
		$this->include_script('js/settings.js');
		$this->add_button(array(
			'label'	=> 'pictures.pictures',
			'command'	=> 'pictures',
			'id'		=> 'a4c4b0cb-087b-4edd-a746-f3bacb5dd04e',
			'class'		=> 'button-pictures',
			'classsel'	=> 'button-pictures button-selected',
			'innerclass'=> 'button-inner',
			'type'		=> 'link'
		), 'taskbar');

		if(isset($_GET['code'])) {
			$code = filter_var($_GET['code'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			$html = '<script>
				window.addEventListener("load", (event) => {
					let cArr = JSON.parse(localStorage.getItem("appval"));
					localStorage.removeItem("appval");

					let data = new FormData();
					data.append("client_id", cArr["client_id"]);
					data.append("client_secret", cArr["client_secret"]);
					data.append("redirect_uri", cArr["redirect_uri"]);
					data.append("grant_type", "authorization_code");
					data.append("scope", cArr["scope"]);
					data.append("code", "'.$code.'");

					let xhr = new XMLHttpRequest();
					xhr.onload = function() {
						let result = JSON.parse(this.response);

						if(xhr.status === 200) {
							let nToken = result.access_token;
							saveVals(cArr["instance"],nToken, cArr["redirect_uri"]);
							window.opener.ProcessChildMessage(nToken);
							window.close();
						} else {
							console.error("error saving token")
						}
					}
					xhr.open("POST", cArr["instance"] + "/oauth/token", false);
					xhr.setRequestHeader("Authorization", "Bearer " + cArr["token"]);
					xhr.send(data);
				});

				function saveVals(instance,token, rdu) {
					const data = JSON.stringify({
						action: "pfmd",
						data: {
							instance:instance,
							token:token,
						}
					});
					let xhr = new XMLHttpRequest();
					xhr.onload = function() {
						let result = this.response;
					}
					xhr.open("POST", "./plugins/pictures/photos.php");
					xhr.setRequestHeader("Content-type", "application/json; charset=utf-8");
					xhr.responseType = "json";
					xhr.send(data);
				}
			</script>';
			
			die($html);
		}

		if ($rcmail->task == 'pictures') {
			$this->register_action('index', array($this, 'action'));
			$rcmail->output->set_env('refresh_interval', 0);
			$rcmail->output->set_env('sdays', $rcmail->config->get('sharedays', 60));
			$rcmail->output->set_env('c', $rcmail->config->get('pfc', 0));
			$rcmail->output->set_env('pfm', $rcmail->config->get('pfm', 0));
			$rcmail->output->set_env('t', $rcmail->config->get('pft', 'Mastodon'));
			$this->include_script('js/glightbox/glightbox.min.js');
			$rcmail->output->set_env('ptags', json_encode($this->get_tags()));
			$this->include_stylesheet('js/tagify/tagify.css');
			$this->include_script('js/tagify/tagify.js');
		} else {
			$this->add_hook('render_page', [$this, 'checkbroken']);
		}

		if($rcmail->task == 'settings') {
			$this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
			$this->add_hook('preferences_list', array($this, 'preferences_list'));
			$this->add_hook('preferences_save', array($this, 'preferences_save'));
		}
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

		$days = $rcmail->config->get('sharedays');
		$days = ($days) ? $days:60;
		$field_id='sharedays';
		$input = new html_inputfield(array('name' => 'sharedays', 'id' => $field_id));
		$p['blocks']['main']['options']['sharedays'] = array(
														'title'=> html::label($field_id, $this->gettext('sharedays')),
														'content'=> $input->show($days));

		$field_id='pixelfed_instance';
		$input = new html_inputfield(array('name' => 'pixelfed_instance', 'id' => $field_id, 'placeholder' => 'https://pixelfed.social'));
		$p['blocks']['main']['options']['pixelfed_instance'] = array(
														'title'=> html::label($field_id, $this->gettext('pf_md_instance')),
														'content'=> $input->show($rcmail->config->get('pixelfed_instance')));
		$field_id='pixelfed_token';
		$input = new html_inputfield(array('name' => 'pixelfed_token', 'id' => $field_id, 'type' => 'hidden'));
		$p['blocks']['main']['options']['pixelfed_token'] = array(
														'content'=> $input->show($rcmail->config->get('pixelfed_token')));
		$field_id='pft';
		$input = new html_inputfield(array('name' => 'pft', 'id' => $field_id, 'type' => 'hidden'));
		$p['blocks']['main']['options']['pft'] = array(
														'content'=> $input->show($rcmail->config->get('pft')));
		$field_id='pfm';
		$input = new html_inputfield(array('name' => 'pfm', 'id' => $field_id, 'type' => 'hidden'));
		$p['blocks']['main']['options']['pfm'] = array(
														'content'=> $input->show($rcmail->config->get('pfm')));
		$field_id='pfc';
		$input = new html_inputfield(array('name' => 'pfc', 'id' => $field_id, 'type' => 'hidden'));
		$p['blocks']['main']['options']['pfc'] = array(
														'content'=> $input->show($rcmail->config->get('pfc')));

		return $p;
	}

	function preferences_save($p) {
		if ($p['section'] == 'pictures') {
            $p['prefs'] = array(
                'ptheme'			=> rcube_utils::get_input_value('ptheme', rcube_utils::INPUT_POST),
				'thumbs_pr_page'	=> intval(rcube_utils::get_input_value('thumbs_pr_page', rcube_utils::INPUT_POST)),
				'pmargins'			=> intval(rcube_utils::get_input_value('pmargins', rcube_utils::INPUT_POST)),
				'sharedays'			=> intval(rcube_utils::get_input_value('sharedays', rcube_utils::INPUT_POST)),
				'pixelfed_instance'	=> rcube_utils::get_input_value('pixelfed_instance', rcube_utils::INPUT_POST),
				'pixelfed_token'	=> rcube_utils::get_input_value('pixelfed_token', rcube_utils::INPUT_POST),
				'pft'				=> rcube_utils::get_input_value('pft', rcube_utils::INPUT_POST),
				'pfm'				=> intval(rcube_utils::get_input_value('pfm', rcube_utils::INPUT_POST)),
				'pfc'				=> intval(rcube_utils::get_input_value('pfc', rcube_utils::INPUT_POST))
            );
		}

        return $p;
	}
	
	function action() {
		$rcmail = rcmail::get_instance();	
		$rcmail->output->add_handlers(array('picturescontent' => array($this, 'content')));
		$rcmail->output->set_pagetitle($this->gettext('pictures'));
		$rcmail->output->send('pictures.template');
	}
	
	function content($attrib) {
		$rcmail = rcmail::get_instance();
		$gallery = trim(rcube_utils::get_input_string('_gallery', rcube_utils::INPUT_GPC));
		$attrib['src'] = (strlen($gallery > 0)) ? 'plugins/pictures/photos.php?p='.$gallery:'plugins/pictures/photos.php?p=';
		$this->include_script('js/pictures.js');
		if (empty($attrib['id']))
			$attrib['id'] = 'rcmailpicturescontent';
		$attrib['name'] = $attrib['id'];
		return $rcmail->output->frame($attrib);
	}

	function get_tags() {
		$rcmail = rcmail::get_instance();
		$uid = $rcmail->user->ID;
		$dbh = $rcmail->get_dbh();
		$res = $dbh->query("SELECT `tag_name` FROM `pic_tags` WHERE `user_id` = $uid ORDER BY `tag_name`;");
		$tags = array();

		for ($x = 0; $x < $dbh->num_rows($res); $x++) {
			array_push($tags, $dbh->fetch_assoc($res)['tag_name']);
		}

		return $tags;
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
			$script = "var buttons = [];
					buttons.push({
						text: 'Open',
						click: function() {
							var newURL = window.location.protocol + '//' + window.location.host + window.location.pathname + '?_task=pictures';
							window.location.href = newURL;
							picturesinfo.dialog('close');
						}
					});
					var picturesinfo = rcmail.show_popup_dialog($('#picturesinfo'), '$title', buttons, {
						resizable: false,
						closeOnEscape: true,
						width: 460,
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

function getEXIFSpan($json, $imgid, $lang) {
	$lngfile = dirname(__FILE__)."/localization/$lang.inc";
	include (file_exists($lngfile)) ? $lngfile:dirname(__FILE__)."/localization/en_US.inc";

	$exifArray = json_decode($json, true);
	$exifHTML = "";

	if (is_array($exifArray) && array_key_exists('1', $exifArray)) {
		if($exifArray[0] != "-" && $exifArray[8] != "-") $exifHTML.= $labels['exif_camera'].": ".$exifArray[8]." - ".$exifArray[0]."<br>";
		if($exifArray[10] != "-") $exifHTML.= $labels['exif_expos'].": ".$exifArray[10]."<br>";
		if($exifArray[12] != "-") $exifHTML.= $labels['exif_meter'].": ".$exifArray[12]."<br>";
		if($exifArray[4] != "-") $exifHTML.= $labels['exif_ISO'].": ".$exifArray[4]."<br>";
		if($exifArray[1] != "-") $exifHTML.= $labels['exif_focalength'].": ".$exifArray[1]."<br>";
		if($exifArray[13] != "-") $exifHTML.= $labels['exif_whiteb'].": ".gv($exifArray[13], 'wb', $lang)."<br>";
		if($exifArray[3] != "-") $exifHTML.= $labels['exif_fstop'].": ".$exifArray[3]."<br>";
		if($exifArray[11] != "-") $exifHTML.= $labels['exif_flash'].": ".gv($exifArray[11], 'fl', $lang)."<br>";
	} elseif (is_array($exifArray) && array_key_exists('Model', $exifArray)) {
		$exifHTML = "<span class='infotop'>".$labels['metadata']."</span>";

		if(array_key_exists('Make', $exifArray) && array_key_exists('Model', $exifArray))
			$camera = (strpos($exifArray['Model'], explode(" ",$exifArray['Make'])[0]) !== false) ? $exifArray['Model']:$exifArray['Make']." - ".$exifArray['Model'];			
		elseif(array_key_exists('Model', $exifArray))
			$camera = $exifArray['Model'];
		
		$exifHTML.= (array_key_exists('DateTimeOriginal', $exifArray)) ? "<strong>".$labels['exif_date'].": </strong>".date(DATE_RFC822, $exifArray['DateTimeOriginal'])."<br />":"";
		$exifHTML.= (array_key_exists('Model', $exifArray)) ? "<strong>".$labels['exif_camera'].": </strong>$camera<br />":"";
		$exifHTML.= (array_key_exists('LensID', $exifArray)) ? "<strong>".$labels['exif_lens'].": </strong>".$exifArray['LensID']."<br />":"";
		$exifHTML.= (array_key_exists('Software', $exifArray)) ? "<strong>".$labels['exif_sw'].": </strong>".$exifArray['Software']."<br />":"";
		$exifHTML.= (array_key_exists('ExposureProgram', $exifArray)) ? "<strong>".$labels['exif_expos']." </strong>".gv($exifArray['ExposureProgram'], 'ep', $lang)."<br />":"";
		$exifHTML.= (array_key_exists('MeteringMode', $exifArray)) ? "<strong>".$labels['exif_meter'].": </strong>".gv($exifArray['MeteringMode'], 'mm', $lang)."<br />":"";
		$exifHTML.= (array_key_exists('ExposureTime', $exifArray)) ? "<strong>".$labels['exif_exptime'].": </strong>".$exifArray['ExposureTime']."s<br />":"";
		$exifHTML.= (array_key_exists('ISO', $exifArray)) ? "<strong>".$labels['exif_ISO'].": </strong>".$exifArray['ISO']."<br />":"";
		$exifHTML.= (array_key_exists('FocalLength', $exifArray)) ? "<strong>".$labels['exif_focalength'].": </strong>".$exifArray['FocalLength']."mm<br />":"";
		$exifHTML.= (array_key_exists('WhiteBalance', $exifArray)) ? "<strong>".$labels['exif_whiteb'].": </strong>".gv($exifArray['WhiteBalance'], 'wb', $lang)."<br />":"";
		$exifHTML.= (array_key_exists('FNumber', $exifArray)) ? "<strong>".$labels['exif_fstop'].": </strong>ƒ / ".$exifArray['FNumber']."<br />":"";
		$exifHTML.= (array_key_exists('Flash', $exifArray)) ? "<strong>".$labels['exif_flash'].": </strong>".gv($exifArray['Flash'], 'fl', $lang)."<br />":"";

		if(isset($exifArray['Subject']) && is_array($exifArray['Subject'])) {
			$exifHTML.= "<strong>".$labels['exif_keywords'].": </strong>".implode(", ", $exifArray['Subject'])."<br />";
		} elseif (isset($exifArray['Subject']) && !is_array($exifArray['Subject'])) {
			$exifHTML.= "<strong>".$labels['exif_keywords'].": </strong>".$exifArray['Subject']."<br />";
		}

		if(isset($exifArray['Keywords']) && is_array($exifArray['Keywords'])) {
			$keywords = implode(", ", $exifArray['Keywords']);
			$keywords = str_replace('u00','\u00',$keywords);
			$keywords = json_decode('"' . $keywords . '"');
		} elseif (isset($exifArray['Keywords']) && !is_array($exifArray['Keywords'])) {
			$keywords = $exifArray['Keywords'];
			$keywords = str_replace('u00','\u00',$keywords);
			$keywords = json_decode('"' . $keywords . '"');
		}

		$exifHTML.= isset($keywords) ? "<strong>".$labels['exif_keywords'].": </strong>$keywords<br />":'';

		if(array_key_exists('GPSLatitude', $exifArray) && array_key_exists('GPSLongitude', $exifArray)) {
			$osm_params = http_build_query(array(
				'mlat' => str_replace(',','.',$exifArray['GPSLatitude']),
				'mlon' => str_replace(',','.',$exifArray['GPSLongitude'])
			),'','&amp;');
			$gm_params = http_build_query(array(
				'api' => 1,
				'query' => str_replace(',','.',$exifArray['GPSLatitude']) . ',' . str_replace(',','.',$exifArray['GPSLongitude']),
				'z' => 13
			),'','&amp;');
			$gpslink ="<img src='plugins/pictures/images/marker.png'><a class='mapl' href='https://www.openstreetmap.org/?$osm_params#map=14/".$exifArray['GPSLatitude']."/".$exifArray['GPSLongitude']."' target='_blank'>OSM</a> | <a class='mapl' href='https://www.google.com/maps/search/?$gm_params' target='_blank'>Google Maps</a>";
		} else {
			$osm_params = "";
			$gm_params = "";
		}
		
		$exifHTML.= (array_key_exists('Copyright', $exifArray)) ? "<span class='cpr'>".str_replace("u00a9","&copy;",$exifArray['Copyright'])."</span>":"";
		$exifHTML.= (strlen($osm_params) > 20) ? "<span class='gps'>$gpslink</span>":"";		
	}
	$exifSpan = (strlen($exifHTML > 0)) ? "<span id='exif_$imgid' class='exinfo'>$exifHTML</span>":"";
	return $exifSpan;
}

function gv($val, $type, $lang) {
	$lngfile = dirname(__FILE__)."/localization/$lang.inc";
	include (file_exists($lngfile)) ? $lngfile:dirname(__FILE__)."/localization/en_US.inc";

	switch($type) {
		case 'ep':
			switch ($val) {
				case 0: $str = $labels['em_undefined']; break;
				case 1: $str = $labels['em_manual']; break;
				case 2: $str = $labels['em_auto']; break;
				case 3: $str = $labels['em_time_auto']; break;
				case 4: $str = $labels['em_shutter_auto']; break;
				case 5: $str = $labels['em_creative_auto']; break;
				case 6: $str = $labels['em_action_auto']; break;
				case 7: $str = $labels['em_portrait_auto']; break;
				case 8: $str = $labels['em_landscape_auto']; break;
				case 9: $str = $labels['em_bulb']; break;
				default: $str = $val.'-unknown';
			}
			break;
		case 'mm':
			switch ($val) {
				case 0: $str = $labels['mm_unkown']; break;
				case 1: $str = $labels['mm_average']; break;
				case 2: $str = $labels['mm_middle']; break;
				case 3: $str = $labels['mm_spot']; break;
				case 4: $str = $labels['mm_multi-spot']; break;
				case 5: $str = $labels['mm_multi']; break;
				case 6: $str = $labels['mm_partial']; break;
				case 255: $str = $labels['mm_other']; break;
				default: $str = $val.'-unknown';
			}
			break;
		case 'wb':
			switch($val) {
				case 0: $str = $labels['wb_auto']; break;
				case 1: $str = $labels['wb_daylight']; break;
				case 2: $str = $labels['wb_fluorescent']; break;
				case 3: $str = $labels['wb_incandescent']; break;
				case 4: $str = $labels['wb_flash']; break;
				case 9: $str = $labels['wb_fineWeather']; break;
				case 10: $str = $labels['wb_cloudy']; break;
				case 11: $str = $labels['wb_shade']; break;
				default: $str = $val.'-unknown';
			}
			break;
		case 'fl':
			switch($val) {
				case 0: $str = $labels['NotFired']; break;
				case 1: $str = $labels['Fired']; break;
				case 5: $str = $labels['StrobeReturnLightNotDetected']; break;
				case 7: $str = $labels['StrobeReturnLightDetected']; break;
				case 9: $str = $labels['Fired-CompulsoryMode']; break;
				case 13: $str = $labels['Fired-CompulsoryMode-NoReturnLightDetected']; break;
				case 15: $str = $labels['Fired-CompulsoryMode-ReturnLightDetected']; break;
				case 16: $str = $labels['NotFired-CompulsoryMode']; break;
				case 24: $str = $labels['NotFired-AutoMode']; break;
				case 25: $str = $labels['Fired-AutoMode']; break;
				case 29: $str = $labels['Fired-AutoMode-NoReturnLightDetected']; break;
				case 31: $str = $labels['Fired-AutoMode-ReturnLightDetected']; break;
				case 32: $str = $labels['Noflashfunction']; break;
				case 65: $str = $labels['Fired-RedEyeMode']; break;
				case 69: $str = $labels['Fired-RedEyeMode-NoReturnLightDetected']; break;
				case 71: $str = $labels['Fired-RedEyeMode-ReturnLightDetected']; break;
				case 73: $str = $labels['Fired-CompulsoryMode-RedEyeMode']; break;
				case 77: $str = $labels['Fired-CompulsoryMode-RedEyeMode-NoReturnLightDetected']; break;
				case 79: $str = $labels['Fired-CompulsoryMode-RedEyeMode-ReturnLightDetected']; break;
				case 89: $str = $labels['Fired-AutoMode-RedEyeMode']; break;
				case 93: $str = $labels['Fired-AutoMode-NoReturnLightDetected-RedEyeMode']; break;
				case 95: $str = $labels['Fired-AutoMode-ReturnLightDetected-RedEyeMode']; break;
				default: $str = $val.'-unknown';
			}
			break;
	}
	
	return $str;
}

function showShare($thumbnails, $share) {
	$shareName = $share['name'];
	$expDate = isset($share['expires']) ? date('D, d.m.Y',$share['expires']):'';
	$shareDescription = isset($share['expires']) ? "Shared Gallery \"$shareName\", expires on $expDate":"Shared Gallery \"$shareName\" will not expire";
	$actual_link = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
	$first = $share['first'];

	$actual_url = parse_url($actual_link);
	$actual_image = $actual_url["scheme"]."://".$actual_url["host"].$actual_url["path"]."plugins/pictures/simg.php?p=$first&t=0&w=6";

	$head = isset($share['expires']) ? "$shareName<span>(Expires $expDate)</span>":"$shareName";
	$page = "<!DOCTYPE html>
	<html>
		<head>
			<meta charset='UTF-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=Edge'>
			<meta name='viewport' content='width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no'>

			<meta property='og:title' content='Gallery: $shareName' />
			<meta property='og:description' content='$shareDescription' />
			<meta property='og:url' content='$actual_link' />
			<meta property='og:image' itemprop='image' content='$actual_image' />
			<meta property='og:image:width' content='1200' />
			<meta property='og:image:height' content='675' />
			<meta property='og:site_name' content='Gallery: $shareName' />
			<meta property='og:type' content='website' />

			<link rel='apple-touch-icon' sizes='180x180' href='plugins/pictures/images/apple-touch-icon.png'>
			<link rel='icon' type='image/png' sizes='32x32' href='plugins/pictures/images/favicon-32x32.png'>
			<link rel='icon' type='image/png' sizes='16x16' href='plugins/pictures/images/favicon-16x16.png'>

			<title>$shareName</title>
			<meta name='description' content='$shareDescription' />
			
			<link rel='stylesheet' href='plugins/pictures/js/justifiedGallery/justifiedGallery.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/skins/main.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/js/glightbox/glightbox.min.css' type='text/css' />
			<link rel='stylesheet' href='plugins/pictures/js/plyr/plyr.css' type='text/css' />
			<script src='program/js/jquery.min.js'></script>
			<script src='plugins/pictures/js/justifiedGallery/jquery.justifiedGallery.min.js'></script>
			<script src='plugins/pictures/js/glightbox/glightbox.min.js'></script>
			<script src='plugins/pictures/js/plyr/plyr.js'></script>
			<script src='plugins/pictures/js/pshare.min.js'></script>
			";
	$page.= "\n\t\t</head>\n\t\t<body class='picbdy sshare'><div id='slide_progress'></div>";
	$page.= "\n\t\t\t<div id='header' style='position: fixed; padding-left: 0; width: 100%'><h2 style='align-items: center; display: inline-flex; padding-left: 20px;text-shadow: 1px 1px 3px rgba(15,15,15,1);color: white;'>$head</h2>";
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