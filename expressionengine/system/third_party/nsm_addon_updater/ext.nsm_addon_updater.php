<?php
/**
 * Addon Notifier / Updater extension for ExpressionEngine
 *
 * @package NSM
 * @subpackage Addon Updater
 * @author Leevi Graham
 * 
 * NSM Addon Updater is an EE 2.0 extension that checks an external RSS feed for version updates and displays them on your homepage.
 * 
 * If you want to include NSM Addon updater support in your addon just add the following public variable to any extension class:
 * 
 * 		public $versions_xml = "http://yourdomain.com/versions.xml";
 * 
 * The url should point to a valid RSS 2.0 XML feed that lists individual versions of your addon as <items>. 
 * There is only one required addition to a standard feed: <ee_addon:version>1.0.0b1</ee_addon:version> which is used for version comparison.
 * 
 * Each feed is individually cached so that the CURL calls don't stall the loading of the CP. 
 * Additionally the calls are made via AJAX so there should be no negative affect on CP load.
 * 
 * Example RSS 2.0 XML Feed:
 * 
 * <?xml version="1.0" encoding="utf-8"?>
 * <rss version="2.0" xmlns:ee_addon="http://yourdomain.com/nsm_addon_updater/#rss-xml">
 * 	<channel>
 * 		<title>NSM Addon Updater Changelog</title>
 * 		<link>http://yourdomain.com/nsm.addon_updater.ee_addon/appcast.xml</link>
 * 		<description>Most recent changes with links to updates.</description>
 * 		<item>
 * 			<title>Version 1.0.0b1</title>
 * 			<ee_addon:version>1.0.0b1</ee_addon:version>
 * 			<link>http://yourdomain.com/nsm.addon_updater.ee_addon/1.0.0b1/</link>
 * 			<pubDate>Wed, 09 Jan 2006 19:20:11 +0000</pubDate>
 * 			<description><![CDATA[
 * 				<ul>
 * 					<li>Added the {selected_group_id} variable for available use in the User Key Notification Template.</li>
 * 					<li>Added the form:attribute="" parameter type to all User functions that output forms.</li>
 * 				</ul>
 * 			]]>
 * 			</description>
 * 			<enclosure url="http://yourdomain.com/nsm.addon_updater.ee_addon/download.zip?version=1.0.0b1" length="1623481" type="application/zip" />
 * 		 </item>
 * 	</channel>
 * </rss>
 **/
class Nsm_addon_updater_ext
{
	public $addon_name = "NSM Addon Updater";
	public $name = "NSM Addon Updater";
	public $version = '1.0.0a1';
	public $docs_url = "http://leevigraham.com/";
	public $versions_xml = "https://github.com/newism/nsm.addon_updater.ee_addon/raw/master/expressionengine/system/third_party/nsm_addon_updater/versions.xml";

	public $settings_exist = "y";
	private $default_settings = array(
		'enabled' => TRUE,
		'cache_expiration' => 1,
		'member_groups' => array(1 => array('show_notification' => TRUE))
	);

	private $hooks = array("sessions_end");

	public function __construct($settings='')
	{

		$this->EE =& get_instance();

		// define a constant for the current site_id rather than calling $PREFS->ini() all the time
		if(defined('SITE_ID') == FALSE)
			define('SITE_ID', $this->EE->config->item("site_id"));

		$this->settings = ($settings == FALSE) ? $this->get_settings() : $this->save_settings_to_session($settings);

		if(
			$this->EE->input->get('D') == 'cp'
			&& $this->EE->input->get('C') == 'addons_extensions'
			&& isset($this->EE->cp)
			&& isset($this->settings['member_groups'][$this->EE->session->userdata['group_id']]['show_notification']))
		{
			$script_url = $this->EE->config->system_url() . "expressionengine/third_party/nsm_addon_updater/views/js/update.js";
			$this->EE->cp->add_to_head("<script src='".$script_url."' type='text/javascript' charset='utf-8'></script>");
		}

	}

	public function activate_extension()
	{
		$this->create_hooks();
	}

	public function disable_extension()
	{
		// Uncomment to delete settings during development
		// $this->delete_hooks();
	}

