<?php
/*  Copyright 2009 Jeff Smith (email: jeff@blurbia.com)

    This file is part of Timber.

    Timber is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Timber is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Timber.  If not, see <http://www.gnu.org/licenses/>.
*/
if (!class_exists('Timber'))
{
	class Timber 
	{
		var $t_errors;
		var $t_paths;
		var $t_errorpaths;
		var $t_errorusersread;
		var $options;
		var $custom_message_displayed;
		var $bypass = false;
		
		private static $instance;
		
	    private function timber() 
	    {
			global $wpdb;
			$this->t_errors 			= 	$wpdb->prefix . "timber_errors";
			$this->t_paths 				= 	$wpdb->prefix . "timber_paths";
			$this->t_errorpaths 		= 	$wpdb->prefix . "timber_errorpaths";
			$this->t_errorusersread 	= 	$wpdb->prefix . "timber_errorusersread";
			
			$this->options = array(
				'handle_error_types' => E_ALL & ~E_NOTICE,
				'die_on_types' => E_ALL & ~E_NOTICE,
				'log_backtrace' => true,
				'log_context' => true,
				'suppress_output' => true,
				'pass_through' => false,
				'custom_error_message' => '',
				'view_page_size' => 20
				);
			$db_options = get_option('timber_options');
			if (is_array($db_options)) $this->options = array_merge($this->options, $db_options);
			
			$this->options['custom_error_message'] = stripslashes($this->options['custom_error_message']);
			
			add_action('activate_timber/timber.php', array($this, 'install'));
			add_action('admin_menu', array($this, 'add_menus'));
			add_filter('capabilities_list', array($this, 'add_capabilities'));
			
			$types = $this->options['handle_error_types'];
			
			if ($types > 0)
				set_error_handler(array($this, 'error_handler'), $types);
			
			if (!$this->options['pass_through'])
			{
				error_reporting($types);
				ini_set('display_errors', 0);
			}
			
			if (function_exists('error_get_last'))
			    register_shutdown_function(array($this, 'fatal_error_handler'));
	    }
		public static function singleton() 
		{
		    if (!isset(self::$instance)) {
		        $c = __CLASS__;
		        self::$instance = new $c;
		    }
		
		    return self::$instance;
		}
		public function __clone()
		{
		    trigger_error('Clone operation disabled for class Timber. Timber is a Singleton.', E_USER_ERROR);
		}
		function install() 
		{
		   	global $wpdb, $wp_roles;
		   	
		   	$this->bypass = true;
		
		   	if($wpdb->get_var("show tables like '$this->t_errors'") != $this->t_errors) 
		   	{
		      	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		      	dbDelta("
					CREATE TABLE $this->t_errors (
			  		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					file varchar(255) NOT NULL,
					filename varchar(255) NOT NULL,
					line int unsigned NULL,
					error_type bigint(20) NULL,
					error_message text NOT NULL,
					backtrace longtext NULL,
					variable_dump longtext NULL,
					time_logged datetime NOT NULL,
			  		PRIMARY KEY (id)
					) TYPE=MyISAM;
					");
		      	dbDelta("
					CREATE TABLE $this->t_paths (
			  		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					path varchar(255) UNIQUE NOT NULL,
			  		PRIMARY KEY (id)
					) TYPE=MyISAM;
					");
		      	dbDelta("
					CREATE TABLE $this->t_errorpaths (
			  		error_id bigint(20) unsigned NOT NULL,
			  		path_id bigint(20) unsigned NOT NULL,
			  		PRIMARY KEY (error_id, path_id)
					) TYPE=MyISAM;
					");
		      	dbDelta("
					CREATE TABLE $this->t_errorusersread (
			  		error_id bigint(20) unsigned NOT NULL,
			  		wp_user_id bigint(20) unsigned NOT NULL,
			  		PRIMARY KEY (error_id, wp_user_id)
					) TYPE=MyISAM;
					");
		   	}
		   	
		   	$wp_roles->add_cap('administrator', 'view_errors');
		   	$wp_roles->add_cap('administrator', 'clear_errors');
		   	
		   	$this->bypass = false;
	    }
	    function error_handler($errno, $errstr, $errfile, $errline, $errcontext = '')
	    {
	    	global $wpdb;
	    	
	    	if ($this->bypass || error_reporting() === 0) return false;
	    	
	    	/*if (is_array($errcontext))
	    	{
		        foreach($errcontext as $key => $value)
		        {
		        	if ($temp = serialize($value))
		        		$context[$key] = $value;
		        }
	    	}*/
	    	
	    	$errfile = strlen(ABSPATH) > 1 ? str_replace(ABSPATH, '', $errfile) : $errfile;
	    	
	    	$errdata = array(
	    		'file' => $errfile,
	    		'filename' => basename($errfile),
	    		'line' => $errline,
	    		'error_type' => $errno,
	    		'error_message' => $errstr,
	    		'time_logged' => date('Y-m-d H:i:s')
	    		);
	    		
	    	if ($this->options['log_backtrace']) $errdata['backtrace'] = serialize(debug_backtrace());
	    	if ($this->options['log_context']) $errdata['variable_dump'] = serialize($errcontext);
	        
	    	$wpdb->insert($this->t_errors, $errdata);
	    	$error_id = $wpdb->insert_id;
	    		
	    	$folders = explode('/', $errfile);
	    	foreach($folders as $index => $folder)
	    	{
	    		$path = implode('/', array_slice($folders, 0, $index + 1));
	    		if (!empty($path))
	    		{
		    		if (!$path_id = $wpdb->get_var("select id from $this->t_paths where path='$path'"))
		    		{
		    			$wpdb->insert($this->t_paths, array('path' => $path));
		    			$path_id = $wpdb->insert_id;
		    		}
		    			
		    		$wpdb->insert($this->t_errorpaths, array('error_id' => $error_id, 'path_id' => $path_id));
	    		}
	    	}
	    	
	    	if (!$this->options['pass_through'])
	    	{
		    	if (!$this->options['suppress_output'])
		    		echo $errstr;
		    		
		    	if (!empty($this->options['custom_error_message']) && !$this->custom_message_displayed)
		    	{
		    		echo $this->options['custom_error_message'];
		    		$this->custom_message_displayed = true;
		    	}
		    		
		    	if ($errno & $this->options['die_on_types'])
		    		die();
	    	}
	    	
	    	return !(bool)$this->options['pass_through'];
	    }
	    function fatal_error_handler() 
	    {
	    	$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING;
	    	
			if (
				($error = error_get_last()) && 
				($error['type'] & $fatal_types) &&
				($error['type'] & $this->options['handle_error_types'])
				)
			{
				$context = get_defined_vars();
				if (isset($context['GLOBALS'])) unset($context['GLOBALS']);
				if (isset($context['_SERVER'])) unset($context['_SERVER']);
				if (isset($context['_GET'])) unset($context['_GET']);
				if (isset($context['_POST'])) unset($context['_POST']);
				if (isset($context['_FILES'])) unset($context['_FILES']);
				if (isset($context['_COOKIE'])) unset($context['_COOKIE']);
				if (isset($context['_SESSION'])) unset($context['_SESSION']);
				if (isset($context['_REQUEST'])) unset($context['_REQUEST']);
				if (isset($context['_ENV'])) unset($context['_ENV']);
				
	        	$this->error_handler($error['type'], $error['message'], $error['file'], $error['line'], $context);
			}
	    }
	    function add_menus()
	    {
	    	global $wpdb, $current_user;
	    	$unread_error_count = $wpdb->get_var(
				"select count(*) from $this->t_errors e 
					left join $this->t_errorusersread r on r.error_id=e.id and r.wp_user_id=$current_user->ID 
					where wp_user_id is null");
				
	    	if ($unread_error_count > 0)
	    		$unread = ' <span class="update-plugins count-'.$unread_error_count.
					'"><span class="plugin-count">'.$unread_error_count.'</span></span>';
					
			add_menu_page("Errors", "Errors$unread", 'view_errors', basename(__FILE__), array($this, 'view_errors_page'));
			add_options_page("Timber", "Timber", 'manage_options', basename(__FILE__).'/options', array($this, 'options_page'));
			
			if (isset($_GET['page']) && strpos($_GET['page'], basename(__FILE__)) === 0)
			{
				add_action('admin_init', array($this, 'admin_init'));
				add_action('admin_head', array($this, 'admin_head'));
			}
	    }
	    function add_capabilities($caplist)
	    {
	    	$caplist[] = 'view_errors';
	    	$caplist[] = 'clear_errors';
	    	return $caplist;
	    }
	    function admin_init()
	    {
	    	switch($_GET['page'])
	    	{
	    		case basename(__FILE__):
			    	if (isset($_GET['ClearAll']))
			    	{
						if (!current_user_can('clear_errors')) die('Access denied.');
			    		global $wpdb;
			    		$wpdb->query("delete from $this->t_errors");
			    		$wpdb->query("delete from $this->t_paths");
			    		$wpdb->query("delete from $this->t_errorpaths");
			    		$wpdb->query("delete from $this->t_errorusersread");
			    		
			    		wp_redirect('?page='.basename(__FILE__));
			    	}
			    	if (isset($_GET['doaction']))
			    	{
			    		if ($_GET['action'] == 'markread')
			    		{
			    			if (is_array($_GET['error']))
			    			{
			    				foreach($_GET['error'] as $error_id) $this->markread($error_id);
			    			}
			    			wp_redirect('?page='.basename(__FILE__));
			    		}
			    		elseif ($_GET['action'] == 'clear')
			    		{
							if (!current_user_can('clear_errors')) die('Access denied.');
			    			if (is_array($_GET['error']) && count($_GET['error']) > 0)
			    			{
			    				$error_ids = implode(',', array_map('intval', $_GET['error']));
			    				
			    				global $wpdb;
			    				$wpdb->query("delete from $this->t_errors where id in ($error_ids)");
				    			$wpdb->query("delete from $this->t_errorpaths where error_id in ($error_ids)");
				    			$wpdb->query("delete from $this->t_errorusersread where error_id in ($error_ids)");
			    			}
			    			wp_redirect('?page='.basename(__FILE__));
			    		}
			    	}
			    	break;
	    	}
	    }
	    function admin_head()
	    {
	    	?>
	    	<style type="text/css">
	    		.widefat td.overflow-show { overflow:visible; }
	    		.has-tooltip { position:relative; display:block; }
	    		.tooltip { position:absolute; right:10px; bottom:10px; padding:8px; background:white; display:none;
	    			border:1px solid #dfdfdf; white-space:nowrap; }
	    		.has-tooltip:hover .tooltip { display:block; }
	    		ul.stacktrace { padding-top:15px; }
	    		ul.stacktrace li { font-size:8pt; line-height:12pt; }
	    		ul.stacktrace li:hover { font-size:10pt; }
	    		tr.read strong { font-weight:normal; }
	    		tr.noresults td { padding:60px 40px; font-style:italic; }
	    	</style>
	    	<script type="text/javascript">
	    		function viewStack(id)
	    		{
	    			if (jQuery('#stack-trace-'+id).html() == '')
		    			jQuery.get('<?php bloginfo('url'); echo '/'.str_replace(ABSPATH, '', __FILE__); ?>', 
		    				{ timber_action:'viewstack', error_id:id }, 
		    				function(html) {
		    					
		    					jQuery('#stack-trace-link-'+id).html( 
		    						(jQuery('#stack-trace-wrap-'+id).is(':visible')) ? 'View Stack Trace' : 'Hide Stack Trace' );
		    			
		    					if (jQuery('#context-wrap-'+id).is(':visible'))
		    						jQuery('#context-link-'+id).html('View Context');
		    					
		    					jQuery('#stack-trace-'+id).html(html);
		    					jQuery('#stack-trace-wrap-'+id).slideToggle(250);
		    					jQuery('#context-wrap-'+id+':visible').slideToggle(250);
		    					jQuery('#error-row-'+id).addClass('read');
	    						jQuery('#mark-read-link-'+id).html('Mark as Unread');
		    				}
		    			);
		    		else
		    		{
		    			jQuery('#stack-trace-link-'+id).html( 
		    				(jQuery('#stack-trace-wrap-'+id).is(':visible')) ? 'View Stack Trace' : 'Hide Stack Trace' );
    					
    					if (jQuery('#context-wrap-'+id).is(':visible'))
    						jQuery('#context-link-'+id).html('View Context');
    			
		    			jQuery('#stack-trace-wrap-'+id).slideToggle(250);
		    			jQuery('#context-wrap-'+id+':visible').slideToggle(250);
		    		}
		    		
	    			return false;
	    		}
	    		function viewContext(id)
	    		{
	    			if (jQuery('#context-'+id).html() == '')
		    			jQuery.get('<?php bloginfo('url'); echo '/'.str_replace(ABSPATH, '', __FILE__); ?>', 
		    				{ timber_action:'viewcontext', error_id:id }, 
		    				function(html) {
		    					
		    					jQuery('#context-link-'+id).html( 
		    						(jQuery('#context-wrap-'+id).is(':visible')) ? 'View Context' : 'Hide Context' );
		    			
		    					if (jQuery('#stack-trace-wrap-'+id).is(':visible'))
		    						jQuery('#stack-trace-link-'+id).html('View Stack Trace');
		    			
		    					jQuery('#context-'+id).html(html);
		    					jQuery('#context-wrap-'+id).slideToggle(250);
		    					jQuery('#stack-trace-wrap-'+id+':visible').slideToggle(250);
		    					jQuery('#error-row-'+id).addClass('read');
	    						jQuery('#mark-read-link-'+id).html('Mark as Unread');
		    				}
		    			);
		    		else
		    		{
		    			jQuery('#context-link-'+id).html( 
		    				(jQuery('#context-wrap-'+id).is(':visible')) ? 'View Context' : 'Hide Context' );
    			
    					if (jQuery('#stack-trace-wrap-'+id).is(':visible'))
    						jQuery('#stack-trace-link-'+id).html('View Stack Trace');
    			
		    			jQuery('#context-wrap-'+id).slideToggle(250);
		    			jQuery('#stack-trace-wrap-'+id+':visible').slideToggle(250);
		    		}
		    		
	    			return false;
	    		}
	    		function markRead(id)
	    		{
	    			var action = jQuery('#mark-read-link-'+id).html() == 'Mark as Read' ? 'markread' : 'markunread';
	    			jQuery.get('<?php bloginfo('url'); echo '/'.str_replace(ABSPATH, '', __FILE__); ?>', 
	    				{ timber_action:action, error_id:id }, 
	    				function(html) {
	    					if (html == 'ACK')
	    					{
	    						if (jQuery('#mark-read-link-'+id).html() == 'Mark as Read')
	    						{
	    							jQuery('#error-row-'+id).addClass('read');
	    							jQuery('#mark-read-link-'+id).html('Mark as Unread');
	    						}
	    						else
	    						{
	    							jQuery('#error-row-'+id).removeClass('read');
	    							jQuery('#mark-read-link-'+id).html('Mark as Read');
	    						}
	    					}
	    				}
	    			);
	    			return false;
	    		}
	    		function clearError(id)
	    		{
	    			jQuery.get('<?php bloginfo('url'); echo '/'.str_replace(ABSPATH, '', __FILE__); ?>', 
	    				{ timber_action:'clear', error_id:id }, 
	    				function(html) {
	    					if (html == 'ACK')
	    						jQuery('#error-row-'+id).css('display', 'none');
	    				}
	    			);
	    			return false;
	    		}
	    	</script>
	    	<?php
	    }
	    function view_errors_page()
	    {
	    	global $wpdb, $current_user;
	    	
	    	$page_size = intval($this->options['view_page_size']);
	    	if ($page_size < 10) $page_size = 10;
	    	$paged = intval($_GET['paged']);
	    	if ($paged < 1) $paged = 1;
	    	
	    	if ($_GET['view'] == 'unread') $where = 'and r.wp_user_id is null ';
	    	elseif ($_GET['view'] == 'read') $where = 'and r.wp_user_id is not null ';
	    	else $where = '';
	    	
	    	if (isset($_GET['path']) && intval($_GET['path']) > 0)
	    		$pathjoin = "join $this->t_errorpaths ep on ep.error_id = e.id and ep.path_id=".intval($_GET['path']);
	    	
	    	$error_count = $wpdb->get_var("select count(*) from $this->t_errors");
	    	$error_read_count = $wpdb->get_var("select count(*) from $this->t_errors e 
				join $this->t_errorusersread r on r.error_id=e.id and r.wp_user_id=$current_user->ID");
	    	$error_unread_count = $error_count - $error_read_count;
	    	
	    	$page_count = ceil($error_count / $page_size);
	    	if ($paged > $page_count) $paged = $page_count;
	    	
	    	$offset = ($paged - 1) * $page_size;
	    	$errors = $wpdb->get_results(
				"select e.id, e.file, e.filename, e.line, e.error_type, e.error_message, e.time_logged, r.wp_user_id 
				from $this->t_errors e $pathjoin
				left join $this->t_errorusersread r on r.error_id=e.id and r.wp_user_id=$current_user->ID 
				where 1=1 $where
				order by time_logged desc, id desc limit $offset, $page_size"
				);
				
			$paths = $wpdb->get_results("select * from $this->t_paths");
	    	?>
	    	<div class="wrap">
		    	<h2>PHP Errors</h2>
		    	<form method="get" action="">
		    		<input type="hidden" name="page" value="<?php echo $_GET['page']; ?>" />
		    		<ul class="subsubsub">
						<li><a class="current" href="?page=<?php echo basename(__FILE__); ?>">All <span class="count">(<?php echo $error_count; ?>)</span></a> |</li>
						<li><a href="?page=<?php echo basename(__FILE__); ?>&amp;view=unread">Unread <span class="count">(<?php echo $error_unread_count; ?>)</span></a> |</li>
						<li><a href="?page=<?php echo basename(__FILE__); ?>&amp;view=read">Read <span class="count">(<?php echo $error_read_count; ?>)</span></a></li>
					</ul>
		    		<div class="tablenav">
						<div class="alignleft actions">
							<select name="action">
								<option selected="selected" value="-1">Bulk Actions</option>
								<option value="markread">Mark as Read</option>
								<?php if (current_user_can('clear_errors')) : ?>
								<option value="clear">Clear</option>
			    				<?php endif; ?>
							</select>
							<input type="submit" class="button-secondary action" id="doaction" name="doaction" value="Apply" />
							
							<select name="path">
								<option value=""<?php 
									if (!isset($_GET['path']) || intval($_GET['path']) == 0) echo ' selected="selected"'; ?>>View all paths</option>
								<?php foreach($paths as $path) : ?>
								<option value="<?php echo $path->id; ?>"<?php 
									if ($path->id == $_GET['path']) echo ' selected="selected"'; ?>><?php echo $path->path; ?></option>
								<?php endforeach; ?>
							</select>
							<?php if (false) : //not implemented ?>
							<select class="postform" id="file" name="file">
								<option value="0">View all files</option>
							</select>
							<?php endif; ?>
							<input type="submit" class="button-secondary" value="Filter" />
							
							<?php if (current_user_can('clear_errors')) : ?>
			    			<input class="button-secondary action" type="submit" name="ClearAll" value="Clear All" />
			    			<?php endif; ?>
			    		</div>
			    		<div class="tablenav-pages">
			    			<span class="displaying-num">Displaying 
			    				<?php echo ($error_count == 0 ? 0 : ($offset+1).'-'.
			    						($page_count > 1 ? $offset+$page_size+1 : $error_count)).
										' of '.$error_count ?></span>
			    			<?php if ($page_count > 1) :
			    			for ($i=1; $i<=$page_count; $i++) : ?>
			    			<a class="page-numbers" href="?paged=<?php echo $i ?>"><?php echo $i ?></a>
			    			<?php endfor;
			    			endif; ?>
			    		</div>
			    		<div class="clear"></div>
			    	</div>
			    	<div class="clear"></div>
			    	<table class="widefat">
			    		<thead>
			    			<tr>
			    				<th class="manage-column check-column" scope="col"><input type="checkbox"/></th>
			    				<th class="manage-column" style="width:100px;">Type</th>
			    				<th class="manage-column">Message</th>
			    				<th class="manage-column">File</th>
			    				<th class="manage-column">Line</th>
			    				<th class="manage-column">Time</th>
			    			</tr>
			    		</thead>
			    		<tfoot>
			    			<tr>
			    				<th class="manage-column check-column" scope="col"><input type="checkbox"/></th>
			    				<th class="manage-column">Type</th>
			    				<th class="manage-column">Message</th>
			    				<th class="manage-column">File</th>
			    				<th class="manage-column">Line</th>
			    				<th class="manage-column">Time</th>
			    			</tr>
			    		</tfoot>
			    		<tbody>
			    		<?php 
			    		if (count($errors) > 0) :
			    		
			    			$odd = true; 
			    			foreach ($errors as $error) : 
			    			
			    			$classes = array();
			    			if ($odd) $classes[] = 'alternate';
			    			$odd = !$odd;
			    			if ($error->wp_user_id) $classes[] = 'read';
			    			?>
			    			<tr id="error-row-<?php echo $error->id; ?>"<?php 
			    				if (count($classes) > 0) echo ' class="'.implode(' ', $classes).'"'; ?>>
			    				<th class="check-column" scope="row">
									<input type="checkbox" value="<?php echo $error->id; ?>" name="error[]"/>
								</th>
			    				<td><strong><?php echo Timber::error_type_tostring($error->error_type); ?></strong></td>
			    				<td>
			    					<strong><?php echo $error->error_message; ?></strong>
			    					<div class="row-actions" style="padding-top:8px;">
			    						<span><a id="stack-trace-link-<?php echo $error->id; ?>" href="#" onclick="return viewStack(<?php echo $error->id; ?>);">View Stack Trace</a> | </span>
			    						<span><a id="context-link-<?php echo $error->id; ?>" href="#" onclick="return viewContext(<?php echo $error->id; ?>);">View Context</a> | </span>
			    						<span><a id="mark-read-link-<?php echo $error->id; ?>" href="#" onclick="return markRead(<?php echo $error->id; ?>);"><?php echo ($error->wp_user_id) ? 'Mark as Unread' : 'Mark as Read'; ?></a>
			    						<?php if (current_user_can('clear_errors')) : ?>
			    						 | </span>
			    						<span><a href="#" onclick="return clearError(<?php echo $error->id; ?>);">Clear</a></span>
			    						<?php else : ?>
			    						</span>
			    						<?php endif; ?>
			    					</div>
			    					<div id="stack-trace-wrap-<?php echo $error->id; ?>" style="display:none;">
			    						<div id="stack-trace-<?php echo $error->id; ?>"></div></div>
			    					<div id="context-wrap-<?php echo $error->id; ?>" style="display:none;">
			    						<div id="context-<?php echo $error->id; ?>"></div></div>
			    				</td>
			    				<td class="overflow-show">
			    					<a class="has-tooltip" <?php 
			    						$url = '';
			    						if (strpos(ABSPATH.$error->file, get_theme_root()) === 0)
			    							$url = '/wp-admin/theme-editor.php?file='.
				    							str_replace(WP_CONTENT_DIR, '', ABSPATH.$error->file); 
			    						elseif (strpos($error->file, PLUGINDIR) === 0)
				    						$url = '/wp-admin/plugin-editor.php?file='.
				    							str_replace(PLUGINDIR.'/', '', $error->file); 
				    							
				    					if (!empty($url))
				    					{
				    						echo 'href="'.get_bloginfo('url').$url.'"';
				    					}
			    						?>>
			    						<span class="tooltip"><?php echo str_replace(ABSPATH, '', $error->file); ?></span>
			    						<?php echo $error->filename; ?>
			    					</a></td>
			    				<td><?php echo $error->line; ?></td>
			    				<td><?php echo date('g:ia (n/j)', strtotime($error->time_logged)); ?></td>
			    			</tr>
			    			<?php endforeach; 
			    		else : ?>
			    			<tr class="noresults">
			    				<td colspan="6">No errors found.</td>
			    			</tr>
			    		<?php endif; ?>
			    		</tbody>
			    	</table>
		    		<div class="tablenav">
						<div class="alignleft actions">
							<select name="action">
								<option selected="selected" value="-1">Bulk Actions</option>
								<option value="markread">Mark as Read</option>
								<option value="clear">Clear</option>
							</select>
							<input type="submit" class="button-secondary action" id="doaction" name="doaction" value="Apply" />
						</div>
			    		<div class="tablenav-pages">
			    			<span class="displaying-num">Displaying 
			    				<?php echo ($error_count == 0 ? 0 : ($offset+1).'-'.
			    						($page_count > 1 ? $offset+$page_size+1 : $error_count)).
										' of '.$error_count ?></span>
			    			<?php if ($page_count > 1) :
			    			for ($i=1; $i<=$page_count; $i++) : ?>
			    			<a class="page-numbers" href="?paged=<?php echo $i ?>"><?php echo $i ?></a>
			    			<?php endfor;
			    			endif; ?>
			    		</div>
			    		<div class="clear"></div>
			    	</div>
			    </form>
	    	</div>
	    	<?php
	    }
	    function options_page()
	    {
			if (isset($_POST['Submit']))
			{
				if (is_array($_POST["var_handle_error_types"])) 
					$_POST["var_handle_error_types"] = array_sum($_POST["var_handle_error_types"]);
				
				if (is_array($_POST["var_die_on_types"])) 
					$_POST["var_die_on_types"] = array_sum($_POST["var_die_on_types"]);
				
				foreach($this->options as $key => $val) $this->options[$key] = $_POST["var_$key"];
				update_option('timber_options', $this->options);
				
				$updateMessage = 'Options saved'."<br />";
				$update = true;
			}
			if (isset($updateMessage)) 
				echo '<div id="message" class="updated fade"><p><strong>'.__($updateMessage).'</strong></p></div>';
			?>
	    	<div class="wrap">
	    		<h2>Timber Settings</h2>
		    	<form method="post" action="">
					<table class="form-table">
		
						<tr valign="top">
							<th scope="row">Behavior</th>
							<td>
								<label><input type="checkbox" name="var_log_backtrace" id="var_log_backtrace" 
									value="1" <?php if ($this->options['log_backtrace']) echo 'checked="checked"' 
									?> /> Log stack trace</label><br />
								<label><input type="checkbox" name="var_log_context" id="var_log_context" 
									value="1" <?php if ($this->options['log_context']) echo 'checked="checked"' 
									?> /> Log variable contents</label><br />
								<label><input type="checkbox" name="var_suppress_output" id="var_suppress_output" 
									value="1" <?php if ($this->options['suppress_output']) echo 'checked="checked"' 
									?> /> Suppress error messages sent to browser</label><br />
								<label><input type="checkbox" name="var_pass_through" id="var_pass_through" 
									value="1" <?php if ($this->options['pass_through']) echo 'checked="checked"' 
									?> /> Pass through (handle errors as normal after logging)</label><br />
								<label><input type="text" name="var_view_page_size" id="var_view_page_size" 
									value="<?php echo $this->options['view_page_size']; ?>" size="3"
									/> View # errors per page</label><br />
							</td>
						</tr>
						
						<tr valign="top">
							<th scope="row">Log Error Types</th>
							<td>
								<fieldset>
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_ERROR ?>"
									<?php if ($this->options['handle_error_types'] & E_ERROR) echo 'checked="checked"' 
									?> /> E_ERROR </label><br />	
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_WARNING ?>"
									<?php if ($this->options['handle_error_types'] & E_WARNING) echo 'checked="checked"' 
									?> /> E_WARNING </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_PARSE ?>"
									<?php if ($this->options['handle_error_types'] & E_PARSE) echo 'checked="checked"' 
									?> /> E_PARSE </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_NOTICE ?>"
									<?php if ($this->options['handle_error_types'] & E_NOTICE) echo 'checked="checked"' 
									?> /> E_NOTICE </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_CORE_ERROR ?>"
									<?php if ($this->options['handle_error_types'] & E_CORE_ERROR) echo 'checked="checked"' 
									?> /> E_CORE_ERROR </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_CORE_WARNING ?>"
									<?php if ($this->options['handle_error_types'] & E_CORE_WARNING) echo 'checked="checked"' 
									?> /> E_CORE_WARNING </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_COMPILE_ERROR ?>"
									<?php if ($this->options['handle_error_types'] & E_COMPILE_ERROR) echo 'checked="checked"' 
									?> /> E_COMPILE_ERROR </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_COMPILE_WARNING ?>"
									<?php if ($this->options['handle_error_types'] & E_COMPILE_WARNING) echo 'checked="checked"' 
									?> /> E_COMPILE_WARNING </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_USER_ERROR ?>"
									<?php if ($this->options['handle_error_types'] & E_USER_ERROR) echo 'checked="checked"' 
									?> /> E_USER_ERROR </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_USER_WARNING ?>"
									<?php if ($this->options['handle_error_types'] & E_USER_WARNING) echo 'checked="checked"' 
									?> /> E_USER_WARNING </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_USER_NOTICE ?>"
									<?php if ($this->options['handle_error_types'] & E_USER_NOTICE) echo 'checked="checked"' 
									?> /> E_USER_NOTICE </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_STRICT ?>"
									<?php if ($this->options['handle_error_types'] & E_STRICT) echo 'checked="checked"' 
									?> /> E_STRICT </label><br />
								<label><input type="checkbox" name="var_handle_error_types[]" value="<?php echo E_RECOVERABLE_ERROR ?>"
									<?php if ($this->options['handle_error_types'] & E_RECOVERABLE_ERROR) echo 'checked="checked"' 
									?> /> E_RECOVERABLE_ERROR </label><br />
								</fieldset>
							</td>
						</tr>
						
						<tr valign="top">
							<th scope="row">Die On Error Types</th>
							<td>
								<fieldset>
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_ERROR ?>"
									<?php if ($this->options['die_on_types'] & E_ERROR) echo 'checked="checked"' 
									?> /> E_ERROR </label><br />	
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_WARNING ?>"
									<?php if ($this->options['die_on_types'] & E_WARNING) echo 'checked="checked"' 
									?> /> E_WARNING </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_PARSE ?>"
									<?php if ($this->options['die_on_types'] & E_PARSE) echo 'checked="checked"' 
									?> /> E_PARSE </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_NOTICE ?>"
									<?php if ($this->options['die_on_types'] & E_NOTICE) echo 'checked="checked"' 
									?> /> E_NOTICE </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_CORE_ERROR ?>"
									<?php if ($this->options['die_on_types'] & E_CORE_ERROR) echo 'checked="checked"' 
									?> /> E_CORE_ERROR </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_CORE_WARNING ?>"
									<?php if ($this->options['die_on_types'] & E_CORE_WARNING) echo 'checked="checked"' 
									?> /> E_CORE_WARNING </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_COMPILE_ERROR ?>"
									<?php if ($this->options['die_on_types'] & E_COMPILE_ERROR) echo 'checked="checked"' 
									?> /> E_COMPILE_ERROR </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_COMPILE_WARNING ?>"
									<?php if ($this->options['die_on_types'] & E_COMPILE_WARNING) echo 'checked="checked"' 
									?> /> E_COMPILE_WARNING </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_USER_ERROR ?>"
									<?php if ($this->options['die_on_types'] & E_USER_ERROR) echo 'checked="checked"' 
									?> /> E_USER_ERROR </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_USER_WARNING ?>"
									<?php if ($this->options['die_on_types'] & E_USER_WARNING) echo 'checked="checked"' 
									?> /> E_USER_WARNING </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_USER_NOTICE ?>"
									<?php if ($this->options['die_on_types'] & E_USER_NOTICE) echo 'checked="checked"' 
									?> /> E_USER_NOTICE </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_STRICT ?>"
									<?php if ($this->options['die_on_types'] & E_STRICT) echo 'checked="checked"' 
									?> /> E_STRICT </label><br />
								<label><input type="checkbox" name="var_die_on_types[]" value="<?php echo E_RECOVERABLE_ERROR ?>"
									<?php if ($this->options['die_on_types'] & E_RECOVERABLE_ERROR) echo 'checked="checked"' 
									?> /> E_RECOVERABLE_ERROR </label><br />
								</fieldset>
							</td>
						</tr>
						
						<tr valign="top">
							<th scope="row"><label for="var_custom_error_message">Custom Error Message</label></th>
							<td>
								<textarea id="var_custom_error_message" name="var_custom_error_message" class="largetext code" 
									cols="50" rows="10"><?php echo $this->options['custom_error_message']; ?></textarea>
							</td>
						</tr>
						
		    		</table>
		    		
					<p class="submit">
					<input type="submit" class="button-primary" name="Submit" value="<?php _e('Save Settings'); ?>" />
					</p>
				</form>
	    	</div>
	    	<?php
	    }
	    function error_type_tostring($type)
	    {
	    	switch($type)
	    	{
	    		case E_ERROR:
	    			return 'Error';
				case E_WARNING:
	    			return 'Warning';
				case E_PARSE:
	    			return 'Parse Error';
				case E_NOTICE:
					return 'Notice';
				case E_CORE_ERROR:
	    			return 'Core Error';
				case E_CORE_WARNING:
	    			return 'Core Warning';
				case E_COMPILE_ERROR:
	    			return 'Compile Error';
				case E_COMPILE_WARNING:
	    			return 'Compile Warning';
				case E_USER_ERROR:
	    			return 'User Error';
				case E_USER_WARNING:
	    			return 'User Warning';
				case E_USER_NOTICE:
	    			return 'User Notice';
				case E_ALL:
	    			return 'All Errors';
				case E_STRICT:
	    			return 'Strict';
				case E_RECOVERABLE_ERROR:
	    			return 'Recoverable Error';
	    	}
	    }
	    function get_error_log($id)
	    {
	    	global $wpdb;
	    	$id = $wpdb->escape($id);
	    	return $wpdb->get_row("select * from $this->t_errors where id=$id");
	    }
	    function format_backtrace($backtrace)
	    {
	    	if ($backtrace[0]['function'] == 'error_handler') array_shift($backtrace);
	    	if ($backtrace[0]['function'] == 'fatal_error_handler') array_shift($backtrace);
	    	
	    	$formatted = '<ul class="stacktrace">';
	    	foreach($backtrace as $trace)
	    	{
	    		$formatted .= '<li>'.$trace['function'].'() at Line '.$trace['line'].' in '.
	    			str_replace(ABSPATH, '', $trace['file']).'</li>';
	    	}
	    	$formatted .= '</ul>';
	    	
	    	return $formatted;
	    }
	    function format_context($context)
	    {
	    	//return $formatted;
	    	return '<pre>'.print_r($context, true).'</pre>';
	    }
	    function markread($error_id, $user_id = false)
	    {
	    	global $wpdb, $current_user;
	    	if (!$user_id) $user_id = $current_user->ID;
	    	
	    	$wpdb->insert($this->t_errorusersread, array('error_id' => $error_id, 'wp_user_id' => $user_id));
	    }
	    function markunread($error_id, $user_id = false)
	    {
	    	global $wpdb, $current_user;
	    	if (!$user_id) $user_id = $current_user->ID;
	    	
	    	$wpdb->query("delete from $this->t_errorusersread where error_id=$error_id and wp_user_id=$user_id");
	    }
	    function delete($error_id)
	    {
	    	global $wpdb;
	    	$wpdb->query("delete from $this->t_errors where id=$error_id");
	    	$wpdb->query("delete from $this->t_errorpaths where error_id=$error_id");
	    	$wpdb->query("delete from $this->t_errorusersread where error_id=$error_id");
	    }
	}
}

if (!defined('ABSPATH'))
{
	for ($i = 0; $i < 40; $i++)
	{
		$configfile = str_repeat('../', $i).'wp-config.php';
		if (file_exists($configfile))
		{
			require_once($configfile);
			break;
		}
	}
	$ajax_call = true;
}

global $timber;
if (!isset($timber) || !is_object($timber) || get_class($timber) != 'Timber')
{
	$timber = Timber::singleton();
}

if ($ajax_call && isset($_GET['timber_action']))
{
	if (!current_user_can('view_errors')) die('Access denied.');
	if (isset($_GET['error_id'])) $error = $timber->get_error_log($_GET['error_id']);
	switch($_GET['timber_action'])
	{
		case 'viewstack':
			$timber->markread($error->id);
			echo $timber->format_backtrace(unserialize($error->backtrace));
			break;
		case 'viewcontext':
			$timber->markread($error->id);
			echo $timber->format_context(unserialize($error->variable_dump));
			break;
		case 'markread':
			$timber->markread($error->id);
			echo 'ACK';
			break;
		case 'markunread':
			$timber->markunread($error->id);
			echo 'ACK';
			break;
		case 'clear':
			if (!current_user_can('clear_errors')) die('Access denied.');
			$timber->delete($error->id);
			echo 'ACK';
			break;
	}
}
?>