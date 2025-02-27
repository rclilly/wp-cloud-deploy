<?php
/**
 * Tools tab.
 *
 * @package wpcd
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCD_WORDPRESS_TABS_TOOLS
 */
class WPCD_WORDPRESS_TABS_TOOLS extends WPCD_WORDPRESS_TABS {

	/**
	 * WPCD_WORDPRESS_TABS_TOOLS constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_filter( "wpcd_app_{$this->get_app_name()}_get_tabnames", array( $this, 'get_tab' ), 10, 2 );
		add_filter( "wpcd_app_{$this->get_app_name()}_get_tabs", array( $this, 'get_tab_fields' ), 10, 2 );
		add_filter( "wpcd_app_{$this->get_app_name()}_tab_action", array( $this, 'tab_action' ), 10, 3 );

		// Allow the clear_background_processes action to be triggered via an action hook.
		add_action( 'wpcd_wordpress-app_clear_background_processes', array( $this, 'clear_background_processes' ), 10, 2 );

		// Allow update wp site option action to be triggered via an action hook.
		add_action( "wpcd_{$this->get_app_name()}_update_wp_site_option", array( $this, 'do_update_wp_site_option_action' ), 10, 2 ); // Hook:wpcd_wordpress-app_update_wp_site_option

	}

	/**
	 * Returns a string that can be used as the unique name for this tab.
	 */
	public function get_tab_slug() {
		return 'tools';
	}

	/**
	 * Returns a string that is the name of a view TEAM permission required to view this tab.
	 */
	public function get_view_tab_team_permission_slug() {
		return 'view_wpapp_site_tools_tab';
	}

	/**
	 * Populates the tab name.
	 *
	 * @param array $tabs The default value.
	 * @param int   $id   The post ID of the server.
	 *
	 * @return array    $tabs The default value.
	 */
	public function get_tab( $tabs, $id ) {
		if ( $this->get_tab_security( $id ) ) {
			$tabs[ $this->get_tab_slug() ] = array(
				'label' => __( 'Tools', 'wpcd' ),
				'icon'  => 'fad fa-toolbox',
			);
		}
		return $tabs;
	}

	/**
	 * Checks whether or not the user can view the current tab.
	 *
	 * @param int $id The post ID of the site.
	 *
	 * @return boolean
	 */
	public function get_tab_security( $id ) {
		// If admin has an admin lock in place and the user is not admin they cannot view the tab or perform actions on them.
		if ( $this->get_admin_lock_status( $id ) && ! wpcd_is_admin() ) {
			return false;
		}
		// If we got here then check team and other permissions.
		return ( true === $this->wpcd_wpapp_site_user_can( $this->get_view_tab_team_permission_slug(), $id ) && true === $this->wpcd_can_author_view_site_tab( $id, $this->get_tab_slug() ) );
	}

	/**
	 * Gets the fields to be shown in the TOOLs tab.
	 *
	 * Filter hook: wpcd_app_{$this->get_app_name()}_get_tabs
	 *
	 * @param array $fields fields.
	 * @param int   $id id.
	 *
	 * @return array Array of actions, complying with the structure necessary by metabox.io fields.
	 */
	public function get_tab_fields( array $fields, $id ) {

		// If user is not allowed to access the tab then don't paint the fields.
		if ( ! $this->get_tab_security( $id ) ) {
			return $fields;
		}

		return $this->get_fields_for_tab( $fields, $id, $this->get_tab_slug() );

	}

