<?php

if ( !defined( 'WP_CLI' ) ) return;

/**
 * Blog Extract
 */
class Blog_Extract extends WP_CLI_Command {

	/**
	 * Blog extract
	 *
	 * ## OPTIONS
	 *
	 * <blog-id>
	 * : ID of blog to extract
	 *
	 * [--v]
	 * : Verbose
	 *
	 * ## EXAMPLES
	 *
	 *     wp extract 3
	 */
	function __invoke( $args, $assoc_args ) {

		// verify multisite
		if ( ! is_multisite() ) {
			WP_CLI::error( "This is a multisite command only." );
		}

		// get args
		list( $blogid ) = $args;
		$v = isset( $assoc_args['v'] );

		// verify valid blog id
		if ( ! ( $details = get_blog_details( $blogid ) ) ) {
			WP_CLI::error( "Given blog id is invalid." );
			return;
		}

		switch_to_blog( $blogid );

		global $wpdb;

		$blog_tables = $wpdb->tables('blog');

		/************************************\
		                             DATABASE
		\************************************/
		 /*
		  * We use the $rename_tables array to store any tables that
		  * will need to be renamed upon import to the new database.
		  */
		$rename_tables = array();

		/*
		 * For blog ID 1, we have to use a different temp user table name,
		 * since $wpdb->prefix doesn't have a number appended
		 * and we don't want to affect the global user tables.
		 */
		if ( 1 == $blogid ) {
			$tmp_users = "{$wpdb->prefix}temp_users";
			$tmp_usermeta = "{$wpdb->prefix}temp_usermeta";

			// Add these to the rename_tables array, so we can
			// rename them when importing to the new database
			$rename_tables[ $tmp_users ] = "{$wpdb->prefix}users";
			$rename_tables[ $tmp_usermeta ] = "{$wpdb->prefix}usermeta";
		} else {
			$tmp_users = "{$wpdb->prefix}users";
			$tmp_usermeta = "{$wpdb->prefix}usermeta";
		}

		$blog_1_case = ! empty( $rename_tables ); // just a nice flag to use later

		$blog_tables['users'] = $tmp_users;
		$blog_tables['usermeta'] = $tmp_usermeta;

		// @debug let's take a look at what tables we're archiving
		WP_CLI::debug( print_r( $blog_tables, true ) );

		$users = wp_list_pluck( get_users(), 'ID' );
		$super_admin_ids = array();
		foreach ( get_super_admins() as $username ) {
			$super_admin_ids[] = get_user_by( 'login', $username )->ID;
		}

		$supes = array_diff( $super_admin_ids, $users );
		$users = array_filter( array_unique( array_merge( $users, $super_admin_ids ) ) );

		$userlist = implode( ',', $users );

		if ( $v ) {
			WP_CLI::log( 'Begin copying user tables' );
		}

		/*
		 * Checks if we are attempting to create a temp table
		 * with the same name as the main user table. If so, bail.
		 * Currently happens if attempting to export blog ID 1,
		 * since the DB prefix will not have a number appended.
		 */
		if ( $tmp_users == $wpdb->users ) {
			// OMG run away, FAST before we break something important
			WP_CLI::error( 'There was an error duplicating user tables' );
		}

		// duplicate global user tables
		// delete unnecessary rows (probably not performant on large data)
		$wpdb->query( "create table if not exists {$tmp_users} like {$wpdb->users}" );
		$check = $wpdb->get_col( "select * from {$tmp_users}" );
		if ( empty( $check ) ) {
			if ( $v ) {
				WP_CLI::log( 'copying main users table' );
			}
			if ( $userlist ) {
				$wpdb->query( "insert into {$tmp_users} select * from {$wpdb->users} where ID IN ({$userlist})" );
			} else {
				WP_CLI::log( WP_CLI::colorize( "%YNo users were exported. You'll need to create users manually.%n" ) );
			}
		}

		$wpdb->query( "create table if not exists {$tmp_usermeta} like {$wpdb->usermeta}" );
		$check = $wpdb->get_col( "select * from {$tmp_usermeta}" );
		if ( empty( $check ) ) {
			if ( $v ) {
				WP_CLI::log( 'copying main usermeta table' );
			}
			if ( $userlist ) {
				$wpdb->query( "insert into {$tmp_usermeta} select * from {$wpdb->usermeta} where user_id IN ({$userlist})" );
			}
		}

		// for the super admins that were not specifically added
		// to the blog on the network, give administrator role
		foreach ( $supes as $sid ) {
			$wpdb->insert( $tmp_usermeta,
				array(
					'user_id'    => $sid,
					'meta_key'   => $wpdb->prefix .'capabilities',
					'meta_value' => serialize( array( 'administrator' => true ) ),
				),
				array(
					'%d',
					'%s',
					'%s',
				)
			);
		}

		if ( $v ) {
			WP_CLI::log( 'Begin exporting tables' );
		}
		$tablelist = implode( ' ', $blog_tables );
		$sql_file = "database-{$blogid}.sql";
		shell_exec( "mysqldump -h " . DB_HOST . " -u ". DB_USER ." -p". DB_PASSWORD ." ". DB_NAME ." {$tablelist} > {$sql_file}" );

		if ( file_exists( ABSPATH . $sql_file ) ) {
			if ( ( $filesize = filesize( ABSPATH . $sql_file ) ) > 0 ) {

				// Add statements to rename any tables we have in the $rename_tables array
				if ( $blog_1_case ) {
					$sql_fh = fopen( ABSPATH . $sql_file, 'a' );
					fwrite( $sql_fh, "\n" );
					foreach ( $rename_tables as $oldname => $newname ) {
						fwrite( $sql_fh, "RENAME TABLE `{$oldname}` TO `{$newname}`;\n" );
					}
					fclose( $sql_fh );
				}

				if ( $v ) {
					WP_CLI::log( 'Database tables exported' );
				}

				$wpdb->query( "drop table if exists {$tmp_users}, {$tmp_usermeta}" );

			} else {
				unlink( ABSPATH . $sql_file );
				WP_CLI::error( 'There was an error exporting the archive.' );
			}
		} else {
			WP_CLI::error( 'There was an error exporting the archive.' );
		}

		/************************************\
		                                FILES
		\************************************/

		$export_dirs = array( ABSPATH . $sql_file );

		// uploads
		$upload_dir = wp_upload_dir();
		$export_dirs[] = $upload_dir['basedir'];

		// plugins
		$plugins = get_option( 'active_plugins' );
		$plugins = array_map( function($i) {
			$parts = explode( '/', $i );
			$root = array_shift( $parts );
			return WP_CONTENT_DIR .'/plugins/'. $root;
		}, $plugins );
		$export_dirs = array_merge( $export_dirs, $plugins );

		// network plugins
		$networkplugins = wp_get_active_network_plugins();
		$networkplugins = array_map( function($i) {
			$parts = explode( '/', str_replace( WP_CONTENT_DIR .'/plugins/', '', $i ) );
			$root = array_shift( $parts );
			return WP_CONTENT_DIR .'/plugins/'. $root;
		}, $networkplugins );
		$export_dirs = array_merge( $export_dirs, $networkplugins );

		// mu plugins
		$export_dirs[] = ABSPATH . MUPLUGINDIR;

		// mu plugins
		$dropins = array_keys( get_dropins() );
		$dropins = array_map( function($i) {
			return WP_CONTENT_DIR .'/'. $i;
		}, $dropins );
		$export_dirs = array_merge( $export_dirs, $dropins );


		// theme(s)
		$themes = array_unique( array( get_stylesheet(), get_template() ) );
		$themes = array_map( function($i) { return WP_CONTENT_DIR. get_raw_theme_root( get_stylesheet() ) .'/' . $i;}, $themes );
		$export_dirs = array_merge( $export_dirs, $themes );

		// remove ABSPATH. makes the export more friendly when extracted
		$exports = array_map( function($i) {
			return '"'. str_replace( ABSPATH, '', $i ) .'"';
		}, $export_dirs );

		// @debug let's take a look at what we're archiving
		WP_CLI::debug( print_r( $exports, true ) );

		// work out any directories that should be excluded from the archive
		$exclude = '';
		$exclude_exports = array();

		if ( $blog_1_case ) {
			// if we renamed, we're on site ID 1, which also means uploads aren't in /sites/
			$exclude_exports[] = str_replace( ABSPATH, '', $upload_dir['basedir'] ) . '/sites';
		}

		if ( count( $exclude_exports ) > 0 ) {
			foreach ( $exclude_exports as $ee ) {
				$exclude .= " --exclude=$ee ";
			}
		}

		// @debug let's take a look
		WP_CLI::debug( print_r( $exclude_exports, true ) );

		// @todo make this user-set
		$export_file = "archive-{$blogid}.tar.gz";

		// GOOD!
		$abspath = ABSPATH;
		$exports = implode( ' ', $exports );
		if ( $v ) {
			WP_CLI::log( 'Begin archiving files' );
		}
		shell_exec( "cd {$abspath}; tar -cvf {$export_file} {$exports} {$exclude}" );

		if ( file_exists( ABSPATH . $export_file ) ) {
			if ( ( $filesize = filesize( ABSPATH . $export_file ) ) > 0 ) {
				// sql dump was archived, remove regular file
				unlink( ABSPATH . $sql_file );

				$filesize = size_format( $filesize, 2 );
				WP_CLI::success( "$export_file created! ($filesize)" );

				$prefix = WP_CLI::colorize( "%P{$wpdb->prefix}%n" );
				WP_CLI::log( 'In your new install in wp-config.php, set the $table_prefix to '. $prefix );
				WP_CLI::log( 'You\'ll also need to do a search-replace for the url change' );

				$old_url = untrailingslashit( $details->domain. $details->path );
				WP_CLI::log( '=========================================' );

				WP_CLI::log( "# update URLs" );
				WP_CLI::log( "wp search-replace {$old_url} NEWURL" );
				if ( ! $blog_1_case ) {
					// again, we're on ID 1, so uploads aren't in /sites/, so no need for these find-replace recommendations
					$rel_upl = str_replace( ABSPATH, '', $upload_dir['basedir'] );
					WP_CLI::log( "# move the uploads to the typical directory" );
					WP_CLI::log( "mv {$rel_upl}/* wp-content/uploads/" );
					WP_CLI::log( "# remove the old directory" );
					WP_CLI::log( "rm -rf wp-content/uploads/sites/" );
					WP_CLI::log( "# update database" );
					WP_CLI::log( "wp search-replace {$rel_upl}/ wp-content/uploads/" );
				}

				WP_CLI::log( '=========================================' );


			} else {
				unlink( ABSPATH . $export_file );
				WP_CLI::error( 'There was an error creating the archive.' );
			}
		} else {
			WP_CLI::error( 'Unable to create the archive.' );
		}

	}

}

WP_CLI::add_command( 'extract', 'Blog_Extract' );