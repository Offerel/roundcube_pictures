<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 1.5.3
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
include INSTALL_PATH . 'program/include/iniset.php';
$rcmail = rcmail::get_instance();

if (!empty($rcmail->user->ID)) {
	$username = $rcmail->user->get_username();
	$pictures_path = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$basepath = rtrim($rcmail->config->get('work_path', false), '/');
	$thumb_path = $basepath."/".$username."/photos/";
	$webp_path = $basepath."/".$username."/webp/";
	$thumbsize = 300;
	
	if (!is_dir($pictures_path)) {
		if(!mkdir($pictures_path, 0755, true)) {
			error_log('Creating subfolders for $config[\'pictures_path\'] failed. Please check directory permissions.');
			die();
		}
	}
} else {
	http_response_code(403);
	die('Login failed. User is not logged in.');
}

$page_navigation = "";
$thumbnails = "";
$new = "";
$images = "";
$exif_data = "";
$messages = "";
$comment = "";
$requestedDir = null;
$label_max_length = $rcmail->config->get('label_max_length', false);
$ccmd = $rcmail->config->get('ffmpeg_cmd');
$exif_mode = $rcmail->config->get('exif');

if(isset($_FILES['galleryfiles'])) {
	$files = $_FILES['galleryfiles'];
	$folder = $_POST['folder'];
	$aAllowedMimeTypes = [ 'image/jpeg', 'video/mp4' ];

	$cFiles = count($_FILES['galleryfiles']['name']);

	for($i = 0; $i < $cFiles; $i++) {
		$fname = $_FILES['galleryfiles']['name'][$i];
		if($_FILES['galleryfiles']['size'][$i] > 0 && in_array($_FILES['galleryfiles']['type'][$i], $aAllowedMimeTypes)) {
			if(move_uploaded_file($_FILES['galleryfiles']['tmp_name'][$i], "$pictures_path$folder/$fname")) {
				if($fname == 'folder.jpg') {
					rsfolderjpg("$pictures_path$folder/$fname");
				} else {
					$exif = createthumb("$pictures_path$folder/$fname", $_FILES['galleryfiles']['type'][$i]);
					todb("$pictures_path$folder/".$fname, $rcmail->user->ID, $pictures_path, $exif);
				}
				
				$test[] = array('message' => "$fname uploaded", 'type' => 'info');
			} else {
				error_log("Uploaded picture could not moved into target folder");
				$test[] = array('message' => 'Upload failed. Permission error', 'type' => 'error');
			}
		} else {
			error_log("Uploaded picture internal error (size, mimetype)");
			$test[] = array('message' => 'Upload failed. Internal Error', 'type' => 'error');
		}
	}
	die(json_encode($test));
}