	/**
	 * Called when an action needs to be performed on the tab.
	 *
	 * @param mixed  $result The default value of the result.
	 * @param string $action The action to be performed.
	 * @param int    $id The post ID of the app.
	 *
	 * @return mixed    $result The default value of the result.
	 */
	public function tab_action( $result, $action, $id ) {

		/* Verify that the user is even allowed to view the app before proceeding to do anything else */
		if ( ! $this->wpcd_user_can_view_wp_app( $id ) ) {
			return new \WP_Error( sprintf( __( 'You are not allowed to perform this action - permissions check has failed for action %1$s in file %2$s for post %3$s by user %4$s', 'wpcd' ), $action, basename( __FILE__ ), $id, get_current_user_id() ) );
		}

		/* Now verify that the user can perform actions on this screen, assuming that they can view the server */
		$valid_actions = array( 'tools-clear-background-processes', 'tools-wpdebug-status', 'tools-edd-nginx-add', 'tools-update-restricted-php-functions', 'tools-reset-site-file-permissions', 'tools-wp-site-option' );
		if ( in_array( $action, $valid_actions, true ) ) {
			if ( ! $this->get_tab_security( $id ) ) {
				return new \WP_Error( sprintf( __( 'You are not allowed to perform this action - permissions check has failed for action %1$s in file %2$s for post %3$s by user %4$s', 'wpcd' ), $action, basename( __FILE__ ), $id, get_current_user_id() ) );
			}
		}

		if ( $this->get_tab_security( $id ) ) {
			switch ( $action ) {
				case 'tools-clear-background-processes':
					// Remove all metas related to background processes that might be "hung" or in-processs.
					$result = $this->clear_background_processes( $id, 'clear_background_processes' );
					if ( ! is_wp_error( $result ) ) {
						$result = array( 'refresh' => 'yes' );
					}
					break;
				case 'tools-wpdebug-status':
					$result = $this->toggle_wpdebug_status( $id, $action );
					break;
				case 'tools-enable-debug-log':
					$action = 'enable_debug';
					$result = $this->enable_disable_wp_debug( $id, $action );
					break;
				case 'tools-disable-debug-log':
					$action = 'disable_debug';
					$result = $this->enable_disable_wp_debug( $id, $action );
					break;
				case 'tools-edd-nginx-add':
					$action = 'enable_edd';
					$result = $this->manage_edd_nginx_rules( $id, $action );
					break;
				case 'tools-update-restricted-php-functions':
					// Verify that the user is allowed to do this - only admins can...
					if ( ! wpcd_is_admin() ) {
						$msg    = __( 'You don\'t have permission to use this function.', 'wpcd' );
						$result = array(
							'refresh' => 'yes',
							'msg'     => $msg,
						);
						break;
					}
					$action = 'enable_php_function';
					$result = $this->update_restricted_php_functions( $id, $action );
					break;
				case 'tools-reset-site-file-permissions':
					$action = 'reset_permissions';
					$result = $this->reset_file_permissions( $id, $action );
					break;
				case 'tools-wp-site-option':
					$action = 'wp_site_update_option';
					$result = $this->update_wp_site_option( $id, $action );
					break;

			}
		}
		return $result;
	}

	/**
	 * Gets the actions to be shown in the TOOLS tab.
	 *
	 * @param int $id id.
	 *
	 * @return array Array of actions with key as the action slug and value complying with the structure necessary by metabox.io fields.
	 */
	public function get_actions( $id ) {

		return $this->get_tools_fields( $id );

	}

