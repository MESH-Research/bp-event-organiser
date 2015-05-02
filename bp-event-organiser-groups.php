<?php /*
================================================================================
BP Group Organiser Group Extension
================================================================================
AUTHOR: Christian Wach <needle@haystack.co.uk>
--------------------------------------------------------------------------------
NOTES
=====

This class extends BP_Group_Extension to create the screens our plugin requires.
See: http://codex.buddypress.org/developer/plugin-development/group-extension-api/

--------------------------------------------------------------------------------
*/



// prevent problems during upgrade or when Groups are disabled
if ( !class_exists( 'BP_Group_Extension' ) ) { return; }



/*
================================================================================
Class Name
================================================================================
*/

class BP_Event_Organiser_Group_Extension extends BP_Group_Extension {



	/*
	============================================================================
	Properties
	============================================================================
	*/

	/*
	// 'public' will show our extension to non-group members
	// 'private' means only members of the group can view our extension
	public $visibility = 'public';

	// if our extension does not need a navigation item, set this to false
	public $enable_nav_item = true;

	// if our extension does not need an edit screen, set this to false
	public $enable_edit_item = true;

	// if our extension does not need an admin metabox, set this to false
	public $enable_admin_item = true;

	// the context of our admin metabox. See add_meta_box()
	public $admin_metabox_context = 'core';

	// the priority of our admin metabox. See add_meta_box()
	public $admin_metabox_priority = 'normal';
	*/

	// no need for a creation step
	public $enable_create_step = false;

	// if our extension does not need an edit screen, set this to false
	public $enable_edit_item = false;

	// if our extension does not need an admin metabox, set this to false
	public $enable_admin_item = false;



	/**
	 * @description: initialises this object
	 * @return nothing
	 */
	public function __construct() {

		// init vars with filters applied
		$name = apply_filters( 'bpeo_extension_title', __( 'Events', 'bp-event-organizer' ) );
		$slug = apply_filters( 'bpeo_extension_slug', bpeo_get_events_slug() );
		$pos = apply_filters( 'bpeo_extension_pos', 31 );

		// test for BP 1.8+
		// could also use 'bp_esc_sql_order' (the other core addition)
		if ( function_exists( 'bp_core_get_upload_dir' ) ) {

			// init array
			$args = array(
				'name' => $name,
				'slug' => $slug,
				'nav_item_position' => $pos,
				'enable_create_step' => false,
			);

			// init
			parent::init( $args );

	 	} else {

			// name our tab
			$this->name = $name;
			$this->slug = $slug;

			// set position in navigation
			$this->nav_item_position = $pos;

			// disable create step
			$this->enable_create_step = false;

		}

		$this->register_subnav();
	}

	/**
	 * Registers subnav menu for a group's 'events' nav item.
	 *
	 * This is ultra hacky.  According to BP, the 'events' nav item is a subnav.
	 * So for 'events' to have a subnav, we have to do some weird stuff.  See how
	 * a group's "Manage" subnav is registered in bp-groups-loader.php for an idea
	 * of what we're doing here.
	 */
	protected function register_subnav() {
		if ( ! bp_is_group() ) {
			return;
		}

		$subnav = array();

		// Common params to all nav items
		$default_params = array(
			'parent_url'        => bpeo_get_group_permalink(),

			// this doesn't make sense; this emulates how a group's "Manage" subnav is
			// registered as well
			'parent_slug'       => buddypress()->groups->current_group->slug . '_events',

			'screen_function'   => array( $this, '_display_hook' ),
			'user_has_access'   => buddypress()->groups->current_group->is_user_member,
			'show_in_admin_bar' => true,
		);

		$sub_nav[] = array_merge( array(
			'name'     => __( 'Calendar', 'bp-event-organizer' ),
			'slug'     => 'calendar',
			'position' => 0,
			'link'     => bpeo_get_group_permalink(),
		), $default_params );

		$sub_nav[] = array_merge( array(
			'name'     => __( 'Upcoming', 'bp-event-organizer' ),
			'slug'     => 'upcoming',
			'position' => 0,
			'link'     => bpeo_get_group_permalink() . 'upcoming/',
		), $default_params );

		$sub_nav[] = array_merge( array(
			'name'     => __( 'New Event', 'bp-event-organizer' ),
			'slug'     => bpeo_get_events_new_slug(),
			'position' => 10,
		), $default_params );

		foreach( (array) $sub_nav as $nav ) {
			bp_core_new_subnav_item( $nav );
		}
	}

