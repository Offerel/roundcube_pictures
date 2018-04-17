#!/usr/bin/php
<?php
/*
 +-----------------------------------------------------------------------+
 | plugins/pictures/mainain.sh                                           |
 |                                                                       |
 | This file is part of the Pictures Roundcube Plugin                    |
 | Copyright (C) 2018, Offerel                                           |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Create Thumbnails via CLI for new uploaded photos                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Offerel                                                       |
 +-----------------------------------------------------------------------+
*/
define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/');
require INSTALL_PATH.'program/include/clisetup.php';
$rcmail = rcube::get_instance();
$users = array();

$db = $rcmail->get_dbh();
$result = $db->query("SELECT username FROM users;");
$rcount = $db->num_rows($result);

for ($x = 0; $x < $rcount; $x++) {
	$users[] = $db->fetch_array($result)[0];
}

foreach($users as $username) {
	//echo $user."\n";
	$pictures_basepath = str_replace("%u", $username, $rcmail->config->get('pictures_path', false));
	$errff = read_photos($path);
}

function read_photos($path) {
	$files = array();
	global $errff;
	chdir(dirname(__FILE__));
	$support_arr = array("jpg","jpeg","png","gif","tif","mp4","mov","wmv","avi","mpg","3gp");
	
	if ($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if($file === '.' || $file === '..') {
				continue;
			}
			
			if(is_dir($path."/".$file."/")) {
				$files[$file] = read_photos($path."/".$file."");
			}
			else {
				if ( in_array( strtolower(pathinfo($file)['extension']), $support_arr ) ) {
					$files[] = $path."/".$file;
					if (exec("/usr/bin/php ".dirname(__FILE__)."/createthumb.php \"".$file."\" 220")) {
						$errff[] = $path."/".$file;
					}
				}
			}
		}
		closedir($handle);
	}
	return $errff;
}
?>