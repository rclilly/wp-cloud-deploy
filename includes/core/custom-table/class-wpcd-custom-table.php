<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


require_once 'class-ct-admin.php';

/**
 * Custom table base class
 */
abstract class WPCD_MB_Custom_Table {
	
	/**
	 * Model slug
	 * 
	 * @var string
	 */
	public $model_name = '';
	
	/**
	 * View Type is public or admin
	 * 
	 * @var string 
	 */
	public $view_type = '';
	
	/**
	 * Instance on custom table model class
	 * 
	 * @var \MetaBox\CustomTable\Model 
	 */
	public $model;
	
	/**
	 * Instance of WPCD_CT_Admin class to handle admin view
	 * 
	 * @var WPCD_CT_Admin 
	 */
	public $admin;
	
	/**
	 * Instance of custom table api class
	 * 
	 * @var Object 
	 */
	public $api;
	
	/**
	 * name of meta table name for custom table
	 */
	const META_TABLE_NAME = 'wpcd_ct_model_meta';
	
	
	/**
	 * Return instance of custom table object by model name
	 * 
	 * @param string $model_name
	 * 
	 * @return object|null
	 */
	public static function get( $model_name ) {
		
		$class_name_prefix = 'WPCD_CT_';
		$class_name = $class_name_prefix . ucwords( $model_name, '_' );
		
		if( class_exists( $class_name ) ) {
			return $class_name::instance();
		}
		
		return null;
	}
	
