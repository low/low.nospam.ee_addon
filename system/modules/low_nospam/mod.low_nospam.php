<?php
/*
=====================================================
 This module was created by Lodewijk Schutte
 - freelance@loweblog.com
 - http://loweblog.com/freelance/
=====================================================
 File: mod.low_nospam.php
-----------------------------------------------------
 Purpose: Akismet and TypePad AntiSpam utilities
=====================================================
*/

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

class Low_nospam
{
	// Selected service details
	var $api			= '';
	var $api_key		= '';
	var $user_agent		= 'ExpressionEngine/%VERSION%';
	var $blog_url		= '';

	var $is_available;
	var $is_valid;
	
	
	// Anti Spam services
	var $services = array(
		
		// Akismet details
		'akismet' => array(
			'name'		=> 'Akismet',
			'version'	=> '1.1',
			'host'		=> 'rest.akismet.com',
			'port'		=> 80
		),
		
		// TypePad AntiSpam details
		'tpas' => array(
			'name'		=> 'TypePad AntiSpam',
			'version'	=> '1.1',
			'host'		=> 'api.antispam.typepad.com',
			'port'		=> 80
		)
	
		// Maybe even more in the future?
	);
	
	// Don't send these _SERVER vars for checking
	var $ignore = array(
		'HTTP_COOKIE',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED_HOST',
		'HTTP_MAX_FORWARDS',
		'HTTP_X_FORWARDED_SERVER',
		'REDIRECT_STATUS',
		'SERVER_PORT',
		'PATH',
		'DOCUMENT_ROOT',
		'SERVER_ADMIN',
		'QUERY_STRING',
		'PHP_SELF',
		'argv'
	);

	// data to check
	var $data = array();
	
	// connection details
	var $connection;



	// -------------------------------------
	//	Constructor / Init
	// -------------------------------------
	function Low_nospam()
	{
		global $PREFS, $DB, $OUT, $LANG;
		
		// get language files
		$LANG->fetch_language_file('low_nospam');

		// get service settings from extension
		$query = $DB->query("SELECT settings FROM exp_extensions WHERE class = 'Low_nospam_check' AND settings != '' LIMIT 1");

		if ($query->num_rows)
		{
			// get serialized extension data
			$ext = unserialize($query->row['settings']);

			// Get selected API service
			if (isset($ext['service']) && isset($this->services[$ext['service']]))
			{
				$this->api = $this->services[$ext['service']];
			}
			else
			{
				return $OUT->show_user_error('general', $LANG->line('service_not_found'));	
			}
			
			// Get selected API key
			if (isset($ext['api_key']))
			{
				$this->api_key = $ext['api_key'];
			}
			else
			{
				return $OUT->show_user_error('general', $LANG->line('api_key_not_found'));	
			}
			
			// get other stuff
			$this->user_agent	= str_replace('%VERSION%', $PREFS->ini('app_version'), $this->user_agent);
			$this->blog_url		= $this->get_blog_url();
			
			// check availability and validity
			if ($this->is_available	= $this->service_is_available())
			{
				$this->is_valid = $this->key_is_valid();
			}
		}
		else
		{
			// settings not found? return error
			return $OUT->show_user_error('general', $LANG->line('settings_not_found'));
		}
	}
	// END

	
	
	// -------------------------------------
	//	Get valid blog url
	// -------------------------------------
	function get_blog_url()
	{
		global $PREFS, $FNS;

		// get blog url
		$blog = $PREFS->ini('site_url');
		
		// if site url is something like '/' or '/weblog', create full path
		if (substr($blog,0,7) != 'http://')
		{
			$blog = 'http://'.$_SERVER['SERVER_NAME'].$PREFS->ini('site_url');
		}

		return $blog;
	}
	// END



	// -------------------------------------
	//	Open connection to the service
	// -------------------------------------
	function connect()
	{
		return ($this->connection = @fsockopen($this->api['host'], $this->api['port'])) ? TRUE : FALSE;
	}
	// END


	
	// -------------------------------------
	//	Close connection to the service
	// -------------------------------------
	function disconnect()
	{
		return @fclose($this->connection);
	}
	// END



