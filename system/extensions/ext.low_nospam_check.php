<?php
/*
=====================================================
 This extension was created by Lodewijk Schutte
 - freelance@loweblog.com
 - http://loweblog.com/freelance/
=====================================================
 File: ext.low_no_spam_check.php
-----------------------------------------------------
 Purpose: Checks comment for spam using mod.low_nospam.php
=====================================================
*/

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

class Low_nospam_check
{
	var $settings		= array();

	var $name			= 'Low NoSpam';
	var $version		= '1.0.2';
	var $description	= 'Anti-spam utility using online services like Akismet and TypePad AntiSpam';
	var $settings_exist = 'y';
	var $docs_url		= '';
	
	var $input			= array();
	var $API;


	// -------------------------------
	// Constructor
	// -------------------------------
	function Low_nospam_check($settings='')
	{
		global $LANG;
		
		$LANG->fetch_language_file('low_nospam');
		
		$this->settings = $settings;
	}
	// END Low_nospam_check


	
	// --------------------------------
	//	Settings
	// --------------------------------	
	function settings()
	{
		return array(
			'service'					=> array('s', array('akismet' => "akismet", 'tpas' => "tpas"), 'akismet'),
			'api_key'					=> '',
			'check_comments'			=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'check_gallery_comments'	=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'check_members'				=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_forum_posts'			=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_wiki_articles'		=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_trackbacks'			=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'moderate_if_unreachable'	=> array('r', array('y' => "yes", 'n' => "no"), 'y')
		);
	}
	// END settings



	// --------------------------------
	//	Check comment (method called by extension)
	// --------------------------------		 
	function check_comment($data)
	{
		global $SESS, $DB;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_comments'] == 'y')
		{
			// Check members too?
			if (!$SESS->userdata['member_id'] OR ($SESS->userdata['member_id'] AND $this->settings['check_members'] == 'y'))
			{
				// Input array
				$this->input = array(
					'comment_author'		=> $data['name'],
					'comment_author_email'	=> $data['email'],
					'comment_author_url'	=> $data['url'],
					'comment_content'		=> $data['comment']				
				);

				// Check it!
				if ($this->is_spam())
				{
					// set comment status to 'c' 
					$data['status'] = 'c';
					
					// insert closed comment to DB
					$DB->query($DB->insert_string('exp_comments', $data));
					
					// Exit
					$this->abort();
				}
			}
		}
		
		// return data as if nothing happened...
		return $data;
	}
	// END check_comment
	
		
	 
	// --------------------------------
	//	Check trackback (method called by extension)
	// --------------------------------		 
	function check_trackback($data)
	{
		// check settings to see if trackback needs to be verified
		if ($this->settings['check_trackbacks'] == 'y')
		{
			// input array
			$this->input = array(
				'user_ip'				=> $data['trackback_ip'],
				'comment_type'			=> 'trackback',
				'comment_author'		=> str_replace('&#45;','-',$data['weblog_name']),
				'comment_author_email'	=> '',
				'comment_author_url'	=> $data['trackback_url'],
				'comment_content'		=> $data['title']."\n\n".$data['content']
			);

			// Check it!
			if ($this->is_spam())
			{
				// send xml response and exit script
				header('Content-Type: application/xml');
				die("<?xml version=\"1.0\" encoding=\"utf-8\"?".">\n<response>\n<error>1</error>\n<message>Trackback considered spam</message>\n</response>");
			}
		}
		
		// return data as if nothing happened...
		return $data;
	}
	// END check_trackback
	
	
	
	// --------------------------------
	//	Check forum post (method called by extension)
	// --------------------------------		 
	function check_forum_post($obj)
	{
		global $SESS, $IN;

		// check settings to see if trackback needs to be verified
		if ($this->settings['check_forum_posts'] == 'y')
		{
			// input array
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> (strlen($SESS->userdata['screen_name']) ? $SESS->userdata['screen_name'] : $SESS->userdata['username']),
				'comment_author_email'	=> $SESS->userdata['email'],
				'comment_author_url'	=> $SESS->userdata['url'],
				'comment_content'		=> ($IN->GBL('title') ? $IN->GBL('title')."\n\n" : '').$IN->GBL('body')
			);

			// Check it!
			if ($this->is_spam())
			{
				// No forum post moderation, so just exit
				$this->abort();
			}
		}

