<?php

/**
 * Class EditorialMetadata
 * Editorial Metadata for VIP Workflow
 */
namespace VIPWorkflow\Modules;

require_once __DIR__ . '/rest/editorial-metadata-endpoint.php';

use VIPWorkflow\Modules\EditorialMetadata\REST\EditorialMetadataEndpoint;
use VIPWorkflow\Modules\Shared\PHP\InstallUtilities;
use VIPWorkflow\Modules\Shared\PHP\TaxonomyUtilities;
use VIPWorkflow\VIP_Workflow;
use WP_Error;
use WP_Term;

class EditorialMetadata {

	// ToDo: Add date, and user as supported metadata types
	const SUPPORTED_METADATA_TYPES = [
		'checkbox',
		'text',
	];
	const METADATA_TAXONOMY        = 'vw_editorial_meta';
	const METADATA_POSTMETA_KEY    = 'vw_editorial_meta';
	const SETTINGS_SLUG            = 'vw-editorial-metadata';

	private static $editorial_metadata_terms_cache = [];

	public static function init(): void {
		// Register the taxonomy we use for Editorial Metadata with WordPress core
		add_action( 'init', [ __CLASS__, 'register_editorial_metadata_taxonomy' ] );

		// Register the post meta for each editorial metadata term
		add_action( 'init', [ __CLASS__, 'register_editorial_metadata_terms_as_post_meta' ] );

		// Setup editorial metadata on first install
		add_action( 'init', [ __CLASS__, 'setup_install' ] );

		// Add menu sidebar item
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );

		// Load CSS and JS resources for the admin page
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'action_admin_enqueue_scripts' ] );

		// Load block editor JS
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'load_scripts_for_block_editor' ] );

		// Load block editor CSS
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'load_styles_for_block_editor' ] );
	}

	/**
	 * Register the post meta for each editorial metadata term
	 *
	 * @access private
	 */
	public static function register_editorial_metadata_terms_as_post_meta(): void {
		$editorial_metadata_terms = self::get_editorial_metadata_terms();

		foreach ( $editorial_metadata_terms as $term ) {
			$post_meta_key  = self::get_postmeta_key( $term );
			$post_meta_args = self::get_postmeta_args( $term );

			// ToDo: If a post type was supported, a post was opened and saved, and then the post type was removed from the supported list,
			// the post meta will still exist for new posts of that post type for a short duration.
			// It works fine otherwise. This is a limitation of the current implementation.
			// Working around this is quite expensive, and is fine for now.
			foreach ( VIP_Workflow::instance()->get_supported_post_types() as $post_type ) {
				register_post_meta( $post_type, $post_meta_key, $post_meta_args );
			}
		}
	}

	/**
	 * Register the post metadata taxonomy
	 *
	 * @access private
	 */
	public static function register_editorial_metadata_taxonomy(): void {
		// We need to make sure taxonomy is registered for all of the post types that support it
		$supported_post_types = VIP_Workflow::instance()->get_supported_post_types();

		register_taxonomy( self::METADATA_TAXONOMY, $supported_post_types,
			[
				'public'  => false,
				'labels'  => [
					'name'          => _x( 'Editorial Metadata', 'taxonomy general name', 'vip-workflow' ),
					'singular_name' => _x( 'Editorial Metadata', 'taxonomy singular name', 'vip-workflow' ),
					'search_items'  => __( 'Search Editorial Metadata', 'vip-workflow' ),
					'popular_items' => __( 'Popular Editorial Metadata', 'vip-workflow' ),
					'all_items'     => __( 'All Editorial Metadata', 'vip-workflow' ),
					'edit_item'     => __( 'Edit Editorial Metadata', 'vip-workflow' ),
					'update_item'   => __( 'Update Editorial Metadata', 'vip-workflow' ),
					'add_new_item'  => __( 'Add New Editorial Metadata', 'vip-workflow' ),
					'new_item_name' => __( 'New Editorial Metadata', 'vip-workflow' ),
				],
				'rewrite' => false,
			]
		);
	}

	/**
	 * Load default editorial metadata the first time the module is loaded
	 *
	 * @access private
	 */
	public static function setup_install(): void {
		InstallUtilities::install_if_first_run( self::SETTINGS_SLUG, function () {
			// ToDo: Review the default metadata fields we provide OOB
			$default_terms = [
				[
					'name'        => __( 'Assignment', 'vip-workflow' ),
					'slug'        => 'assignment',
					'type'        => 'text',
					'description' => __( 'What the post needs to cover.', 'vip-workflow' ),
				],
				[
					'name'        => __( 'Needs Photo', 'vip-workflow' ),
					'slug'        => 'needs-photo',
					'type'        => 'checkbox',
					'description' => __( 'Checked if this post needs a photo.', 'vip-workflow' ),
				],
				[
					'name'        => __( 'Word Count', 'vip-workflow' ),
					'slug'        => 'word-count',
					'type'        => 'text',
					'description' => __( 'Required post length in words.', 'vip-workflow' ),
				],
			];

			// Load the metadata fields if the slugs don't conflict
			foreach ( $default_terms as $term ) {
				if ( ! term_exists( $term['slug'], self::METADATA_TAXONOMY ) ) {
					self::insert_editorial_metadata_term( $term );
				}
			}
		});
	}

	/**
	 * Register admin sidebar menu
	 *
	 * @access private
	 */
	public static function add_admin_menu(): void {
		$menu_title = __( 'Editorial Metadata', 'vip-workflow' );

		add_submenu_page( Custom_Status::SETTINGS_SLUG, $menu_title, $menu_title, 'manage_options', self::SETTINGS_SLUG, [ __CLASS__, 'render_settings_view' ] );
	}

	/**
	 * Print settings page for the Editorial Metadata module
	 *
	 * @access private
	 */
	public static function render_settings_view(): void {
		include_once __DIR__ . '/views/manage-editorial-metadata.php';
	}

	/**
	 * Enqueue resources that we need in the admin settings page
	 *
	 * @access private
	 */
	public static function action_admin_enqueue_scripts(): void {
		// Load Javascript we need to use on the configuration views
		if ( VIP_Workflow::is_settings_view_loaded( self::SETTINGS_SLUG ) ) {
			$asset_file = include VIP_WORKFLOW_ROOT . '/dist/modules/editorial-metadata/editorial-metadata-configure.asset.php';
			wp_enqueue_script( 'vip-workflow-editorial-metadata-configure', VIP_WORKFLOW_URL . 'dist/modules/editorial-metadata/editorial-metadata-configure.js', $asset_file['dependencies'], $asset_file['version'], true );
			wp_enqueue_style( 'vip-workflow-editorial-metadata-styles', VIP_WORKFLOW_URL . 'dist/modules/editorial-metadata/editorial-metadata-configure.css', [ 'wp-components' ], $asset_file['version'] );

			wp_localize_script( 'vip-workflow-editorial-metadata-configure', 'VW_EDITORIAL_METADATA_CONFIGURE', [
				'supported_metadata_types'    => self::SUPPORTED_METADATA_TYPES,
				'editorial_metadata_terms'    => self::get_editorial_metadata_terms(),
				'url_edit_editorial_metadata' => EditorialMetadataEndpoint::get_url(),
			] );
		}
	}

	/**
	 * Enqueue resources that we need in the admin settings page
	 *
	 * @access private
	 */
	public static function load_scripts_for_block_editor(): void {
		$asset_file   = include VIP_WORKFLOW_ROOT . '/dist/modules/editorial-metadata/editorial-metadata-block.asset.php';
		$dependencies = array_merge( $asset_file['dependencies'], [ 'vip-workflow-block-custom-status-script' ] );

		wp_enqueue_script( 'vip-workflow-block-editorial-metadata-script', VIP_WORKFLOW_URL . 'dist/modules/editorial-metadata/editorial-metadata-block.js', $dependencies, $asset_file['version'], true );

		wp_localize_script( 'vip-workflow-block-editorial-metadata-script', 'VW_EDITORIAL_METADATA', [
			'editorial_metadata_terms' => self::get_editorial_metadata_terms(),
		] );
	}

	/**
	 * Enqueue resources that we need in the block editor
	 *
	 * @access private
	 */
	public static function load_styles_for_block_editor(): void {
		$asset_file = include VIP_WORKFLOW_ROOT . '/dist/modules/editorial-metadata/editorial-metadata-block.asset.php';

		wp_enqueue_style( 'vip-workflow-editorial-metadata-styles', VIP_WORKFLOW_URL . 'dist/modules/editorial-metadata/editorial-metadata-block.css', [ 'wp-components' ], $asset_file['version'] );
	}

	// Public API

	/**
	 * Get all of the editorial metadata terms as objects and sort by position
	 *
	 * @param array $filter_args Filter to specific arguments
	 * @return array $ordered_terms The terms as they should be ordered
	 */
	public static function get_editorial_metadata_terms(): array {
		// Internal object cache for repeat requests
		if ( ! empty( self::$editorial_metadata_terms_cache ) ) {
			return self::$editorial_metadata_terms_cache;
		}

		$terms = get_terms( [
			'taxonomy'   => self::METADATA_TAXONOMY,
			'orderby'    => 'name',
			'hide_empty' => false,
		]);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$terms = [];
		}

		$ordered_terms = [];
		$hold_to_end   = [];

		// Order the terms
		foreach ( $terms as $key => $term ) {
			// Unencode and set all of our psuedo term meta because we need the position if it exists
			$unencoded_description = TaxonomyUtilities::get_unencoded_description( $term->description );

			if ( is_array( $unencoded_description ) ) {
				foreach ( $unencoded_description as $key => $value ) {
					$term->$key = $value;
				}
			}

			// We require the position key later on
			if ( ! isset( $term->position ) ) {
				$term->position = false;
			}

			// Set the post meta key for the term, this is not set when the term is first created due to a lack of term_id.
			if ( ! isset( $term->meta_key ) ) {
				$term->meta_key = self::get_postmeta_key( $term );
			}

			// Only add the term to the ordered array if it has a set position and doesn't conflict with another key
			// Otherwise, hold it for later
			if ( $term->position && ! array_key_exists( $term->position, $ordered_terms ) ) {
				$ordered_terms[ (int) $term->position ] = $term;
			} else {
				$hold_to_end[] = $term;
			}
		}
		// Sort the items numerically by key
		ksort( $ordered_terms, SORT_NUMERIC );

		// Append all of the terms that didn't have an existing position
		foreach ( $hold_to_end as $unpositioned_term ) {
			$ordered_terms[] = $unpositioned_term;
		}

		$ordered_terms = array_values( $ordered_terms );

		// Set the internal object cache
		self::$editorial_metadata_terms_cache = $ordered_terms;

		return $ordered_terms;
	}

	/**
	 * Returns a term for single metadata field
	 *
	 * @param int|string $field The slug or ID for the metadata field term to return
	 * @return WP_Term|false $term Term's object representation
	 */
	public static function get_editorial_metadata_term_by( $field, $value ) {
		// We only support id, slug and name for lookup.
		if ( ! in_array( $field, [ 'id', 'slug', 'name' ] ) ) {
			return false;
		}

		if ( 'id' === $field ) {
			$field = 'term_id';
		}

		// ToDo: This is inefficient as we are fetching all the terms, and then finding the one that matches.
		$terms = self::get_editorial_metadata_terms();
		$term  = wp_filter_object_list( $terms, [ $field => $value ] );

		$term = array_shift( $term );

		return null !== $term ? $term : false;
	}

	/**
	 * Insert a new editorial metadata term
	 * @todo Handle conflicts with existing terms at that position (if relevant)
	 */
	public static function insert_editorial_metadata_term( array $args ): WP_Term|WP_Error {
		// Term is always added to the end of the list
		$default_position = count( self::get_editorial_metadata_terms() ) + 1;

		$defaults  = [
			'position'    => $default_position,
			'name'        => '',
			'slug'        => '',
			'description' => '',
			'type'        => '',
		];
		$args      = array_merge( $defaults, $args );
		$term_name = $args['name'];
		unset( $args['name'] );

		// We're encoding metadata that isn't supported by default in the term's description field
		$args_to_encode = [
			'description' => $args['description'],
			'position'    => $args['position'],
			'type'        => $args['type'],
		];

		$encoded_description = TaxonomyUtilities::get_encoded_description( $args_to_encode );
		$args['description'] = $encoded_description;

		unset( $args['position'] );
		unset( $args['type'] );

		$inserted_term = wp_insert_term( $term_name, self::METADATA_TAXONOMY, $args );

		// Reset the internal object cache
		self::$editorial_metadata_terms_cache = [];

		// Populate the inserted term with the new values, or else only the term_taxonomy_id and term_id are returned.
		if ( is_wp_error( $inserted_term ) ) {
			return $inserted_term;
		} else {
			// Update the term with the meta_key, as we use the term_id to generate it
			self::update_editorial_metadata_term( $inserted_term['term_id'] );
			$inserted_term = self::get_editorial_metadata_term_by( 'id', $inserted_term['term_id'] );
		}

		return $inserted_term;
	}

	/**
	 * Update an existing editorial metadata term if the term_id exists
	 *
	 * @param int $term_id The term's unique ID
	 * @param array $args Any values that need to be updated for the term
	 * @return WP_Term|WP_Error $updated_term The updated WP_Term or a WP_Error object if something disastrous happened
	*/
	public static function update_editorial_metadata_term( int $term_id, array $args = [] ): WP_Term|WP_Error {
		$old_term = self::get_editorial_metadata_term_by( 'id', $term_id );
		if ( ! $old_term ) {
			return new WP_Error( 'invalid', __( "Editorial metadata term doesn't exist.", 'vip-workflow' ) );
		}

		// Reset the internal object cache
		self::$editorial_metadata_terms_cache = [];

		$new_args = [];

		$old_args = [
			'position'    => $old_term->position,
			'name'        => $old_term->name,
			'slug'        => $old_term->slug,
			'description' => $old_term->description,
			'type'        => $old_term->type,
			'meta_key'    => isset( $old_term->meta_key ) ? $old_term->meta_key : self::get_postmeta_key( $old_term ),
		];

		$new_args = array_merge( $old_args, $args );

		// We're encoding metadata that isn't supported by default in the term's description field
		$args_to_encode          = [
			'description' => $new_args['description'],
			'position'    => $new_args['position'],
			'type'        => $new_args['type'],
			'meta_key'    => $new_args['meta_key'],
		];
		$encoded_description     = TaxonomyUtilities::get_encoded_description( $args_to_encode );
		$new_args['description'] = $encoded_description;

		unset( $new_args['position'] );
		unset( $new_args['type'] );
		unset( $new_args['meta_key'] );

		$updated_term = wp_update_term( $term_id, self::METADATA_TAXONOMY, $new_args );

		// Reset the internal object cache
		self::$editorial_metadata_terms_cache = [];

		// Populate the updated term with the new values, or else only the term_taxonomy_id and term_id are returned.
		if ( is_wp_error( $updated_term ) ) {
			return $updated_term;
		} else {
			$updated_term = self::get_editorial_metadata_term_by( 'id', $term_id );
		}

		return $updated_term;
	}

	/**
	 * Delete an existing editorial metadata term
	 *
	 * @param int $term_id The term we want deleted
	 * @return bool $result Whether or not the term was deleted
	 */
	public static function delete_editorial_metadata_term( int $term_id ): bool {
		$term          = self::get_editorial_metadata_term_by( 'id', $term_id );
		$post_meta_key = self::get_postmeta_key( $term );
		delete_post_meta_by_key( $post_meta_key );

		$result = wp_delete_term( $term_id, self::METADATA_TAXONOMY );

		if ( ! $result ) {
			return new WP_Error( 'invalid', __( 'Unable to delete editorial metadata term.', 'vip-workflow' ) );
		}

		// Reset the internal object cache
		self::$editorial_metadata_terms_cache = [];

		// Re-order the positions after deletion
		$editorial_metadata_terms = self::get_editorial_metadata_terms();

		// ToDo: Optimize this to only work on the next or previous item.
		$current_postition = 1;

		// save each status with the new position
		foreach ( $editorial_metadata_terms as $editorial_metadata_term ) {
			self::update_editorial_metadata_term( $editorial_metadata_term->term_id, [ 'position' => $current_postition ] );

			++$current_postition;
		}

		return $result;
	}

	/** Generate the args for registering post meta
	 *
	 * @param WP_Term $term The term object
	 * @return array $args Post meta args
	 */
	public static function get_postmeta_args( WP_Term $term ): array {
		$arg_type = '';
		switch ( $term->type ) {
			case 'checkbox':
				$arg_type = 'boolean';
				break;
			case 'text':
				$arg_type = 'string';
				break;
		}

		$args = [
			'type'              => $arg_type,
			'description'       => $term->description,
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => function ( $value ) use ( $arg_type ) {
				switch ( $arg_type ) {
					case 'boolean':
						return boolval( $value );
					case 'string':
						return stripslashes( wp_filter_nohtml_kses( trim( $value ) ) );
				}
			},
		];

		return $args;
	}

	/**
	 * Generate a unique key based on the term id and type
	 *
	 * Key is in the form of vw_editorial_meta_{type}_{term_id}
	 *
	 * @param WP_Term $term The term object
	 * @return string $postmeta_key Unique key
	 */
	public static function get_postmeta_key( WP_Term $term ): string {
		$key          = self::METADATA_POSTMETA_KEY;
		$prefix       = "{$key}_{$term->type}";
		$postmeta_key = "{$prefix}_" . $term->term_id;
		return $postmeta_key;
	}
}

EditorialMetadata::init();