	// -------------------------------------
	//	Test connection - is server up?
	// -------------------------------------
	function service_is_available()
	{
		if ($this->connect())
		{
			$this->disconnect();
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	// END



	// -------------------------------------
	//	Communicate with service
	// -------------------------------------
	function get_response($request, $path, $type = "post", $response_length = 1160)
	{
		global $PREFS, $OUT;
		
		if ($this->connect())
		{
			// build request
			$request
				= strtoupper($type)." /{$this->api['version']}/{$path} HTTP/1.0\r\n"
				. "Host: ".((!empty($this->api_key)) ? $this->api_key."." : null)."{$this->api['host']}\r\n"
				. "Content-Type: application/x-www-form-urlencoded; charset=".$PREFS->ini('charset')."\r\n"
				. "Content-Length: ".strlen($request)."\r\n"
				. "User-Agent: {$this->user_agent}\r\n"
				. "\r\n"
				. $request;
	
			$response = '';
	
			@fwrite($this->connection, $request);
	
			while(!feof($this->connection))
			{
				$response .= @fgets($this->connection, $response_length);
			}
	
			$response = explode("\r\n\r\n", $response, 2);
			$res = $response[1];
			$this->disconnect();
		}
		else
		{
			$res = FALSE;
		}
		
		// return the response or FALSE
		return $res;
	}
	// END

	

	// -------------------------------------
	//	Compose query string
	// -------------------------------------
	function get_query_string()
	{
		foreach($_SERVER AS $key => $value)
		{
			if(!in_array($key, $this->ignore))
			{
				$this->data[$key] = $value;
			}
		}

		$query_string = '';

		foreach($this->data AS $key => $data)
		{
			$query_string .= $key . '=' . urlencode(stripslashes($data)) . '&';
		}

		return $query_string;
	}
	// END



	// -------------------------------------
	//	Prep input data -- see if something's missing
	// -------------------------------------	
	function prep_data($data = array())
	{
		$this->data = $data;
		
		// Check IP
		if (!isset($this->data['user_ip']) || empty($this->data['user_ip']))
		{
			$this->data['user_ip'] = $_SERVER['REMOTE_ADDR'];
		}
		
		// Check user agent
		if (!isset($this->data['user_agent']) || empty($this->data['user_agent']))
		{
			$this->data['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
		}
		
		// Check referrer
		if (!isset($this->data['referrer']) || empty($this->data['referrer']))
		{
			$this->data['referrer'] = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		}
						
		// Check blog url
		if (!isset($this->data['blog']) || empty($this->data['blog']))
		{
			$this->data['blog'] = $this->blog_url;
		}
		
		// Check comment_type
		if (!isset($this->data['comment_type']) || empty($this->data['comment_type']))
		{
			$this->data['comment_type'] = 'comment';
		}
	}
	// END



	// -------------------------------------
	//	Verify API key
	// -------------------------------------
	function key_is_valid()
	{
		$key_check = $this->get_response("key={$this->api_key}&blog={$this->blog_url}", 'verify-key');
			
		return ($key_check == "valid");
	}
	// END



	// -------------------------------------
	//	Check input
	// -------------------------------------
	function is_spam()
	{
		$response = $this->get_response($this->get_query_string(), 'comment-check');
		
		return ($response == "true");
	}
	// END



	// -------------------------------------
	//	Evaluate comment as spam
	// -------------------------------------
	function mark_as_spam($comment = array())
	{
		if (!empty($comment))
		{
			$this->data = $comment;
		}
		
		$this->get_response($this->get_query_string(), 'submit-spam');
	}
	// END



	// -------------------------------------
	//	Evaluate comment as ham
	// -------------------------------------
	function mark_as_ham($comment = array())
	{
		if (!empty($comment))
		{
			$this->data = $comment;
		}
		
		$this->get_response($this->get_query_string(), 'submit-ham');
	}
	// END


} // END low_nospam class
?>