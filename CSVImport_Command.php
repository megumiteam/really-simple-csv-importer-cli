<?php

class CSVImport_Command extends Import_Command {

	public $processed_posts = array();

	/**
	 * Imports content from a given CSV file.
	 *
	 * Provides a command line interface to the WordPress Importer plugin, for
	 * performing data migrations.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Path to one or more valid CSV, CSV files for importing. Directories are also accepted.
	 *
	 * --authors=<authors>
	 * : How the author mapping should be handled. Options are 'create', 'mapping.csv', or 'skip'. The first will create any non-existent users from the CSV file. The second will read author mapping associations from a CSV, or create a CSV for editing if the file path doesn't exist. The CSV requires two columns, and a header row like "old_user_login,new_user_login". The last option will skip any author mapping.
	 *
	 * [--skip=<data-type>]
	 * : Skip importing specific data. Supported options are: 'attachment' and 'image_resize' (skip time-consuming thumbnail generation).
	 *
	 * ## EXAMPLES
	 *
	 *     # Import content from a CSV file
	 *     $ wp import example.wordpress.2016-06-21.xml --authors=create
	 *     Starting the import process...
	 *     Processing post #1 ("Hello world!") (post_type: post)
	 *     -- 1 of 1
	 *     -- Tue, 21 Jun 2016 05:31:12 +0000
	 *     -- Imported post as post_id #1
	 *     Success: Finished importing from 'example.wordpress.2016-06-21.xml' file.
	 */
	public function __invoke( $args, $assoc_args ) {
		$defaults   = array(
			'authors' => null,
			'skip'    => array(),
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );
		if ( ! is_array( $assoc_args['skip'] ) ) {
			$assoc_args['skip'] = explode( ',', $assoc_args['skip'] );
		}

		$csv_import = false;
		$new_args = [];
		foreach ( $args as $arg ) {
			if ( is_dir( $arg ) ) {
				$dir   = WP_CLI\Utils\trailingslashit( $arg );
				$files = glob( $dir . '*.CSV' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}

				$files = glob( $dir . '*.xml' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}

				$files = glob( $dir . '*.csv' );
				if ( ! empty( $files ) ) {
					$csv_import = true;
					$new_args = array_merge( $new_args, $files );
				}
			} else {
				if ( file_exists( $arg ) ) {
					if ( preg_match('#\.csv$#i', $arg) ) {
						$csv_import = true;
					}
					$new_args[] = $arg;
				}
			}
		}
		$args = $new_args;

		return $csv_import
			? $this->csv_importer( $args, $assoc_args )
			: parent::__invoke( $args, $assoc_args );
	}

	private function csv_importer( $args, $assoc_args ) {
		$csv_importer = $this->is_csv_importer_available();
		if ( is_wp_error( $csv_importer ) ) {
			return WP_CLI::error( $csv_importer );
		}

		$this->add_csv_filters();

		WP_CLI::log( 'Starting the import process...' );

		foreach ( $args as $file ) {
			if ( ! is_readable( $file ) ) {
				WP_CLI::warning( "Can't read '$file' file." );
			}

			if ( preg_match('#\.csv$#i', $file) ) {
				$ret = $this->import_csv( $file, $assoc_args );
				if ( is_wp_error( $ret ) ) {
					WP_CLI::error( $ret );
				} else {
					WP_CLI::log( '' ); // CSV import ends with HTML, so make sure message is on next line
					WP_CLI::success( "Finished importing from '$file' file." );
				}
			} else {
				parent::__invoke( $args, $assoc_args );
			}
		}
	}

	/**
	 * Imports a CSV file.
	 */
	private function import_csv( $file, $args ) {

		$csv_import                  = new RS_CSV_Importer();
		$csv_import->file            = $file;
		$csv_import->processed_posts = $this->processed_posts;
		$import_data                 = $csv_import->file;
		if ( is_wp_error( $import_data ) ) {
			return $import_data;
		}

		// Prepare the data to be used in process_author_mapping();
		$author_data = $this->get_authors_from_csv( $import_data );

		// Drive the import
		$_GET  = array(
			'import' => 'wordpress',
			'step'   => 2,
		);
		$_POST = array(
			'fetch_attachments' => $csv_import->fetch_attachments,
		);

		if ( in_array( 'image_resize', isset($args['skip']) ? $args['skip'] : [], true ) ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_set_image_sizes' ) );
		}

		$GLOBALS['wpcli_import_current_file'] = basename( $file );
		$csv_import->process_posts( $file );
		$this->processed_posts += $csv_import->processed_posts;

		return true;
	}

	public function filter_set_image_sizes( $sizes ) {
		return parent::filter_set_image_sizes( $sizes );
	}

