<?php

namespace Locomotive\MailChimp;

use Mailchimp;

/**
 * File: MailChimp Facade Class
 *
 * Acts as a master facade class for MailChimp to allow me to isolate and test
 * functionality without bootstrapping the vendor API.
 *
 * @package Locomotive\MailChimp
 * @link    https://bitbucket.org/mailchimp/mailchimp-api-php/ Mailchimp API Client
 * @see     Locomotive\WordPress\Facade {
 *    Inspired by a modified version of Kevin Sperrine's WordPress facade.
 * }
 */

/**
 * Class: MailChimp Facade
 *
 * @version 2015-02-13
 * @since   0.0.0 (2015-02-12)
 */

class Facade
{
	public  $is_initialized = false;
	private $facade;
	private $current_list;
	private $current_list_merge_vars;
	private $current_list_groupings;
	private $all_lists;

	/**
	 * MailChimp Initialization
	 *
	 * @version 2015-02-13
	 * @since   2015-02-12
	 * @access  public
	 * @param   string  $api_key       The MailChimp API Key to enable the API client.
	 * @param   array   $user_options  Optional. Extra options for setting up the API client.
	 * @return  void|object
	 */

	public function initialize( $api_key = null, $user_options = [] )
	{
		if ( $this->is_initialized() ) {
			return $this->facade;
		}

		if ( ! is_null( $api_key ) ) {
			return $this->facade = new Mailchimp( $api_key, $user_options );
		}
	}

	/**
	 * Is MailChimp API Client Initialized?
	 *
	 * @version 2015-02-12
	 * @since   2015-02-12
	 * @access  public
	 * @return  bool
	 */

	public function is_initialized()
	{
		return ( $this->facade instanceof Mailchimp );
	}

	/**
	 * Log Exception
	 *
	 * @since   2015-04-03
	 * @access  public
	 */

	private function log( $e, $f = null )
	{
		if ( $e instanceof \Exception ) {
			error_log( sprintf( '%1$s, %2$s -- %3$s %4$s', __CLASS__ . ( empty( $f ) ? '' : '::' . $f ), get_class( $e ), $e->getMessage(), $e->getCode() ) );
		}
	}

	/**
	 * Is the api key entered by the user valid?
	 *
	 * @version 2015-02-13
	 * @since   2015-02-12
	 * @access  public
	 * @param   string  $api_key       The MailChimp API Key to enable the API client.
	 * @param   array   $user_options  Optional. Extra options for setting up the API client.
	 * @return  bool
	 */

	public function is_api_key_valid( $api_key = null, $user_options = [] )
	{
		$this->facade = new Mailchimp( $api_key, $user_options );

		try {
			$ping = $this->facade->helper->ping();

			if ( "Everything's Chimpy!" === $ping['msg'] ) {
				return true;
			}
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}

		return false;
	}

	/**
	 * Get list object from mailchimp
	 *
	 * @param $list_id
	 * @param bool $return_mc_error wheter to return mailchimp error or only false
	 * @return array|bool
	 */

	public function get_list_by_id( $list_id, $return_mc_error = true )
	{
		try {
			$list = $this->facade->lists->getList( [ 'list_id' => $list_id ] );
			$list = $this->current_list = reset( $list['data'] );

			// Reset groupings for current list
			$this->current_list_groupings = null;
			return $list;

		}
		catch ( \Mailchimp_List_DoesNotExist $e ) {
			$this->log( $e, __FUNCTION__ );

			return ( $return_mc_error ? $e : false );
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return ( $return_mc_error ? $e : false );
		}
	}

	/**
	 * Get all lists from user accounts
	 *
	 * @param bool $return_mc_error
	 * @todo find a way to deal with MailChimp paging
	 * @return array|bool
	 */

	public function get_all_lists( $return_mc_error = true )
	{
		try {
			// There is an API limit of 100 list return in one call
			$lists = $this->facade->lists->getList( [], 0, 100 );
			$this->all_lists = $lists;
			return $lists['data'];
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return $return_mc_error ? $e : false;
		}
	}