	/**
	 * Gets the fields to be shown in the TOOLS tab.
	 *
	 * @param int $id the post id of the app cpt record.
	 *
	 * @return array Array of actions with key as the action slug and value complying with the structure necessary by metabox.io fields.
	 */
	private function get_tools_fields( $id ) {

		// Bail if site is not enabled.
		if ( ! $this->is_site_enabled( $id ) ) {
			return $this->get_disabled_header_field();
		}

		/* What type of web server are we running? */
		$webserver_type = $this->get_web_server_type( $id );

		// Basic checks passed, ok to proceed.
		$actions = array();

		// Set header description.
		$header_debug_desc  = __( 'Enable or disable the WordPress Debug log file.  This file is stored in the wp-content folder. Once enabled you can retrieve it with your sFTP client.', 'wpcd' );
		$header_debug_desc .= '<br />' . __( 'Note that if you toggle the WP_DEBUG flag directly in wp-config.php, the status shown here might not be accurate.', 'wpcd' );

		/* DEBUG LOG */
		$actions['tools-debug-log-header'] = array(
			'label'          => __( 'Manage Debug Logs', 'wpcd' ),
			'type'           => 'heading',
			'raw_attributes' => array(
				'desc' => $header_debug_desc,
			),
		);

		// Get the current debug log flag value.
		if ( true === $this->get_site_local_wpdebug_flag( $id ) ) {
			$debug_status = 'on';
		} else {
			$debug_status = 'off';
		}

		/* Set the confirmation prompt based on the the current status of this flag */
		$confirmation_prompt = '';
		if ( 'on' === $debug_status ) {
			$confirmation_prompt = __( 'Are you sure you would like to disable the WordPress debug log?', 'wpcd' );
		} else {
			$confirmation_prompt = __( 'Are you sure you would like to enable the WordPress debug log?', 'wpcd' );
		}

		if ( 'on' !== $debug_status ) {
			$debug_desc = __( 'Click to enable the WordPress debug log. <br />Messages will go directly to the debug.log file located in the wp-content folder.  Nothing will be shown on the screen.', 'wpcd' );
		} else {
			$debug_desc = __( 'Click to disable the WordPress debug log', 'wpcd' );
		}

		$actions['tools-wpdebug-status'] = array(
			'label'          => '',
			'raw_attributes' => array(
				'on_label'            => __( 'Enabled', 'wpcd' ),
				'off_label'           => __( 'Disabled', 'wpcd' ),
				'std'                 => 'on' === $debug_status,
				'desc'                => $debug_desc,
				'confirmation_prompt' => $confirmation_prompt,
			),
			'type'           => 'switch',
		);

		/* RESET SITE PERMISSIONS */
		$actions['tools-edd-reset-site-permissions-header'] = array(
			'label'          => __( 'Reset file permissions', 'wpcd' ),
			'type'           => 'heading',
			'raw_attributes' => array(
				'desc' => __( 'Sometimes file permissions are inadvertently changed.  Use this to reset them to their defaults: 664 for files and 2775 for folders.', 'wpcd' ),
			),
		);

		$actions['tools-reset-site-file-permissions'] = array(
			'label'          => '',
			'raw_attributes' => array(
				'std'                 => __( 'Reset', 'wpcd' ),
				'confirmation_prompt' => __( 'Are you sure you would like to reset the file permissions for this website?', 'wpcd' ),
			),
			'type'           => 'button',
		);

		/**
		 * EDD NGINX
		 */
		/* We should only show this section for nginx servers */
		if ( 'nginx' === $webserver_type ) {
			$actions['tools-edd-nginx-header'] = array(
				'label'          => __( 'NGINX Rules for Easy Digital Downloads', 'wpcd' ),
				'type'           => 'heading',
				'raw_attributes' => array(
					'desc' => __( 'Easy Digital Downloads require specical rules to be added to the NGINX web server in order to protect the EDD files.  This section allows you to add those rules. You should only add them if you are using EDD.', 'wpcd' ),
				),
			);

			$actions['tools-edd-nginx-add'] = array(
				'label'          => '',
				'raw_attributes' => array(
					'std'                 => __( 'Add EDD Rules', 'wpcd' ),
					'desc'                => __( 'Add the EDD Rules for the NGINX web server.', 'wpcd' ),
					'confirmation_prompt' => __( 'Are you sure you would like to add the EDD NGINX rules to your NGINX configuration file for this website?', 'wpcd' ),
				),
				'type'           => 'button',
			);
		}

		/* RESTRICTED PHP FUNCTIONS */
		if ( wpcd_is_admin() ) {

			$actions['tools-update-restricted-php-functions-header'] = array(
				'label'          => __( 'Restricted PHP Functions', 'wpcd' ),
				'type'           => 'heading',
				'raw_attributes' => array(
					'desc' => __( 'For security purposes certain PHP functions should be restricted. You can use this option to make sure you have the most recent restrictions for your site. <br /> Note that under normal circumstances you should not need to use this option.', 'wpcd' ),
				),
			);

			/* Set the text of the confirmation prompt */
			$confirmation_prompt = __( 'Are you sure you would like to reset the list of restricted PHP functions for this site?', 'wpcd' );

			$actions['tools-update-restricted-php-functions'] = array(
				'label'          => '',
				'raw_attributes' => array(
					'std'                 => __( 'Reset', 'wpcd' ),
					'confirmation_prompt' => $confirmation_prompt,
				),
				'type'           => 'button',
			);

		}

		/* BACKGROUND PROCESSES */
		$actions['tools-clear-background-processes-header'] = array(
			'label'          => __( 'Clear Background Processes', 'wpcd' ),
			'type'           => 'heading',
			'raw_attributes' => array(
				'desc' => __( 'This plugin uses a lot of CRON background processes.  Sometimes they can get into a state where they never end and start to fill up the database with irrelevant messages. Use this to reset these processes.', 'wpcd' ),
			),
		);

		/* Set the text of the confirmation prompt */
		$confirmation_prompt = __( 'Are you sure you would like to clear and reset all background processes for this site?', 'wpcd' );

		$actions['tools-clear-background-processes'] = array(
			'label'          => '',
			'raw_attributes' => array(
				'std'                 => __( 'Clear', 'wpcd' ),
				'confirmation_prompt' => $confirmation_prompt,
			),
			'type'           => 'button',
		);

		/* WP SITE OPTIONS */
		$actions['tools-wp-site-option-header'] = array(
			'label'          => __( 'Update WP Site Options', 'wpcd' ),
			'type'           => 'heading',
			'raw_attributes' => array(
				'desc' => __( 'Update certain site options. Note that you should be able to change all these options from directly inside your site so there is rarely a need to use this function..', 'wpcd' ),
			),
		);

		$actions['tools-wp-site-option-name'] = array(
			'label'          => __( 'Option Name', 'wpcd' ),
			'raw_attributes' => array(
				'desc'           => __( 'Select the option', 'wpcd' ),
				'options'        => $this->get_site_options_list(),
				'data-wpcd-name' => 'wps_option',
			),
			'type'           => 'select',
		);

		$actions['tools-wp-site-option-value'] = array(
			'label'          => __( 'Option Value', 'wpcd' ),
			'raw_attributes' => array(
				'desc'           => __( 'Enter the value for the option', 'wpcd' ),
				'data-wpcd-name' => 'wps_new_option_value',
			),
			'type'           => 'text',
		);

		$actions['tools-wp-site-option'] = array(
			'label'          => '',
			'raw_attributes' => array(
				'std'                 => __( 'Update Site Option', 'wpcd' ),
				'confirmation_prompt' => __( 'Are you sure you would like to update this site option?', 'wpcd' ),
				// fields that contribute data for this action.
				'data-wpcd-fields'    => json_encode( array( '#wpcd_app_action_tools-wp-site-option-name', '#wpcd_app_action_tools-wp-site-option-value' ) ),
			),
			'type'           => 'button',
		);

		return $actions;

	}