	private function get_authors_from_csv( $file_name ) {
		$author_data = [];
		$fp = new SplFileObject($file_name);
		$fp->setFlags(SplFileObject::READ_CSV);
		$headers = [];
		$post_author_index = -1;
		foreach ($fp as $raw => $line) {
			if ( 0 === $raw ) {
				$post_author_index = array_search('post_author',$line);
                if (!$post_author_index) {
					break;
				}
			} else {
                if (isset($line[$post_author_index]) && !isset($author_data[$line[$post_author_index]])) {
					$author = new \stdClass();
					$author->ID = intval($line[$post_author_index]);
					$author->user_login = get_the_author_meta('user_login', $author->ID);
					$author->user_email = get_the_author_meta('user_email', $author->ID);
					$author->display_name = get_the_author_meta('display_name', $author->ID);
					$author->first_name = get_the_author_meta('first_name', $author->ID);
					$author->last_name = get_the_author_meta('last_name', $author->ID);
					$author_data[$author->ID] = $author;
				}
			}
		}
		return $author_data;
	}

	/**
	 * Defines useful verbosity filters for the CSV importer.
	 */
	private function add_csv_filters() {

		add_filter(
			'wp_import_posts',
			function( $posts ) {
				global $wpcli_import_counts;
				$wpcli_import_counts['current_post'] = 0;
				$wpcli_import_counts['total_posts']  = count( $posts );
				return $posts;
			},
			10
		);

		add_filter(
			'wp_import_post_comments',
			function( $comments, $post_id, $post ) {
				global $wpcli_import_counts;
				$wpcli_import_counts['current_comment'] = 0;
				$wpcli_import_counts['total_comments']  = count( $comments );
				return $comments;
			},
			10,
			3
		);

		add_filter(
			'wp_import_post_data_raw',
			function( $post ) {
				global $wpcli_import_counts, $wpcli_import_current_file;

				$wpcli_import_counts['current_post']++;
				WP_CLI::log( '' );
				WP_CLI::log( '' );
				WP_CLI::log( sprintf( 'Processing post #%d ("%s") (post_type: %s)', $post['post_id'], $post['post_title'], $post['post_type'] ) );
				WP_CLI::log( sprintf( '-- %s of %s (in file %s)', number_format( $wpcli_import_counts['current_post'] ), number_format( $wpcli_import_counts['total_posts'] ), $wpcli_import_current_file ) );
				WP_CLI::log( '-- ' . date( 'r' ) );

				return $post;
			}
		);

		add_action(
			'wp_import_insert_post',
			function( $post_id, $original_post_id, $post, $postdata ) {
				global $wpcli_import_counts;
				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( '-- Error importing post: ' . $post_id->get_error_code() );
				} else {
					WP_CLI::log( "-- Imported post as post_id #{$post_id}" );
				}

				if ( 0 === ( $wpcli_import_counts['current_post'] % 500 ) ) {
					WP_CLI\Utils\wp_clear_object_cache();
					WP_CLI::log( '-- Cleared object cache.' );
				}

			},
			10,
			4
		);

		add_action(
			'wp_import_insert_term',
			function( $t, $import_term, $post_id, $post ) {
				WP_CLI::log( "-- Created term \"{$import_term['name']}\"" );
			},
			10,
			4
		);

		add_action(
			'wp_import_set_post_terms',
			function( $tt_ids, $term_ids, $taxonomy, $post_id, $post ) {
				WP_CLI::log( '-- Added terms (' . implode( ',', $term_ids ) . ") for taxonomy \"{$taxonomy}\"" );
			},
			10,
			5
		);

		add_action(
			'wp_import_insert_comment',
			function( $comment_id, $comment, $comment_post_id, $post ) {
				global $wpcli_import_counts;
				$wpcli_import_counts['current_comment']++;
				WP_CLI::log( sprintf( '-- Added comment #%d (%s of %s)', $comment_id, number_format( $wpcli_import_counts['current_comment'] ), number_format( $wpcli_import_counts['total_comments'] ) ) );
			},
			10,
			4
		);

		add_action(
			'import_post_meta',
			function( $post_id, $key, $value ) {
				WP_CLI::log( "-- Added post_meta $key" );
			},
			10,
			3
		);

	}

	/**
	 * Determines whether the requested importer is available.
	 */
	private function is_csv_importer_available() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( class_exists( 'RS_CSV_Importer' ) ) {
			return true;
		}

		$plugins            = get_plugins();
		$csv_importer = 'really-simple-csv-importer/rs-csv-importer.php';
		if ( array_key_exists( $csv_importer, $plugins ) ) {
			$error_msg = "Really Simple CSV Importer needs to be activated. Try 'wp plugin activate really-simple-csv-importer'.";
		} else {
			$error_msg = "Really Simple CSV Importer needs to be installed. Try 'wp plugin install really-simple-csv-importer --activate'.";
		}

		return new WP_Error( 'importer-missing', $error_msg );
	}
}
