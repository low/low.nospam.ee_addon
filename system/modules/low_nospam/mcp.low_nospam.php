<?php
/*
=====================================================
 This module was created by Lodewijk Schutte
 - freelance@loweblog.com
 - http://loweblog.com/freelance/
=====================================================
 File: mcp.low_nospam.php
-----------------------------------------------------
 Purpose: handles caught comments
=====================================================
*/

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

class Low_nospam_CP {

	var $name		= 'Low_nospam';
	var $version	= '1.0.2';

	var $site_id;
	var $gallery;
	
	// -------------------------
	//	Constructor
	// -------------------------
	
	function Low_nospam_CP($switch = TRUE)
	{
		global $IN, $LANG, $PREFS, $DB;
		
		// get site id
		if ( ! ($this->site_id = $IN->GBL('cp_last_site_id','COOKIE')) )
		{
			$this->site_id = 1;
		}
		
		// Is gallery installed?
		$query = $DB->query("SELECT module_id FROM exp_modules WHERE module_name = 'Gallery'");
		$this->gallery = ($query->num_rows != 0);
			
		// Get existing language files
		$LANG->fetch_language_file('low_nospam');
		$LANG->fetch_language_file('comment');
		$LANG->fetch_language_file('publish');
		$LANG->fetch_language_file('publish_ad');
			
		if ($switch)
		{ 
			switch($IN->GBL('P'))
			{
				case 'list_action':
					$this->list_action();
				break;
				
				case 'mark_as_spam':
					$this->mark('spam');
				break;
				
				case 'upgrade':
					$this->upgrade();
				break;
				
				case 'view':
					$this->view_comments();
				break;
				
				case 'delete_all':
					$this->confirm_deletion(TRUE);
				break;
				
				default:
					$this->home();
				break;
			}
		}
	}
	// END
	
	
	
	// ----------------------------------------
	//	List action: either go to confirm delete or mark comments as ham
	// ----------------------------------------
	function list_action()
	{
			global $IN;
			
			switch ($IN->GBL('with_selected', 'POST'))
			{
				case 'delspam':
					$this->confirm_deletion();
				break;
				
				case 'openham':
					$this->mark('ham');
				break;
				
				default:
					$this->home();
				break;
			}
	}
	// END
	
	
	
	// ----------------------------------------
	//	Module Homepage
	// ----------------------------------------
	function home($msg = '')
	{
		global $DSP, $LANG, $DB, $LOC, $IN, $OUT, $FNS;
	
		// title and breadcrumb
		$DSP->title = $DSP->crumb = $LANG->line('low_nospam_module_name');
	
		// get message, if any
		if ($msg = $IN->GBL('msg','GET'))
		{
			$DSP->body .= $DSP->qdiv('box success', $LANG->line($msg));
		}
		
		// Get the Akismet class
		if ( ! class_exists('Low_nospam'))
		{
			require PATH_MOD.'low_nospam/mod.low_nospam'.EXT;
		}
		 
		// init Low_nospam class
		$API = new Low_nospam();
		
		if ($API->is_available AND !$API->is_valid)
		{
			$DSP->body .= $DSP->qdiv('box alert', $LANG->line('invalid_api_key'));
		}
		
		// Heading
		$DSP->body .= $DSP->qdiv('tableHeading', $LANG->line('low_nospam_module_name'));
	
		// check if upgrade is needed
		$query = $DB->query("SELECT module_version FROM exp_modules WHERE module_name = '{$this->name}'");
		if ($query->row['module_version'] != $this->version)
		{
			$DSP->body
				.=	$DSP->div('box defaultBold')
				.		$DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=upgrade', $LANG->line('upgrade_module'))
				.	$DSP->div_c();
		}
		// show list of closed comments
		else
		{
			// get number of closed comments
			$query = $DB->query("SELECT COUNT(*) AS num FROM exp_comments WHERE site_id = '{$this->site_id}' AND status = 'c'");
			if ($comments = $query->row['num'])
			{
				$row_comments = $DSP->table_row(array(
					array(
						'class'	=> 'tableCellTwo defaultBold',
						'text'	=> $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=view'.AMP.'T=comments',
									$comments.' '.$LANG->line((($comments == 1) ? 'closed_comment' : 'closed_comments')))
					),
					array(
						'class'	=> 'tableCellTwo defaultBold',
						'text'	=> $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=delete_all'.AMP.'T=comments', $LANG->line('delete_all'))
					)
				));
			}
			
			// Get number of closed gallery comments, if any
			if ($this->gallery)
			{
				$query = $DB->query("SELECT COUNT(*) AS num FROM exp_gallery_comments WHERE status = 'c'");
				if ($gallery_comments = $query->row['num'])
				{
					$row_gallery_comments = $DSP->table_row(array(
						array(
							'class'	=> 'tableCellOne defaultBold',
							'text'	=> $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=view'.AMP.'T=gallery',
										$gallery_comments.' '.$LANG->line((($gallery_comments == 1) ? 'closed_gallery_comment' : 'closed_gallery_comments')))
						),
						array(
							'class'	=> 'tableCellOne defaultBold',
							'text'	=> $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=delete_all'.AMP.'T=gallery', $LANG->line('delete_all'))
						)
					));
				}
			}
			else
			{
				$gallery_comments = 0;
			}

