<?php
/**
 * Function ev_membership_process_form
 *
 * Handles the processing of the submitted membership form data.
 * Hooked to admin_post_ev_submit_application and admin_post_nopriv_ev_submit_application.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function ev_membership_process_form() {

    // --- 1. Security Check: Verify Nonce ---
    if ( ! isset( $_POST['ev_nonce_field'] ) || ! wp_verify_nonce( $_POST['ev_nonce_field'], 'ev_submit_application_nonce_action' ) ) {
        // Nonce is invalid or missing - likely a security issue or form expired
        wp_die( esc_html__( 'Security check failed. Please go back, refresh the page, and try submitting the form again.', 'easyverein-membership-form' ), 'Security Check Failed', ['response' => 403] );
    }

    // --- 2. Input Sanitization and Validation ---
    $required_fields = [
        'ev_salutation', 'ev_first_name', 'ev_last_name', 'ev_email', 'ev_street',
        'ev_house_number', 'ev_zip_code', 'ev_city', 'ev_country', 'ev_membership_type',
        'ev_iban', 'ev_sepa_agreement', 'ev_privacy_agreement'
    ];
    $form_data = []; // Store sanitized data
    $validation_errors = []; // Store validation error messages

    // Loop through POST data, sanitize fields starting with 'ev_'
    foreach ( $_POST as $key => $value ) {
        if ( strpos( $key, 'ev_' ) === 0 ) {
            $field_name = str_replace('ev_', '', $key); // Get readable field name
            $sanitized_value = '';

            // Apply specific sanitization based on field type
            switch ($key) {
                case 'ev_email':
                    $sanitized_value = sanitize_email( $value );
                    if ( ! empty( $sanitized_value ) && ! is_email( $sanitized_value ) ) {
                        $validation_errors[] = __( 'Please enter a valid email address.', 'easyverein-membership-form' );
                    }
                    break;
                case 'ev_iban':
                    // Remove spaces and convert to uppercase
                    $sanitized_value = sanitize_text_field( strtoupper( str_replace( ' ', '', $value ) ) );
                    // Basic IBAN format check (can be enhanced with checksum validation if needed)
                    if ( ! empty( $sanitized_value ) && ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $sanitized_value ) ) {
                         $validation_errors[] = __( 'Please enter a valid IBAN format.', 'easyverein-membership-form' );
                    }
                    break;
                case 'ev_sepa_agreement':
                case 'ev_privacy_agreement':
                    // Checkbox values should be '1' if checked
                    $sanitized_value = ( isset( $_POST[$key] ) && $_POST[$key] === '1' ) ? true : false;
                    if ( $sanitized_value === false && in_array( $key, $required_fields ) ) {
                         // Use a more descriptive error based on the field name
                         $agreement_name = ($key === 'ev_sepa_agreement') ? __('SEPA Mandate Agreement', 'easyverein-membership-form') : __('Privacy Policy Agreement', 'easyverein-membership-form');
                         $validation_errors[] = sprintf( __( 'You must agree to the %s.', 'easyverein-membership-form' ), $agreement_name );
                    }
                    break;
                case 'ev_birthdate':
                    $sanitized_value = sanitize_text_field( $value );
                    // Optional: Validate date format YYYY-MM-DD if not empty
                    if ( ! empty( $sanitized_value ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sanitized_value ) ) {
                        $validation_errors[] = __( 'Invalid date format for Date of Birth (must be YYYY-MM-DD).', 'easyverein-membership-form' );
                    } elseif ( empty( $sanitized_value ) ) {
                        $sanitized_value = null; // Ensure empty optional date is null for API
                    }
                    break;
                case 'ev_phone':
                    // Basic sanitization for phone, allows digits, spaces, +, -, ()
                    $sanitized_value = sanitize_text_field( preg_replace('/[^-+() \d]/', '', $value) );
                     if ( empty( $sanitized_value ) ) {
                        $sanitized_value = null; // Ensure empty optional phone is null for API
                    }
                    break;
                default:
                    // Default sanitization for text fields
                    $sanitized_value = sanitize_text_field( $value );
                    break;
            }

            $form_data[$key] = $sanitized_value;

            // Check if required fields are empty (after sanitization)
            // Skip checkbox validation here as it's handled above
            if ( in_array( $key, $required_fields ) && empty( $sanitized_value ) && !is_bool($sanitized_value) ) {
                 // Generate a user-friendly field name
                 $friendly_field_name = ucwords( str_replace( '_', ' ', $field_name ) );
                 $validation_errors[] = sprintf( __( 'The field "%s" is required.', 'easyverein-membership-form' ), $friendly_field_name );
            }
        }
    }

    // --- 3. Handle Validation Errors ---
    $options = get_option( 'ev_membership_options' ); // Get options again for redirect URLs
    $error_page_id = isset( $options['error_page_id'] ) ? $options['error_page_id'] : 0;
    $error_page_url = $error_page_id ? get_permalink( $error_page_id ) : '';
    // Fallback: Redirect back to the referring page if no specific error page is set
    $referer_url = wp_get_referer();
    $redirect_url_on_error = ! empty( $error_page_url ) ? $error_page_url : ( $referer_url ? $referer_url : home_url() );

    if ( ! empty( $validation_errors ) ) {
        // Remove duplicates and combine error messages
        $error_message = implode( ' ', array_unique( $validation_errors ) );
        // Redirect back to the form page with error status and message
        $redirect_url = add_query_arg( [
            'ev_status' => 'validation',
            'ev_message' => urlencode( $error_message )
        ], $redirect_url_on_error ); // Redirect back to referrer or error page

        wp_safe_redirect( $redirect_url );
        exit;
    }

    // --- 4. Prepare Data for EasyVerein API ---

    // --- 4.a. Contact Details Payload ---
    $contact_payload = [
        // Map form fields to easyVerein contact details fields
        // Ensure keys match the API documentation exactly
        'salutation' => $form_data['ev_salutation'] ?? null,
        'firstName' => $form_data['ev_first_name'] ?? null,
        'familyName' => $form_data['ev_last_name'] ?? null,
        'address' => trim( ( $form_data['ev_street'] ?? '' ) . ' ' . ( $form_data['ev_house_number'] ?? '' ) ),
        // 'addressExtra' => ???, // Add if you collect address line 2/extra info
        'zip' => $form_data['ev_zip_code'] ?? null,
        'city' => $form_data['ev_city'] ?? null,
        'country' => $form_data['ev_country'] ?? 'DE', // Default to Germany if needed
        'email' => $form_data['ev_email'] ?? null,
        'privatePhone' => $form_data['ev_phone'], // Already sanitized to null if empty
        'dateOfBirth' => $form_data['ev_birthdate'], // Already sanitized to null if empty, format YYYY-MM-DD
        // Add any other custom fields you have mapped in easyVerein and collect in the form
        // 'customFields' => [ 'your_custom_field_api_name' => $form_data['ev_your_custom_field'] ],
    ];

    // --- 5. Call API: Create Contact Details ---
    $api_handler = EV_API_Handler::get_instance(); // Get singleton instance
    $contact_response = $api_handler->create_contact_details( $contact_payload );

    // Handle errors during contact creation
    if ( is_wp_error( $contact_response ) ) {
        $error_code = $contact_response->get_error_code();
        $error_message = $contact_response->get_error_message();
        $api_handler->log( "API Error Creating Contact: [$error_code] $error_message", 'ERROR' );

        // Prepare user-friendly message
        $user_message = sprintf( __( 'Error submitting contact details: %s', 'easyverein-membership-form' ), $error_message );
        $redirect_url = add_query_arg( ['ev_status' => 'error', 'ev_message' => urlencode( $user_message )], $redirect_url_on_error );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    // Validate response format (expecting an array with an 'id')
    if ( ! is_array( $contact_response ) || ! isset( $contact_response['id'] ) ) {
        $api_handler->log( "API Error: Unexpected response format after creating contact details: " . print_r( $contact_response, true ), 'ERROR' );
        $user_message = __( 'An unexpected error occurred after submitting contact details (Code: C1). Please contact support.', 'easyverein-membership-form' );
        $redirect_url = add_query_arg( ['ev_status' => 'error', 'ev_message' => urlencode( $user_message )], $redirect_url_on_error );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    $contact_details_id = $contact_response['id']; // Get the ID of the newly created contact
    $api_handler->log( "Contact details created successfully. ID: " . $contact_details_id );

    // --- 6. Prepare Data for Member Application ---
    // *** CHANGE: Generate ISO 8601 DateTime in UTC for API fields requiring DateTime ***
    $current_iso_datetime_utc = gmdate('Y-m-d\TH:i:s\Z'); // Format: YYYY-MM-DDTHH:MM:SSZ

    // Generate a unique SEPA mandate reference (Example: Prefix-Timestamp-UserID/Hash)
    // Adjust this logic if easyVerein requires a specific format
    $sepa_mandate_ref = 'WPAPP-' . time() . '-' . substr( md5( $form_data['ev_email'] ), 0, 6 );

    $member_payload = [
        // Link to the previously created contact details
        'contactDetails' => $contact_details_id,
        // Core membership application data
        // *** CHANGE: Use ISO DateTime format ***
        'joinDate' => $current_iso_datetime_utc,
        'membershipNumber' => $form_data['ev_membership_type'] ?? null, // The ID selected in the form
        '_isApplication' => true, // *** CRUCIAL: This marks it as an application ***
         // *** CHANGE: Use ISO DateTime format ***
        'declarationOfApplication' => $current_iso_datetime_utc,

        // SEPA Direct Debit Information
        'useSepa' => true, // Assume SEPA is used if IBAN is provided and agreed
        'sepaIban' => $form_data['ev_iban'] ?? null,
        // Use provided account holder, fallback to applicant's name
        'sepaAccountOwner' => ! empty( $form_data['ev_account_holder'] ) ? $form_data['ev_account_holder'] : ( $form_data['ev_first_name'] . ' ' . $form_data['ev_last_name'] ),
        'sepaMandateReference' => $sepa_mandate_ref,
        // *** CHANGE: Use Date format (YYYY-MM-DD) for sepaMandateDate as per typical SEPA requirements ***
        'sepaMandateDate' => current_time( 'Y-m-d' ), // Date the SEPA mandate was granted (submission date)
		'emailOrUserName' => $form_data['ev_email'],

        // Add any other relevant member fields or custom fields if needed
        // 'resignationDate' => null,
        'memberGroups' => [ $form_data['ev_membership_type'] ],
        // 'customFields' => [ 'member_custom_field' => $value ],
    ];

    // --- 7. Call API: Create Member Application ---
    $member_response = $api_handler->create_member_application( $member_payload );

    // Handle errors during member application creation
    if ( is_wp_error( $member_response ) ) {
        $error_code = $member_response->get_error_code();
        $error_message = $member_response->get_error_message();
        // Log the payload that caused the error for easier debugging
        $api_handler->log( "API Error Creating Member Application for Contact ID {$contact_details_id}: [$error_code] $error_message. Payload: " . print_r($member_payload, true), 'ERROR' );


        // IMPORTANT CONSIDERATION: If member creation fails, the contact still exists.
        // You *could* try to delete the contact here, but that adds complexity and potential race conditions.
        // For now, log the error and inform the user. Manual cleanup might be needed in easyVerein.

        $user_message = sprintf( __( 'Error submitting membership application details: %s', 'easyverein-membership-form' ), $error_message );
        $redirect_url = add_query_arg( ['ev_status' => 'error', 'ev_message' => urlencode( $user_message )], $redirect_url_on_error );
        wp_safe_redirect( $redirect_url );
        exit;
    }

     // Validate member response format
    if ( ! is_array( $member_response ) || ! isset( $member_response['id'] ) ) {
         $api_handler->log( "API Error: Unexpected response format after creating member application for Contact ID {$contact_details_id}: " . print_r( $member_response, true ), 'ERROR' );
         $user_message = __( 'An unexpected error occurred after submitting the application (Code: M1). Please contact support.', 'easyverein-membership-form' );
         $redirect_url = add_query_arg( ['ev_status' => 'error', 'ev_message' => urlencode( $user_message )], $redirect_url_on_error );
         wp_safe_redirect( $redirect_url );
         exit;
    }

    $member_id = $member_response['id'];
    $api_handler->log( "Member application created successfully for Contact ID {$contact_details_id}. Member ID: {$member_id}. Response: " . print_r( $member_response, true ) );

    // --- 8. Redirect to Success Page ---
    $success_page_id = isset( $options['success_page_id'] ) ? $options['success_page_id'] : 0;
    $success_page_url = $success_page_id ? get_permalink( $success_page_id ) : home_url();
    $success_message = __( 'Thank you! Your membership application has been received successfully.', 'easyverein-membership-form' );

    $redirect_url = add_query_arg( [
        'ev_status' => 'success',
        'ev_message' => urlencode( $success_message )
    ], $success_page_url );

    wp_safe_redirect( $redirect_url );
    exit;
}