	/**
	 * Get the list of site options that we're going to allow users to update.
	 */
	public function get_site_options_list() {
		$options_list = array(
			'admin_email'      => __( 'Site Admin Email Address', 'wpcd' ),
			'default_role'     => __( 'Default Role', 'wpcd' ),
			'timezone_string ' => __( 'Timezone String', 'wpcd' ),
		);
		return $options_list;
	}


	/**
	 * Clear background processes.
	 *
	 * Action Hook: wpcd_wordpress-app_clear_background_processes (optional - most times called directly and not via an action hook.)
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed. Not used in this script.
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	public function clear_background_processes( $id, $action ) {

		delete_post_meta( $id, "wpcd_app_{$this->get_app_name()}_action_status" );
		delete_post_meta( $id, "wpcd_app_{$this->get_app_name()}_action" );
		delete_post_meta( $id, "wpcd_app_{$this->get_app_name()}_action_args" );
		delete_post_meta( $id, 'wpcd_command_mutex' );
		delete_post_meta( $id, 'wpcd_temp_log_id' );

		// Return an error so that it can be displayed in a dialog box...
		return new \WP_Error( __( 'Background processes have been reset.', 'wpcd' ) );
	}

	/**
	 * Enable/disable debug log.
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed (this matches the string required in the bash scripts).
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	private function toggle_wpdebug_status( $id, $action ) {

		$instance = $this->get_app_instance_details( $id );

		if ( is_wp_error( $instance ) ) {
			return new \WP_Error( sprintf( __( 'Unable to execute this request because we cannot get the instance details for action %s', 'wpcd' ), $action ) );
		}

		// Figure out if we're disabling or enabling the flag.
		if ( true === $this->get_site_local_wpdebug_flag( $id ) ) {
			$action = 'disable_debug';
		} else {
			$action = 'enable_debug';
		}

		// Get the full command to be executed by ssh.
		$run_cmd = $this->turn_script_into_command(
			$instance,
			'toggle_wp_debug.txt',
			array(
				'command' => "{$action}_site",
				'action'  => $action,
				'domain'  => get_post_meta(
					$id,
					'wpapp_domain',
					true
				),
			)
		);

		do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

		$result  = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );
		$success = $this->is_ssh_successful( $result, 'toggle_wp_debug.txt' );

		if ( ! $success ) {
			return new \WP_Error( sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result ) );
		} else {

			// Update metas.
			if ( true === $this->get_site_local_wpdebug_flag( $id ) ) {
				$this->set_site_local_wpdebug_flag( $id, false );
			} else {
				$this->set_site_local_wpdebug_flag( $id, true );
			}

			$success = array(
				'msg'     => __( 'The WordPress debug flags have been toggled.', 'wpcd' ),
				'refresh' => 'yes',
			);
		}

		return $success;

	}


	/**
	 * Add/Remove the NGINX rules for EDD.
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed (this matches the string required in the bash scripts).
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	private function manage_edd_nginx_rules( $id, $action ) {

		/* What type of web server are we running? */
		$webserver_type = $this->get_web_server_type( $id );