	/**
	 * Constructor.
	 */
	public function __construct() {
		
		$this->api = WPCD_Custom_Table_API::get( $this->get_model_name() );
		$this->register_model();
		
		$this->model = \MetaBox\CustomTable\Model\Factory::get( $this->get_model_name() );
		
		$this->admin = new WPCD_CT_Admin( $this->model );
		
		
		add_filter( 'rwmb_meta_boxes', array( $this, 'metaboxes' ) );
		
		add_action("wp_ajax_wpcd_{$this->get_model_name()}_inline_edit", array( $this, 'inline_edit_form') );
		add_action("wp_ajax_wpcd_{$this->get_model_name()}_inline_add", array( $this, 'inline_edit_form') );
		
		add_action("wp_ajax_wpcd_{$this->get_model_name()}_save_inline_edit", array( $this, 'save_inline_edit') );
		add_action("wp_ajax_wpcd_{$this->get_model_name()}_save_inline_add", array( $this, 'save_inline_edit') );
		
		add_action("wp_ajax_wpcd_ct_{$this->get_model_name()}_delete_item", array( $this, 'ajax_handle_delete_item' ) );
		add_filter( "mbct_{$this->get_model_name()}_row_actions", array( $this, 'table_row_actions'), 20, 2 );
		
		
		if ( $this->model->show_in_menu_custom ) {
			if( is_admin() ) {
				add_action( 'admin_menu', [ $this->admin, 'add_menu' ] );
			} elseif( $this->is_public_custom_table_page() ) {
				add_action( 'wp_enqueue_scripts', [ $this->admin, 'enqueue' ] );
			}
			add_action( 'init', array( $this, 'maybe_save_meta_boxes' ), 200 );
		}
		
		add_filter( "mbct_{$this->model->name}_column_output", array( $this, 'column_output'), 30, 4 );
	}
	
	
	/**
	 * Fires on activation of plugin.
	 *
	 * @param  boolean $network_wide    True if plugin is activated network-wide.
	 *
	 * @return void
	 */
	public static function Activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			// Get all blogs in the network.
			$blog_ids = get_sites( array( 'fields' => 'ids' ) );
			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( $blog_id );
				self::do_activate();
				restore_current_blog();
			}
		} else {
			self::do_activate();
		}
	}
	
	/**
	 * Create tables on Activation of the plugin.
	 *
	 * @return void
	 */
	public static function do_activate() {
		
		$provider = WPCD_MB_Custom_Table::get( 'provider' );
		$provider->create_table();
		
		$dns_provider = WPCD_MB_Custom_Table::get( 'dns_provider' );
		$dns_provider->create_table();
		
		$dns_zone = WPCD_MB_Custom_Table::get( 'dns_zone' );
		$dns_zone->create_table();
		
		$dns_zone_record = WPCD_MB_Custom_Table::get( 'dns_zone_record' );
		$dns_zone_record->create_table();
		
		$meta_table_columns = array(
			'item_id'			=> 'int(11) NOT NULL',
			'model'				=> 'varchar(50) NOT NULL',
			'meta_key'			=> 'varchar(100) NOT NULL',
			'meta_value'		=> 'varchar(250) DEFAULT NULL'
		);
		
		MetaBox\CustomTable\API::create( $provider->get_meta_table_name(), $meta_table_columns, [], true );
	}

	/**
	 * Return name of meta table for custom tables
	 * 
	 * @return string
	 */
	public function get_meta_table_name() {
		return $this->api->get_meta_table_name();
	}
	
	/**
	 * Return content for owner and parent columns
	 * 
	 * @param string $output
	 * @param string $column
	 * @param array $item
	 * @param string $model
	 * 
	 * @return string
	 */
	function column_output( $output, $column, $item, $model ) {
		
		if( $column == 'owner' ) {
			$output = strip_tags( $output, '<div>' );
		} elseif( $column == 'parent_id' ) {
			$output = $this->get_parent_name( $item['parent_id'] );
		}
		
		return $output;
	}
	
	/**
	 * Add nonce field in ajax forms
	 * 
	 * @param object $metabox
	 */
	function inline_edit_metabox_add_nonce_field( $metabox ) {
		printf('<input type="hidden" name="nonce" value="%s" />', $this->get_nonce( $this->action() ) );
	}
	
	/**
	 * Check if form is submitted and try to save
	 * 
	 * @global string $wpcd_ct_validation_error
	 * @global string $wpcd_ct_current_main_page_model
	 * 
	 * @return void
	 */
	public function maybe_save_meta_boxes() {
		global $wpcd_ct_validation_error, $wpcd_ct_current_main_page_model;
		// Save.
		
		if ( ! ( isset( $_POST['wpcd_ct_submit'] ) && $this->is_custom_table_page() ) ) {
			return;
		}
		
		$wpcd_ct_current_main_page_model = $this->get_model_name();
		
		$result = $this->validate_data();
		
		if( !$result['success'] ) {
			$wpcd_ct_validation_error = $result['error'];
			add_action( 'wpcd_ct_public_notices', array( $this, 'validation_error_notice' ) );
			return;
		}
		
		
		$_POST['submit'] = 'save';
		add_action( 'mbct_model_edit_load', array( $this, 'do_save_model' ) , -1, 1 );
		do_action( 'mbct_model_edit_load', $this->model );
		unset( $_POST['submit'] );
	}
	
	
	/**
	 * Save custom table form data
	 * 
	 * @global object $wpdb
	 * 
	 * @param object $model
	 * 
	 * @return void
	 */
	public function do_save_model( $model ) {
		
		if ( empty( $_POST['submit'] ) ) {
			return;
		}
		
		// Get the correct inserted ID when add new model.
		global $wpdb;
		
		$object_id = rwmb_request()->filter_get( 'model-id', FILTER_SANITIZE_NUMBER_INT );

		$this->add_encrypt_filters();
		
		$save_object_id = empty($object_id) ? 'eoid_'. wp_generate_password(6, false) : $object_id;
		$meta_boxes_count = count( $this->metaboxes(array()) );
		
		rwmb_get_registry('meta_box')->get( $this->form_meta_box_id() )->save_post( $save_object_id );
		
		if( $meta_boxes_count > 1 ) {
			for( $i = 1; $i < $meta_boxes_count; $i++ ) {
				do_action( 'rwmb_after_save_post', $save_object_id );
			}
		}
		
		
		$this->remove_encrypt_filters();
		
		$allowed_roles = filter_input( INPUT_POST, 'allowed_roles', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$allowed_users = filter_input( INPUT_POST, 'allowed_users', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$allowed_roles = !is_array( $allowed_roles ) ? array() : $allowed_roles;
		$allowed_users = !is_array( $allowed_users ) ? array() : $allowed_users;
		
		
		$message   = 'updated';
		if ( 'add' === $this->action() ) {
			$object_id = $wpdb->insert_id;
			$message   = 'added';
			
			if( !wpcd_is_admin() ) {
				$current_user_id = get_current_user_id();
				if( !in_array( $current_user_id, $allowed_users ) ) {
					$allowed_users[] = $current_user_id;
				}
			}
		}
		
		if( wpcd_is_admin() ) {
			$this->api->update_meta_values( $object_id, 'allowed_roles', $allowed_roles );
			$this->api->update_meta_values( $object_id, 'allowed_users', $allowed_users );
		}
		
		
		$owner = rwmb_request()->filter_post( 'owner', FILTER_SANITIZE_NUMBER_INT );
		
		if( 'add' === $this->action() && $object_id && !$owner ) {
			$this->api->update_owner( $object_id );
		}
		
		$url = add_query_arg( [
			'model-action'  => 'edit',
			'model-id'      => $object_id,
			'model-message' => $message,
		] );
		wp_safe_redirect( $url );
		die();
	}
	
	/**
	 * Add filters to encrypt data before saving
	 */
	function add_encrypt_filters() {}
	
	/**
	 * Remove filters to encrypt data before saving
	 */
	function remove_encrypt_filters() {}
	
	/**
	 * Check if page exists
	 * 
	 * @param boolean $force_check
	 * 
	 * @return boolean
	 */
	public function page_exists( $force_check = false ) {

		$page_id = $this->get_page_id();

		if ( $page_id && $force_check ) {
			$page = get_post( $page_id );

			if ( ! $page || $page->post_status == 'trash' ) {
				$page_id = false;
			}
		}
		return $page_id;
	}
	
	/**
	 * Return option name that store front-end page id
	 * 
	 * @return string
	 */
	public function get_page_id_option_name() {
		return "wpcd_ct_{$this->get_model_name()}_page_id";
	}
	
	/**
	 * Return front-end page id
	 * 
	 * @return int
	 */
	public function get_page_id() {
		return get_option( $this->get_page_id_option_name() );
	}
	
	/**
	 * Return admin listing page url
	 * 
	 * @return string
	 */
	public function admin_listing_page_url() {
		return add_query_arg( array('post_type' => 'wpcd_app_server', 'page' => "model-{$this->get_model_name()}"), get_admin_url(null, 'edit.php') );
	}
	
	/**
	 * Return public listing page url
	 * 
	 * @return string
	 */
	public function public_listing_page_url() {
		
		$page_id =  $this->get_page_id();
		return get_permalink( $page_id );
	}
	
	/**
	 * Return table row action
	 * 
	 * @param array $actions
	 * @param array $item
	 * 
	 * @return array
	 */
	public function table_row_actions( $actions, $item = array() ) {
		
		if( $this->is_custom_table_page() ) {
			
			unset($actions['delete']);
			
			$actions['edit'] = sprintf(
				'<a href="%s">' . esc_html__( 'Edit', 'mb-custom-table' ) . '</a>',
				add_query_arg( [
					'model-action' => 'edit',
					'model-id'     => $item['ID'],
				], $this->get_listing_page_url( $this->get_view() ) )
			);
			

			$actions['wpcd-ct-delete'] = sprintf(
					'<a href="#" data-id="%d" data-model="%s" data-nonce="%s" data-view="%s" class="wpcd-ct-delete-item">' . esc_html__( 'Delete', 'mb-custom-table' ) . '</a>',
					$item['ID'],
					$this->model->name,
					$this->get_nonce('delete'),
					$this->get_view()
				);
		}
		
		return $actions;
	}
	
	/**
	 * Check if its a public custom table page
	 * 
	 * @staticvar boolean $is_public_page
	 * 
	 * @return boolean
	 */
	public function is_public_custom_table_page() {
		
		static $is_public_page = null;
		
		if( null === $is_public_page ) {
			
			$page_id = $this->get_page_id();
			$current_page_id = $this->get_current_public_page_id();
			$is_public_page = ( $page_id && $current_page_id && $page_id == $current_page_id ) ? true : false;
		}
		
		return $is_public_page;
	}
	
	/**
	 * Check if its a admin custom table page
	 * 
	 * @return boolean
	 */
	public function is_admin_custom_table_page() {
		$is_custom_table_page = rwmb_request()->get( 'page' ) == 'model-'.$this->get_model_name();
		return $is_custom_table_page;
	}
	
	/**
	 * Return current public page id
	 * 
	 * @global int $wpcd_current_public_page_id
	 * 
	 * @return int
	 */
	public function get_current_public_page_id() {
		global $wpcd_current_public_page_id;
		
		if( is_admin() ) {
			return '';
		}
		
		if( !empty( $wpcd_current_public_page_id ) ) {
			return $wpcd_current_public_page_id;
		}
		
		$id = '';
		
		$_server_name = isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : parse_url( home_url( '/' ), PHP_URL_HOST );
		$id = url_to_postid( 'http://' . $_server_name . $_SERVER['REQUEST_URI'] );
		
		$wpcd_current_public_page_id = empty( $id ) ? '-1' : $id;
		return $wpcd_current_public_page_id;
	}
	
	/**
	 * Is it custom page page public or admin
	 * 
	 * @return boolean
	 */
	public function is_custom_table_page() {
		if( is_admin() ) {
			return $this->is_admin_custom_table_page();
		} else {
			
			$current_page_id = $this->get_current_public_page_id();
			return ( $current_page_id && $current_page_id == $this->get_page_id() );
		}
	}
	
	/**
	 * Is it item edit screen
	 * 
	 * @return boolean
	 */
	public function is_item_edit_screen() {
		$action = rwmb_request()->get( 'model-action' );
		return ( $action === 'edit' );
	}
	
	/**
	 * Is it item add screen
	 * 
	 * @return boolean
	 */
	public function is_item_add_screen() {
		$action = rwmb_request()->get( 'model-action' );
		return ( $action === 'add' );
	}
	
	/**
	 * Is it item add/edit screen
	 * 
	 * @return boolean
	 */
	public function is_item_add_edit_screen() {
		return ( $this->is_item_add_screen() || $this->is_item_edit_screen() );
	}
	
	/**
	 * Return content for child items table on admin-end
	 * 
	 * @param int $model_id
	 * 
	 * @return string
	 */
	public function display_child_items_table( $model_id ) {
		ob_start();
		
		$list_table = new WPCD_CT_Childs_List_Table( [
			'model' => MetaBox\CustomTable\Model\Factory::get($this->get_model_name()),
			'model_id' => $model_id,
			'parent_model_column' => 'parent_id',
			] );

		$list_table->prepare_items();
		$list_table->display();
		return ob_get_clean();
	}
	
	/**
	 * Return content for child items table on front-end
	 * 
	 * @param int $model_id
	 * 
	 * @return string
	 */
	public function display_public_child_items_table( $model_id ) {
		ob_start();
		
		require_once wpcd_path . 'includes/core/apps/wordpress-app/public/custom-table/class-wpcd-public-ct-childs-list-table.php';
		
		$list_table = new WPCD_Public_CT_Childs_List_Table( [
			'model' => MetaBox\CustomTable\Model\Factory::get($this->get_model_name()),
			'model_id' => $model_id,
			'parent_model_column' => 'parent_id',
			] );

		$list_table->prepare_items();
		$list_table->display();
		return ob_get_clean();
	}
	
	/**
	 * Display front-end table
	 */
	public function display_public_table() {
		
		require_once wpcd_path . 'includes/core/apps/wordpress-app/public/custom-table/class-ct-public-list-table.php';
		
		$table = new WPCD_CT_Public_List_Table( [
			'model' => $this->model,
		] );
		
		include wpcd_path . 'includes/core/apps/wordpress-app/public/custom-table/views/list-table.php';
		$table->update_table_pagination_js();
			
	}
	
	/**
	 * Display front-end edit screen
	 */
	function display_public_edit_screen() {
		
		$action = rwmb_request()->get( 'model-action' );
		$actions = array( 'add', 'edit' );
		
		if( in_array( $action, $actions ) ) {
		
			$item_id = filter_input( INPUT_GET, 'model-id', FILTER_SANITIZE_NUMBER_INT );
			include wpcd_path . "includes/core/apps/wordpress-app/public/custom-table/views/edit.php";
		}
	}
	
	/**
	 * Return content for submit box
	 * 
	 * @return string
	 */
	public function submit_box() {

		$delete_url = wp_nonce_url( add_query_arg( 'model-action', 'delete' ), 'delete' );
		ob_start();
		?>

		<div class="mbct-submit wpcd-mbct-submit">
			<?php 
			do_action( 'mbct_before_submit_box', $this->model );
			if ( $this->action() === 'edit' ) {				
				
				printf(
					'<a href="#" data-id="%d" data-model="%s" data-nonce="%s" data-view="%s" id="wpcd-mbct-delete">' . esc_html__( 'Delete', 'mb-custom-table' ) . '</a>',
					rwmb_request()->get('model-id'),
					$this->model->name,
					$this->get_nonce('delete'),
					$this->get_view()
				);
				
			}
			
			echo '<input type="submit" name="wpcd_ct_submit" id="wpcd_ct_submit" class="button button-primary wpcd-button" value="Save" />';
			do_action( 'mbct_after_submit_box', $this->model ); 
			?>
		</div>

		<?php
		
		return ob_get_clean();
	}
	
	
	/**
	 * Trigger save data via ajax
	 */
	function save_inline_edit() {
		add_action( 'mbct_model_edit_load', array( $this, 'ajax_save_meta_box' ), 1 );
		do_action('mbct_model_edit_load');
		die();
	}
	
	/**
	 * Call after custom table data saved via ajax
	 * 
	 * @param int $item_id
	 */
	function after_save_metabox( $item_id ) {}
	
	/**
	 * Save custom table form via ajax
	 */
	function ajax_save_meta_box() {

		$object_id = rwmb_request()->filter_post( 'model-id', FILTER_SANITIZE_NUMBER_INT );
		$metabox = rwmb_get_registry( 'meta_box' )->get( $this->inline_edit_metabox() );
		
		if( $object_id ) {
			$metabox->set_object_id( $object_id );
		}
		
		$validate_result = $this->validate_data( $this->action() );
		
		if( $validate_result['success'] ) {
			rwmb_request()->set_get_data( array('page' => "model-{$this->get_model_name()}") );
			
			$save_object_id = empty($object_id) ? 'eoid_'. wp_generate_password(6, false) : $object_id;	
			$metabox->save_post( $save_object_id );
			$this->after_save_metabox( $object_id );
		}
		
		wp_send_json( $validate_result );
		die();
	}
	
	/**
	 * Print data validation error message
	 * 
	 * @global string $wpcd_ct_validation_error
	 */
	public function validation_error_notice() {
		global $wpcd_ct_validation_error;
		
		$class = 'notice notice-error';
		$message = $wpcd_ct_validation_error;
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) ); 
	}
	
	/**
	 * Return current view admin or public
	 * 
	 * @staticvar string $view
	 * 
	 * @return string
	 */
	public function get_view() {
		static $view;
		
		if( !$view ) {
			$view = (is_admin() ? 'admin' : 'public');
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				$view = filter_input( INPUT_POST, 'view', FILTER_UNSAFE_RAW );
				if( empty( $view ) ) {
					$view = filter_input( INPUT_GET, 'view', FILTER_UNSAFE_RAW );
				}
				$view = sanitize_text_field( $view );
			}
		}
		return $view;
	}
	
	/**
	 * Return current action
	 * 
	 * @staticvar string $action
	 * 
	 * @return string
	 */
	public function action() {
		static $action;
		
		if( !$action ) {
			$action = '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				$_action = filter_input( INPUT_POST, 'action', FILTER_UNSAFE_RAW );
				
				if( empty( $_action ) ) {
					$_action = filter_input( INPUT_GET, 'action', FILTER_UNSAFE_RAW );
				}
				$_action = sanitize_text_field( $_action );
				$_action_parts = explode( '_', $_action );
				$action =  end( $_action_parts );
			} else {
				$action = rwmb_request()->get( 'model-action' );
			}
		}
		
		return $action;
	}
	
	/**
	 * Return model display name
	 * 
	 * @return string
	 */
	function get_model_display_name() {
		return strtolower( $this->model->labels['singular_name'] );
	}
	
	/**
	 * Default permission error message for an action
	 * 
	 * @param string $action
	 * 
	 * @return string
	 */
	function default_permission_error( $action ) {
		$default_error = sprintf( 'You are not allowed to %s %s.', $action, $this->get_model_display_name() );
		return $default_error;
	}
	
	/**
	 * Print window with error message
	 * 
	 * @param string $message
	 */
	public function print_error_window( $message ) {
		?>
		<div id="mb_ct_<?php echo $this->get_model_name();?>_edit_form" class="wpcd_mb_inline_edit_form_window">
			<?php printf('<h3>Error</h3>' ); ?>
			<div><?php echo $message; ?></div>
			
			<div class="rwmb-field rwmb-buttons-wrapper wpcd_ct_buttons_row">
				<div class="rwmb-label">
					<button type="button" class="mfp-close-window-button">Cancel</button>
				</div>
				<div class="rwmb-input">
					<button type="button" class="mfp-close"></button>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Return content for ajax add/edit form
	 */
	function inline_edit_form() {

		$model_id = filter_input( INPUT_GET, 'model-id', FILTER_SANITIZE_NUMBER_INT );
		$action_param = sanitize_text_field( filter_input( INPUT_GET, 'action', FILTER_UNSAFE_RAW ) );
		$type =  end( explode( '_', $action_param ) );
		$parent_id = filter_input( INPUT_GET, 'parent-id', FILTER_SANITIZE_NUMBER_INT );
		$nonce = sanitize_text_field( filter_input( INPUT_GET, 'nonce', FILTER_UNSAFE_RAW ) );
		$view = sanitize_text_field( filter_input( INPUT_GET, 'view', FILTER_UNSAFE_RAW ) );
		$permission = $this->add_edit_form_permissions( $type, $parent_id, $model_id );
		
		if( !$permission['has_access'] ) {
			$this->print_error_window( $permission['message'] );
			die();
		} elseif( !$this->verify_view_nonce($view, "{$type}_{$this->get_model_name()}", $nonce) ) {
			$this->print_error_window( "Error getting window, try again later." );
			die();
		}
		
		$metabox_id = $this->inline_edit_metabox();

		$metabox = rwmb_get_registry( 'meta_box' )->get( $metabox_id );
		$metabox->set_object_id( $model_id );
		
		ob_start();

		$action = "wpcd_{$this->get_model_name()}_save_inline_{$type}";
		$title = ( $type == 'edit' ) ? 'Edit' : 'Add' . ' ' . $this->model->labels['singular_name'];
		
		?>

		<div id="mb_ct_<?php echo $this->get_model_name(); ?>_edit_form" class="wpcd_mb_inline_edit_form_window">
			<?php printf('<h3>%s</h3>', $title ); ?>

			<form method="post">
				<input type="hidden" name="action" value="<?php echo $action; ?>" />
				<?php
				if( 'edit' == $type ) {
					printf( '<input type="hidden" name="model-id" value="%s" />', esc_attr( $model_id ) );
				}
				
				$metabox->show();
				?>
				<div class="rwmb-field rwmb-buttons-wrapper wpcd_ct_buttons_row">
					<div class="rwmb-label"></div>
					<div class="rwmb-input">
						<button type="submit" class="wpcd_ct_save_button wpcd-button">Save</button>
						<button type="button" class="mfp-close-window-button wpcd-button">Cancel</button>
						<div class="spinner"></div>
						<button type="button" class="mfp-close"></button>
					</div>
				</div>
			</form>
		</div>

		<?php
		
		remove_action( "rwmb_before_{$metabox_id}", array( $this, 'before_metabox_content' ) );
		
		echo ob_get_clean();
		die();
	}
	
	/**
	 * Return url of listing page
	 * 
	 * @param string $view
	 * 
	 * @return string
	 */
	function get_listing_page_url( $view = 'public' ) {
			
		if( $view == 'admin' ){
			$url = add_query_arg( array('post_type' => 'wpcd_app_server', 'page' => "model-{$this->get_model_name()}"), get_admin_url(null, 'edit.php') );
		} else {
			$url = get_permalink( $this->get_page_id() );
		}
		return $url;
	}
	
	
	/**
	 * Verify nonce field
	 * 
	 * @param string $action
	 * 
	 * @return boolean
	 */
	public function verify_nonce( $action ) {
		
		$nonce = sanitize_text_field( filter_input( INPUT_POST, 'nonce', FILTER_UNSAFE_RAW ) );
		return wp_verify_nonce( $nonce, $this->nonce_action( $action ) );
	}
	
	/**
	 * Get nonce action
	 * 
	 * @param string $action
	 * 
	 * @return string
	 */
	public function nonce_action( $action ) {
		
		$nonce_action = $action . '_' . $this->get_model_name();
		return $nonce_action;
	}
	
	/**
	 * Create nonce for an action
	 * 
	 * @param string $action
	 * 
	 * @return string
	 */
	public function get_nonce( $action ) {
		return wp_create_nonce( $this->nonce_action( $action ) );
	}
	
	/**
	 * Get nonce for a view
	 * 
	 * @param string $type
	 * @param string $action
	 * 
	 * @return string
	 */
	function get_view_nonce( $type, $action ) {
		return wp_create_nonce( "{$type}_{$action}" );
	}
	
	/**
	 * Verify nonce for a view
	 * 
	 * @param string $type
	 * @param string $action
	 * @param string $nonce
	 * 
	 * @return boolean
	 */
	public function verify_view_nonce( $type, $action, $nonce ) {
		return wp_verify_nonce( $nonce, "{$type}_{$action}" );
	}
	
	/**
	 * Check permission for an action ie(add/edit/delete)
	 * 
	 * @param string $action
	 * @param int $item_id
	 * @param int $user_id
	 * @param string $view
	 * 
	 * @return boolean
	 */
	function check_action_permission( $action, $item_id = 0, $user_id = null, $view = 'admin' ) {
		
		$has_access = false;
		
		switch( $action ) {
			case 'edit':
				$has_access = $this->user_can_edit( $item_id, $user_id );
			case 'delete':
				$has_access = $this->user_can_delete( $item_id, $user_id );
				break;
			case 'add':
				$has_access = $this->user_can_add( $user_id );
				break;
			case 'listing':
				$has_access = true;
				break;
		}
		
		return $has_access;
	}
	
	/**
	 * Check if user can view main page of a custom table
	 * 
	 * @return array
	 */
	public function check_main_page_permission() {
		$object_id = rwmb_request()->filter_get( 'model-id', FILTER_SANITIZE_NUMBER_INT );
		
		$action = $this->action();
		$action = empty( $action ) ? 'listing' : $action;
		
		$has_access = false;
		$message = "";
		
		if( !get_current_user_id() ) {
			return array( 'has_access' => $has_access, 'message' => 'You don\'t have access to this page.' );
		}
		$has_access = $this->check_action_permission( $action, $object_id, null, $this->get_view() );
		
		if( !$has_access ) {
			
			$listing_url = remove_query_arg( array( 'model-id', 'model-action', 'model-message' ) );
			$back_link = sprintf( '<a href="%s">%s</a>', $listing_url, 'Back to ' . $this->model->labels['name'] );
			$message = sprintf( '<div class="notice notice-error"><p>%s %s</p></div>', $this->default_permission_error( $action ), $back_link ); 
		}
		
		$result = array( 'has_access' => $has_access, 'message' => $message );
		
		return $result;
	}
	
	/**
	 * Return Table name
	 * 
	 * @return type
	 */
	public function get_table_name() {
		return $this->api->get_table_name();
	}
	
	/**
	 * Return model name
	 * 
	 * @return string
	 */
	public function get_model_name() {
		return $this->model_name;
	}
	
	/**
	 * Encrypt data before it is saved in the database
	 *
	 * @param string $new new value being saved.
	 * @param string $field name of being field saved.
	 * @param string $old old value of the field.
	 *
	 * @return string $new the encrypted value of the field.
	 */
	public function encrypt( $new, $field, $old ) {
		return WPCD()->encrypt( $new );
	}

	/**
	 * Decrypt data before it is shown on the screen
	 *
	 * @param string $meta the value in the field being decrypted.
	 * @param string $field the name of the field.
	 * @param string $saved the original saved value of the field.
	 *
	 * @return string $meta the decrypted value of the field.
	 */
	public function decrypt( $meta, $field, $saved ) {
		return WPCD()->decrypt( $meta );
	}

	/**
	 * Return allowed roles from settings
	 * 
	 * @return array
	 */
	function get_setting_add_allowed_roles() {
		
		$setting = '';
		switch( $this->get_model_name() ) {
			case 'dns_provider':
			case 'dns_zone':
			case 'dns_zone_record':
				$setting = 'dns_add_allowed_roles';
				break;
			default:
				$setting = 'provider_add_allowed_roles';
		}
		
		$roles = array();
		
		if( $setting ) {
			$roles = maybe_unserialize ( wpcd_get_early_option( $setting ) );
			$roles = is_array( $roles ) && !empty( $roles ) ? $roles : array();
		}
		return $roles;
	}
	
	/**
	 * Check if user can add new item
	 * 
	 * @param int $user_id
	 * 
	 * @return boolean
	 */
	public function user_can_add( $user_id = null ) {
		
		if( true === wpcd_is_admin() ) {
			return true;
		}
		if( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if( !$user_id ) {
			return;
		}
		
		$allowed_roles = $this->get_setting_add_allowed_roles();
		
		foreach( $allowed_roles  as $role ) {
			if( wpcd_user_has_role( $user_id, $role ) ) {
				return true;
			}
		}
		
		return false;
	}
	
	
	/**
	 * Check if user can edit an item
	 * 
	 * @param int $item_id
	 * @param int $user_id
	 * 
	 * @return boolean
	 */
	public function user_can_edit( $item_id, $user_id = 0 ) {
		
		if( !$item_id ) {
			return false;
		}
			
		$item = $this->api->get_by_id( $item_id );
		
		if( !$item ) {
			return false;
		}
		if( true === wpcd_is_admin() ) {
			return true;
		}
		
		$user_id = $user_id ? $user_id : get_current_user_id();
		
		if( !$user_id ) {
			return false;
		}
		
		$owner = ( is_object( $item ) && property_exists( $item, 'owner' ) ) ? $item->owner : null;
		
		if( $owner == $user_id ) {
			return true;
		}
		
		$allowed_users = $this->api->get_meta_values( $item_id, 'allowed_users' );
		
		if( in_array( $user_id, $allowed_users ) ) {
			return true;
		}
		
		$allowed_roles = $this->api->get_meta_values( $item_id, 'allowed_roles' );
		
		foreach( $allowed_roles  as $role ) {
			if( wpcd_user_has_role($user_id, $role) ) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Check if user can delete item
	 * 
	 * @param int $item_id
	 * 
	 * @return boolean
	 */
	public function user_can_delete( $item_id ) {
		return $this->user_can_edit( $item_id );
	}
	
	/**
	 * Return user display name for non wpcd_is_admin() users to display in owner field
	 * 
	 * @return string
	 */
	function field_owner_std() {
		$std = '';
		$object_id = rwmb_request()->get('model-id');
		
		if( !$object_id ) {
			return $std;
		}
		
		$item = $this->api->get_by_id( $object_id );
		if( $item && is_object( $item ) && property_exists( $item, 'owner' ) ) {
			$owner_id = $item->owner;
			$owner = get_user_by( 'id', $owner_id );
			if( $owner ) {
				$std = $owner->display_name ? $owner->display_name : __( '(No title)', 'wpcd' );
			}
		}
		return $std;
	}
	
	/**
	 * Return allowed users field values
	 * 
	 * @param array $new
	 * @param array $field
	 * @param array $old
	 * 
	 * @return array
	 */
	function field_allowed_users( $new, $field, $old ) {
		
		$object_id = rwmb_request()->get('model-id');
		
		if( !$object_id ) {
			return $new;
		}
		
		$storage = isset( $field['storage'] ) && is_object( $field['storage'] ) ? $field['storage'] : null;
		$table = $storage && property_exists( $storage, 'table') ? $storage->table : '';
		
		if( $table && $table === $this->get_table_name() ) {
			$new = $this->api->get_meta_values( $object_id, 'allowed_users' );
		}
		
		return $new;
	}
	
	/**
	 * Return allowed roles field values
	 * 
	 * @param array $new
	 * @param array $field
	 * @param array $old
	 * 
	 * @return array
	 */
	function field_allowed_roles( $new, $field, $old ) {
		
		$object_id = rwmb_request()->get('model-id');
		
		if( !$object_id ) {
			return $new;
		}
		
		$storage = isset( $field['storage'] ) && is_object( $field['storage'] ) ? $field['storage'] : null;
		$table = $storage && property_exists( $storage, 'table') ? $storage->table : '';
		
		if( $table && $table === $this->get_table_name() ) {
			$new = $this->api->get_meta_values( $object_id, 'allowed_roles' );
		}
		
		return $new;
	}
	
	/**
	 * Return table items
	 * 
	 * @param array $args
	 * @param int $user_id
	 * 
	 * @return array
	 */
	function get_items( $args = array(), $user_id = null ) {
		return $this->api->get_items_by_permission( $args, $user_id );
	}
	
}