	public function update_extension()
	{
		// no need for this yet
	}

	public function settings_form()
	{
		$this->EE->lang->loadfile('nsm_addon_updater');
		$this->EE->load->library('form_validation');

		$vars['settings'] = $this->settings;
		$vars['message'] = FALSE;

		if($new_settings = $this->EE->input->post(__CLASS__))
		{
			$vars['settings'] = $new_settings;
			$this->EE->form_validation->set_rules(__CLASS__.'[cache_expiration]', 'lang:cache_expiration_field', 'trim|required|integer');
			if ($this->EE->form_validation->run())
			{
				$this->save_settings_to_db($new_settings);
				$vars['message'] = $this->EE->lang->line('extension_settings_saved_success');
			}
		}

		$vars['member_groups'] = $this->EE->db->select('group_id, group_title')
		                            ->where('site_id', SITE_ID)
		                            ->order_by('group_title')
		                            ->get('member_groups')
									->result_array();

		$vars['addon_name'] = $this->addon_name;
		return $this->EE->load->view(__CLASS__ . '/form_settings', $vars, TRUE);
	}

	private function get_settings($refresh = FALSE)
	{
		$settings = FALSE;
		if(isset($this->EE->session->cache[$this->addon_name][__CLASS__]['settings']) === FALSE || $refresh === TRUE)
		{
			$settings_query = $this->EE->db->select('settings')
			                               ->where('enabled', 'y')
			                               ->where('class', __CLASS__)
			                               ->get('extensions', 1);
			                               
			if($settings_query->num_rows())
			{
				$settings = unserialize($settings_query->row()->settings);
				$this->save_settings_to_session($settings);
			}
		}
		else
		{
			$settings = $this->EE->session->cache[$this->addon_name][__CLASS__]['settings'];
		}
		return $settings;
	}

	protected function save_settings_to_db($settings)
	{
		$this->EE->db->where('class', __CLASS__)
		             ->update('extensions', array('settings' => serialize($settings)));
	}

	protected function save_settings_to_session($settings)
	{
		$this->EE->session->cache[$this->addon_name][__CLASS__]['settings'] = $settings;
		return $settings;
	}

	public function sessions_end(&$sess)
	{
		if(
			$this->EE->input->get('nsm_addon_updater') == TRUE
			&& isset($this->settings['member_groups'][$sess->userdata['group_id']]['show_notification'])
		)
		{
			$versions = FALSE;

			if(!$feeds = $this->get_update_feeds())
				die();

			foreach ($feeds as $addon_id => $feed)
			{
				$namespaces = $feed->getNameSpaces(true);
				$latest_version = 0;
				foreach ($feed->channel->item as $version)
				{
					$ee_addon = $version->children($namespaces['ee_addon']);

					$version_number = (string)$ee_addon->version;
					if(
						version_compare($version_number, $this->EE->extensions->OBJ[$addon_id]->version, '>')
						&& version_compare($version_number, $latest_version, '>')
					)
					{
						$latest_version = $version_number;

						$versions[$addon_id]['addon_name'] = $this->EE->extensions->OBJ[$addon_id]->name;
						$versions[$addon_id]['installed_version'] = $this->EE->extensions->OBJ[$addon_id]->version;

						$versions[$addon_id]['title'] = (string)$version->title;
						$versions[$addon_id]['latest_version'] = $version_number;
						$versions[$addon_id]['notes'] = (string)$version->description;
						$versions[$addon_id]['docs_url'] = (string)$version->link;
						$versions[$addon_id]['download'] = FALSE;
						$versions[$addon_id]['created_at'] = $version->pubDate;

						if($version->enclosure)
						{
							$versions[$addon_id]['download']['url'] = (string)$version->enclosure['url'];
							$versions[$addon_id]['download']['type'] = (string)$version->enclosure['type'];
							$versions[$addon_id]['download']['size'] = (string)$version->enclosure['length'];
						}
					}
				}
			}
			print($this->EE->load->view("../third_party/nsm_addon_updater/views/Nsm_addon_updater_ext/updates", array('versions' => $versions), TRUE));
			die();
		}
	}