if(isset($_POST['img_action'])) {
	global $rcmail, $pictures_path;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;
	$action = $_POST['img_action'];
	$images = isset($_POST['images']) ? $_POST['images']:[];
	$org_path = isset($_POST['orgPath']) ? urldecode($_POST['orgPath']):'';
	$album_target = isset($_POST['target']) ? html_entity_decode(trim(filter_var($_POST['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS),'/')):"";

	switch($action) {
		/*
						*/
		case 'cUser':	$user = filter_var($_POST['user'], FILTER_UNSAFE_RAW);
						$query = "SELECT `user_id` FROM `users` WHERE `username` = '$user' AND user_id != $user_id;";
						$dbh->query($query);
						$uid = $dbh->fetch_assoc()['user_id'];
						die($uid);
						break;
	}
	die();
}

if( isset($_GET['g']) ) {
	$dir = $_POST['g'];
	$offset = filter_var($_POST['s'], FILTER_SANITIZE_NUMBER_INT);
	$thumbnails = showGallery($dir, $offset);
	die($thumbnails);
}

$jarr  = json_decode(file_get_contents('php://input'), true);
if(json_last_error() === JSON_ERROR_NONE && isset($jarr['action'])) {
	switch($jarr['action']) {
		case 'pixelfed_verify':
			$response = pixelfed_verify();
			break;
		case 'getSubs':
			$response = getSubs();
			break;
		case 'getshares':
			$response = getshares();
			break;
		case 'search':
			$response = search_photos($jarr['data']);
			break;
		case 'getMetadata':
			$response= get_mdata($jarr['data']['media']);
			break;
		case 'saveMetadata':
			$response = meta_files($jarr['data']);
			break;
		case 'setKeywords':
			$response = save_keywords($jarr['data']['keywords']);
			break;
		case 'albCreate':
			$response = albCreate($jarr['data']);
			break;
		case 'albMove':
			$response = albMove($jarr['data']);
			break;
		case 'albRename':
			$response = albRename($jarr['data']);
			break;
		case 'albDel':
			$response = albDel($jarr['data']);
			break;
		case 'imgMove':
			$response = imgMove($jarr['data']);
			break;
		case 'imgDel':
			$response = imgDel($jarr['data']);
			break;
		case 'share':
			$response = share($jarr['data']);
			break;
		case 'shareDel':
			$response = shareDel($jarr['data']);
			break;
		default:
			error_log('Unknown action \''.$jarr['action'].'\'');
			$response = [
				'code' => 500,
				'message' => 'Unknow task'
			];
	}

	http_response_code($response['code']);
	header('Content-Type: application/json; charset=utf-8');
	die(json_encode($response, JSON_NUMERIC_CHECK | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
}

function shareDel($data) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$share = filter_var($data['share'], FILTER_SANITIZE_NUMBER_INT);
	$query = "DELETE FROM `pic_shares` WHERE `share_id` = $share AND `user_id` = ".$rcmail->user->ID;
	$ret = $dbh->query($query);
	
	$response = [
		'code' => 200
	];

	return $response;
}

function share($data) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$images = isset($data['images']) ? $data['images']:[];
	$shareid = filter_var($data['shareid'], FILTER_SANITIZE_NUMBER_INT);
	$cdate = date("Y-m-d");
	$sharename = (empty($data['sharename'])) ? "Unkown-$cdate": filter_var($data['sharename'], FILTER_UNSAFE_RAW);
	$sharelink = bin2hex(random_bytes(25));
	$edate = filter_var($data['expiredate'], FILTER_SANITIZE_NUMBER_INT);
	$share_down = filter_var($data['download'], FILTER_SANITIZE_NUMBER_INT);
	$share_down = ($share_down > 0) ? 1:"NULL";
	$expiredate = ($edate > 0) ? $edate:"NULL";
	$type = filter_var($data['type'], FILTER_UNSAFE_RAW);
	$pixelfed = [];
	$shareintern = [];
	$response = [];

	if($type === 'pixelfed') {
		$pixelfed = sharePixelfed(filter_var($data['pf_text'], FILTER_UNSAFE_RAW), filter_var($data['pf_sens'], FILTER_VALIDATE_BOOLEAN), filter_var($data['pf_vis'], FILTER_UNSAFE_RAW), $images);
		if(count($pixelfed) > 1) {
			$response = [
				'code' => 200,
				'pixelfed' => $pixelfed
			];
		} else {
			$response = [
				'code' => 500,
				'pixelfed' => $pixelfed
			];
		}
	}

	if($type === 'intern') {
		$shareintern = shareIntern($sharename, $images, filter_var($data['suser'], FILTER_UNSAFE_RAW), filter_var($data['uid'], FILTER_SANITIZE_NUMBER_INT));
		if($shareintern['code'] === 200) {
			$response = [
				'code' => 200,
				'message' => 'Intern shared finished'
			];
		} else {
			$response = [
				'code' => 500,
				'message' => $shareintern['message']
			];
		}
	}

	if($type === 'public') {
		$user_id = $rcmail->user->ID;
		if(empty($shareid)) {
			$query = "INSERT INTO `pic_shares` (`share_name`,`share_link`,`share_down`,`expire_date`,`user_id`) VALUES ('$sharename','$sharelink',$share_down,$expiredate,$user_id)";
			$ret = $dbh->query($query);
			$shareid = ($ret === false) ? "":$dbh->insert_id("pic_shares");
		} else {
			$query = "UPDATE `pic_shares` SET `share_name`= '$sharename', `expire_date` = $expiredate WHERE `share_id`= $shareid AND `user_id` = $user_id";
			$ret = $dbh->query($query);
		}
	
		$query = "SELECT `pic_id` FROM `pic_pictures` WHERE `pic_path` IN ('".implode("','", $images)."') AND `user_id` = $user_id";
		$ret = $dbh->query($query);
	
		$query = "INSERT IGNORE INTO `pic_shared_pictures` (`share_id`, `user_id`, `pic_id`) VALUES ";
	
		$rows = $dbh->num_rows();
		for ($i=0; $i < $rows; $i++) { 
			$query.="($shareid, $user_id, ".$dbh->fetch_assoc()['pic_id']."),";
		}
	
		$query = substr_replace($query,";", -1);
		$ret = $dbh->query($query);
	
		$query = "SELECT `share_link` FROM `pic_shares` WHERE `share_id` = $shareid";
		$dbh->query($query);
		$sharelink = $dbh->fetch_assoc()['share_link'];

		$response = [
			'code' => 200,
			'link' => $sharelink
		];
	}

	$response['type'] = $type;
	return $response;
}

function imgDel($data) {
	global $pictures_path, $user_id;
	$images = isset($data['images']) ? $data['images']:[];
	$source = isset($data['source']) ? urldecode($data['source']):'';
	foreach($images as $image) {
		delSymLink($pictures_path.$source.'/'.$image);
		delimg($pictures_path.$source.'/'.$image);
		rmdb($source.'/'.$image, $user_id);
	}
	
	$response = [
		'code' => 200,
	];

	return $response;
}

function imgMove($data) {
	global $pictures_path;
	$target = isset($data['target']) ? html_entity_decode(trim(filter_var($data['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS),'/')):"";
	$nepath = filter_var($data['nepath'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	$images = isset($data['images']) ? $data['images']:[];
	$source = isset($data['source']) ? urldecode($data['source']):'';

	if (!is_dir($pictures_path.$target.$nepath)) mkdir($pictures_path.$target.'/'.$nepath, 0755, true);
	foreach($images as $image) {
		chSymLink($pictures_path.$source.'/'.$image, $pictures_path.$target.'/'.$nepath.'/'.$image);
		mvimg($pictures_path.$source.'/'.$image, $pictures_path.$target.'/'.$nepath.'/'.$image);
		mvdb($source.'/'.$image, $target.'/'.$nepath.'/'.$image);
	}

	$response = [
		'code' => 200,
	];

	return $response;
}

function albCreate($data) {
	global $pictures_path;
	$source = rtrim($pictures_path,'/').'/'.filter_var($data['source'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	$target = isset($data['target']) ? urldecode(dirname($source).'/'.filter_var($data['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)):'';

	if (!@mkdir($target, 0755, true)) {
		$error = error_get_last();
		$message = $error['message'];
		$code = 500;
		error_log($message.$target);
	} else {
		$message = "Album ".$data['target']." created";
		$code = 200;
	}

	$response = [
		'code' => $code,
		'message' => $message,
		'source' => $data['target']
	];

	return $response;
}

function albDel($data) {
	global $pictures_path, $rcmail;
	$source = rtrim($pictures_path,'/').'/'.filter_var($data['source'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	delSymLink($source);
	$rmdir = (removeDirectory($source, $rcmail->user->ID)) ? 200:500;

	$response = [
		'code' => $rmdir,
		'path' => $data['source']
	];

	return $response;
}

function albRename($data) {
	global $pictures_path;
	$source = rtrim($pictures_path,'/').'/'.filter_var($data['source'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	$target = urldecode(dirname($source).'/'.filter_var($data['target'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
	$newPath = html_entity_decode(str_replace($pictures_path,'',$target));
	$oldpath = str_replace($pictures_path,'',$source);
	
	chSymLink($source, $pictures_path.$newPath);
	mvdb($oldpath, $newPath);
	$rename = (rename($source, $target)) ? 200:500;
	
	$response = [
		'code' => $rename,
		'old' => $oldpath,
		'new' => $newPath
	];

	return $response;
}

function albMove($data) {
	global $pictures_path, $thumb_path;
	$source = rtrim($pictures_path,'/').'/'.filter_var($data['source'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	$target = isset($data['target']) ?  $pictures_path.$data['target']:'';
	$bsrc = pathinfo($source, PATHINFO_BASENAME);
	chSymLink($source, "$target/$bsrc");
	mvdb($source, "$target/$bsrc");
	$thumbnail = rename($thumb_path.str_replace($pictures_path,'',$source), $thumb_path.str_replace($pictures_path,'',$target)."/".$bsrc);
	$image = rename($source, $target."/".$bsrc);

	$code = ($thumbnail && $image) ? 200:500;
	$response = [
		'code' => $code,
		'thumbnail' => $thumbnail,
		'image' => $image,
		'target' => $data['target'],
		'source' => $data['source']
	];

	return $response;
}

function getshares() {
	global $rcmail;
	$shares = getExistingShares();

	$sarray = array([
		'id' => 0,
		'name' => $rcmail->gettext('selshr','pictures'),
		'exp' => null,
		'down' => null
	]);

	foreach ($shares as $share) {
		$sarray[] = [
			'id' => $share['share_id'],
			'name' => $share['share_name'],
			'exp' => $share['expire_date'],
			'down' => $share['share_down']
		];
	}

	$response = [
		'code' => 200,
		'shares' => $sarray
	];

	return $response;
}

function getSubs() {
	global $rcmail;
	$skip_objects = $rcmail->config->get('skip_objects');
	$pictures_path = str_replace("%u", $rcmail->user->get_username(), $rcmail->config->get('pictures_path'));
	$subdirs = getAllSubDirectories($pictures_path);

	$dirs = array($rcmail->gettext('selalb','pictures'));
	foreach ($subdirs as $dir) {
		$dir = trim(substr($dir,strlen($pictures_path)),'/');
		if(!strposa($dir, $skip_objects)) $dirs[] = $dir;
	}

	$response = [
		'code' => 200,
		'dirs' => $dirs,
	];
	
	return $response;
}

function pixelfed_verify() {
	global $rcmail;
	$base_url = $rcmail->config->get('pixelfed_instance');
	$token = $rcmail->config->get('pixelfed_token');

	if(!$base_url || !$token) {
		$response = [
			'code' => 300,
		];
	} else {
		$response = [
			'code' => 200,
			'base_url' => $base_url,
			'token' => 'tokenset',
		];
	}

	return $response;
}

function delSymLink($src) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$query = "DELETE FROM `pic_symlink_map` WHERE `symlink` LIKE '$src%';";
	$dbh->query($query);
	$query = "DELETE FROM `pic_symlink_map` WHERE `target` LIKE '$src%';";
	$dbh->query($query);
}

function chSymLink($src, $target) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$query = "UPDATE `pic_symlink_map` SET `symlink` = REPLACE(`symlink`, '$src', '$target');";
	$dbh->query($query);

	$query = "UPDATE `pic_symlink_map` SET `target` = REPLACE(`target`, '$src', '$target');";
	$dbh->query($query);

	$query = "SELECT `symlink`, `target` FROM `pic_symlink_map` WHERE `target` = '$target';";
	$res = $dbh->query($query);
	$rows = $dbh->affected_rows($res);
	if($rows > 0) {
		$links = $dbh->fetch_assoc($res);
		if(file_exists($links['symlink'])) unlink($links['symlink']);
		symlink($links['target'], $links['symlink']);
		$otime = filemtime($org_path);
		touch($links['symlink'], $otime);
	}
}

function sharePixelfed($status, $sensitive, $visibility, $images) {
	global $rcmail, $pictures_path;
	$imagepath = $pictures_path.'/'.$images[0];

	$mime = mime_content_type($imagepath);

	switch($mime) {
		case 'image/gif':
			$image = @imagecreatefromgif($imagepath);
			break;
		case 'image/jpeg':
			$image = @imagecreatefromjpeg($imagepath);
			break;
		case 'image/png':
			$image = @imagecreatefrompng($imagepath);
			break;
		case 'image/webp':
			$image = @imagecreatefrompng($imagepath);
			break;
	}

	$image = @imagecreatefromjpeg($imagepath);
	$exif = exif_read_data($imagepath);

	switch($exif['Orientation']) {
		case '3':
			$image = imagerotate($image, 180, 0);
			break;
		case '6':
			$image = imagerotate($image, 270, 0);
			break;
		case '8':
			$image = imagerotate($image, 90, 0);
			break;
	}

	$iname = basename($imagepath);
	$tmpname = sys_get_temp_dir().'/'.$iname;
	imagewebp($image, $tmpname, 60);

	$headers = array(
		'Content-Type: multipart/form-data',
		'Authorization: Bearer '.$rcmail->config->get('pixelfed_token'),
		'Accept: application/json'
	);

	$curl_session = curl_init();

	curl_setopt($curl_session, CURLOPT_URL, rtrim($rcmail->config->get('pixelfed_instance'), '/').'/api/v1.1/status/create');
	curl_setopt($curl_session, CURLOPT_POST, true);
	curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl_session, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($curl_session, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($curl_session, CURLOPT_POSTFIELDS, [
		'status' => $status,
		'sensitive' => $sensitive,
		'visibility' => $visibility,
		'file' => new CURLFile($tmpname, $iname, 'image/webp')
	]);

	$result = curl_exec($curl_session);

	curl_close($curl_session);
	imagedestroy($image);
	unlink($tmpname);

	return json_decode($result, true);
}

function shareIntern($sharename, $images, $sUser, $uid) {
	global $rcmail, $username;
	$dbh = rcmail_utils::db();
	$share_path = rtrim(str_replace("%u", $sUser, $rcmail->config->get('pictures_path', false)), '/')."/Incoming/$sharename";
	$mdmsg = '';
	$mdcode = 200;

	if(!@mkdir($share_path, 0755, true)) {
		$error = error_get_last();
		return [
			'code' => 500,
			'message' => $error['message']
		];
	}

	foreach ($images as $key => $image) {
		$org_path = rtrim(str_replace("%u", $username, $rcmail->config->get('pictures_path', false)), '/')."/".$image;
		$sym_path = rtrim(str_replace("%u", $sUser, $rcmail->config->get('pictures_path', false)), '/')."/Incoming/$sharename/".basename($image);
		if(!@symlink($org_path, $sym_path)) {
			$error = error_get_last();
			return [
				'code' => 500,
				'message' => $error['message']
			];
		}
		$otime = filemtime($org_path);
		touch($sym_path, $otime);

		$query = "INSERT IGNORE INTO `pic_symlink_map` (`user_id`,`symlink`,`target`) VALUES ($uid, '$sym_path', '$org_path')";
		$dbh->query($query);
	}

	$dtime = date("d.m.Y H:i:s");
	$logfile = $rcmail->config->get('log_dir', false)."/fssync.log";
	$line = $dtime." SharedPictures Pictures SyncOK\n";
	file_put_contents($logfile, $line, FILE_APPEND);

	$identity = $rcmail->user->get_identity();
	$to = $sUser;
	$from = $identity['name']."<$username>";

	$SENDMAIL = new rcmail_sendmail(
		[],
		[
			'sendmail' => true,
			'savedraft' => false,
			'saveonly' => false,
			'dsn_enabled' => $rcmail->config->get('dsn_default'),
			'error_handler' => function (...$args) use ($rcmail) {
				call_user_func_array(
					[$rcmail->output, 'show_message'],
					$args
				);
				$rcmail->output->send('iframe');
			},
			'charset' => 'UTF-8',
			'keepformatting' => false,
			'from' => $username,
			'mailto' => $to
		]
	);

	$headers = [
		'Date' => $rcmail->user_date(),
		'From' => $SENDMAIL->email_input_format($from, true),
		'To' => $SENDMAIL->email_input_format($to, true),
		'Subject' => $rcmail->gettext('ShareSubject','pictures'),
		'Message-ID' => $rcmail->gen_message_id($username),
		'X-Sender' => $SENDMAIL->email_input_format($username, true)
	];

	$ShareText = str_replace('%s', $sharename, str_replace('%u', $identity['name'], $rcmail->gettext('ShareText','pictures')));

	$data = array(
		'_task' => 'pictures',
		'_gallery' => 'Incoming/'.$sharename
	);

	$actual_link = str_replace('plugins/pictures/photos.php', '', "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]").'?'.http_build_query($data);
	$ShareOpen = "<a class='ogal' href='$actual_link' target='_top'>".$rcmail->gettext('ShareOpen','pictures')."</a>";
	$ShareText = str_replace('%o', $ShareOpen, $ShareText);

	$body = "<html>
				<head>
					<style>
						a.v1ogal {
							display: block;
							margin: 1em auto;
							position: relative;
							width: fit-content;
							padding: 10px;
							border-radius: 7px;
							background-color: #00acff;
							color: white;
							font-weight: 600;
							text-decoration: none;
						}
					</style>
					<title>Shared Gallery</title>
				</head>
				<body>
					<p>$ShareText</p>
				</body>
			</html>";

	$isHtml = true;
	$MAIL_MIME = $SENDMAIL->create_message($headers, $body, $isHtml, []);
	$sendStatus = $SENDMAIL->deliver_message($MAIL_MIME);

	return [
		'code' => 200,
	];
}

function meta_files($data) {
	global $pictures_path;
	$pictures_path = rtrim($pictures_path, '/');
	$files = $data['files'];
	$times = array();

	foreach ($files as $key => $value) {
		$files[$key] = "$pictures_path/$value";
		$times[$key] = filemtime("$pictures_path/$value");
	}

	$media = implode('" "', $files);
	$keywords = implode(', ', $data['keywords']);
	$description = $data['description'];
	$title = $data['title'];

	exec("exiftool -overwrite_original -title=\"$title\" -ImageDescription=\"$description\" -IPTC:Keywords=\"$keywords\" \"$media\"", $output, $error);
	$msg = ($error != 0) ? 'exiftool: '.trim(preg_replace('/\s+/', ' ', implode(',', $output))):0;

	foreach ($files as $key => $value) {
		touch($value, $times[$key]);
	}

	meta_db($data);

	if($msg != 0) {
		error_log($msg);
		$code = 500;
	} else {
		$code = 200;
	}

	$response = [
		'code' => $code,
		'message' => $msg
	];

	return $response;
}

function meta_db($data) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$uid = $rcmail->user->ID;
	$files = implode('\',\'', $data['files']);
	$keywords = implode(', ', $data['keywords']);
	$query = "SELECT `pic_id`, `pic_EXIF` FROM `pic_pictures` WHERE `pic_path` IN ('$files') AND `user_id` = $uid";
	$dbh->query($query);
	$rows = $dbh->num_rows();
	$db_data = [];
	for ($i=0; $i < $rows; $i++) { 
		array_push($db_data, $dbh->fetch_assoc());
	}

	foreach ($db_data as $key => $value) {
		$exif_arr = json_decode($value['pic_EXIF'], true);
		if(isset($keywords) && strlen($keywords) > 0) $exif_arr['Keywords'] = $keywords;
		if(isset($data['description'])  && strlen($data['description']) > 0) $exif_arr['ImageDescription'] = $data['description'];
		if(isset($data['title']) && strlen($data['title']) > 0) $exif_arr['Title'] = $data['title'];
		$query = "UPDATE `pic_pictures` SET `pic_EXIF` = '".json_encode($exif_arr)."' WHERE `pic_id` = ".$value['pic_id'];
		$dbh->query($query);
	}
}

function save_keywords($keywords) {
	global $rcmail;
	$uid = $rcmail->user->ID;
	$dbh = rcmail_utils::db();

	foreach ($keywords as $key => $value) {
		$query = "INSERT INTO `pic_tags` (`tag_name`, `user_id`) VALUES ('$value', $uid);";
		$res = $dbh->query($query);
		$tagid = ($res === false) ? "":$dbh->insert_id("pic_tags");
	}

	$query = "SELECT `tag_name` FROM `pic_tags` WHERE `user_id` = $uid ORDER BY `tag_name`;";
	$res = $dbh->query($query);
	$tags = array();

	for ($x = 0; $x < $dbh->num_rows($res); $x++) {
		array_push($tags, $dbh->fetch_assoc($res)['tag_name']);
	}
	
	$response = [
		'code' => (count($tags) > 0) ? 200:500,
		'keywords' => $tags
	];

	return $response;
}

function get_mdata($files) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$files = implode("','", $files);
	$query = "SELECT `pic_EXIF` from `pic_pictures` WHERE `pic_path` IN ('$files') AND user_id = ".$rcmail->user->ID;
	$dbh->query($query);
	$rows = $dbh->num_rows();
	$data = [];
	for ($i = 0; $i < $rows; $i++) {
		array_push($data, json_decode($dbh->fetch_assoc()['pic_EXIF'], true));
	}

	$final_keywords = 0;
	$final_title = 0;
	$final_description = 0;

	foreach ($data as $key => $exif) {
		if($key == 0 && count($data) > 1) {
			$last_keywords = isset($exif['Keywords']) ? $exif['Keywords']:'';
			$last_title = isset($exif['Title']) ? $exif['Title']:'';
			$last_description = isset($exif['ImageDescription']) ? $exif['ImageDescription']:'';
			continue;
		} elseif(count($data) == 1) {
			$final_keywords = $exif['Keywords'];
			$final_title = isset($exif['Title']) ? $exif['Title']:'';
			$final_description = isset($exif['ImageDescription']) ? $exif['ImageDescription']:'';
			continue;
		}
	  
		$keywords = isset($exif['Keywords']) ? $exif['Keywords']:'';
		$final_keywords = ($keywords == $last_keywords && $final_keywords != 2) ? $keywords:2;
		$last_keywords = $keywords;
	  
		$title = isset($exif['Title']) ? $exif['Title']:'';
		$final_title = ($title == $last_title && $final_title != 2) ? $title:2;
		$last_title = $title;
	  
		$description = isset($exif['ImageDescription']) ? $exif['ImageDescription']:'';
		$final_description = ($description == $last_description && $final_description != 2) ? $description:2;
		$last_description = $description;
	}

	$response = [
		'code' => 200,
		'mdata' => [
			"keywords" => $final_keywords,
			"title" => $final_title,
			"description" => $final_description
		]
	];
	
	return $response;
}

function search_photos($keywords) {
	global $rcmail, $pictures_path, $thumb_path, $exif_mode;
	$dbh = rcmail_utils::db();
	$wcond = "";
	foreach($keywords as $keyword) {
		$wcond.= " `pic_EXIF` LIKE '%$keyword%' AND";
	}
	$query = "SELECT * FROM `pic_pictures` WHERE$wcond `user_id` = ".$rcmail->user->ID." ORDER BY `pic_taken`;";
	$dbh->query($query);
	$rows = $dbh->num_rows();
	$pdata = [];
	for ($i = 0; $i < $rows; $i++) {
		array_push($pdata, $dbh->fetch_assoc());
	}

	$images = [];

	foreach($pdata as $image) {
		$path_parts = pathinfo("$thumb_path".$image['pic_path']);
		$file = $path_parts['filename'];
		$thumbnail = $path_parts['dirname'].'/'.$file.'.webp';
		$exif = json_decode($image['pic_EXIF'], true);

		if(isset($exif['ImageDescription'])) {
			$alt = $exif['ImageDescription'];
		} elseif (isset($exif['Titel'])) {
			$alt = $exif['Titel'];
		} elseif (isset($exif['Keywords'])) {
			$alt = $exif['Keywords'];
		} else {
			$alt = $file;
		}

		$exifInfo = ($exif_mode != 0 && isset($image['pic_EXIF'])) ? parseEXIF($exif, 'json'):NULL;
		
		$images[] = [
			'path' => $image['pic_path'],
			'alt' => $alt,
			'dim' => array(getimagesize($thumbnail)[0],getimagesize($thumbnail)[1]),
			'exif' => $exifInfo
		];
	}

	$response = [
		'code' => 200,
		'images' => $images,
		'keywords' => $keywords
	];

	return $response;
}

function removeDirectory($path, $user) {
	$files = glob($path . '/*');
	foreach ($files as $file) {
		is_dir($file) ? removeDirectory($file, $user):unlink($file);
		rmdb($file, $user);
	}
	rmdir($path);
	return true;
}

if( isset($_GET['p']) ) {
	$dir = html_entity_decode(urldecode(filter_var($_GET['p'],FILTER_SANITIZE_FULL_SPECIAL_CHARS)));
	guardAgainstDirectoryTraversal($dir);
	echo showPage(showGallery($dir), $dir);
	die();
} else {
	echo showPage(showGallery(""), '');
	die();
}

function rsfolderjpg($filename) {
	global $thumbsize;
	list($owidth, $oheight) = getimagesize($filename);
	if($owidth > $oheight) {
		$new_height = $thumbsize;
		$factor = $oheight/$thumbsize;
		$new_width = round($owidth/$factor);
	} else {
		$new_width = $thumbsize;
		$factor = $owidth/$thumbsize;
		$new_height = round($oheight/$factor);
	}

	$image = imagecreatefromjpeg($filename);
	$image_p = imagecreatetruecolor($new_width, $new_height);
	imagecopyresampled($image_p, $image, 0, 0, 0, 0, $new_width, $new_height, $owidth, $oheight);
	imagewebp($image_p, $filename, 60);
}

function showPage($thumbnails, $dir) {
	$rcmail = rcmail::get_instance();
	$theme = $rcmail->config->get('ptheme');
	$pmargins = $rcmail->config->get('pmargins');
	$dir = ltrim(rawurldecode($dir), '/');
	$gal = ltrim($dir, '/');
	$maxfiles = ini_get("max_file_uploads");
	$page = "<!DOCTYPE html>
	<html>
		<head>
			<title>$gal</title>
			<link rel='icon' type='image/png' sizes='16x16' href='images/favicon-16x16.png'>
			<link rel=\"stylesheet\" href=\"js/justifiedGallery/justifiedGallery.min.css\" type=\"text/css\" />
			<link rel='stylesheet' href='skins/main.min.css' type='text/css' />
			<link rel='stylesheet' href='skins/pth_$theme.css' type='text/css' />
			<link rel='stylesheet' href='js/glightbox/glightbox.min.css' type='text/css' />
			<link rel='stylesheet' href='js/plyr/plyr.css' type='text/css' />
			<link rel='stylesheet' href='js/tagify/tagify.css' type='text/css' />
			<script src=\"../../program/js/jquery.min.js\"></script>
			<script src=\"js/justifiedGallery/jquery.justifiedGallery.min.js\"></script>
			<script src='js/glightbox/glightbox.min.js'></script>
			<script src='js/plyr/plyr.js'></script>
			";
	$aarr = explode('/',$dir);
	$path = "";
	$albumnav = "<li><a href='?p='>Start</a></li>";
	foreach ($aarr as $folder) {
		$path = $path.'/'.$folder;
		if(strlen($folder) > 0) $albumnav.= "<li><a href='?p=$path'>$folder</a></li>";
	}
	$page.= "</head>
	\t\t<body class='picbdy'>
	\t\t\t<div id='loader' class='lbg'><div class='db-spinner'></div></div>
	
	\t\t\t<div id='header'>
	\t\t\t\t<ul class='breadcrumb'>
	\t\t\t\t\t$albumnav
	\t\t\t\t</ul>
	\t\t\t</div>
	\t\t\t<div id='progress' class='progress'><div class='progressbar'></div></div>
	\t\t\t<div id='galdiv'>";
	$page.= $thumbnails;
	$page.="
	<script>
		var clicks = 0;
		let ntitle = '$gal';
		let btitle = ntitle.split('/');
		let ttitle = (ntitle.length > 0) ? 'Fotos - ' + btitle[btitle.length - 1 ]:'Fotos';
		window.parent.document.title = ttitle;

		if (document.readyState !== 'complete') {
			aLoader('hidden');
		}
		
		let headerN = document.querySelector('#header .breadcrumb');
		
		headerN.lastElementChild.addEventListener('click', function(e){
			if(headerN.childElementCount > 1) {
				e.preventDefault();
				window.parent.edit_album();
			}
		});
		
		Array.from(document.getElementsByClassName('folder')).forEach(
			function(e,i,a) { e.addEventListener('click', function() {aLoader()}) }
		);

		$('#folders').justifiedGallery({
			rowHeight: 220,
			maxRowHeight: 220,
			margins: $pmargins,
			border: 0,
			rel: 'folders',
			lastRow: 'nojustify',
			captions: false,
			randomize: false,
		});
		
		$('#images').justifiedGallery({
			rowHeight: 220,
			margins: $pmargins,
			border: 0,
			rel: 'gallery',
			lastRow: 'nojustify',
			captions: false,
			randomize: false,
		});
		
		$('#images').justifiedGallery().on('jg.complete', function(e) {
			if(e.currentTarget.clientHeight > 100 && e.currentTarget.clientHeight < document.documentElement.clientWidth) {
				lazyload();
			}
		});
		
		$(window).scroll(function() {
			lazyload();
		});
		
		lightbox = GLightbox({
			plyr: {
				config: {
					muted: true,
				}
			},
			autoplayVideos: false,
			loop: false,
			videosWidth: '100%',
			closeOnOutsideClick: false
		});

		lightbox.on('slide_changed', (data) => {
			document.querySelector('.gnext').classList.remove('mvbtn');
			if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
			if(document.getElementById('dlbtn'))document.getElementById('dlbtn').remove();
			if(document.getElementById('fbtn'))document.getElementById('fbtn').remove();

			document.querySelectorAll('.exinfo').forEach(element => {
				element.classList.remove('eshow');
			});

			let file = new URL(data.current.slideConfig.href).searchParams.get('file').split('/').slice(-1)[0];
			let dlbtn = document.createElement('button');
			let fbtn = document.createElement('button');
			dlbtn.id = 'dlbtn';
			fbtn.id = 'fbtn';
			dlbtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" xml:space=\"preserve\" width=\"23\" height=\"23\" fill=\"#fff\" stroke=\"#fff\" viewBox=\"0 0 29.978 29.978\"><path d=\"M25.462 19.105v6.848H4.515v-6.848H.489v8.861c0 1.111.9 2.012 2.016 2.012h24.967c1.115 0 2.016-.9 2.016-2.012v-8.861h-4.026zm-10.842-.679-5.764-6.965s-.877-.828.074-.828h3.248V.494S12.049 0 12.793 0h4.572c.536 0 .524.416.524.416v10.008h2.998c1.154 0 .285.867.285.867s-4.904 6.51-5.588 7.193c-.492.495-.964-.058-.964-.058z\"/></svg>';
			fbtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 14 14\"><path fill=\"#fff\" fill-rule=\"evenodd\" d=\"M2 9H0v5h5v-2H2V9ZM0 5h2V2h3V0H0v5Zm12 7H9v2h5V9h-2v3ZM9 0v2h3v3h2V0H9Z\"/></svg>';
			dlbtn.addEventListener('click', e => {
				window.location = 'simg.php?w=3&file=' + new URL(data.current.slideConfig.href).searchParams.get('file').replace(/([^:])(\/\/+)/g, '$1/');
			})
			fbtn.addEventListener('click', e => {
				if(document.fullscreenElement){ 
					document.exitFullscreen() 
				} else { 
					document.getElementById('glightbox-body').requestFullscreen();
				}
			});
			let closebtn = document.querySelector('.gclose');
			closebtn.before(dlbtn);
			closebtn.before(fbtn);

			let iBox = document.getElementById(file);
			let infobtn = document.createElement('button');
			infobtn.id = 'infbtn';
			infobtn.innerHTML = '<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.0\" viewBox=\"0 0 160 160\"><g fill=\"white\"><path d=\"M80 15c-35.88 0-65 29.12-65 65s29.12 65 65 65 65-29.12 65-65-29.12-65-65-65zm0 10c30.36 0 55 24.64 55 55s-24.64 55-55 55-55-24.64-55-55 24.64-55 55-55z\"/><path d=\"M89.998 51.25a11.25 11.25 0 1 1-22.5 0 11.25 11.25 0 1 1 22.5 0zm.667 59.71c-.069 2.73 1.211 3.5 4.327 3.82l5.008.1V120H60.927v-5.12l5.503-.1c3.291-.1 4.082-1.38 4.327-3.82V80.147c.035-4.879-6.296-4.113-10.757-3.968v-5.074L90.665 70\"/></g></svg>';
			closebtn.before(infobtn);

			if(document.getElementById(file)) {
				infobtn.dataset.iid = file;
				infobtn.addEventListener('click', iBoxShow, true);
			} else {
				infobtn.disabled = true;
			}
		});

		lightbox.on('slide_before_change', (data) => {
			let cindex = data.current.index + 1;
			let cimages = document.getElementsByClassName('glightbox').length;
			let last = document.getElementById('last') ? false:true;
			if(cindex == cimages && last) {
				setTimeout(lazyload, 100, true);
			}
		});

		lightbox.on('close', () => {
			document.querySelectorAll('.exinfo').forEach(element => {
				element.classList.remove('eshow');
			});
		});

		lightbox.on('open', () => {
			document.querySelector('.gclose').addEventListener('click', () => {
				if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
				if(document.getElementById('dlbtn'))document.getElementById('dlbtn').remove();
				if(document.getElementById('fbtn'))document.getElementById('fbtn').remove();
				document.querySelectorAll('.exinfo').forEach(element => {
					element.classList.remove('eshow');
				});
			}, {once: true});
		});
		
		var prevScrollpos = window.scrollY;
		var header = document.getElementById('header');

		window.onscroll = function() {
			var currentScrollPos = window.scrollY;
			if (prevScrollpos > currentScrollPos) {
				header.style.top = '0';
				(currentScrollPos > 150) ? header.classList.add('shadow'):header.classList.remove('shadow');
			} else {
				header.style.top = '-55px';
				header.classList.remove('shadow')
			}
			prevScrollpos = currentScrollPos;
		}
		
		checkboxes();

		function iBoxShow(e) {
			let gnext = document.querySelector('.gnext');
			let info = document.getElementById(document.getElementById('infbtn').dataset.iid);
			let infobox = info.cloneNode(true);
			if(document.getElementById('infobox')) document.getElementById('infobox').remove();
			infobox.id = 'infobox';
			
			clicks += 1;
			if(clicks % 2 != 0) {
				infobox.classList.add('eshow')
				document.querySelector('.gcontainer').append(infobox);
				gnext.classList.add('mvbtn');
			} else {
				gnext.classList.remove('mvbtn');
			}
		}
		
		function aLoader(mode = 'visible') {
			document.getElementById('loader').style.visibility = mode;
		}
		
		function lazyload(slide = false) {
			if(document.getElementById('last') && !slide) return false;
			if(document.getElementsByClassName('glightbox').length <= 0 && !slide) return false;

			let wheight = $(document).height() - 10;
			let wposition = Math.ceil($(window).scrollTop() + $(window).height());

			if(wposition > wheight || slide) {
				$.ajax({
					type: 'POST',
					url: 'photos.php?g=1',
					async: false,
					beforeSend: aLoader('visible'),
					data: {
						g: '$gal',
						s: $('.glightbox').length
					},
					success: function(response) {
						aLoader('hidden');
						$('#images').append(response);
						$('#images').justifiedGallery('norewind');
						const html = new DOMParser().parseFromString(response, 'text/html');
						html.body.childNodes.forEach(element => {		
						if(element.children !== undefined && element.children.count > 0 && element.children[0].classList.contains('glightbox')) {
								lightbox.insertSlide({
									'href': element.children[0].href,
									'type': element.children[0].dataset.type
								});
						}
							
						});
						lightbox.reload();
						checkboxes();
						return false;
					}
				});
			}
		}
		
		function checkboxes() {
			var chkboxes = $('.icheckbox');
			var lastChecked = null;
			chkboxes.click(function(e) {
				if(!lastChecked) {
					lastChecked = this;
					return;
				}
	
				if(e.shiftKey) {
					var start = chkboxes.index(this);
					var end = chkboxes.index(lastChecked);
					chkboxes.slice(Math.min(start,end), Math.max(start,end)+ 1).prop('checked', lastChecked.checked);
	
				}
				lastChecked = this;
			});
		}
		
		function count_checks() {
			if(document.querySelectorAll('input[type=\"checkbox\"]:checked').length > 0) {
				window.parent.document.getElementById('movepicture').classList.remove('disabled');
				window.parent.document.getElementById('delpicture').classList.remove('disabled');
				window.parent.document.getElementById('sharepicture').classList.remove('disabled');
				window.parent.document.getElementById('editmeta').classList.remove('disabled');
			} else {
				window.parent.document.getElementById('movepicture').classList.add('disabled');
				window.parent.document.getElementById('delpicture').classList.add('disabled');
				window.parent.document.getElementById('sharepicture').classList.add('disabled');
				window.parent.document.getElementById('editmeta').classList.add('disabled');
			}
		}
		
		var dropZones = document.getElementsByClassName('dropzone');
		
		for (var i = 0; i < dropZones.length; i++) {
			dropZones[i].addEventListener('dragover', handleDragOver, false);
			dropZones[i].addEventListener('dragleave', handleDragLeave, false);
			dropZones[i].addEventListener('drop', handleDrop, false);
		}
		
		function handleDragOver(event){
			event.preventDefault();
			if(this.localName != 'body') {
				this.classList.add('mmn-drop');
			}
		}
		
		function handleDragLeave(event){
			event.preventDefault();
			this.classList.remove('mmn-drop');
		}
		
		function handleDrop(event){
			event.stopPropagation();
			event.preventDefault();
			this.classList.remove('mmn-drop');
			
			let url = (this.parentElement.href) ? this.parentElement.href:location.href;
			let folder = new URL(url).searchParams.get('p');
			startUpload(event.dataTransfer.files, folder);
		}
		
		function startUpload(files, folder) {
			let formdata = new FormData();
			xhr = new XMLHttpRequest();
			let maxfiles = $maxfiles;
			let mimeTypes = ['image/jpeg', 'video/mp4'];
			folder = decodeURIComponent((folder + '').replace(/\+/g, '%20'));
			let progressBar = document.getElementById('progress');
			if (files.length > maxfiles) {
				console.log('You try to upload more than the max count of allowed files(' + maxfiles + ')');
				return false;
			} else {
				for (var i = 0; i < files.length; i++) {
					if (mimeTypes.indexOf(files.item(i).type) == -1) {
						console.log('Unsupported filetype(' + files.item(i).name + '), exiting');
						return false;
					} else {
						formdata.append('galleryfiles[]', files.item(i), files.item(i).name);
						formdata.append('folder',folder);
					}
				}
				
				xhr.upload.addEventListener('progress', function(event) {
					let percentComplete = Math.ceil(event.loaded / event.total * 100);
					progressBar.style.visibility = 'visible';
					progressBar.firstChild.innerHTML = percentComplete + '%';
					progressBar.firstChild.style.width = percentComplete + '%';
				});

				xhr.onload = function() {
					if (xhr.status === 200) {
						let data = JSON.parse(xhr.responseText);
						let bg = '';

						for (var i = 0; i < data.length; i++) {
							switch(data[i].type) {
								case 'error': bg = '#dc3545'; break;
								case 'warning': bg = '#ffc107'; break;
								default: bg = '#28a745';
							}

							progressBar.firstChild.style.backgroundColor = bg;
							console.log(data[i].message);
						}

						setTimeout(function() {
							progressBar.style.visibility = 'hidden';
							progressBar.firstChild.style.width = 0;
							progressBar.firstChild.style.backgroundColor = '#007bff';
							location.reload();
							count_checks();
						}, 5000);
					}
				}
				
				xhr.open('POST', 'photos.php');
				xhr.send(formdata);
			}
		}
	</script>
	";

	$page.= "</div></body></html>";
	return $page;
}

function parseEXIF($jarr, $format = 'html') {
	global $rcmail;
	if (!is_array($jarr)) return false;

	if(array_key_exists('1', $jarr)) {
		$osm_params = http_build_query(array(
			'mlat' => str_replace(',','.',$jarr[14]),
			'mlon' => str_replace(',','.',$jarr[15])
		),'','&amp;');

		$gm_params = http_build_query(array(
			'api' => 1,
			'query' => str_replace(',','.',$jarr[14]) . ',' . str_replace(',','.',$jarr[15]),
			'z' => 13
		),'','&amp;');

		$gpslink ="<img src='images/marker.png'><a class='mapl' href='https://www.openstreetmap.org/?$osm_params#map=14/".$jarr[14]."/".$jarr[15]."' target='_blank'>OSM</a> | <a class='mapl' href='https://www.google.com/maps/search/?$gm_params' target='_blank'>Google Maps</a>";
		$camera = (array_key_exists('0', $jarr) && strpos($jarr[0], explode(" ",$jarr[8])[0]) !== false) ? $jarr[0]:$jarr[8]." - ".$jarr[0];

		$exifInfo = (array_key_exists('0', $jarr)) ? $rcmail->gettext('exif_camera','pictures').": $camera<br>":"";
		$exifInfo.= (array_key_exists('5', $jarr)) ? $rcmail->gettext('exif_date','pictures').": ".date($rcmail->config->get('date_format', '')." ".$rcmail->config->get('time_format', ''), $jarr[5])."<br>":"";
		$exifInfo.= (array_key_exists('9', $jarr) && $jarr[9] != "-") ? $rcmail->gettext('exif_sw','pictures').": ".$jarr[9]."<br>":"";
		$exifInfo.= (array_key_exists('10', $jarr) && $jarr[10] != "-") ? $rcmail->gettext('exif_expos','pictures').": ".$jarr[10]."<br>":"";
		$exifInfo.= (array_key_exists('12', $jarr) && $jarr[12] != "-") ? $rcmail->gettext('exif_meter','pictures').": ".$jarr[12]."<br>":"";
		$exifInfo.= (array_key_exists('4', $jarr) && $jarr[4] != "-") ? $rcmail->gettext('exif_ISO','pictures').": ".$jarr[4]."<br>":"";
		$exifInfo.= (array_key_exists('1', $jarr) && $jarr[1] != "-") ? $rcmail->gettext('exif_focalength','pictures').": ".$jarr[1]."<br>":"";
		$exifInfo.= (array_key_exists('13', $jarr) && $jarr[13] != "-") ? $rcmail->gettext('exif_whiteb','pictures').": ".$rcmail->gettext(wb($jarr[13]),'pictures')."<br>":"";
		$exifInfo.= (array_key_exists('3', $jarr) && $jarr[3] != "-") ? $rcmail->gettext('exif_fstop','pictures').": ".$jarr[3]."<br>":"";
		$exifInfo.= (array_key_exists('11', $jarr) && $jarr[11] != "-") ? $rcmail->gettext('exif_flash','pictures').": ".$rcmail->gettext(flash($jarr[11]),'pictures')."<br>":"";
		$exifInfo.= (strlen($osm_params) > 20) ? "$gpslink<br>":"";
		$exifInfo.= (array_key_exists('6', $jarr)) ? $rcmail->gettext('exif_desc','pictures').": ".$jarr[6]."<br>":"";
	} else {
		if(array_key_exists('GPSLatitude', $jarr) && array_key_exists('GPSLongitude', $jarr)) {
			$osm_params = http_build_query(array(
				'mlat' => str_replace(',','.',$jarr['GPSLatitude']),
				'mlon' => str_replace(',','.',$jarr['GPSLongitude'])
			),'','&amp;');
			$gm_params = http_build_query(array(
				'api' => 1,
				'query' => str_replace(',','.',$jarr['GPSLatitude']) . ',' . str_replace(',','.',$jarr['GPSLongitude']),
				'z' => 13
			),'','&amp;');
			$osm = "https://www.openstreetmap.org/?$osm_params#map=14/".$jarr['GPSLatitude']."/".$jarr['GPSLongitude'];
			$google = "https://www.google.com/maps/search/?$gm_params";
			$gpslink ="<img src='images/marker.png'><a class='mapl' href='https://www.openstreetmap.org/?$osm_params#map=14/".$jarr['GPSLatitude']."/".$jarr['GPSLongitude']."' target='_blank'>OSM</a> | <a class='mapl' href='https://www.google.com/maps/search/?$gm_params' target='_blank'>Google Maps</a>";
		} else {
			$osm_params = "";
			$gm_params = "";
		}

		if(array_key_exists('Make', $jarr) && array_key_exists('Model', $jarr)) 
			$camera = (strpos($jarr['Model'], explode(" ",$jarr['Make'])[0]) !== false) ? $jarr['Model']:$jarr['Make']." - ".$jarr['Model'];			
		elseif(array_key_exists('Model', $jarr))
			$camera = $jarr['Model'];

		$exifInfo = (array_key_exists('Model', $jarr)) ? $rcmail->gettext('exif_camera','pictures').": $camera<br>":"";
		if(array_key_exists('Model', $jarr)) $eInfo[$rcmail->gettext('exif_camera','pictures')] = $camera;

		$exifInfo.= (array_key_exists('LensID', $jarr)) ? $rcmail->gettext('exif_lens','pictures').": ".$jarr['LensID']."<br>":"";
		if(array_key_exists('LensID', $jarr)) $eInfo[$rcmail->gettext('exif_lens','pictures')] = $jarr['LensID'];

		$exifInfo.= (array_key_exists('DateTimeOriginal', $jarr)) ? $rcmail->gettext('exif_date','pictures').": ".date($rcmail->config->get('date_format', '')." ".$rcmail->config->get('time_format', ''), $jarr['DateTimeOriginal'])."<br>":"";
		if(array_key_exists('DateTimeOriginal', $jarr)) $eInfo[$rcmail->gettext('exif_date','pictures')] = date($rcmail->config->get('date_format', '')." ".$rcmail->config->get('time_format', ''), $jarr['DateTimeOriginal']);

		$exifInfo.= (array_key_exists('Software', $jarr)) ? $rcmail->gettext('exif_sw','pictures').": ".$jarr['Software']."<br>":"";
		if(array_key_exists('Software', $jarr)) $eInfo[$rcmail->gettext('exif_sw','pictures')] = $jarr['Software'];

		$exifInfo.= (array_key_exists('ExposureProgram', $jarr)) ? $rcmail->gettext('exif_expos','pictures').": ".$rcmail->gettext(ep($jarr['ExposureProgram']),'pictures')."<br>":"";
		if(array_key_exists('ExposureProgram', $jarr)) $eInfo[$rcmail->gettext('exif_expos','pictures')] = $rcmail->gettext(ep($jarr['ExposureProgram']),'pictures');

		$exifInfo.= (array_key_exists('MeteringMode', $jarr)) ? $rcmail->gettext('exif_meter','pictures').": ".$rcmail->gettext(mm($jarr['MeteringMode']),'pictures')."<br>":"";
		if(array_key_exists('MeteringMode', $jarr)) $eInfo[$rcmail->gettext('exif_meter','pictures')] = $rcmail->gettext(mm($jarr['MeteringMode']),'pictures');

		$exifInfo.= (array_key_exists('ExposureTime', $jarr)) ? $rcmail->gettext('exif_exptime','pictures').": ".$jarr['ExposureTime']."s<br>":"";
		if(array_key_exists('ExposureTime', $jarr)) $eInfo[$rcmail->gettext('exif_exptime','pictures')] = $jarr['ExposureTime'];

		$exifInfo.= (array_key_exists('ISO', $jarr)) ? $rcmail->gettext('exif_ISO','pictures').": ".$jarr['ISO']."<br>":"";
		if(array_key_exists('ISO', $jarr)) $eInfo[$rcmail->gettext('exif_ISO','pictures')] = $jarr['ISO'];

		$exifInfo.= (array_key_exists('FocalLength', $jarr)) ? $rcmail->gettext('exif_focalength','pictures').": ".$jarr['FocalLength']."mm<br>":"";
		if(array_key_exists('FocalLength', $jarr)) $eInfo[$rcmail->gettext('exif_focalength','pictures')] = $jarr['FocalLength'];

		$exifInfo.= (array_key_exists('WhiteBalance', $jarr)) ? $rcmail->gettext('exif_whiteb','pictures').": ".$rcmail->gettext(wb($jarr['WhiteBalance']),'pictures')."<br>":"";
		if(array_key_exists('WhiteBalance', $jarr)) $eInfo[$rcmail->gettext('exif_whiteb','pictures')] = $rcmail->gettext(wb($jarr['WhiteBalance']),'pictures');

		$exifInfo.= (array_key_exists('FNumber', $jarr)) ? $rcmail->gettext('exif_fstop','pictures').": ƒ/".$jarr['FNumber']."<br>":"";
		if(array_key_exists('FNumber', $jarr)) $eInfo[$rcmail->gettext('exif_fstop','pictures')] = "ƒ/".$jarr['FNumber'];

		$exifInfo.= (array_key_exists('Flash', $jarr)) ? $rcmail->gettext('exif_flash','pictures').": ".$rcmail->gettext(flash($jarr['Flash']),'pictures')."<br>":"";
		if(array_key_exists('Flash', $jarr)) $eInfo[$rcmail->gettext('exif_flash','pictures')] = $rcmail->gettext(flash($jarr['Flash']),'pictures');

		$exifInfo.= (array_key_exists('Title', $jarr)) ? $rcmail->gettext('exif_title','pictures').": ".$jarr['Title']."<br>":"";
		if(array_key_exists('Title', $jarr)) $eInfo[$rcmail->gettext('exif_title','pictures')] = $jarr['Title'];

		$exifInfo.= (isset($jarr['ImageDescription']) && strlen($jarr['ImageDescription']) > 0) ? $rcmail->gettext('exif_desc','pictures').": ".$jarr['ImageDescription']."<br>":"";
		if(isset($jarr['ImageDescription']) && strlen($jarr['ImageDescription']) > 0) $eInfo[$rcmail->gettext('exif_desc','pictures')] = $jarr['ImageDescription'];
		
		if(isset($jarr['Keywords']) && is_array($jarr['Keywords'])) {
			$keywords = str_replace('u00','\u00', implode(", ", $jarr['Keywords']));
			$keywords = json_decode('"' . $keywords . '"');
		} elseif (isset($jarr['Keywords']) && !is_array($jarr['Keywords'])) {
			$keywords = str_replace('u00','\u00',$jarr['Keywords']);
			$keywords = json_decode('"' . $keywords . '"');
		} else {
			$keywords = '';
		}

		$exifInfo.= $rcmail->gettext('exif_keywords','pictures').": $keywords<br>";
		$eInfo[$rcmail->gettext('exif_keywords','pictures')] = $keywords;
		
		$exifInfo.= (array_key_exists('Copyright', $jarr)) ? $rcmail->gettext('exif_copyright','pictures').": ".str_replace("u00a9","&copy;",$jarr['Copyright'])."<br>":"";
		if(array_key_exists('Copyright', $jarr)) $eInfo[$rcmail->gettext('exif_copyright','pictures')] = $jarr['Copyright'];

		$exifInfo.= (strlen($osm_params) > 20) ? "$gpslink<br>":"";
		if(strlen($osm_params) > 20) {
			$eInfo['map']['osm'] = $osm;
			$eInfo['map']['google'] = $google;
		}
	}

	if($format === 'html') {
		return $exifInfo;
	} else {
		return $eInfo;
	}
}

function ep($val) {
	switch ($val) {
		case 0: $str = "em_undefined"; break;
		case 1: $str = "em_manual"; break;
		case 2: $str = "em_auto"; break;
		case 3: $str = "em_time_auto"; break;
		case 4: $str = "em_shutter_auto"; break;
		case 5: $str = "em_creative_auto"; break;
		case 6: $str = "em_action_auto"; break;
		case 7: $str = "em_portrait_auto"; break;
		case 8: $str = "em_landscape_auto"; break;
		case 9: $str = "em_bulb"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function mm($val) {
	switch ($val) {
		case 0: $str = "mm_unkown"; break;
		case 1: $str = "mm_average"; break;
		case 2: $str = "mm_middle"; break;
		case 3: $str = "mm_spot"; break;
		case 4: $str = "mm_multi-spot"; break;
		case 5: $str = "mm_multi"; break;
		case 6: $str = "mm_partial"; break;
		case 255: $str = "mm_other"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function wb($val) {
	switch($val) {
		case 0: $str = "wb_auto"; break;
		case 1: $str = "wb_daylight"; break;
		case 2: $str = "wb_fluorescent"; break;
		case 3: $str = "wb_incandescent"; break;
		case 4: $str = "wb_flash"; break;
		case 9: $str = "wb_fineWeather"; break;
		case 10: $str = "wb_cloudy"; break;
		case 11: $str = "wb_shade"; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function flash($val) {
	switch($val) {
		case 0: $str = 'NotFired'; break;
		case 1: $str = 'Fired'; break;
		case 5: $str = 'StrobeReturnLightNotDetected'; break;
		case 7: $str = 'StrobeReturnLightDetected'; break;
		case 9: $str = 'Fired-CompulsoryMode'; break;
		case 13: $str = 'Fired-CompulsoryMode-NoReturnLightDetected'; break;
		case 15: $str = 'Fired-CompulsoryMode-ReturnLightDetected'; break;
		case 16: $str = 'NotFired-CompulsoryMode'; break;
		case 24: $str = 'NotFired-AutoMode'; break;
		case 25: $str = 'Fired-AutoMode'; break;
		case 29: $str = 'Fired-AutoMode-NoReturnLightDetected'; break;
		case 31: $str = 'Fired-AutoMode-ReturnLightDetected'; break;
		case 32: $str = 'Noflashfunction'; break;
		case 65: $str = 'Fired-RedEyeMode'; break;
		case 69: $str = 'Fired-RedEyeMode-NoReturnLightDetected'; break;
		case 71: $str = 'Fired-RedEyeMode-ReturnLightDetected'; break;
		case 73: $str = 'Fired-CompulsoryMode-RedEyeMode'; break;
		case 77: $str = 'Fired-CompulsoryMode-RedEyeMode-NoReturnLightDetected'; break;
		case 79: $str = 'Fired-CompulsoryMode-RedEyeMode-ReturnLightDetected'; break;
		case 89: $str = 'Fired-AutoMode-RedEyeMode'; break;
		case 93: $str = 'Fired-AutoMode-NoReturnLightDetected-RedEyeMode'; break;
		case 95: $str = 'Fired-AutoMode-ReturnLightDetected-RedEyeMode'; break;
		default: $str = $val.'-unknown';
	}
	return $str;
}

function showGallery($requestedDir, $offset = 0) {
	global $pictures_path, $rcmail, $label_max_length, $exif_mode, $thumb_path;
	$ballowed = ['jpg','jpeg','mp4'];
	$files = array();
	$hidden_vid = "";
	$pnavigation = "";
	
	$dbh = rcmail_utils::db();
	$requestedDir = ltrim(rawurldecode($requestedDir), '/');
	$thumbdir = $pictures_path.$requestedDir;
	$current_dir = $thumbdir;
	$forbidden = $rcmail->config->get('skip_objects', false);
	
	if (is_dir($current_dir) && $handle = @opendir($current_dir)) {
		$query = "SELECT * FROM `pic_pictures` WHERE `pic_path` LIKE \"$requestedDir%\" AND user_id = ".$rcmail->user->ID;
		$result = $dbh->query($query);
		$rows = $dbh->num_rows();
		$pdata = [];
		for ($i = 0; $i < $rows; $i++) {
			array_push($pdata, $dbh->fetch_assoc());
		}

		while (false !== ($file = readdir($handle))) {
			if(!in_array($file, $forbidden)) {
			if (is_dir($current_dir."/".$file)) {
				if ($file != "." && $file != ".." && strpos($file, '.') !== 0) {
					checkpermissions($current_dir."/".$file);
					$requestedDir = trim($requestedDir,"/");
					$npath = trim($requestedDir.'/'.$file,'/');
					$arr_params = array('p' => $npath);
					$fparams = http_build_query($arr_params,'','&amp;');
					
					if (file_exists($current_dir.'/'.$file.'/folder.jpg')) {
						$imgUrl = "simg.php?file=".urlencode($requestedDir.'/'.$file."/folder.jpg");
					} else {
						unset($firstimage);
						$firstimage = getfirstImage("$current_dir/".$file);
						if ($firstimage != "") {
							$params = array('file' 	=> "$requestedDir/$file/$firstimage", 't' => 1);
							$imgParams = http_build_query($params);
							$imgUrl = "simg.php?$imgParams";
						} else {
							$imgUrl = "images/defaultimage.jpg";
						}
					}
					
					$dirs[] = array("name" => $file,
								"date" => filemtime($current_dir."/".$file),
								"html" => "\n\t\t\t\t\t\t<a id='".trim("$requestedDir/$file", '/')."' class='folder' href='photos.php?$fparams' title='$file'><img src='$imgUrl' alt='$file' /><span class='dropzone'>$file</span></a>"
								);
				}
			}
			
			$allowed = (in_array(strtolower(substr($file, strrpos($file,".")+1)), $ballowed)) ? true:false;
			$fullpath = $current_dir."/".$file;
			$fs = filesize($fullpath);

			$tpath = str_replace($pictures_path, $thumb_path, $fullpath);
			$path_parts = pathinfo($tpath);
			$tpath = $path_parts['dirname'].'/'.$path_parts['filename'].'.webp';
			$gis = (is_file($tpath)) ? getimagesize($tpath)[3]:"";
			$shared = (is_link("$current_dir/$file")) ? " is_shared":"";

			if ($file != "folder.jpg" && $allowed && $fs > 0 && strpos($file, '.') !== 0) {
				$requestedDir = trim($requestedDir,'/').'/';
				$linkUrl = "simg.php?file=".rawurlencode("$requestedDir$file").'&w=5&t=0';
				$dbpath = str_replace($pictures_path, '', $fullpath);
				$key = array_search("$requestedDir$file", array_column($pdata, 'pic_path'));
				$exifInfo = ($exif_mode != 0 && isset($pdata[$key]['pic_EXIF'])) ? parseEXIF(json_decode($pdata[$key]['pic_EXIF'], true)):'';
				$taken = $pdata[$key]['pic_taken'];

				checkpermissions("$current_dir/$file");

				$imgParams = http_build_query(array('file' => "$requestedDir$file", 't' => 1));
				$imgUrl = "simg.php?$imgParams";
				$caption = (strlen($exifInfo) > 10) ? "<div id='$file' class='exinfo'>$exifInfo</div>":"";
				
				if (preg_match("/.jpeg$|.jpg$|.gif$|.png$/i", strtolower($file))) {				
					$html = "\t\t\t\t\t\t<div><a class=\"image glightbox$shared\" href='$linkUrl' data-type='image'><img src=\"$imgUrl\" alt=\"$file\" $gis /></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\">$caption</div>";
				}
				
				if (preg_match("/\.mp4$|\.mpg$|\.mov$|\.avi$|\.wmv$|\.webm$/i", strtolower($file))) {
					$videos[] = array("html" => "<div style=\"display: none;\" id=\"".pathinfo($file)['filename']."\"><video class=\"lg-video-object lg-html5\" controls preload=\"none\"><source src=\"$linkUrl\" type=\"video/mp4\"></video></div>");
					$html = "\t\t\t\t\t\t<div><a class=\"video glightbox$shared\" href='$linkUrl' data-type='video'><img src=\"$imgUrl\" alt=\"$file\" $gis /><span class='video'></span></a><input name=\"images\" value=\"$file\" class=\"icheckbox\" type=\"checkbox\" onchange=\"count_checks()\">$caption</div>";
				}
				
				$files[] = array(
					"name" => $file,
					"date" => $taken,
					"size" => filesize($current_dir."/".$file),
					"html" => $html
				);
			}
			}
		}
		closedir($handle);
	} else {
		error_log('Could not open "'.htmlspecialchars(stripslashes($current_dir)).'" folder for reading!');
		die("ERROR: Please check server error log");
	}

	$thumbnails = "";
	$start = 0;

	if (isset($dirs) && sizeof($dirs) > 0) {
		$thumbnails.= "\n\t\t\t\t\t<div id='folders'>";
		array_walk($dirs, function (&$row) {
			$row['name'] = $row['name'] ?? null;
		});
		$keys = array_column($dirs, 'name');
		array_multisort($keys, SORT_ASC, $dirs);
		foreach ($dirs as $folder) {
			$thumbnails.= $folder["html"];
			$start++;
		}
		$thumbnails.= "\n\t\t\t\t\t</div>";
	}

	$thumbnails.= "\n\t\t\t\t\t<div id='images' class='justified-gallery'>";
	$thumbnails2 = "";
	$offset_end = 0;

	if (sizeof($files) > 0) {
		array_walk($files, function (&$row) {
			$row['date'] = $row['date'] ?? null;
		});
		$keys = array_column($files, 'date');
		array_multisort($keys, SORT_ASC, $files);
		
		$offset_end = $offset + $rcmail->config->get("thumbs_pr_page", false);
		$offset_end = (count($files) < $offset_end) ? count($files):$offset_end;
		
		for ($y = $offset; $y < $offset_end; $y++) {
			$thumbnails2.= "\n".$files[$y]["html"];
		}

		if(isset($videos) && sizeof($videos) > 0){
			foreach($videos as $video) {
				$hidden_vid.= $video["html"];
			}
		}
	}
	$thumbnails.= $thumbnails2;
	$thumbnails.= "\n\t\t\t\t\t</div>";
	$thumbnails.= $hidden_vid;

	if($offset_end == count($files)) $thumbnails2.= "<span id='last'></span>";

	if($offset > 0) {
		die($thumbnails2);
	} else {
		return $thumbnails;
	}
}

function getAllSubDirectories($directory, $directory_seperator = "/") {
	global $rcmail;
	$dirs = array_map(function($item)use($directory_seperator){return $item.$directory_seperator;},array_filter(glob($directory.'*' ),'is_dir'));
	foreach($dirs AS $dir) {
		$dirs = array_merge($dirs,getAllSubDirectories($dir,$directory_seperator) );
	}
	asort($dirs);
	return $dirs;
}

function getExistingShares() {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;
	$query = "SELECT `share_id`, `share_name`, `expire_date`, `share_down` FROM `pic_shares` WHERE `user_id` = 1 ORDER BY `share_name` ASC";
	$erg = $dbh->query($query);
	$rowc = $dbh->num_rows();
	$shares = [];
	for ($i = 0; $i < $rowc; $i++) {
		array_push($shares, $dbh->fetch_assoc());
	}
	return $shares;
}

if (!function_exists('exif_read_data') && $exif_mode != 0) {
	error_log('EXIF functions are not available');
}

function strposa($haystack, $needle, $offset=0) {
	if(!is_array($needle)) $needle = array($needle);
	foreach($needle as $query) {
		if(strpos($haystack, $query, $offset) !== false) return true;
	}
	return false;
}

function getfirstImage($dirname) {
	$extensions = array("jpg", "png", "jpeg", "gif");
	$images = array();
	if ($handle = opendir($dirname)) {
		while (false !== ($files[] = readdir($handle)));
		closedir($handle);
		foreach ($files as $key => $file) {
			$pathparts = pathinfo("$dirname/$file");
			if (isset($pathparts['extension']) && in_array(strtolower($pathparts['extension']), $extensions) && filesize("$dirname/$file") > 0) $images[] = $file;
		}
	}

	if (is_array($images)) {
		asort($images);
		return reset($images);
	} else {
		return null;
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

function readEXIF($file, $info) {
	$exif_arr = array();
	ini_set('exif.decode_unicode_motorola','UCS-2LE');
	$exif_data = @exif_read_data($file);

	if($exif_data && count($exif_data) > 0) {
		(isset($exif_data['Model'])) ? $exif_arr['Model'] = $exif_data['Model']:"";
		(isset($exif_data['FocalLength'])) ? $exif_arr['FocalLength'] = parse_fraction($exif_data['FocalLength']):"";
		(isset($exif_data['FNumber'])) ? $exif_arr['FNumber'] = parse_fraction($exif_data['FNumber'],2):"";
		(isset($exif_data['ISOSpeedRatings'])) ? $exif_arr['ISO'] = $exif_data['ISOSpeedRatings']:"";
		(isset($exif_data['DateTimeOriginal'])) ? $exif_arr['DateTimeOriginal'] = strtotime($exif_data['DateTimeOriginal']):filemtime($file);
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
		(isset($info['APP13'])) ? $exif_arr['Keywords'] = iptc_keywords($info['APP13']):"";
		(isset($exif_data['Artist'])) ? $exif_arr['Artist'] = $exif_data['Artist']:"";
		(isset($exif_data['Description'])) ? $exif_arr['Description'] = $exif_data['Description']:"";
		(isset($exif_data['Title'])) ? $exif_arr['Title'] = $exif_data['Title']:"";
		(isset($exif_data['Copyright'])) ? $exif_arr['Copyright'] = $exif_data['Copyright']:"";
	}

	return array_filter($exif_arr);
}

function iptc_keywords($iptcdata) {
	if(isset(iptcparse($iptcdata)['2#025'])) {
		$keywords = implode(', ', iptcparse($iptcdata)['2#025']);
	} else {
		$keywords = null;
	}
	return $keywords;
}

function exiftool($image) {
	global $pictures_basepath;
	if (`which exiftool`) {
		$tags = "-Model -FocalLength# -FNumber# -ISO# -DateTimeOriginal -ImageDescription -Make -Software -Flash# -ExposureProgram# -ExifIFD:MeteringMode# -WhiteBalance# -GPSLatitude# -GPSLongitude# -Orientation# -ExposureTime -TargetExposureTime -LensID -MIMEType -CreateDate -Artist -Description -Title -Copyright -Subject";
		$options = "-q -j -d '%s'";
		exec("exiftool $options $tags '$image' 2>&1", $output, $error);
		$joutput = implode("", $output);
		$mdarr = json_decode($joutput, true);
	} else {
		error_log("Exiftool seems to be not installed. Database can't be updated.");
	}
	return $mdarr[0];
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

function checkpermissions($file) {
	if (!is_readable($file)) {
		error_log('Can\'t read image $file, check your permissions.');
	}
}

function guardAgainstDirectoryTraversal($path) {
	$pattern = "/^(.*\/)?(\.\.)(\/.*)?$/";
	$directory_traversal = preg_match($pattern, $path);

	if ($directory_traversal === 1) {
		error_log('Could not open \"'.htmlspecialchars(stripslashes($current_dir)).'\" for reading!');
		die("ERROR: Could not open directory \"".htmlspecialchars(stripslashes($current_dir))."\" for reading!");
	}
}

function createthumb($image, $mimetype) {
	global $thumbsize, $pictures_path, $thumb_path, $ccmd, $exif_mode;
	$idir = str_replace($pictures_path, '', $image);
	$otime = filemtime($image);
	$thumb_parts = pathinfo($idir);
	$thumbnailpath = $thumb_path.$thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';
	$ttime = @filemtime($thumbnailpath);
	if(file_exists($thumbnailpath) && $otime == $ttime) return false;

	$thumbparts = pathinfo($thumbnailpath);
	$thumbpath = $thumbparts['dirname'];
		
	if (!is_dir($thumbpath)) {
		if(!mkdir($thumbpath, 0755, true)) {
			error_log("Thumbnail subfolder creation failed ($thumbpath). Please check directory permissions.");
		}
	}

	if(file_exists($thumbnailpath)) {
		if($otime == $ttime) {
			error_log("Ignore $image");
			return false;
		} else {
			error_log("ReParse $image");
		}
	}

	$exif = [];
	$mtype = explode('/', $mimetype)[0];

	if ($mtype == "image") {
		list($width, $height, $type) = getimagesize($image, $info);
		$newwidth = ($width > $height) ? ceil($width/($height/$thumbsize)):$thumbsize;
		if($newwidth <= 0) error_log("Calculating image width failed.");
		
		switch ($type) {
			case 1: $source = @imagecreatefromgif($image); break;
			case 2: $source = @imagecreatefromjpeg($image); break;
			case 3: $source = @imagecreatefrompng($image); break;
			case 6: $source = @imagecreatefrombmp($image); break;
			case 18: $source = @imagecreatefromwebp($image); break;
			case 19: $source = @imagecreatefromavif($image); break;
			default:
				corrupt_thmb($thumbnailpath);
				error_log("Unsupported media format ($type).");
		}
		
		$target = imagescale($source, $newwidth, -1, IMG_GENERALIZED_CUBIC);
		imagedestroy($source);

		switch($exif_mode) {
			case 1: $exif = readEXIF($image, $info); break;
			case 2: $exif = exiftool($image); break;
		}

		unset($exif['SourceFile']);
		if(isset($exif['ImageDescription']) && strlen($exif['ImageDescription']) < 1) unset($exif['ImageDescription']);
		if(isset($exif['Copyright']) && strlen($exif['Copyright']) < 1) unset($exif['Copyright']);
		
		if(is_writable($thumbpath)) {
			imagewebp($target, $thumbnailpath, 60);
			touch($thumbnailpath, $otime);
		} else {
			error_log("Can't write Thumbnail. Please check your directory permissions.");
		}
	} elseif ($mtype == "video") {
		$ffmpeg = exec("which ffmpeg");
		if(file_exists($ffmpeg)) {
			$pathparts = pathinfo($image);
			exec($ffmpeg." -y -v error -i \"".$image."\" -vf \"select=gte(n\,100)\" -vframes 1 -vf \"scale=w=-1:h=".$thumbsize."\" \"".$thumbnailpath."\" 2>&1", $output, $error);
			if($error != 0) {
				corrupt_thmb($thumbnailpath);
				return $exif;
			}
			touch($thumbnailpath, $otime);
			if(strlen($ccmd) > 1) {
				$ccmd = str_replace('%o', $out, str_replace('%i', $image, $ccmd));
				$out = $pathparts['dirname']."/.".$pathparts['filename'].".mp4";
				exec($ccmd, $output, $error);
			}
		} else {
			error_log("ffmpeg is not available, video formats are not supported.");
		}
	}

	$exif['MIMEType'] = $mimetype;
	return $exif;
}

function corrupt_thmb($thumbnailpath) {
	global $thumbsize;
	$sign = imagecreatefrompng('images/error2.png');
	$background = imagecreatefromjpeg('images/defaultimage.jpg');

	$sx = imagesx($sign);
	$sy = imagesy($sign);
	$ix = imagesx($background);
	$iy = imagesy($background);

	$size = 120;
	imagecopyresampled($background, $sign, ($ix-$size)/2, ($iy-$size)/2, 0, 0, $size, $size, $sx, $sy);
	$nw = ($thumbsize/$ix)*$iy;

	$image_new = imagecreatetruecolor($nw, $thumbsize);
	imagecopyresampled($image_new, $background, 0, 0, 0, 0, $nw, $thumbsize, $ix, $iy);
	imagewebp($image_new, $thumbnailpath, 60);
	imagedestroy($sign);
	imagedestroy($background);
	imagedestroy($image_new);
}

function todb($file, $user, $pictures_basepath, $exif) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$ppath = trim(str_replace($pictures_basepath, '', $file),'/');
	$result = $dbh->query("SELECT count(*), `pic_id` FROM `pic_pictures` WHERE `pic_path` = \"$ppath\" AND `user_id` = $user");
	$rarr = $dbh->fetch_array($result);
	$count = $rarr[0];
	$id = $rarr[1];

	$type = explode('/',$exif['MIMEType'])[0];
	if($type == 'image') {
		$taken = (isset($exif['DateTimeOriginal']) && is_int($exif['DateTimeOriginal'])) ? $exif['DateTimeOriginal']:filemtime($file);
	} else {
		if(isset($exif['DateTimeOriginal']) && $exif['DateTimeOriginal'] > 0 && is_int($exif['DateTimeOriginal'])) {
			$taken = $exif['DateTimeOriginal'];
		} elseif (isset($exif['CreateDate']) && $exif['CreateDate'] > 0 && is_int($exif['CreateDate'])) {
			$taken = $exif['CreateDate'];
		} else {
			$taken = strtotime(shell_exec("ffprobe -v quiet -select_streams v:0  -show_entries stream_tags=creation_time -of default=noprint_wrappers=1:nokey=1 \"$file\""));
			$taken = (empty($taken)) ? filemtime($file):$taken;
		}
	}

	$exifj = json_encode($exifj, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$exifj = addcslashes($exif,'\'');

	if($count == 0) {
		$query = "INSERT INTO `pic_pictures` (`pic_path`,`pic_type`,`pic_taken`,`pic_EXIF`,`user_id`) VALUES (\"$ppath\",'$type',$taken,'$exifj',$user)";
	} else {
		$query = "UPDATE `pic_pictures` SET `pic_taken` = $taken, `pic_EXIF` = '$exifj' WHERE `pic_id` = $id";
	}

	$dbh->query($query);
}

function rmdb($file, $user) {
	global $pictures_path;
	$dbh = rcmail_utils::db();
	$ffile = str_replace($pictures_path,'', $file);
	$query = "DELETE FROM `pic_pictures` WHERE `pic_path` like \"$ffile%\" AND `user_id` = $user";
	$ret = $dbh->query($query);
}

function mvdb($oldpath, $newPath) {
	global $rcmail;
	$dbh = rcmail_utils::db();
	$user_id = $rcmail->user->ID;

	$query = "SELECT `pic_id`, `pic_path` FROM `pic_pictures` WHERE `pic_path` like \"$oldpath%\" AND `user_id` = $user_id";
	$ret = $dbh->query($query);
	$rowc = $dbh->num_rows();

	$images = [];
	for ($i = 0; $i < $rowc; $i++) {
		array_push($images, $dbh->fetch_assoc($ret));
	}

	foreach ($images as $image) {
		$pic_id = $image['pic_id'];
		$nnewPath = str_replace($oldpath, $newPath, $image['pic_path']);
		$query = "UPDATE `pic_pictures` SET `pic_path` = \"$nnewPath\" WHERE `pic_id` = $pic_id";
		$ret = $dbh->query($query);
	}
}

function mvimg($oldpath, $newPath) {
	global $rcmail, $pictures_path, $thumb_path;
	$dfiles = $rcmail->config->get('dummy_time', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($oldpath);

	if($dfiles > 0 && substr_count($oldpath, $dfolder) > 0) {
		rename($oldpath, $newPath);
		touch($oldpath, $ftime);
	} else {
		rename($oldpath, $newPath);
	}

	$th_old = str_replace($pictures_path, $thumb_path, $oldpath);
	$thumb_parts = pathinfo($th_old);
	$th_old = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';
	$th_new = str_replace($pictures_path, $thumb_path, $newPath);
	$thumb_parts = pathinfo($th_old);
	$th_new = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';

	if(file_exists($th_old)) {
		rename($th_old, $th_new);
		touch($th_new, $ftime);
	}
}

function delimg($file) {
	global $rcmail, $pictures_path, $thumb_path, $webp_path;
	$dfiles = $rcmail->config->get('dummy_time', false);
	$dfolder = $rcmail->config->get('dummy_folder', false);
	$ftime = filemtime($file);

	if($dfiles > 0 && substr_count($file, $dfolder) > 0) {
		if(unlink($file)) touch($file, $ftime);
	} else {
		unlink($file);
	}
	
	$pathparts = pathinfo($file);
	$hiddenvid = $pathparts['dirname'].'/.'.$pathparts['filename'].'mp4';
	if(file_exists($hiddenvid)) unlink($hiddenvid);

	$thumbnailpath = str_replace($pictures_path, $thumb_path, $file);
	$thumb_parts = pathinfo($thumbnailpath);
	$thumbnailpath = $thumb_parts['dirname'].'/'.$thumb_parts['filename'].'.webp';

	if(file_exists($thumbnailpath)) unlink($thumbnailpath);

	$webp = str_replace($pictures_path, $webp_path, $file).".webp";
	if(file_exists($webp)) unlink($webp);
}

$thumbdir = rtrim($pictures_path.$requestedDir,'/');
$current_dir = $thumbdir;
guardAgainstDirectoryTraversal($current_dir);
?>