	/**
	 * Override parent _display_hook() method to add logic for single events.
	 */
	public function _display_hook() {
		// add event subnav
		add_action( 'bp_template_content', array( $this, 'add_subnav' ) );

		// new event
		if ( bpeo_is_action( 'new' ) ) {
			// check if user has access
			// @todo currently all group members have access to edit events... restrict to mods?
			if ( false === is_user_logged_in() || false === buddypress()->groups->current_group->is_user_member ) {
				bp_core_add_message( __( 'You do not have access to edit this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() );
				die();
			}

			// magic admin screen code!
			require BPEO_PATH . '/includes/class.bpeo_frontend_admin_screen.php';

			$this->create_event = new BPEO_Frontend_Admin_Screen( array(
				'type'           => 'new',
				'redirect_root'  => bpeo_get_group_permalink()
			) );

			add_action( 'bp_template_content', array( $this->create_event, 'display' ) );

		// upcoming
		} elseif ( bp_is_action_variable( 'upcoming', 0 ) ) {
			add_action( 'bp_template_content', array( $this, 'call_display' ) );

		// single event
		} elseif ( ! empty( buddypress()->action_variables ) ) {
			$this->single_event_screen();
			add_action( 'bp_template_title',   array( $this, 'display_single_event_title' ) );
			add_action( 'bp_template_content', array( $this, 'display_single_event' ) );

		// default behavior
		} else{
			add_action( 'bp_template_content', array( $this, 'call_display' ) );
		}

		bp_core_load_template( apply_filters( 'bp_core_template_plugin', $this->template_file ) );
	}

	/**
	 * Output the events subnav menu on group event pages.
	 *
	 * See how a group's "Manage" subnav works for an idea of what we're doing.
	 */
	public function add_subnav() {
		$_action_variables = buddypress()->action_variables;

		// highlight the 'calendar' slug when we're on the slug
		if ( false === bp_action_variable() ) {
			buddypress()->action_variables[] = 'calendar';
		}

	?>

		<div class="item-list-tabs no-ajax" id="subnav" role="navigation">
			<ul>
				<?php bp_get_options_nav( buddypress()->groups->current_group->slug . '_events' ); ?>
			</ul>
		</div><!-- .item-list-tabs -->

	<?php
		buddypress()->action_variables = $_action_variables;
	}

	/**
	 * @description display our content when the nav item is selected
	 */
	public function display( $group_id = null ) {
		// show header
		echo '<h3>'.apply_filters( 'bpeo_extension_title', __( 'Group Events', 'bp-event-organizer' ) ).'</h3>';

		// show secondary title if filter is in use
		$filter_title = bpeo_get_the_filter_title();
		if ( ! empty( $filter_title ) ) {
			echo "<h4>{$filter_title}</h4>";
		}

		// delete the calendar transient cache depending on user cap
		// @todo EO's calendar transient cache needs overhauling
		if( current_user_can( 'read_private_events' ) ){
			delete_transient( 'eo_full_calendar_public_priv' );
		} else {
			delete_transient( 'eo_full_calendar_public' );
		}

		$template_slug = bp_action_variable( 0 );
		if ( empty( $template_slug ) ) {
			$template_slug = 'calendar';
		}

		// Ceci n'est pas un template stack.
		$template = bp_locate_template( 'bp-event-organiser/' . $template_slug . '.php' );
		if ( false === $template ) {
			$template = BPEO_PATH . 'templates/' . $template_slug . '.php';
		}

		include $template;

	}

	/**
	 * Single event screen handler.
	 */
	protected function single_event_screen() {
		if ( false === bp_is_current_action( $this->slug ) ) {
			return;
		}

		if ( empty( buddypress()->action_variables ) ) {
			return;
		}

		// query for the event
		$event = eo_get_events( array(
			'name' => bp_action_variable()
		) );

		// check if event exists
		if ( empty( $event ) ) {
			bp_core_add_message( __( 'Event does not exist.', 'bp-event-organiser' ), 'error' );
			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}

		// check if event belongs to group
		// this needs to be edited once boone finishes new schema
		if ( false == in_array( bp_get_current_group_id(), $GLOBALS['buddypress_event_organiser']->eo->get_calendar_groups( $event[0]->ID ) ) ) {
			bp_core_add_message( __( 'Event does not belong to this group.', 'bp-event-organiser' ), 'error' );
			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}

		// save queried event as property
		$this->queried_event = $event[0];

		// edit single event logic
		if ( bpeo_is_action( 'edit' ) ) {
			// check if user has access
			if ( false === current_user_can( 'edit_event', $this->queried_event->ID ) ) {
				bp_core_add_message( __( 'You do not have access to edit this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}


			// magic admin screen code!
			require BPEO_PATH . '/includes/class.bpeo_frontend_admin_screen.php';

			$this->edit_event = new BPEO_Frontend_Admin_Screen( array(
				'queried_post'   => $this->queried_event,
				'redirect_root'  => bpeo_get_group_permalink()
			) );

		// delete single event logic
		} elseif ( bpeo_is_action( 'delete' ) ) {
			// check if user has access
			if ( false === current_user_can( 'delete_event', $this->queried_event->ID ) ) {
				bp_core_add_message( __( 'You do not have permission to delete this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}

			// verify nonce
			if ( false === bp_action_variable( 2 ) || ! wp_verify_nonce( bp_action_variable( 2 ), "bpeo_delete_event_{$this->queried_event->ID}" ) ) {
				bp_core_add_message( __( 'You do not have permission to delete this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}

			// delete event
			$delete = wp_delete_post( $this->queried_event->ID, true );
			if ( false === $delete ) {
				bp_core_add_message( __( 'There was a problem deleting the event.', 'bp-event-organiser' ), 'error' );
			} else {
				bp_core_add_message( __( 'Event deleted.', 'bp-event-organiser' ) );
			}

			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}
	}

	/**
	 * Display the single event title within a group.
	 *
	 * This is for themes using the 'bp_template_title' hook.
	 */
	public function display_single_event_title() {
		if ( bpeo_is_action( 'edit' ) ) {
			return;
		}

		if ( empty( $this->queried_event ) ) {
			return;
		}

		// save $post global temporarily
		global $post;
		$_post = false;
		if ( ! empty( $post ) ) {
			$_post = $post;
		}

		// override the $post global so EO can use its functions
		$post = $this->queried_event;

		the_title();

		// revert $post global
		if ( ! empty( $_post ) ) {
			$post = $_post;
		}
	}

	/**
	 * Display a single event within a group.
	 *
	 * @todo Move part of this functionality into a template part so theme devs can customize.
	 */
	public function display_single_event() {
		if ( empty( $this->queried_event ) ) {
			return;
		}

		// save $post global temporarily
		global $post, $pages;
		$_post = false;
		if ( ! empty( $post ) ) {
			$_post = $post;
		}

		// override the $post global so EO can use its functions
		$post = $this->queried_event;

		// edit screen has its own display method
		if ( bpeo_is_action( 'edit' ) ) {
			$this->edit_event->display();

			// revert $post global
			if ( ! empty( $_post ) ) {
				$post = $_post;
			}
			return;
		}

		// output title if theme is not using the 'bp_template_title' hook
		if ( ! did_action( 'bp_template_title' ) ) {
			the_title( '<h2>', '</h2>' );
		}

		// do something after the title
		// this is the same hook used in the admin area
		do_action( 'edit_form_after_title', $post );

		// BP removes all filters for 'the_content' during theme compat.
		// bring it back and remove BP's content filter
		bp_restore_all_filters( 'the_content' );
		remove_filter( 'the_content', 'bp_replace_the_content' );

		// hey there, mr. hack!
		//
		// we're going to use the_content() in our BPEO template part.  so we want to
		// get the rendered content for the event without BP theme compat running its
		// filter.
		//
		// get_the_content() is weird and checks the $pages global for the content
		if ( bp_use_theme_compat_with_current_theme() ) {
			$key = 0;

		// bp-default requires the key set to -1
		} else {
			$key = -1;
		}
		$pages[$key] = apply_filters( 'the_content', $post->post_content );

		// remove all filters like before
		bp_remove_all_filters( 'the_content' );

		// output single event content
		eo_get_template_part( 'content-eo', 'event' );

		// revert $post global
		if ( ! empty( $_post ) ) {
			$post = $_post;
		}
	}

} // class ends



// register our class
bp_register_group_extension( 'BP_Event_Organiser_Group_Extension' );