		// hook doesn't return anything
	}
	// END check_forum_post
	
	
	
	// --------------------------------
	//	Check gallery comment (method called by extension)
	// --------------------------------		 
	function check_gallery_comment()
	{
		global $SESS, $IN, $DB, $LOC;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_gallery_comments'] == 'y')
		{
			// Check members too?
			if (!$SESS->userdata['member_id'] OR ($SESS->userdata['member_id'] AND $this->settings['check_members'] == 'y'))
			{
				// Input array
				$this->input = array(
					'comment_author'		=> $SESS->userdata['screen_name']	? $SESS->userdata['screen_name']	: $IN->GBL('name'),
					'comment_author_email'	=> $SESS->userdata['email']			? $SESS->userdata['email']			: $IN->GBL('email'),
					'comment_author_url'	=> $SESS->userdata['url']			? $SESS->userdata['url']			: $IN->GBL('url'),
					'comment_content'		=> $IN->GBL('comment')
				);
				
				// Check it!
				if ($this->is_spam())
				{
					// gallery entry id
					$entry_id = $DB->escape_str($IN->GBL('entry_id'));
					
					// get gallery id
					$query = $DB->query("SELECT gallery_id FROM exp_gallery_entries WHERE entry_id = '{$entry_id}'");
					
					// row to insert
					$data = array(
						'entry_id'		=> $entry_id,
						'gallery_id'	=> $query->row['gallery_id'],
						'author_id'		=> $SESS->userdata['member_id'],
						'name'			=> $this->input['comment_author'],
						'email'			=> $this->input['comment_author_email'],
						'url'			=> $this->input['comment_author_url'],
						'location'		=> $SESS->userdata['location'] ? $SESS->userdata['location'] : $IN->GBL('location'),
						'ip_address'	=> $SESS->userdata['ip_address'],
						'comment_date'	=> $LOC->now,
						'comment'		=> $this->input['comment_content'],
						'notify'		=> $IN->GBL('notify_me') ? 'y' : 'n',
						// Set status to closed
						'status'		=> 'c'
					);
					
					// insert closed comment to DB
					$DB->query($DB->insert_string('exp_gallery_comments', $data));
					
					// Exit
					$this->abort();
				}
			}
		}
		
		// hook doesn't return anything
	}
	// END check_gallery_comment
	
	
	
	// --------------------------------
	//	Check wiki article (method called by extension)
	// --------------------------------		 
	function check_wiki_article($obj, $query)
	{
		global $SESS, $IN, $DB;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_wiki_articles'] == 'y')
		{
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> (strlen($SESS->userdata['screen_name']) ? $SESS->userdata['screen_name'] : $SESS->userdata['username']),
				'comment_author_email'	=> $SESS->userdata['email'],
				'comment_author_url'	=> $SESS->userdata['url'],
				'comment_content'		=> $IN->GBL('title').' '.$IN->GBL('article_content')
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// HANDLE WIKI ARTICLE SPAM
				$wiki_id = $obj->wiki_id;
				$page_id = $DB->escape_str($query->row['page_id']);
				
				// get real last revision id
				$query  = $DB->query("SELECT last_revision_id FROM exp_wiki_page WHERE wiki_id = {$wiki_id} AND page_id = {$page_id}");
				$rev_id = $query->row['last_revision_id'];

				// close revision
				$DB->query("UPDATE exp_wiki_revisions SET revision_status = 'closed' WHERE wiki_id = {$wiki_id} AND page_id = {$page_id} AND revision_id = {$rev_id}");
				
				$this->abort();
			}

		}
		
		// hook doesn't return anything
	}
	// END check_wiki_article
	



	// --------------------------------
	//	Check input
	// -------------------------------- 
	function is_spam()
	{
		global $SESS, $EXT, $DB, $OUT, $LANG;
		
		// init return value
		$is_spam = FALSE;
		
		// Get the Akismet class needed to perform the check
		if (!class_exists('Low_nospam'))
		{
			if (file_exists(PATH_MOD.'low_nospam/mod.low_nospam'.EXT))
			{
				require(PATH_MOD.'low_nospam/mod.low_nospam'.EXT);
			}
			else
			{
				// required low_nospam class not found: throw out error
				return $OUT->show_user_error('general', $LANG->line('low_nospam_class_not_found'));
			}
		}
			 
		// initiate NoSpam class
		$this->API = new Low_nospam();
		
		// Available but API key was not valid, throw out error
		if ($this->API->is_available && !$this->API->is_valid)
		{
			return $OUT->show_user_error('general', $LANG->line('invalid_api_key'));
		}
		
		// Not available, check settings
		if (!$this->API->is_available && $this->settings['moderate_if_unreachable'] == 'y')
		{
			$is_spam = TRUE;
		}
		
		// Available and valid, regular check
		if ($this->API->is_available && $this->API->is_valid)
		{
			$this->API->prep_data($this->input);
			$is_spam = $this->API->is_spam();
		}
		
		// return boolean
		return $is_spam;
	}
	// END is_spam


	// --------------------------------
	//	Exit!!!
	// --------------------------------
	function abort()
	{
		global $OUT, $LANG, $EXT;
		
		// set end_script to true
		$EXT->end_script = true;
		
		// return error message
		$feedback = ($this->API->is_available) ? $LANG->line('low_nospam_thinks_this_is_spam') : $LANG->line('service_unreachable');
		return $OUT->show_user_error('submission', $feedback);
		
		// hand break
		exit;
	}
	//END abort


	// --------------------------------
	//	Activate Extension
	// --------------------------------
	
	function activate_extension()
	{
		global $DB;
		
		// Hooks and methods
		$hooks = array(
			'insert_comment_insert_array'	=> 'check_comment',
			'insert_trackback_insert_array'	=> 'check_trackback',
			'forum_submit_post_start'		=> 'check_forum_post',
			'gallery_insert_new_comment'	=> 'check_gallery_comment',
			'edit_wiki_article_end'			=> 'check_wiki_article'
		);
		
		// insert hooks and methods
		foreach ($hooks AS $hook => $method)
		{
			$DB->query(
				$DB->insert_string(
					'exp_extensions', array(
						'extension_id'	=> '',
						'class'			=> __CLASS__,
						'method'		=> $method,
						'hook'			=> $hook,
						'settings'		=> '',
						'priority'		=> 1,
						'version'		=> $this->version,
						'enabled'		=> 'y'
					)
				)
			); // end db->query
		}
	}
	// END activate_extension
	 
	 
	// --------------------------------
	//	Update Extension
	// --------------------------------	
	function update_extension($current = '')
	{
		global $DB;
		
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
		
		$DB->query("UPDATE exp_extensions SET version = '{$this->version}' WHERE class = '".__CLASS__."'");
	}
	// END update_extension


	// --------------------------------
	//	Disable Extension
	// --------------------------------
	function disable_extension()
	{
		global $DB;
		
		$DB->query("DELETE FROM exp_extensions WHERE class = '".__CLASS__."'");
	}
	// END disable_extension
	 
}
// END CLASS
?>