<?php
/**
 * Plugin Name:       EasyVerein Membership Form-Gemini
 * Plugin URI:        https://yourwebsite.com/ # Replace with your URL
 * Description:       Integrates an EasyVerein membership application form via their REST API v2.0, including token refresh and logging.
 * Version:           1.0.1
 * Author:            Your Name # Replace with your name/company
 * Author URI:        https://yourwebsite.com/ # Replace with your URL
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyverein-membership-form
 * Domain Path:       /languages
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define Plugin Constants for easier file path management
define( 'EV_MEMBERSHIP_PLUGIN_VERSION', '1.0.1' );
define( 'EV_MEMBERSHIP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'EV_MEMBERSHIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EV_MEMBERSHIP_API_BASE_URL', 'https://easyverein.com/api/v2.0/' );
define( 'EV_MEMBERSHIP_LOG_DIR', EV_MEMBERSHIP_PLUGIN_PATH . 'logs/' );

// Ensure the log directory exists on activation and is protected
register_activation_hook( __FILE__, 'ev_membership_activate' );
function ev_membership_activate() {
    // Create log directory if it doesn't exist
    if ( ! file_exists( EV_MEMBERSHIP_LOG_DIR ) ) {
        wp_mkdir_p( EV_MEMBERSHIP_LOG_DIR );
    }
    // Add index.html to prevent directory browsing
    if ( ! file_exists( EV_MEMBERSHIP_LOG_DIR . 'index.html' ) ) {
        @file_put_contents( EV_MEMBERSHIP_LOG_DIR . 'index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    }
    // Add .htaccess to deny access (for Apache servers)
    if ( ! file_exists( EV_MEMBERSHIP_LOG_DIR . '.htaccess' ) ) {
        $htaccess_content = "Options -Indexes\ndeny from all";
        @file_put_contents( EV_MEMBERSHIP_LOG_DIR . '.htaccess', $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    }
    // Set default options if they don't exist
     if ( false === get_option( 'ev_membership_options' ) ) {
        add_option( 'ev_membership_options', [
            'api_key' => '',
            'success_page_id' => '',
            'error_page_id' => '',
            'membership_types' => 'Standard=12345', // Default example: Form Label=easyVerein_ID
        ]);
    }
}

// Include necessary plugin files
require_once EV_MEMBERSHIP_PLUGIN_PATH . 'includes/class-ev-api-handler.php';
require_once EV_MEMBERSHIP_PLUGIN_PATH . 'includes/admin-settings.php';
require_once EV_MEMBERSHIP_PLUGIN_PATH . 'includes/form-handler.php';
require_once EV_MEMBERSHIP_PLUGIN_PATH . 'includes/form-processor.php';

// Enqueue frontend styles
function ev_membership_enqueue_styles() {
    // Only enqueue on the frontend and if the shortcode is likely present (basic check)
    // A more robust check would involve checking post content for the shortcode
    if ( ! is_admin() ) {
        wp_enqueue_style(
            'easyverein-form-style', // Handle for the stylesheet
            EV_MEMBERSHIP_PLUGIN_URL . 'css/easyverein-form.css', // URL to the CSS file
            [], // Dependencies (optional)
            EV_MEMBERSHIP_PLUGIN_VERSION // Version number (for cache busting)
        );
    }
}
add_action( 'wp_enqueue_scripts', 'ev_membership_enqueue_styles' );

// Initialize Admin Settings Page
if ( is_admin() ) {
    EV_Membership_Admin_Settings::init();
}

// Register the shortcode [easyverein_membership_form]
add_shortcode( 'easyverein_membership_form', 'ev_membership_display_form' );

// Register the form submission action hooks for logged-in and non-logged-in users
add_action( 'admin_post_nopriv_ev_submit_application', 'ev_membership_process_form' ); // For non-logged-in users
add_action( 'admin_post_ev_submit_application', 'ev_membership_process_form' );      // For logged-in users

?>