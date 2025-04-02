<?php
/**
 * Class EV_Membership_Admin_Settings
 *
 * Handles the creation and management of the plugin's settings page
 * in the WordPress admin area.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class EV_Membership_Admin_Settings {

    /**
     * Stores the retrieved options array.
     * @var array
     */
    private static $options;

    /**
     * Initialize hooks for admin settings.
     */
    public static function init() {
        // Add the menu item under the 'Settings' menu
        add_action( 'admin_menu', [ self::class, 'add_admin_menu' ] );
        // Register settings and fields
        add_action( 'admin_init', [ self::class, 'settings_init' ] );
        // Load current options
        self::$options = get_option( 'ev_membership_options' );
    }

    /**
     * Add the submenu page under the 'Settings' menu.
     */
    public static function add_admin_menu() {
        add_options_page(
            __( 'EasyVerein Membership Form Settings', 'easyverein-membership-form' ), // Page title
            __( 'EasyVerein Form', 'easyverein-membership-form' ),                     // Menu title
            'manage_options',                                                          // Capability required
            'easyverein_membership_form_settings',                                     // Menu slug
            [ self::class, 'options_page_html' ]                                       // Function to display the page content
        );
    }

    /**
     * Register settings sections and fields.
     */
    public static function settings_init() {
        // Register the setting group
        register_setting(
            'ev_membership_options_group',                 // Option group name
            'ev_membership_options',                       // Option name in wp_options table
            [ self::class, 'sanitize_options' ]            // Sanitization callback function
        );

        // --- API Settings Section ---
        add_settings_section(
            'ev_membership_section_api',                   // Section ID
            __( 'API Configuration', 'easyverein-membership-form' ), // Section title
            null, // Callback for section description (optional)
            'ev_membership_options_group'                  // Page slug where section appears
        );

        add_settings_field(
            'api_key',                                     // Field ID
            __( 'EasyVerein API Key', 'easyverein-membership-form' ), // Field title
            [ self::class, 'render_field' ],               // Callback to render the field HTML
            'ev_membership_options_group',                 // Page slug
            'ev_membership_section_api',                   // Section ID
            [ // Arguments passed to the render callback
                'label_for' => 'api_key',
                'type' => 'password', // Use password type for API keys
                'description' => __( 'Enter your EasyVerein API v2 Key (found in your EasyVerein settings under "API"). This key is used to authenticate and obtain temporary access tokens.', 'easyverein-membership-form' )
            ]
        );

         // --- Form Settings Section ---
        add_settings_section(
            'ev_membership_section_form',                  // Section ID
            __( 'Form & Redirect Settings', 'easyverein-membership-form' ), // Section title
            null,
            'ev_membership_options_group'
        );

        add_settings_field(
            'membership_types',                            // Field ID
             __( 'Membership Types', 'easyverein-membership-form' ), // Field title
             [ self::class, 'render_field' ],              // Render callback
             'ev_membership_options_group',
             'ev_membership_section_form',
             [ // Arguments
                 'label_for' => 'membership_types',
                 'type' => 'textarea',
                 'description' => __( 'Define the membership types available in the form dropdown. Enter one per line in the format: <strong>Form Label=EasyVerein_Membership_ID</strong>. Example:<br><code>Standard Member=12345</code><br><code>Family Plan=67890</code><br>You can find the Membership IDs in your EasyVerein administration under "Members" -> "Settings" -> "Membership types".', 'easyverein-membership-form' )
             ]
        );

        add_settings_field(
            'success_page_id',                             // Field ID
             __( 'Success Page', 'easyverein-membership-form' ), // Field title
             [ self::class, 'render_page_dropdown' ],      // Render callback for page dropdown
             'ev_membership_options_group',
             'ev_membership_section_form',
             [ // Arguments
                 'id' => 'success_page_id',
                 'description' => __( 'Select the page users should be redirected to after a successful application submission.', 'easyverein-membership-form' )
             ]
        );

        add_settings_field(
            'error_page_id',                               // Field ID
             __( 'Error Page', 'easyverein-membership-form' ), // Field title
             [ self::class, 'render_page_dropdown' ],      // Render callback
             'ev_membership_options_group',
             'ev_membership_section_form',
             [ // Arguments
                 'id' => 'error_page_id',
                 'description' => __( 'Select the page users should be redirected to if an API error or other processing error occurs.', 'easyverein-membership-form' )
             ]
        );
    }

    /**
     * Render standard input or textarea fields.
     *
     * @param array $args Field arguments (type, label_for, description).
     */
    public static function render_field( $args ) {
        $option_name = 'ev_membership_options';
        $field_id = $args['label_for'];
        // Get the current value, default to empty string if not set
        $value = isset( self::$options[$field_id] ) ? self::$options[$field_id] : '';

        // Render textarea field
        if ( $args['type'] === 'textarea' ) {
             printf(
                 '<textarea id="%1$s" name="%2$s[%1$s]" rows="6" cols="50" class="large-text code">%3$s</textarea>',
                 esc_attr( $field_id ),
                 esc_attr( $option_name ),
                 esc_textarea( $value ) // Use esc_textarea for textarea content
             );
        }
        // Render other input types (text, password, etc.)
        else {
             printf(
                 '<input type="%1$s" id="%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text" />',
                 esc_attr( $args['type'] ),
                 esc_attr( $field_id ),
                 esc_attr( $option_name ),
                 esc_attr( $value ) // Use esc_attr for input values
             );
        }

        // Display the field description if provided
        if ( ! empty( $args['description'] ) ) {
            // Use wp_kses_post to allow basic HTML in descriptions (like <strong> or <code>)
            printf( '<p class="description">%s</p>', wp_kses_post( $args['description'] ) );
        }
    }

     /**
      * Render a dropdown list of WordPress pages.
      *
      * @param array $args Field arguments (id, description).
      */
     public static function render_page_dropdown( $args ) {
        $option_name = 'ev_membership_options';
        $field_id = $args['id'];
        // Get the currently selected page ID
        $selected_page_id = isset( self::$options[$field_id] ) ? self::$options[$field_id] : '';

        // Use wp_dropdown_pages to generate the dropdown
        wp_dropdown_pages([
            'name'              => esc_attr( $option_name ) . '[' . esc_attr( $field_id ) . ']', // Input name format: option_name[field_id]
            'id'                => esc_attr( $field_id ), // Input ID
            'selected'          => esc_attr( $selected_page_id ), // Pre-select the saved value
            'show_option_none'  => __( '&mdash; Select a Page &mdash;', 'easyverein-membership-form' ), // Placeholder text
            'option_none_value' => '', // Value for the placeholder
            'sort_column'       => 'post_title', // Sort pages alphabetically
        ]);

        // Display the field description if provided
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Sanitize the option values before saving them to the database.
     *
     * @param array $input Raw input data from the form submission.
     * @return array Sanitized data.
     */
    public static function sanitize_options( $input ) {
        $sanitized_input = [];
        $current_options = get_option('ev_membership_options'); // Get current options for comparison

        // Sanitize API Key (treat as text, allow trimming)
        if ( isset( $input['api_key'] ) ) {
            $new_api_key = sanitize_text_field( trim($input['api_key']) );
            $sanitized_input['api_key'] = $new_api_key;

            // IMPORTANT: If the API key has changed, clear the stored access token
            // to force a re-login with the new key on the next API call.
            if ( !isset($current_options['api_key']) || $new_api_key !== $current_options['api_key'] ) {
                delete_option('ev_access_token');
                delete_option('ev_token_expires');
                // Add an admin notice maybe?
                add_settings_error('ev_membership_options', 'api_key_changed', __('API Key changed. Previous access token cleared.', 'easyverein-membership-form'), 'updated');
            }
        }

        // Sanitize Success Page ID (must be a positive integer)
        if ( isset( $input['success_page_id'] ) ) {
            $sanitized_input['success_page_id'] = absint( $input['success_page_id'] ); // absint ensures positive integer
        }

        // Sanitize Error Page ID (must be a positive integer)
        if ( isset( $input['error_page_id'] ) ) {
            $sanitized_input['error_page_id'] = absint( $input['error_page_id'] );
        }

        // Sanitize Membership Types (textarea)
         if ( isset( $input['membership_types'] ) ) {
            // Allow basic text, line breaks, equals sign for the format
            $sanitized_input['membership_types'] = sanitize_textarea_field( $input['membership_types'] );
        }

        return $sanitized_input;
    }

    /**
     * Render the HTML for the options page.
     */
    public static function options_page_html() {
        // Check if the current user has the required capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'easyverein-membership-form' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php settings_errors('ev_membership_options'); // Display any settings errors (like API key changed notice) ?>

            <form action="options.php" method="post">
                <?php
                // Output security fields for the registered setting group
                settings_fields( 'ev_membership_options_group' );
                // Output the settings sections and their fields
                do_settings_sections( 'ev_membership_options_group' );
                // Output the save button
                submit_button( __( 'Save Settings', 'easyverein-membership-form' ) );
                ?>
            </form>
        </div>
        <?php
    }
}