	/**
	 * Get total number of list for the current call
	 *
	 * @return int|bool
	 */

	public function get_current_list_total_results()
	{
		return ( isset( $this->all_lists ) ? (int) $this->all_lists['total'] : 0 );
	}

	/**
	 * Return all groupings found in the list
	 */

	public function get_all_groupings()
	{
		if ( isset( $this->current_list_groupings ) ) {
			return $this->current_list_groupings;
		}

		try {
			return $this->current_list_groupings = $this->facade->lists->interestGroupings( $this->current_list['id'] );
		}
		catch ( \Mailchimp_List_InvalidOption $e ) {
			$this->log( $e, __FUNCTION__ );

			// There is no grouping present
			if ( 211 === $e->getCode() ) {
				return false;
			}
		}
	}

	/**
	 * If a grouping doesn't exist create one, otherwise return the already created one
	 *
	 * @param string $name Grouping name
	 * @return bool|array
	 */

	public function get_grouping( $name )
	{
		if ( $groupings = $this->get_all_groupings() ) {
			foreach ( $groupings as $key => $grouping ) {
				if ( $name === $grouping['name'] ) {
					return $grouping;
					break;
				}
			}
		}

		return false;
	}

	/**
	 * Add new grouping
	 *
	 * @param $name
	 * @param string $type
	 * @param $groups
	 * @return bool|int
	 */

