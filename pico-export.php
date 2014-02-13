<?php
/*
Plugin Name: WordPress to Pico Exporter
Description: Exports WordPress posts, pages, and options as YAML files parsable by Pico (based on WordPRess to Jekyll Exporter by Benjamin J. Balter  (https://github.com/benbalter/wordpress-to-jekyll-exporter)
Version: 1.5
Author: z720
Author URI: http://z720.net
License: GPLv3 or Later

Copyright 2012-2013 S. Erard

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Pico_Export {

  private $zip_folder = 'pico-export/'; //folder zip file extracts to

  public  $required_classes = array( 
    'Markdownify\Parser' => '%pwd%/includes/markdownify/Parser.php',
    'Markdownify\Converter' => '%pwd%/includes/markdownify/Converter.php',
    'Markdownify\ConverterExtra' => '%pwd%/includes/markdownify/ConverterExtra.php',
  );

  /**
   * Hook into WP Core
   */
  function __construct() {

    add_action( 'admin_menu', array( &$this, 'register_menu' ) );
    add_action( 'current_screen', array( &$this, 'callback' ) );

  }

  /**
   * Listens for page callback, intercepts and runs export
   */
  function callback() {

    if ( get_current_screen()->id != 'export' )
      return;

    if ( !isset( $_GET['type'] ) || $_GET['type'] != 'pico' )
      return;

    if ( !current_user_can( 'manage_options' ) )
      return;

    $this->export();
    exit();

  }


  /**
   * Add menu option to tools list
   */
  function register_menu() {

    add_management_page( __( 'Export to Pico', 'pico-export' ), __( 'Export to Pico', 'pico-export' ), 'manage_options', 'export.php?type=pico' );

  }


  /**
   * Get an array of all post and page IDs
   * Note: We don't use core's get_posts as it doesn't scale as well on large sites
   */
  function get_posts() {

    global $wpdb;
    return $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type IN ('post', 'page' )" );

  }


  /**
   * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
   */
  function convert_meta( $post ) {

    $output = array(
      'Title'   => get_the_title( $post ),
      'Author'  => get_userdata( $post->post_author )->display_name,
      'Excerpt' => $post->post_excerpt,
      'Template'  => get_post_type( $post ),
    );

    //preserve exact permalink
    $output[ 'Permalink' ] = str_replace( home_url(), '', get_permalink( $post ) );

    //Preserve blog post status according to Pico blogging best practice
    if ( 'post' == $post->post_type ) {
      $output[ 'Date' ] = mysql2date( 'c', $post->post_date_gmt );
    }
    
    //convert traditional post_meta values, hide hidden values
    foreach ( get_post_custom( $post->ID ) as $key => $value ) {

      if ( substr( $key, 0, 1 ) == '_' )
        continue;

      $output[ $key ] = $value;

    }

    return $output;
  }


  /**
   * Convert post taxonomies for export
   */
  function convert_terms( $post ) {
    $output = array();
    foreach ( get_taxonomies( array( 'object_type' => array( get_post_type( $post ) ) ) ) as $tax ) {

      $terms = wp_get_post_terms( $post, $tax );

      //convert tax name for Pico
      switch ( $tax ) {
      case 'post_tag':
        $tax = 'tags';
        break;
      case 'category':
        $tax = 'categories';
        break;
      }

      if ( $tax == 'post_format' ) {
        $output['format'] = get_post_format( $post );
      } else {
        $output[ $tax ] = wp_list_pluck( $terms, 'name' );
      }
    }

    return $output;
  }

  /**
   * Convert the main post content to Markdown.
   */
  function convert_content( $post ) {

    $content = apply_filters( 'the_content', $post->post_content );
    $converter = new Markdownify\ConverterExtra;
    $markdown = $converter->parseString( $content );

    if ( false !== strpos( $markdown, '[]: ' ) ) {
      // faulty links; return plain HTML
      return $content;
    }

    return $markdown;
  }

  /**
   * Loop through and convert all posts to MD files with YAML headers
   */
  function convert_posts() {
    global $post;

    foreach ( $this->get_posts() as $postID ) {
      // Meta data
      $output = "/*\n";

      $post = get_post( $postID );
      setup_postdata( $post );

      $meta = array_merge( $this->convert_meta( $post ), $this->convert_terms( $postID ) );

      // remove falsy values, which just add clutter
      foreach ( $meta as $key => $value ) {
        if ( !is_numeric( $value ) && !$value ) {
          unset( $meta[ $key ] );
        } else {
          $key = ucfirst($key);
          if( is_array( $value ) ) {
            $value = implode( ', ', $value );
          }
          $output .= " $key: $value\n";
        }
      }

      $output .= "*/\n";
      // Content
      $output .= $this->convert_content( $post );
      $this->write( $output, $post, $meta[ 'permalink' ] );
    }

  }

  function filesystem_method_filter() {
    return 'direct';
  }

  /** 
   *  Conditionally Include required classes
   */
  function require_classes() {

      foreach ( $this->required_classes as $class => $path ) {
        
        if ( class_exists( $class ) )
            continue;

        $path = str_replace( "%pwd%", dirname( __FILE__ ), $path );

        require_once( $path );

      }

  }

  /**
   * Main function, bootstraps, converts, and cleans up
   */
  function export() {
    global $wp_filesystem;

    define( 'DOING_PICO_EXPORT', true );

    $this->require_classes();

    add_filter( 'filesystem_method', array( &$this, 'filesystem_method_filter' ) );

    WP_Filesystem();

    $temp_dir = get_temp_dir();
    $this->dir = $temp_dir . 'wp-pico-' . md5( time() ) . '/';
    $this->zip = $temp_dir . 'wp-pico.zip';
    $wp_filesystem->mkdir( $this->dir );
    
    $this->convert_posts();
    $this->convert_uploads();
    $this->zip();
    $this->send();
    $this->cleanup();

  }

  /**
   * Recursive mkdir
   */
  function mkdirrecursive( $path ) {
    global $wp_filesystem;
    $p = '';
    foreach( explode( '/', $path ) as $folder ) {
      $p .= $folder . '/';
      $wp_filesystem->mkdir( $this->dir . '/' . $p );
    }
  }

  /**
   * Write file to temp dir
   */
  function write( $output, $post, $path ) {

    global $wp_filesystem;

    if ( $path ) {
      $this->mkdirrecursive( $path );
      $filename = ltrim($path . '/index.md', '/');
    } else {
      $wp_filesystem->mkdir( $this->dir . $post->post_name );
      $filename = $post->post_name . '/index.md';
    }

    $wp_filesystem->put_contents( $this->dir . $filename, $output );

  }


  /**
   * Zip temp dir
   */
  function zip() {

    //create zip
    $zip = new ZipArchive();
    $zip->open( $this->zip, ZIPARCHIVE::CREATE );
    $this->_zip( $this->dir, $zip );
    $zip->close();

  }


  /**
   * Helper function to add a file to the zip
   */
  function _zip( $dir, &$zip ) {

    //loop through all files in directory
    foreach ( glob( trailingslashit( $dir ) . '*' ) as $path ) {

      // periodically flush the zipfile to avoid OOM errors
      if ((($zip->numFiles+1) % 250) == 0) {
         $filename = $zip->filename;
         $zip->close();
         $zip->open($filename);
      }

      if ( is_dir( $path ) ) {
        $this->_zip( $path, $zip );
        continue;
      }

      //make path within zip relative to zip base, not server root
      $local_path = ltrim( str_replace( $this->dir, $this->zip_folder, $path ), '/' );

      //add file
      $zip->addFile( realpath( $path ), $local_path );

    }

  }


  /**
   * Send headers and zip file to user
   */
  function send() {

    //send headers
    @header( 'Content-Type: application/zip' );
    @header( "Content-Disposition: attachment; filename=pico-export.zip" );
    @header( 'Content-Length: ' . filesize( $this->zip ) );

    //read file
    ob_clean(); flush();
    readfile( $this->zip );

  }


  /**
   * Clear temp files
   */
  function cleanup( ) {

    global $wp_filesystem;

    $wp_filesystem->delete( $this->dir, true );
    $wp_filesystem->delete( $this->zip );

  }


  /**
   * Rename an assoc. array's key without changing the order
   */
  function rename_key( &$array, $from, $to ) {

    $keys = array_keys( $array );
    $index = array_search( $from, $keys );

    if ( $index === false )
      return;

    $keys[ $index ] = $to;
    $array = array_combine( $keys, $array );


  }

  function convert_uploads() {

    $upload_dir = wp_upload_dir();
    $this->copy_recursive( $upload_dir['basedir'], $this->dir . str_replace( trailingslashit( get_home_url() ), '', $upload_dir['baseurl'] )  );

  }

  /**
   * Copy a file, or recursively copy a folder and its contents
   *
   * @author      Aidan Lister <aidan@php.net>
   * @version     1.0.1
   * @link        http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
   * @param       string   $source    Source path
   * @param       string   $dest      Destination path
   * @return      bool     Returns TRUE on success, FALSE on failure
   */
  function copy_recursive($source, $dest) {

    global $wp_filesystem;

    // Check for symlinks
    if ( is_link( $source ) ) {
      return symlink( readlink( $source ), $dest );
    }

    // Simple copy for a file
    if ( is_file( $source ) ) {
      return $wp_filesystem->copy( $source, $dest );
    }

    // Make destination directory
    if ( !is_dir($dest) ) {
      $wp_filesystem->mkdir( $dest );
    }

    // Loop through the folder
    $dir = dir($source);
    while (false !== $entry = $dir->read()) {
      // Skip pointers
      if ($entry == '.' || $entry == '..') {
        continue;
      }

      // Deep copy directories
      $this->copy_recursive("$source/$entry", "$dest/$entry");
    }

    // Clean up
    $dir->close();
    return true;

  }

}

$je = new Pico_Export();

if ( defined('WP_CLI') && WP_CLI ) {
  
  class Pico_Export_Command extends WP_CLI_Command {

    function __invoke() {
      global $je;

      $je->export();
    }
  }

  WP_CLI::add_command( 'pico-export', 'Pico_Export_Command' );

}