		/* We should run this for nginx servers */
		if ( 'nginx' !== $webserver_type ) {
			return new \WP_Error( sprintf( __( 'Unable to execute this request because this action is only valid for NGINX web servers: The action is: %s', 'wpcd' ), $action ) );
		}

		$instance = $this->get_app_instance_details( $id );

		if ( is_wp_error( $instance ) ) {
			return new \WP_Error( sprintf( __( 'Unable to execute this request because we cannot get the instance details for action %s', 'wpcd' ), $action ) );
		}

		// Get the full command to be executed by ssh.
		$run_cmd = $this->turn_script_into_command(
			$instance,
			'toggle_edd_nginx_rules.txt',
			array(
				'action' => $action,
				'domain' => get_post_meta(
					$id,
					'wpapp_domain',
					true
				),
			)
		);

		do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

		$result  = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );
		$success = $this->is_ssh_successful( $result, 'toggle_edd_nginx_rules.txt' );

		if ( ! $success ) {
			return new \WP_Error( sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result ) );
		} else {
			$success = array(
				'msg'     => __( 'The NGINX rules for EDD have been added to your site and the NGINX server has been restarted.', 'wpcd' ),
				'refresh' => 'yes',
			);
		}

		return $success;

	}

	/**
	 * Update the list of restricted PHP functions for a site
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed (this matches the string required in the bash scripts).
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	private function update_restricted_php_functions( $id, $action ) {

		$instance = $this->get_app_instance_details( $id );

		if ( is_wp_error( $instance ) ) {
			return new \WP_Error( sprintf( __( 'Unable to execute this request because we cannot get the instance details for action %s', 'wpcd' ), $action ) );
		}

		// List of functions to remove - ***should be NO spaces between the commas!***.
		/* $remove_functions = "'dl, exec, fpassthru, getmypid, getmyuid, highlight_file, ignore_user_abort, link, opcache_get_configuration, passthru, pcntl_exec, pcntl_get_last_error, pcntl_setpriority, pcntl_strerror, pcntl_wifcontinued, php_uname, phpinfo, popen, posix_ctermid, posix_getcwd, posix_getegid, posix_geteuid, posix_getgid, posix_getgrgid, posix_getgrnam, posix_getgroups, posix_getlogin, posix_getpgid, posix_getpgrp, posix_getpid, posix_getppid, posix_getpwnam, posix_getpwuid, posix_getrlimit, posix_getsid, posix_getuid, posix_isatty, posix_kill, posix_mkfifo, posix_setegid, posix_seteuid, posix_setgid, posix_setpgid, posix_setsid, posix_setuid, posix_times, posix_ttyname, posix_uname, proc_close, proc_get_status, proc_nice, proc_open, proc_terminate, shell_exec, show_source, source, system, virtual, set_time_limit, tmpfile, posix, listen, set_time_limit, php_uname, disk_free_space, diskfreespace, opcache_compile_file, opcache_invalidate, opcache_is_script_cached, pcntl_alarm, pcntl_fork, socket_listen, pcntl_getpriority, pcntl_signal, pcntl_signal_dispatch, pcntl_sigprocmask, pcntl_sigtimedwait, pcntl_sigwaitinfo, pcntl_waitpidpcntl_wait, pcntl_wexitstatus, pcntl_wifexited, pcntl_wifsignaled, pcntl_wifstopped, pcntl_wstopsig, pcntl_wtermsig'"; */
		$remove_functions = "'dl,exec,fpassthru,getmypid,getmyuid,highlight_file,ignore_user_abort,link,opcache_get_configuration,passthru,pcntl_exec,pcntl_get_last_error,pcntl_setpriority,pcntl_strerror,pcntl_wifcontinued,php_uname,phpinfo,popen,posix_ctermid,posix_getcwd,posix_getegid,posix_geteuid,posix_getgid,posix_getgrgid,posix_getgrnam,posix_getgroups,posix_getlogin,posix_getpgid,posix_getpgrp,posix_getpid,posix_getppid,posix_getpwnam,posix_getpwuid,posix_getrlimit,posix_getsid,posix_getuid,posix_isatty,posix_kill,posix_mkfifo,posix_setegid,posix_seteuid,posix_setgid,posix_setpgid,posix_setsid,posix_setuid,posix_times,posix_ttyname,posix_uname,proc_close,proc_get_status,proc_nice,proc_open,proc_terminate,shell_exec,show_source,source,system,virtual,set_time_limit,tmpfile,posix,listen,set_time_limit,php_uname,disk_free_space,diskfreespace,opcache_compile_file,opcache_invalidate,opcache_is_script_cached,pcntl_alarm,pcntl_fork,socket_listen,pcntl_getpriority,pcntl_signal,pcntl_signal_dispatch,pcntl_sigprocmask,pcntl_sigtimedwait,pcntl_sigwaitinfo,pcntl_waitpidpcntl_wait,pcntl_wexitstatus,pcntl_wifexited,pcntl_wifsignaled,pcntl_wifstopped,pcntl_wstopsig,pcntl_wtermsig,leak'";

		// Get the full command to be executed by ssh.
		$action = 'enable_php_function';

		$run_cmd = $this->turn_script_into_command(
			$instance,
			'enable_disable_php_functions.txt',
			array(
				'action'         => $action,
				'domain'         => get_post_meta( $id, 'wpapp_domain', true ),
				'functions_list' => $remove_functions,
			)
		);

		do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

		$result = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );

		$success = $this->is_ssh_successful( $result, 'enable_disable_php_functions.txt' );

		if ( $success ) {
			// now we have to add the new list - ***should be NO spaces between the commas!***.
			$add_functions = "'dl,exec,fpassthru,getmypid,getmyuid,highlight_file,ignore_user_abort,link,opcache_get_configuration,passthru,pcntl_exec,pcntl_get_last_error,pcntl_setpriority,pcntl_strerror,pcntl_wifcontinued,phpinfo,popen,posix_ctermid,posix_getcwd,posix_getegid,posix_geteuid,posix_getgid,posix_getgrgid,posix_getgrnam,posix_getgroups,posix_getlogin,posix_getpgid,posix_getpgrp,posix_getpid,posix_getppid,posix_getpwnam,posix_getpwuid,posix_getrlimit,posix_getsid,posix_getuid,posix_isatty,posix_kill,posix_mkfifo,posix_setegid,posix_seteuid,posix_setgid,posix_setpgid,posix_setsid,posix_setuid,posix_times,posix_ttyname,posix_uname,proc_close,proc_get_status,proc_nice,proc_open,proc_terminate,shell_exec,show_source,source,system,virtual'";

			// Get the full command to be executed by ssh.
			$action  = 'disable_php_function';
			$run_cmd = $this->turn_script_into_command(
				$instance,
				'enable_disable_php_functions.txt',
				array(
					'action'         => $action,
					'domain'         => get_post_meta( $id, 'wpapp_domain', true ),
					'functions_list' => $add_functions,
				)
			);

			do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

			$result  = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );
			$success = $this->is_ssh_successful( $result, 'enable_disable_php_functions.txt' );

		} else {

			return new \WP_Error( sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result ) );

		}

		// If we got here it means that we've run both parts and we need to see if the second part succeeded.
		if ( ! $success ) {
			return new \WP_Error( sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result ) );
		} else {
			$success = array(
				'msg'     => __( 'The PHP restricted functions list for this site has been updated!', 'wpcd' ),
				'refresh' => 'yes',
			);
		}

		return $success;

	}

	/**
	 * Reset file permissions for this site
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed (this matches the string required in the bash scripts).
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	private function reset_file_permissions( $id, $action ) {

		$instance = $this->get_app_instance_details( $id );

		if ( is_wp_error( $instance ) ) {
			return new \WP_Error( sprintf( __( 'Unable to execute this request because we cannot get the instance details for action %s', 'wpcd' ), $action ) );
		}

		// Get the full command to be executed by ssh.
		$run_cmd = $this->turn_script_into_command(
			$instance,
			'reset_site_permissions.txt',
			array(
				'action' => $action,
				'domain' => get_post_meta(
					$id,
					'wpapp_domain',
					true
				),
			)
		);

		do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

		$result  = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );
		$success = $this->is_ssh_successful( $result, 'reset_site_permissions.txt' );

		if ( ! $success ) {
			return new \WP_Error( sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result ) );
		} else {
			$success = array(
				'msg'     => __( 'The file permissions for this site has been reset.', 'wpcd' ),
				'refresh' => 'yes',
			);
		}

		return $success;

	}

	/**
	 * Update a site option.
	 *
	 * @param int    $id     The postID of the app cpt.
	 * @param string $action The action to be performed (this matches the string required in the bash scripts).
	 * @param array  $in_args Alternative source of arguments passed via action hook or direct function call instead of pulling from $_POST.
	 *
	 * @return boolean|WP_Error    success/failure
	 */
	private function update_wp_site_option( $id, $action, $in_args = array() ) {

		if ( empty( $in_args ) ) {
			// Get data from the POST request.
			$args = array_map( 'sanitize_text_field', wp_parse_args( wp_unslash( $_POST['params'] ) ) );
		} else {
			$args = $in_args;
		}

		// Get app/server details.
		$instance = $this->get_app_instance_details( $id );

		// Bail if no app/server details.
		if ( is_wp_error( $instance ) ) {
			/* Translators: %s is the action name. */
			$message = sprintf( __( 'Unable to execute this request because we cannot get the instance details for action %s', 'wpcd' ), $action );
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_failed", $id, $action, $message, $args );
			return new \WP_Error( $message );
		}

		// Make sure the option is in the approved list.
		if ( ! in_array( $args['wps_option'], array_keys( $this->get_site_options_list() ), true ) ) {
			$message = __( 'The option name is invalid - possible security issue.', 'wpcd' );
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_failed", $id, $action, $message, $args );
			return new \WP_Error( $message );
		}

		// Check to make sure that all required fields have values.
		if ( ! $args['wps_option'] ) {
			$message = __( 'The option name cannot be blank.', 'wpcd' );
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_failed", $id, $action, $message, $args );
			return new \WP_Error( $message );
		} else {
			// Sanitize the option name for use on the linux command line.
			$args['wps_option'] = escapeshellarg( $args['wps_option'] );
		}
		if ( ! $args['wps_new_option_value'] ) {
			$message = __( 'The new option value cannot be blank.', 'wpcd' );
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_failed", $id, $action, $message, $args );
			return new \WP_Error( $message );
		} else {
			// Sanitize the option name for use on the linux command line.
			$args['wps_new_option_value'] = escapeshellarg( $args['wps_new_option_value'] );
		}

		// Get the full command to be executed by ssh.
		$run_cmd = $this->turn_script_into_command(
			$instance,
			'update_wp_site_option.txt',
			array_merge(
				$args,
				array(
					'action' => $action,
					'domain' => get_post_meta(
						$id,
						'wpapp_domain',
						true
					),
				)
			)
		);

		do_action( 'wpcd_log_error', sprintf( 'attempting to run command for %s = %s ', print_r( $instance, true ), $run_cmd ), 'trace', __FILE__, __LINE__, $instance, false );

		$result  = $this->execute_ssh( 'generic', $instance, array( 'commands' => $run_cmd ) );
		$success = $this->is_ssh_successful( $result, 'update_wp_site_option.txt' );

		if ( ! $success ) {
			/* Translators: %1$s is the action; %2$s is the result of the ssh call. */
			$message = sprintf( __( 'Unable to %1$s site: %2$s', 'wpcd' ), $action, $result );
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_failed", $id, $action, $message, $args );
			return new \WP_Error( $message );
		} else {
			$success = array(
				'msg'     => __( 'The site option was updated.', 'wpcd' ),
				'refresh' => 'yes',
			);

			// Let others know we've been successful.
			do_action( "wpcd_{$this->get_app_name()}_update_wp_site_option_successful", $id, $action, $args );
		}

		return $success;

	}

	/**
	 * Trigger the update wp site option action from an action hook.
	 *
	 * Action Hook: wpcd_{$this->get_app_name()}_update_wp_site_option | wpcd_wordpress-app_update_wp_site_option
	 *
	 * @param string $id ID of app where domain change has to take place.
	 * @param array  $args array arguments that the add admin function needs.
	 */
	public function do_update_wp_site_option_action( $id, $args ) {
		$this->update_wp_site_option( $id, 'wp_site_update_option', $args );
	}

}

new WPCD_WORDPRESS_TABS_TOOLS();