	public function add_grouping( $name, $type = 'checkboxes', $groups )
	{
		try {
			return $this->facade->lists->interestGroupingAdd( $this->current_list['id'], $name, $type, $groups )['id'];
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Completely remove grouping
	 *
	 * @param string $name
	 * @return bool
	 */

	public function delete_grouping( $name )
	{
		try {
			$grouping_id = $this->get_grouping( $name )['id'];

			if ( $grouping_id ) {
				return $this->facade->lists->interestGroupingDel( $grouping_id );
			}
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}

		return false;
	}

	/**
	 * Add a group to a corresponding grouping
	 *
	 * @param $name
	 * @param $grouping_id
	 * @return bool
	 */

	public function add_to_grouping( $name, $grouping_id )
	{
		try {
			return $this->facade->lists->interestGroupAdd( $this->current_list['id'], $name, $grouping_id )['id'];
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Remove a group from a grouping
	 *
	 * @param $name
	 * @param $grouping_id
	 *
	 * return bool|void
	 * @return bool
	 */

	public function delete_from_grouping( $name, $grouping_id )
	{
		try {
			$this->facade->lists->interestGroupDel( $this->current_list['id'], $name, $grouping_id );
			return true;
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Helper function that make sure we have exactly the same groups in mailchimp and locally
	 *
	 * @param $local_groups
	 * @param $remote_groups
	 * @param $grouping_id
	 */

	public function handle_grouping_integrity( $local_groups, $remote_groups, $grouping_id )
	{
		$groups_to_delete = [];

		foreach ( $remote_groups as $group ) {
			if ( in_array( $group['name'], $local_groups ) ) {
				// This means we already have this group so we can skip the creation
				$key = array_search( $group['name'], $local_groups );
				unset( $local_groups[ $key ] );
			}
			else {
				$groups_to_delete[] = $group['name'];
			}
		}

		// Remove groups
		foreach ( $groups_to_delete as $group_to_delete ) {
			$this->delete_from_grouping( $group_to_delete, $grouping_id );
		}

		foreach ( $local_groups as $group_to_add ) {
			$this->add_to_grouping( $group_to_add, $grouping_id );
		}
	}

	/**
	 * Get a folder ID by name or create the folder if it doesn't exist
	 *
	 * @param $name
	 * @return bool|int
	 */

	public function get_campaign_folder_id( $name )
	{
		if ( empty( $name ) ) {
			return false;
		}

		try {
			$folders = $this->facade->folders->getList( 'campaign' );

			foreach ( $folders as $folder ) {
				if ( $folder['name'] === $name ) {
					return $folder['folder_id'];
				}
			}

			return $this->create_campaign_folder( $name );
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}


	public function create_campaign_folder( $folder_name = '' )
	{
		try {
			return $this->facade->folders->add( $folder_name, 'campaign' );
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}


	/**
	 * Create all segments necessary
	 *
	 * @param array $campaign
	 * @param bool $return_mc_error wheter to return mailchimp error or only false
	 * @return bool|array
	 */

	public function create_campaign( $campaign, $return_mc_error = true )
	{
		if ( isset( $campaign['segment_opts'] ) ) {
			$result = $this->test_segment( $campaign['segment_opts'], $return_mc_error );
		}
		else {
			$result = $campaign['segment_opts'] = null;
		}

		if ( $result instanceof \Mailchimp_Error ) {
			return ( $return_mc_error ? $result : false );
		}

		try {
			/** @todo should we skip empty segments? Create those campaigns individually when needed. */
			if ( is_array( $result ) && isset( $result['total'] ) ) {
				if ( 0 === (int) $result['total'] ) {
					throw new \Mailchimp_Campaign_InvalidSegment( 'The segment is empty (0 recipients).' );
				}

				// Add the campaigns
				list( $type, $options, $content, $segment_opts, $type_opts ) = array_values( $campaign );
				$campaign = $this->facade->campaigns->create( $type, $options, $content, $segment_opts, $type_opts );

				if ( isset( $campaign['id'] ) && $campaign['id'] ) {
					$campaign['is_broadcast'] = $this->send_campaign( $campaign['id'], $campaign['type'] );
				}

				return $campaign;
			}
			else {
				throw new \Mailchimp_Error( 'The segment test failed for an unknown reason. Please try again later.' );
			}
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );
		}

		return ( $return_mc_error ? $e : false );
	}


	/**
	 * Send a given campaign immediately. For RSS campaigns, this will "start" them.
	 *
	 * @todo Should we send them immediately?
	 * @param string|int $campaign_id
	 * @param string $campaign_type
	 * @return int|bool
	 */

	public function send_campaign( $campaign_id, $campaign_type = '' )
	{
		try {
			$response = $this->facade->campaigns->send( $campaign_id );

			if ( is_array( $response ) && isset( $response['complete'] ) ) {
				return $response['complete'];
			}

			if ( 'rss' === $campaign_type ) {
				throw new \Mailchimp_Error( 'The RSS campaign could not be started for an unknown reason.' );
			}
			else {
				throw new \Mailchimp_Error( 'The campaign could not be sent for an unknown reason.' );
			}
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );
		}

		return false;
	}


	/**
	 * Test segment
	 *
	 * @param array $campaign
	 * @param bool $return_mc_error wheter to return mailchimp error or only false
	 * @return bool|array
	 */

	public function test_segment( $segment_opts, $return_mc_error = true )
	{
		try {
			$result = $this->facade->lists->segmentTest( $this->current_list['id'], $segment_opts );

			/** @todo should we skip empty segments? Create those campaigns individually when needed. */
			if ( is_array( $result ) && isset( $result['total'] ) ) {
				return $result;
			}

			throw new \Mailchimp_Error( 'The segment test failed for an unknown reason. Please try again later.' );
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return ( $return_mc_error ? $e : false );
		}
	}


	/**
	 * Retrieve user template in MailChimp
	 *
	 * @return array
	 */

	public function get_user_template()
	{
		try {
			$templates = $this->facade->templates->getList( [ 'user' => true ], [ 'include_drag_and_drop' => true ] );

			return $templates['user'];
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return [];
		}
	}

	/**
	 * Function to get all merge vars affected to the current list
	 *
	 * @return bool
	 */

	public function get_all_merge_vars()
	{
		if ( isset( $this->current_list_merge_vars ) ) {
			return $this->current_list_merge_vars;
		}

		try {
			$response = $this->facade->lists->mergeVars( [ $this->current_list['id'] ] );
			$response = reset( $response['data'] );

			return $this->current_list_merge_vars = $response['merge_vars'];
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Get merge var by tag
	 *
	 * @param string $tag
	 * @return bool|array
	 */

	public function get_merge_var( $tag )
	{
		if ( $merge_vars = $this->get_all_merge_vars() ) {
			foreach ( $merge_vars as $key => $merge_var ) {
				if ( $tag === $merge_var['tag'] ) {
					return $merge_var;
				}
			}
		}

		return false;
	}

	/**
	 * Add new merge var to the current list
	 *
	 * @param $tag
	 * @param $name
	 * @param $options
	 * @return bool
	 */

	public function add_merge_var( $tag, $name, $options )
	{
		try {
			$this->facade->lists->mergeVarAdd( $this->current_list['id'], $tag, $name, $options );
			return true;
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Update merge var to the current list
	 *
	 * @param $tag
	 * @param $options
	 * @return bool
	 */

	public function update_merge_var( $tag, $options )
	{
		try {
			// Field type cannot be save with update
			unset( $options['field_type'] );

			$this->facade->lists->mergeVarUpdate( $this->current_list['id'], $tag, $options );
			return true;
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Make sure merge vars specified is present in MailChimp list
	 * Update all merge vars option
	 *
	 * @param string $tag
	 * @param string $name
	 * @param array $options
	 * @return bool
	 */

	public function handle_merge_var_integrity( $tag, $name = '', $options = [] )
	{
		if ( $merge_var = $this->get_merge_var( $tag ) ) {
			return $this->update_merge_var( $tag, $options );
		}
		else {
			return $this->add_merge_var( $tag, $name, $options );
		}

		return false;
	}

	/**
	 * Sync users with the saved list
	 *
	 * @access public
	 * @param $list_id
	 * @param $users
	 *
	 * @return void
	 */

	public function sync_list_users( $list_id, $users )
	{
		try {
			$this->facade->lists->batchSubscribe( $list_id, $users, false, true );
			return true;
		}
		catch ( \Mailchimp_Error $e ) {
			$this->log( $e, __FUNCTION__ );

			return false;
		}
	}

	/**
	 * Magic __call method that creates a facade for
	 * the chosen MailChimp API client.
	 *
	 * @throws \Exception
	 * @access public
	 *
	 * @param string $method The MailChimp API function you want to call.
	 * @param mixed $arguments The arguments passed to the function
	 *
	 * @return mixed The return value depends on the MailChimp API function
	 */

	public function __call( $method, $arguments )
	{
		if ( method_exists( $this->facade, $method ) ) {
			try {
				$response = call_user_func_array( [ $this->facade, $method ], $arguments );

				if ( is_array( $response ) && isset( $response['complete'] ) ) {
					return $response['complete'];
				}
				else {
					return $response;
				}

				throw new \Mailchimp_Error( 'An unknown error occurred processing your request.' );
			}
			catch ( \Mailchimp_Error $e ) {
				$this->log( $e, __FUNCTION__ );
			}

			return false;
		}

		throw new \Exception( sprintf( 'The function, "%s::%s", does not exist.', get_class( $this->facade ), $method ) );
	}

	/**
	 * Magic __get method that creates a facade for
	 * the chosen MailChimp API client.
	 *
	 * @throws \Exception
	 * @access public
	 *
	 * @param string $property The MailChimp API property you want to get.
	 *
	 * @return mixed The return value depends on the MailChimp API property
	 */

	public function __get( $property )
	{
		if ( property_exists( $this->facade, $property ) ) {
			return $this->facade->{ $property };
		}

		throw new \Exception( sprintf( 'The property, "%s::\$%s", does not exist.', get_class( $this->facade ), $property ) );
	}

	/**
	 * Most function assume a context and we can change this context here
	 *
	 * @param $list array
	 */

	public function set_current_list( $list )
	{
		$this->current_list = $list;
	}

}