			// Handle rows
			if ($comments OR $gallery_comments)
			{
				if ($comments AND !$gallery_comments)
				{
					// redirect to comments immediately
					$FNS->redirect(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=view'.AMP.'T=comments');
				}
				else
				{
					$DSP->body
						.=	$DSP->table_open(array('class'=>'tableBorder','width'=>'100%'))
						.		($comments ? $row_comments : NULL)
						.		($gallery_comments ? $row_gallery_comments : NULL)
						.	$DSP->table_c();
				}
			}
			else
			{
				$DSP->body .= $DSP->qdiv('box success', $LANG->line('no_closed_comments'));
			}
		}
	}
	// END
	
	
	
	// ----------------------------------------
	//	View closed comments
	// ----------------------------------------
	function view_comments()
	{
		global $DSP, $LANG, $DB, $LOC, $IN, $FNS;
		
		$type = $IN->GBL('T','GET');
		$head = $LANG->line('view_comments');
		
		// title and breadcrumb
		$DSP->title = $head.' | '.$LANG->line('low_nospam_module_name');
		$DSP->crumb = $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name, $LANG->line('low_nospam_module_name')) . $DSP->crumb_item($head);
		$DSP->body = $DSP->qdiv('tableHeading', $head);
		
		// add Delete All on the right
        $DSP->right_crumb($LANG->line('delete_all'), BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'P=delete_all'.AMP.'T='.$type);


		// Create query according to type
		if ($type == 'comments')
		{
			// get closed comments
			$sql = "SELECT
				c.*, w.blog_name, t.title AS entry_title
			FROM
				exp_comments c,
				exp_weblogs w,
				exp_weblog_titles t
			WHERE
				c.status = 'c'
			AND
				c.entry_id = t.entry_id
			AND
				c.weblog_id = w.weblog_id
			AND
				c.site_id = '{$this->site_id}'
			ORDER BY
				c.comment_date DESC
			";
		}
		elseif ($type == 'gallery')
		{
			// get closed comments
			$sql = "SELECT
				c.*, g.gallery_short_name AS blog_name, e.cat_id, e.title AS entry_title
			FROM
				exp_gallery_comments c,
				exp_galleries g,
				exp_gallery_entries e
			WHERE
				c.status = 'c'
			AND
				c.entry_id = e.entry_id
			AND
				c.gallery_id = g.gallery_id
			ORDER BY
				c.comment_date DESC
			";
		}
		
		
		$query = $DB->query($sql);
			
		// Handle rows
		if ($query->num_rows)
		{
		 	$th_style = 'tableHeadingAlt';
			
			// Show list of closed comments
			$DSP->body
				.=$DSP->toggle()
				.	$DSP->form_open(array('action'=>'C=modules'.AMP.'M='.$this->name.AMP.'P=list_action'.AMP.'T='.$type, 'method'=>'post', 'name'=>'target', 'id'=>'target'))
				.		$DSP->table_open(array('class'=>'tableBorder','width'=>'100%'))
				.			$DSP->table_row(array(
								array('class' => $th_style, 'text' => $LANG->line('comment')),
								array('class' => $th_style, 'text' => $LANG->line('weblog')),
								array('class' => $th_style, 'text' => $LANG->line('view_entry')),
								array('class' => $th_style, 'text' => $LANG->line('author')),
								array('class' => $th_style, 'text' => $LANG->line('email')),
								array('class' => $th_style, 'text' => $LANG->line('url')),
								array('class' => $th_style, 'text' => $LANG->line('date')),
								array('class' => $th_style, 'text' => $LANG->line('ip_address')),
								array('class' => $th_style, 'text' => $DSP->input_checkbox('toggleflag', '', '', 'onclick="toggle(this);"'))
							));

		 	// loop thru results and build rows
		 	$i = 0;
		 	foreach ($query->result AS $row)
		 	{
				$style = (++$i % 2) ? 'tableCellTwo' : 'tableCellOne';
				
				// stripped down version of comment, like regular comment list
				$row['comment'] = strip_tags(str_replace(array("\t","\n","\r"), '', $row['comment']));
				$row['comment']	= $FNS->char_limiter(trim($row['comment']), 25);

				if ($type == 'gallery')
				{
					$edit_comment = $DSP->anchor(BASE.AMP.'C=modules'.
	             									AMP.'M=gallery'.
													AMP.'P=edit_comment'.
													AMP.'gallery_id='.$row['gallery_id'].
													AMP.'entry_id='.$row['entry_id'].
													AMP.'comment_id='.$row['comment_id'].
													AMP.'cat_id='.$row['cat_id'],
	             								$row['comment']);

					// edit entry link
					$edit_entry = $DSP->anchor(BASE.AMP.'C=modules'.
	             									AMP.'M=gallery'.
													AMP.'P=entry_form'.
													AMP.'gallery_id='.$row['gallery_id'].
													AMP.'entry_id='.$row['entry_id'],
												$row['entry_title']);
				}
				else
				{

			     	$edit_comment = $DSP->anchor(BASE.AMP.'C=edit'.
	             									AMP.'M=edit_comment'.
	             									AMP.'weblog_id='.$row['weblog_id'].
	             									AMP.'entry_id='.$row['entry_id'].
	             									AMP.'comment_id='.$row['comment_id'],
	             								$row['comment']);

					// edit entry link
					$edit_entry = $DSP->anchor(BASE.AMP.'C=edit'.
													AMP.'M=view_entry'.
													AMP.'weblog_id='.$row['weblog_id'].
													AMP.'entry_id='.$row['entry_id'],
												$row['entry_title']);
					
				}

				// table rows
				$DSP->body
					.=$DSP->table_row(array(
						array('class'=>$style,'text'=>$edit_comment),
						array('class'=>$style,'text'=>$row['blog_name']),
						array('class'=>$style,'text'=>$edit_entry),
						array('class'=>$style,'text'=>$row['name']),
						array('class'=>$style,'text'=>$row['email']),
						array('class'=>$style,'text'=>$row['url']),
						array('class'=>$style,'text'=>$LOC->set_human_time($row['comment_date'])),
						array('class'=>$style,'text'=>$row['ip_address']),
						array('class'=>$style,'text'=>$DSP->input_checkbox('toggle[]', $row['comment_id'],'',''))
					));
	 		}

			// Close table and add submit footer
			$DSP->body
				.=$DSP->table_close()
				.	$DSP->div('defaultRight')
				.		$DSP->input_submit($LANG->line('submit'))
				.		$DSP->input_select_header('with_selected')
				.			$DSP->input_select_option('delspam',$LANG->line('spam_and_delete'))
				.			($type != 'gallery' ? $DSP->input_select_option('openham',$LANG->line('ham_and_open')) : null)
				.		$DSP->input_select_footer()
				.	$DSP->div_c()
			 	. $DSP->form_close();

		}
		else
		{
			$DSP->body .= $DSP->qdiv('success', $LANG->line('no_closed_comments'));
		}
	}
	// END
	
	
	
	// ----------------------------------------
	//	Delete all closed comments and mark as spam
	// ----------------------------------------
	function delete_all()
	{
		global $IN, $LANG, $DSP, $DB;
		 
		// Title and breadcrumb
		$DSP->title = $LANG->line('delete_confirm');
		$DSP->crumb = $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name, $LANG->line('low_nospam_module_name')) . $DSP->crumb_item($LANG->line('delete_confirm'));
		
		if ($IN->GBL('T', 'GET') == 'gallery')
		{
			$sql = "SELECT comment_id FROM exp_gallery_comments WHERE status = 'c'";
		}
		else
		{
			$sql = "SELECT comment_id FROM exp_comments WHERE status = 'c' AND site_id = '{$this->site_id}'";
		}
		$query = $DB->query($sql);
		$comments = array();
		
		foreach ($query->result AS $row)
		{
			$comments[] = $row['comment_id'];
		}
		
		// start building output
		$DSP->body
			.=$DSP->form_open(array('action' => 'C=modules'.AMP.'M='.$this->name.AMP.'P=mark_as_spam'))
			.	$DSP->input_hidden('comment_ids', implode('|', $comments))
			.	$DSP->input_hidden('type', $IN->GBL('T', 'GET'))
			.	$DSP->qdiv('alertHeading', $LANG->line('delete_confirm'))
			.	$DSP->div('box')
			.		'<strong>'.$LANG->line('delete_comments_confirm').'</strong>'
			.		$DSP->br(2)
			.		$DSP->qdiv('alert', $LANG->line('action_can_not_be_undone'))
			.		$DSP->br()
			.		$DSP->input_submit($LANG->line('delete'))
			.	$DSP->div_c()
			. $DSP->form_close();

	} // END delete_all()
	
	
	
	// ----------------------------------------
	//	Confirm deletion
	// ----------------------------------------
	function confirm_deletion($all = FALSE)
	{
		global $IN, $LANG, $DSP, $DB;
		
		// init comment-id array
		$comments = array();
		$type = $IN->GBL('T', 'GET');
		
		// confirm deletion of all comments? Get appropriate ids
		if ($all)
		{
			// Get gallery comments or regular comments?
			if ($type == 'gallery')
			{
				$sql = "SELECT comment_id FROM exp_gallery_comments WHERE status = 'c'";
			}
			else
			{
				$sql = "SELECT comment_id FROM exp_comments WHERE status = 'c' AND site_id = '{$this->site_id}'";
			}
			$query = $DB->query($sql);

			foreach ($query->result AS $row)
			{
				$comments[] = $row['comment_id'];
			}
		}
		// Confirm selected comments, stored in $_POST['toggle']
		else
		{
			$comments = $IN->GBL('toggle','POST');
		}
		
		// no comments to check? Go home.
		if (!$comments)
		{
			return $this->home();
		}
		
		// Title and breadcrumb
		$DSP->title = $LANG->line('delete_confirm');
		$DSP->crumb = $DSP->anchor(BASE.AMP.'C=modules'.AMP.'M='.$this->name, $LANG->line('low_nospam_module_name')) . $DSP->crumb_item($LANG->line('delete_confirm'));
		
		// start building output
		$DSP->body
			.=$DSP->form_open(array('action' => 'C=modules'.AMP.'M='.$this->name.AMP.'P=mark_as_spam'.AMP.'T='.$type))
			.	$DSP->input_hidden('comment_ids', implode('|', $comments))
			.	$DSP->qdiv('alertHeading', $LANG->line('delete_confirm'))
			.	$DSP->div('box')
			.		'<strong>'.$LANG->line((count($comments) == 1) ? 'delete_comment_confirm' : 'delete_comments_confirm').'</strong>'
			.		$DSP->br(2)
			.		$DSP->qdiv('alert', $LANG->line('action_can_not_be_undone'))
			.		$DSP->br()
			.		$DSP->input_submit($LANG->line('delete'))
			.	$DSP->div_c()
			. $DSP->form_close();
			
	}
	// END
	


	// ----------------------------------------
	//	Mark given comments as either SPAM or HAM
	// ----------------------------------------
	function mark($as = 'spam', $type = 'comments')
	{
		global $IN, $LANG, $DSP, $FNS, $DB;
		
		// if no comments are posted, go home
		if ( ($as == 'spam' && !$IN->GBL('comment_ids','POST')) OR ($as == 'spam' && !$IN->GBL('toggle','POST')) )
		{
			return $this->home();
		}
		
		$comments = array();
		$type = $IN->GBL('T');
		
		// turn comments into array
		if ($as == 'spam')
		{
			$comments = explode('|', $IN->GBL('comment_ids','POST'));	
		}
		else
		{
			foreach($IN->GBL('toggle','POST') AS $key => $comment_id)
			{
				$comments[] = $comment_id;
				// needed to play nice with the Publish class
				$_POST['toggle_'.$key] = 'c'.$comment_id;
			}
		}
		 
		// Get the Low_nospam class
		if ( ! class_exists('Low_nospam'))
		{
			require PATH_MOD.'low_nospam/mod.low_nospam'.EXT;
		}
		 
		// init nospam class
		$NSPM = new Low_nospam();
		
		if ($NSPM->is_available AND $NSPM->is_valid)
		{
			$method = 'mark_as_'.$as;
			
			foreach ($this->get_comments($comments, $type) AS $row)
			{
				// marks the comments as spam/ham
				$NSPM->$method($row);
			}
			
			if ($type == 'gallery')
			{
				// Handle gallery comments
				if ($as == 'spam')
				{
					// Just delete 'em; they're closed, so no stats are affected
					$DB->query("DELETE FROM exp_gallery_comments WHERE comment_id IN ('".implode("','", $comments)."')");	
				}
				else
				{
					// Gallery module doesn't support batch opening of closed comments,
					// and I'll be damned if I'm gonna write all that stuff myself.
					// I'll just show a message saying 'open them using the Gallery module'...
					
					$FNS->redirect(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'msg=open_gallery_comments_not_supported');	
				}
				
			}
			else
			{
				// Get the Publish class, to delete the comments
				if ( ! class_exists('Publish'))
				{
					 require PATH_CP.'cp.publish'.EXT;
				}
				$PUB = new Publish();	 // init publish class

				// Delete or Open
				($as == 'spam') ? $PUB->delete_comment() : $PUB->change_comment_status('open');
			}
			
			// go back home, using redirect
			$FNS->redirect(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'msg=comments_'.(($as == 'spam') ? 'deleted' : 'opened'));
			exit;
		}
		else
		{
			// Service not available or invalid key...
		}
	}
	// END



	// ----------------------------------------
	//	Get comments from DB, service-friendly
	// ----------------------------------------
	function get_comments($ids = array(), $type = 'comments')
	{
		global $DB;
		
		$sql_table = ($type == 'gallery') ? 'exp_gallery_comments' : 'exp_comments';
		$sql_where = "comment_id IN ('".implode("','", $ids)."')";

		// Compose query, service-friendy
		$sql = "
			SELECT
				ip_address	AS user_ip,
				'comment'	AS comment_type,
				name		AS comment_author,
				email		AS comment_author_email,
				url			AS comment_author_url,
				comment		AS comment_content
			FROM
				{$sql_table}
			WHERE
				{$sql_where}
		";

		$query = $DB->query($sql);
		return $query;
	}
	// END
 


	// ----------------------------------------
	//	Module upgrader
	// ----------------------------------------
	function upgrade()
	{
		global $DB, $FNS;
		
		$query = $DB->query("UPDATE exp_modules SET module_version = '".$DB->escape_str($this->version)."' WHERE module_name = '".$DB->escape_str($this->name)."'");
		
		$FNS->redirect(BASE.AMP.'C=modules'.AMP.'M='.$this->name.AMP.'msg=upgraded');
	}
	// END
	
	

	// ----------------------------------------
	//	Module installer
	// ----------------------------------------
	function low_nospam_module_install()
	{
			global $DB;				
			
			$DB->query($DB->insert_string('exp_modules', array(
				'module_name'		=> $this->name,
				'module_version'	=> $this->version,
				'has_cp_backend'	=> 'y'
			)));
	}
	// END
	
	

	// ----------------------------------------
	//	Module de-installer
	// ----------------------------------------
	function low_nospam_module_deinstall()
	{
			global $DB;		
			
			$DB->query("DELETE FROM exp_modules WHERE module_name = '".$DB->escape_str($this->name)."'");
	}
	// END
}
// END CLASS


?>