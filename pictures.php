<?php
/**
 * Roundcube Pictures Plugin
 *
 * @version 0.9.4
 * @author Offerel
 * @copyright Copyright (c) 2018, Offerel
 * @license GNU General Public License, version 3
 */
class pictures extends rcube_plugin
{
	public $task = '?(?!login|logout).*';
	
	public function init()
	{
		$rcmail = rcmail::get_instance();
		$this->load_config();
		$this->add_texts('localization/', true);
		$this->include_stylesheet($this->local_skin_path().'/plugin.css');
		
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
	
	function action()
	{
		$rcmail = rcmail::get_instance();	

		$rcmail->output->add_handlers(array('picturescontent' => array($this, 'content'),));
		$rcmail->output->set_pagetitle($this->gettext('pictures'));
		$rcmail->output->send('pictures.template');
	}
	
	function content($attrib)
	{
		$rcmail = rcmail::get_instance();
		$this->include_script('plugin.js');

		$attrib['src'] = 'plugins/pictures/photos.php';

		if (empty($attrib['id']))
			$attrib['id'] = 'rcmailpicturescontent';
		$attrib['name'] = $attrib['id'];

		return $rcmail->output->frame($attrib);
	}
}