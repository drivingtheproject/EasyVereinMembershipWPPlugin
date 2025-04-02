<?php
/**
 * Function ev_membership_display_form
 *
 * Generates the HTML for the membership application form.
 * Triggered by the shortcode [easyverein_membership_form].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function ev_membership_display_form( $atts ) {
    // --- Preparations ---

    // Shortcode attributes (not used currently, but available for future extension)
    $atts = shortcode_atts( [], $atts, 'easyverein_membership_form' );

    // Get plugin options
    $options = get_option( 'ev_membership_options' );
    if ( empty( $options['api_key'] ) ) {
         return '<p style="color:red;">' . esc_html__( 'EasyVerein Plugin Error: API Key is not configured in settings.', 'easyverein-membership-form' ) . '</p>';
    }

    // Parse membership types from settings
    $membership_types_raw = isset( $options['membership_types'] ) ? $options['membership_types'] : '';
    $membership_types = []; // Format: [easyVerein_ID => Form Label]
    if ( ! empty( $membership_types_raw ) ) {
        $lines = explode( "\n", trim( $membership_types_raw ) );
        foreach ( $lines as $line ) {
            $parts = explode( '=', trim( $line ), 2 );
            if ( count( $parts ) === 2 && ! empty( trim( $parts[0] ) ) && ! empty( trim( $parts[1] ) ) ) {
                // Key = easyVerein ID, Value = Form Label
                $membership_id = trim( $parts[1] );
                $form_label = trim( $parts[0] );
                $membership_types[ $membership_id ] = $form_label;
            }
        }
    }
    if ( empty( $membership_types ) ) {
        return '<p style="color:red;">' . esc_html__( 'EasyVerein Plugin Error: Membership Types are not configured correctly in settings.', 'easyverein-membership-form' ) . '</p>';
    }

    // Check for status messages passed via query parameters after redirection
    $status = isset( $_GET['ev_status'] ) ? sanitize_key( $_GET['ev_status'] ) : '';
    $message = isset( $_GET['ev_message'] ) ? urldecode( sanitize_text_field( $_GET['ev_message'] ) ) : '';

    // --- Form HTML Generation ---
    ob_start(); // Start output buffering to capture HTML
    ?>
    <div class="easyverein-membership-form-wrapper">

        <?php
        // Display success or error messages if present
        if ( $status === 'success' && ! empty( $message ) ) : ?>
            <div class="ev-form-message ev-form-success"><?php echo esc_html( $message ); ?></div>
        <?php elseif ( $status === 'error' && ! empty( $message ) ) : ?>
             <div class="ev-form-message ev-form-error"><?php echo esc_html( $message ); ?></div>
         <?php elseif ( $status === 'validation' && ! empty( $message ) ) : ?>
             <div class="ev-form-message ev-form-error"><?php echo esc_html( __( 'Please correct the errors: ', 'easyverein-membership-form' ) . esc_html( $message ) ); ?></div>
        <?php endif; ?>

        <?php // Only show the form if not successful ?>
        <?php if ($status !== 'success'): ?>
        <form id="easyverein-membership-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post">

            <?php // Security: Add Nonce field ?>
            <?php wp_nonce_field( 'ev_submit_application_nonce_action', 'ev_nonce_field' ); ?>

            <?php // Hidden field to identify the action for admin-post.php ?>
            <input type="hidden" name="action" value="ev_submit_application">

            <h2><?php esc_html_e( 'Membership Application', 'easyverein-membership-form' ); ?></h2>
            <p><?php esc_html_e( 'Fields marked with * are required.', 'easyverein-membership-form' ); ?></p>

            <fieldset class="ev-fieldset ev-fieldset-personal">
                 <legend><?php esc_html_e( 'Personal Information', 'easyverein-membership-form' ); ?></legend>

                 <p class="ev-form-row">
                    <label for="ev_salutation"><?php esc_html_e( 'Salutation', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                    <select id="ev_salutation" name="ev_salutation" required aria-required="true">
                        <option value=""><?php esc_html_e( '-- Please Select --', 'easyverein-membership-form' ); ?></option>
                        <option value="Frau"><?php esc_html_e( 'Mrs.', 'easyverein-membership-form' ); ?></option>
                        <option value="Herr"><?php esc_html_e( 'Mr.', 'easyverein-membership-form' ); ?></option>
                         <option value="Divers"><?php esc_html_e( 'Diverse', 'easyverein-membership-form' ); ?></option>
                         <?php // Add other salutations if needed by easyVerein ?>
                    </select>
                </p>

                 <p class="ev-form-row">
                     <label for="ev_first_name"><?php esc_html_e( 'First Name', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_first_name" name="ev_first_name" required aria-required="true" autocomplete="given-name">
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_last_name"><?php esc_html_e( 'Last Name', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_last_name" name="ev_last_name" required aria-required="true" autocomplete="family-name">
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_email"><?php esc_html_e( 'Email Address', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="email" id="ev_email" name="ev_email" required aria-required="true" autocomplete="email">
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_street"><?php esc_html_e( 'Street', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_street" name="ev_street" required aria-required="true" autocomplete="address-line1">
                 </p>
                  <p class="ev-form-row">
                     <label for="ev_house_number"><?php esc_html_e( 'House Number', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_house_number" name="ev_house_number" required aria-required="true" autocomplete="address-line2"> <?php // Not perfect autocomplete, but closest standard ?>
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_zip_code"><?php esc_html_e( 'ZIP / Postal Code', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_zip_code" name="ev_zip_code" required aria-required="true" autocomplete="postal-code">
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_city"><?php esc_html_e( 'City', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <input type="text" id="ev_city" name="ev_city" required aria-required="true" autocomplete="address-level2">
                 </p>

                 <p class="ev-form-row">
                    <label for="ev_country"><?php esc_html_e( 'Country', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                    <?php // Consider using a library or a more extensive list for countries if needed ?>
                    <select id="ev_country" name="ev_country" required aria-required="true" autocomplete="country-name">
                        <option value="DE"><?php esc_html_e( 'Germany', 'easyverein-membership-form' ); ?></option>
                        <option value="AT"><?php esc_html_e( 'Austria', 'easyverein-membership-form' ); ?></option>
                        <option value="CH"><?php esc_html_e( 'Switzerland', 'easyverein-membership-form' ); ?></option>
                        <?php // Add more countries as needed, using ISO 3166-1 alpha-2 codes as values ?>
                    </select>
                </p>

                 <p class="ev-form-row">
                     <label for="ev_phone"><?php esc_html_e( 'Phone (Optional)', 'easyverein-membership-form' ); ?></label>
                     <input type="tel" id="ev_phone" name="ev_phone" autocomplete="tel">
                 </p>

                 <p class="ev-form-row">
                     <label for="ev_birthdate"><?php esc_html_e( 'Date of Birth (Optional, Format: YYYY-MM-DD)', 'easyverein-membership-form' ); ?></label>
                     <input type="date" id="ev_birthdate" name="ev_birthdate" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD" autocomplete="bday">
                 </p>
            </fieldset>

             <fieldset class="ev-fieldset ev-fieldset-membership">
                 <legend><?php esc_html_e( 'Membership Details', 'easyverein-membership-form' ); ?></legend>
                 <p class="ev-form-row">
                    <label for="ev_membership_type"><?php esc_html_e( 'Choose Membership Type', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                    <select id="ev_membership_type" name="ev_membership_type" required aria-required="true">
                        <option value=""><?php esc_html_e( '-- Please Select --', 'easyverein-membership-form' ); ?></option>
                        <?php foreach ( $membership_types as $id => $label ) : ?>
                            <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </fieldset>

            <fieldset class="ev-fieldset ev-fieldset-payment">
                 <legend><?php esc_html_e( 'Payment Information (SEPA Direct Debit)', 'easyverein-membership-form' ); ?></legend>
                 <p class="ev-form-row">
                     <label for="ev_iban"><?php esc_html_e( 'IBAN', 'easyverein-membership-form' ); ?> <span class="required">*</span></label>
                     <?php // Basic pattern - more complex validation often done server-side or with JS library ?>
                     <input type="text" id="ev_iban" name="ev_iban" required aria-required="true" pattern="[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}" title="<?php esc_attr_e( 'Please enter a valid IBAN (e.g., DE89 3704 0044 0532 0130 00)', 'easyverein-membership-form' ); ?>" placeholder="DE89...">
                 </p>
                 <p class="ev-form-row">
                     <label for="ev_account_holder"><?php esc_html_e( 'Account Holder Name (if different from applicant)', 'easyverein-membership-form' ); ?></label>
                     <input type="text" id="ev_account_holder" name="ev_account_holder" >
                      <small><?php esc_html_e( 'Leave blank if the account holder is the same as the applicant.', 'easyverein-membership-form' ); ?></small>
                 </p>

                <p class="ev-form-row ev-form-checkbox">
                    <input type="checkbox" id="ev_sepa_agreement" name="ev_sepa_agreement" value="1" required aria-required="true">
                    <label for="ev_sepa_agreement">
                        <?php
                        // IMPORTANT: Replace [Your Association Name] and potentially adjust the wording!
                        printf(
                            esc_html__( 'I hereby authorize %s to collect payments from my account via direct debit. Concurrently, I instruct my bank to honour direct debits drawn by %s on my account. Note: I can demand reimbursement of the debited amount within eight weeks, beginning with the debit date. The conditions agreed upon with my bank apply.', 'easyverein-membership-form' ),
                            '<strong>[Your Association Name]</strong>', // Replace!
                            '<strong>[Your Association Name]</strong>'  // Replace!
                        );
                        ?> <span class="required">*</span>
                    </label>
                </p>

            </fieldset>

             <fieldset class="ev-fieldset ev-fieldset-agreements">
                 <legend><?php esc_html_e( 'Agreements', 'easyverein-membership-form' ); ?></legend>
                  <p class="ev-form-row ev-form-checkbox">
                    <input type="checkbox" id="ev_privacy_agreement" name="ev_privacy_agreement" value="1" required aria-required="true">
                     <label for="ev_privacy_agreement">
                        <?php
                        // Ensure a privacy policy page is set in WP Settings -> Privacy
                        $privacy_policy_url = get_privacy_policy_url();
                        if ( $privacy_policy_url ) {
                            printf(
                                wp_kses_post( __( 'I have read the <a href="%s" target="_blank" rel="noopener noreferrer">privacy policy</a> and agree to the processing of my data for the purpose of membership administration.', 'easyverein-membership-form' ) ),
                                esc_url( $privacy_policy_url )
                            );
                        } else {
                            esc_html_e( 'I agree to the processing of my data for the purpose of membership administration (Privacy Policy link missing - please configure in WP Settings -> Privacy).', 'easyverein-membership-form' );
                        }
                        ?> <span class="required">*</span>
                    </label>
                 </p>
                 <?php // Add other agreement checkboxes if needed (e.g., statutes) ?>
            </fieldset>

            <p class="ev-form-submit">
                <button type="submit" class="button button-primary ev-submit-button"><?php esc_html_e( 'Submit Application', 'easyverein-membership-form' ); ?></button>
            </p>

        </form>
        <?php endif; // End conditional form display ?>
    </div><?php
    return ob_get_clean(); // Return the buffered HTML content
}