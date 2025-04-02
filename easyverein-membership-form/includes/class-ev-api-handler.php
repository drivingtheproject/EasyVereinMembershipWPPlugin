<?php
/**
 * Class EV_API_Handler
 *
 * Handles all communication with the EasyVerein API v2.0,
 * including authentication, token refresh, requests, and logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class EV_API_Handler {

    /**
     * Singleton instance.
     * @var EV_API_Handler|null
     */
    private static $instance = null;

    /**
     * EasyVerein API Key (used for initial login/refresh).
     * @var string|null
     */
    private $api_key = null;

    /**
     * Current Bearer Access Token.
     * @var string|null
     */
    private $access_token = null;

    /**
     * Expiry timestamp for the current access token.
     * @var int
     */
    private $token_expires = 0;

    /**
     * Constructor - Protected to enforce singleton pattern.
     * Loads API key and stored token data from WordPress options.
     */
    private function __construct() {
        $options = get_option( 'ev_membership_options' );
        $this->api_key = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : null;

        // Load stored token data from options
        $this->access_token = get_option( 'ev_access_token', null );
        $this->token_expires = (int) get_option( 'ev_token_expires', 0 );

        $this->log( 'API Handler Initialized.' );
        if ( empty( $this->api_key ) ) {
            $this->log( 'API Key is not configured in settings.', 'WARNING' );
        }
    }

    /**
     * Get the singleton instance of the API Handler.
     *
     * @return EV_API_Handler
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log messages to a file.
     *
     * @param mixed $message The message or data to log.
     * @param string $type Log level (e.g., INFO, WARNING, ERROR).
     */
    public function log( $message, $type = 'INFO' ) {
        // Don't log if log directory isn't writable or doesn't exist
        if ( ! is_writable( EV_MEMBERSHIP_LOG_DIR ) ) {
             // Optionally log to PHP error log as a fallback if WP_DEBUG_LOG is enabled
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
                error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    sprintf(
                        "EasyVerein Log Error: Directory %s not writable. Message: [%s] %s",
                        EV_MEMBERSHIP_LOG_DIR,
                        $type,
                        print_r( $message, true )
                    )
                );
            }
            return;
        }

        $log_file = EV_MEMBERSHIP_LOG_DIR . 'easyverein-api.log';
        $timestamp = current_time( 'mysql' ); // Get WordPress localized time
        // Format the message for logging
        $formatted_message = sprintf( "[%s] [%s]: %s\n", $timestamp, strtoupper( $type ), print_r( $message, true ) );

        // Append the message to the log file
        file_put_contents( $log_file, $formatted_message, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    }

    /**
     * Refresh the API access token using the stored API Key.
     * Stores the new token and its expiry time in WP options.
     *
     * @return string|false The new access token on success, false on failure.
     */
    private function refresh_token() {
        $this->log( 'Attempting to refresh API token.' );

        if ( empty( $this->api_key ) ) {
            $this->log( 'API Key is missing. Cannot refresh token.', 'ERROR' );
            return false;
        }

        $login_url = trailingslashit( EV_MEMBERSHIP_API_BASE_URL ) . 'refresh-token';
        $args = [
            'method' => 'GET',
            'headers' => [
                // Authentication for login uses 'Token', not 'Bearer'
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 45, // Increased timeout for potentially slower auth requests
            'redirection' => 0, // Don't follow redirects
            'body' => null, // No body needed for login
        ];

        $this->log( 'Refreshing token - Request URL: ' . $login_url );
        // Avoid logging the full API key in production logs if possible
        $this->log( 'Refreshing token - Request Headers: ' . print_r( $args['headers'], true ) );

        // Make the API request using WordPress HTTP API
        $response = wp_remote_post( $login_url, $args );

        // Handle WP_Error during the request
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            $this->log( 'Token refresh failed (WP_Error): ' . $error_message, 'ERROR' );
            return false;
        }

        // Process the response
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true ); // Decode JSON response into an associative array

        $this->log( 'Token refresh - Response Code: ' . $status_code );
        // Only log body snippet in production to avoid exposing token if sensitive
        $this->log( 'Token refresh - Response Body Snippet: ' . substr($body, 0, 200) );

        // Check for successful response (HTTP 200) and presence of token
        if ( $status_code === 200 && isset( $data['Bearer'] ) ) {
            $this->access_token = $data['Bearer'];
            // Estimate expiry: easyVerein tokens typically last 1 hour (3600s).
            // Set expiry slightly earlier (e.g., 55 mins = 3300s) for safety buffer.
            $this->token_expires = time() + 3300;

            // Store the new token and expiry time securely in WordPress options
            update_option( 'ev_access_token', $this->access_token, false ); // 'false' means do not autoload
            update_option( 'ev_token_expires', $this->token_expires, false ); // 'false' means do not autoload

            $this->log( 'Token successfully refreshed. New expiry: ' . date('Y-m-d H:i:s', $this->token_expires) );
            return $this->access_token;
        } else {
            $error_detail = isset($data['detail']) ? $data['detail'] : $body;
            $this->log( 'Token refresh failed. Status: ' . $status_code . ', Detail: ' . $error_detail, 'ERROR' );
            // Clear potentially invalid stored token if refresh fails
            delete_option( 'ev_access_token' );
            delete_option( 'ev_token_expires' );
            $this->access_token = null;
            $this->token_expires = 0;
            return false;
        }
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * @return string|false The valid access token or false if unable to obtain one.
     */
    private function get_valid_token() {
        // Check if the current token exists and hasn't expired (using the buffer)
        if ( $this->access_token && $this->token_expires > time() ) {
            $this->log( 'Using existing valid token.' );
            return $this->access_token;
        }

        // If no token or expired, attempt to refresh it
        $this->log( 'Existing token invalid, expired, or nearing expiry. Refreshing.' );
        return $this->refresh_token();
    }

    /**
     * Make a request to the EasyVerein API.
     * Handles token retrieval, refresh on failure (401), and logging.
     *
     * @param string $endpoint The API endpoint (e.g., 'contactdetails/').
     * @param string $method   HTTP method (e.g., 'POST', 'GET').
     * @param array  $body_data Data to send in the request body (for POST/PUT).
     * @param bool   $retry_on_failure Whether to attempt a token refresh and retry on 401.
     *
     * @return array|WP_Error Decoded JSON response array on success, WP_Error on failure.
     */
    public function make_request( $endpoint, $method = 'POST', $body_data = [], $retry_on_failure = true ) {
        $token = $this->get_valid_token();

        // If no valid token could be obtained (even after refresh attempt)
        if ( ! $token ) {
             $this->log( 'No valid API token available for request.', 'ERROR' );
             return new WP_Error( 'api_token_error', __( 'Could not obtain a valid API token. Check API Key and connectivity.', 'easyverein-membership-form' ) );
        }

        // Construct the full API URL
        $url = trailingslashit( EV_MEMBERSHIP_API_BASE_URL ) . ltrim( $endpoint, '/' );

        // Prepare request arguments for wp_remote_request
        $args = [
            'method' => strtoupper( $method ),
            'headers' => [
                'Authorization' => 'Bearer ' . $token, // Use Bearer token for general API requests
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30, // Standard timeout for API calls
            'redirection' => 0, // Don't follow redirects
        ];

        // Add JSON encoded body data if provided
        if ( ! empty( $body_data ) && ( $args['method'] === 'POST' || $args['method'] === 'PUT' || $args['method'] === 'PATCH' ) ) {
            $args['body'] = wp_json_encode( $body_data );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $this->log( 'JSON Encode Error for body data: ' . json_last_error_msg(), 'ERROR' );
                return new WP_Error('json_encode_error', __('Failed to encode request data.', 'easyverein-membership-form'));
            }
        } else {
             $args['body'] = null;
        }

        $this->log( "API Request - Endpoint: {$endpoint}, Method: {$method}" );
        // Avoid logging sensitive body data in production logs
        // $this->log( "API Request - Body: " . print_r( $body_data, true ) );

        // Perform the API request
        $response = wp_remote_request( $url, $args );

        // Handle WordPress level errors during the request
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            $this->log( "API Request Failed (WP_Error) - Endpoint: {$endpoint}, Error: " . $error_message, 'ERROR' );
            return $response; // Return the WP_Error object
        }

        // Process the response
        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $response_body, true ); // Decode JSON response

        $this->log( "API Response - Endpoint: {$endpoint}, Status: {$status_code}" );
        // Log only a snippet of the body to avoid large logs or sensitive data exposure
         $this->log( "API Response - Body Snippet: " . substr($response_body, 0, 300) );

        // Check for token expiry signal (HTTP 401 Unauthorized)
        if ( $status_code === 401 && $retry_on_failure ) {
            $this->log( "API Request received 401 - Attempting token refresh and retry for {$endpoint}." );
            // Force token refresh
            $refreshed_token = $this->refresh_token();
            if ( $refreshed_token ) {
                // Retry the original request *once* without allowing further retries
                $this->log( "Token refreshed successfully. Retrying original request for {$endpoint}." );
                return $this->make_request( $endpoint, $method, $body_data, false ); // Set retry_on_failure to false
            } else {
                $this->log( "Token refresh failed after receiving 401. Cannot retry request for {$endpoint}.", 'ERROR' );
                 return new WP_Error( 'token_refresh_failed', __( 'API token expired and could not be refreshed.', 'easyverein-membership-form' ) );
            }
        }

        // Check for other client or server errors (4xx, 5xx)
        if ( $status_code >= 400 ) {
             $error_message = __( 'API request failed.', 'easyverein-membership-form' );
             $error_details = '';
             // Try to extract a more specific error message from the API response body
             if ( is_array( $decoded_body ) ) {
                 // Common easyVerein error structure might have details in keys like 'detail', 'error', or field names
                 $possible_keys = ['detail', 'error', 'non_field_errors'];
                 foreach ($possible_keys as $key) {
                     if (isset($decoded_body[$key])) {
                         $error_details = is_array($decoded_body[$key]) ? implode(', ', $decoded_body[$key]) : $decoded_body[$key];
                         break;
                     }
                 }
                 // If no specific detail found, show the first value if it's a simple error string
                 if (empty($error_details) && count($decoded_body) === 1) {
                    $error_details = reset($decoded_body);
                 }
                 // Fallback to json encoded body if details are complex
                 if (empty($error_details)) {
                    $error_details = wp_json_encode($decoded_body);
                 }

             } elseif (!empty($response_body)) {
                 $error_details = $response_body; // Use raw body if not JSON
             }

             $error_message .= ' Status: ' . $status_code . '. Details: ' . $error_details;
             $this->log( "API Request Error - Endpoint: {$endpoint}, Status: {$status_code}, Body: {$response_body}", 'ERROR' );
             // Return WP_Error with details
             return new WP_Error( 'api_error', $error_message, ['status' => $status_code, 'body' => $decoded_body ?? $response_body] );
        }

        // Success (HTTP 2xx) - Return the decoded JSON response body
        // Handle cases where response might be 204 No Content (empty body)
        if ($status_code === 204) {
            return []; // Return empty array for consistency
        }

        if ( $decoded_body === null && !empty($response_body) ) {
             $this->log( "API Response Warning - Endpoint: {$endpoint}, Status: {$status_code}, Body is not valid JSON: {$response_body}", 'WARNING' );
             return new WP_Error('json_decode_error', __('API response was not valid JSON.', 'easyverein-membership-form'), ['status' => $status_code, 'body' => $response_body]);
        }
		
        $this->log( "Returning response {$decoded_body}");
        return $decoded_body;
    }

    // --- Methods for Specific API Calls ---

    /**
     * Creates contact details via the API.
     *
     * @param array $data Contact details data.
     * @return array|WP_Error API response or error object.
     */
    public function create_contact_details( $data ) {
        $this->log( "Attempting to create contact details." );
        $result = $this->make_request( 'contact-details/', 'POST', $data );
        $this->log( "create_contact_details : {$result}" );
        return $result;
    }

    /**
     * Creates a member application via the API.
     * Ensures the 'isApplication' flag is set.
     *
     * @param array $data Member application data (must include 'contactDetails' ID).
     * @return array|WP_Error API response or error object.
     */
    public function create_member_application( $data ) {
         $this->log( "Attempting to create member application." );
         // Ensure the isApplication flag is explicitly set to true for the application workflow
         $data['isApplication'] = true;
        return $this->make_request( 'member/', 'POST', $data );
    }
}