	private function create_hooks($hooks = FALSE)
	{
		if(!$hooks)
			$hooks = $this->hooks;

		$hook_template = array(
			'class'    => __CLASS__,
			'settings' => $this->default_settings,
			'version'  => $this->version,
		);

		foreach($hooks as $key => $hook)
		{
			if(is_array($hook))
			{
				$data["hook"] = $key;
				$data["method"] = (isset($hook["method"]) === TRUE) ? $hook["method"] : $key;
				$data = array_merge($data, $hook);
			}
			else
			{
				$data["hook"] = $data["method"] = $hook;
			}
			$hook = array_merge($hook_template, $data);
			$hook['settings'] = serialize($hook['settings']);
			$this->EE->db->insert('extensions', $hook);
		}
		
	}

	private function delete_hooks()
	{
		$this->EE->db->where('class', __CLASS__)->delete('extensions');
	}

	private function get_update_feeds()
	{	
		require APPPATH . "third_party/nsm_addon_updater/libraries/Epicurl.php";
		$sources = FALSE;
		$feeds = FALSE;
		$mc = EpiCurl::getInstance();
		foreach ($this->EE->extensions->OBJ as $addon_id => $addon)
		{
			if(isset($addon->versions_xml))
			{
				if(!$xml = $this->get_cache($addon->versions_xml))
				{
					$c = FALSE;
					$c = curl_init($addon->versions_xml);
					curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
					$curls[$addon_id] = $mc->addCurl($c);
					$xml = FALSE;
					if($curls[$addon_id]->code == "200" || $curls[$addon_id]->code == "302")
					{
						$xml = $curls[$addon_id]->data;
						$this->write_cache($xml, $addon->versions_xml);
					}
				}
				if($xml = simplexml_load_string($xml, 'SimpleXMLElement',  LIBXML_NOCDATA))
				{
					$feeds[$addon_id] = $xml;
				}
			}
		}
		return $feeds;
	}

	private function write_cache($data, $url)
	{
		$path = $this->EE->config->item('cache_path');
		$cache_path = ($path == '') ? BASEPATH.'cache/'.__CLASS__ : $path . __CLASS__;
		$filepath = $cache_path ."/". md5($url) . ".xml";

		if (! is_dir($cache_path))
			mkdir($cache_path . "", 0777, TRUE);
		
		if(! is_really_writable($cache_path))
			return;

		if ( ! $fp = @fopen($filepath, FOPEN_WRITE_CREATE_DESTRUCTIVE))
		{
			print("<!-- Unable to write cache file: ".$filepath." -->\n");
			log_message('error', "Unable to write cache file: ".$filepath);
			return;
		}

		flock($fp, LOCK_EX);
		fwrite($fp, $data);
		flock($fp, LOCK_UN);
		fclose($fp);
		@chmod($filepath, DIR_WRITE_MODE);

		print("<!-- Cache file written: " . $filepath . " -->\n");
		log_message('debug', "Cache file written: " . $filepath);
	}

	private function get_cache($url)
	{
		$path = $this->EE->config->item('cache_path');
		$cache_path = ($path == '') ? BASEPATH.'cache/'.__CLASS__ : $path . "nsm_addon_updater".__CLASS__;
		$filepath = $cache_path ."/". md5($url) . ".xml";

		if ( ! @file_exists($filepath))
			return FALSE;
	
		if ( ! $fp = @fopen($filepath, FOPEN_READ))
			return FALSE;

		if(filemtime($filepath) + ($this->settings['cache_expiration'] * 60 * 60 * 24) < time())
		{
			@unlink($filepath);
			print("<!-- Cache file has expired. File deleted: " . $filepath . " -->\n");
			log_message('debug', "Cache file has expired. File deleted");
			return FALSE;
		}

		flock($fp, LOCK_SH);
		$cache = @fread($fp, filesize($filepath));
		flock($fp, LOCK_UN);
		fclose($fp);

		print("<!-- Loaded file from cache: " . $filepath . " -->\n");

		return $cache;
	}

} // END class Nsm_addon_updater_ext