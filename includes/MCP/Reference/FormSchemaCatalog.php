<?php
/**
 * FormSchemaCatalog reference data.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Reference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormSchemaCatalog class.
 *
 * Provides the static reference array for form schema.
 */
final class FormSchemaCatalog {

	/**
	 * Return the reference data array.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$data = array(
			'description'              => 'Bricks form element settings reference. Forms are standard elements (name: "form") added via element:add or page:update_content.',
			'field_types'              => array(
				'text'       => array(
					'description' => 'Single-line text input',
					'properties'  => array( 'placeholder', 'required', 'minLength', 'maxLength', 'pattern', 'width' ),
				),
				'email'      => array(
					'description' => 'Email input with validation',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'textarea'   => array(
					'description' => 'Multi-line text',
					'properties'  => array( 'placeholder', 'required', 'height', 'width' ),
				),
				'richtext'   => array(
					'description' => 'TinyMCE rich text editor (since 2.1)',
					'properties'  => array( 'height', 'width' ),
				),
				'tel'        => array(
					'description' => 'Telephone input',
					'properties'  => array( 'placeholder', 'pattern', 'width' ),
				),
				'number'     => array(
					'description' => 'Numeric input',
					'properties'  => array( 'min', 'max', 'step', 'width' ),
				),
				'url'        => array(
					'description' => 'URL input',
					'properties'  => array( 'placeholder', 'width' ),
				),
				'password'   => array(
					'description' => 'Password with optional toggle',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'select'     => array(
					'description' => 'Dropdown select',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'checkbox'   => array(
					'description' => 'Checkbox group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'radio'      => array(
					'description' => 'Radio button group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'file'       => array(
					'description' => 'File upload',
					'properties'  => array( 'fileUploadLimit', 'fileUploadSize', 'fileUploadAllowedTypes', 'fileUploadStorage', 'width' ),
				),
				'datepicker' => array(
					'description' => 'Date/time picker (Flatpickr)',
					'properties'  => array( 'time (bool)', 'l10n (language code)', 'width' ),
				),
				'image'      => array(
					'description' => 'Image picker (since 2.1)',
					'properties'  => array( 'width' ),
				),
				'gallery'    => array(
					'description' => 'Gallery picker',
					'properties'  => array( 'width' ),
				),
				'hidden'     => array(
					'description' => 'Hidden field',
					'properties'  => array( 'value' ),
				),
				'html'       => array(
					'description' => 'Static HTML output (not an input)',
					'properties'  => array(),
				),
				'rememberme' => array(
					'description' => 'Remember me checkbox (for login forms)',
					'properties'  => array(),
				),
			),
			'field_required_properties' => array(
				'id'   => '6-char lowercase alphanumeric (e.g. abc123) — REQUIRED on every field',
				'type' => 'One of the field types listed above — REQUIRED',
			),
			'field_common_properties'  => array(
				'label'        => 'string — displayed above the field',
				'placeholder'  => 'string — hint text inside the field',
				'value'        => 'string — default value',
				'required'     => 'bool — marks field as required',
				'width'        => 'number (0-100) — column width as percentage (100 = full width)',
				'name'         => 'string — custom name attribute (defaults to form-field-{id})',
				'errorMessage' => 'string — custom validation error message',
				'isHoneypot'   => 'bool — invisible spam trap (always available, no API key needed)',
			),
			'actions'                  => array(
				'email'        => array(
					'description'   => 'Send email notification',
					'required_keys' => array( 'emailSubject', 'emailTo' ),
					'optional_keys' => array( 'emailToCustom (when emailTo=custom)', 'emailBcc', 'fromEmail', 'fromName', 'replyToEmail', 'emailContent (use {{field_id}} or {{all_fields}})', 'htmlEmail (bool, default true)', 'emailErrorMessage' ),
					'confirmation'  => 'For confirmation email to submitter: confirmationEmailSubject, confirmationEmailContent, confirmationEmailTo',
				),
				'redirect'     => array(
					'description'   => 'Redirect after submission (always runs LAST regardless of position in actions array)',
					'required_keys' => array( 'redirect (URL)' ),
					'optional_keys' => array( 'redirectTimeout (ms delay)' ),
				),
				'webhook'      => array(
					'description'    => 'POST data to external URL (since 2.0)',
					'required_keys'  => array( 'webhooks (array of objects)' ),
					'webhook_object' => array(
						'name'         => 'string — endpoint label',
						'url'          => 'string — endpoint URL',
						'contentType'  => 'json or form-data (default: json)',
						'dataTemplate' => 'string — JSON template with {{field_id}} placeholders; empty sends all fields',
						'headers'      => 'string — JSON headers e.g. {"Authorization": "Bearer token"}',
					),
					'optional_keys'  => array( 'webhookMaxSize (KB, default 1024)', 'webhookErrorIgnore (bool)' ),
				),
				'login'        => array(
					'description'   => 'User login',
					'required_keys' => array( 'loginName (field ID for username/email)', 'loginPassword (field ID for password)' ),
					'optional_keys' => array( 'loginRemember (field ID for remember me)', 'loginErrorMessage' ),
				),
				'registration' => array(
					'description'   => 'User registration',
					'required_keys' => array( 'registrationEmail (field ID)', 'registrationPassword (field ID)' ),
					'optional_keys' => array( 'registrationUserName (field ID)', 'registrationFirstName (field ID)', 'registrationLastName (field ID)', 'registrationRole (slug, NEVER administrator)', 'registrationAutoLogin (bool)', 'registrationPasswordMinLength (default 6)', 'registrationWPNotification (bool)' ),
				),
				'create-post'  => array(
					'description'   => 'Create a WordPress post from form data (since 2.1)',
					'required_keys' => array( 'createPostType (post type slug)', 'createPostTitle (field ID)' ),
					'optional_keys' => array( 'createPostContent (field ID)', 'createPostExcerpt (field ID)', 'createPostFeaturedImage (field ID)', 'createPostStatus (draft/publish)', 'createPostMeta (repeater: metaKey, metaValue, sanitizationMethod)', 'createPostTaxonomies (repeater: taxonomy, fieldId)' ),
				),
				'custom'       => array(
					'description'   => 'Custom action via bricks/form/custom_action hook',
					'required_keys' => array(),
				),
			),
			'general_settings'         => array(
				'successMessage'            => 'string — shown after successful submit',
				'submitButtonText'          => 'string — button text (default: Send)',
				'requiredAsterisk'          => 'bool — show asterisk on required fields',
				'showLabels'                => 'bool — show field labels',
				'enableRecaptcha'           => 'bool — Google reCAPTCHA v3 (needs API key in Bricks settings)',
				'enableHCaptcha'            => 'bool — hCaptcha (needs API key in Bricks settings)',
				'enableTurnstile'           => 'bool — Cloudflare Turnstile (needs API key in Bricks settings)',
				'disableBrowserValidation'  => 'bool — add novalidate attribute',
				'validateAllFieldsOnSubmit' => 'bool — show all errors on submit, not just first',
			),
			'examples'                 => array(
				'contact_form'      => array(
					'fields'           => array(
						array(
							'id'          => 'abc123',
							'type'        => 'text',
							'label'       => 'Name',
							'placeholder' => 'Your Name',
							'width'       => 100,
						),
						array(
							'id'          => 'def456',
							'type'        => 'email',
							'label'       => 'Email',
							'placeholder' => 'you@example.com',
							'required'    => true,
							'width'       => 100,
						),
						array(
							'id'          => 'ghi789',
							'type'        => 'textarea',
							'label'       => 'Message',
							'placeholder' => 'Your Message',
							'required'    => true,
							'width'       => 100,
						),
					),
					'actions'          => array( 'email' ),
					'emailSubject'     => 'Contact form request',
					'emailTo'          => 'admin_email',
					'htmlEmail'        => true,
					'successMessage'   => 'Thank you! We will get back to you soon.',
					'submitButtonText' => 'Send Message',
				),
				'login_form'        => array(
					'fields'           => array(
						array(
							'id'       => 'lgn001',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'lgn002',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'    => 'lgn003',
							'type'  => 'rememberme',
							'label' => 'Remember Me',
						),
					),
					'actions'          => array( 'login', 'redirect' ),
					'loginName'        => 'lgn001',
					'loginPassword'    => 'lgn002',
					'loginRemember'    => 'lgn003',
					'redirect'         => '/account',
					'submitButtonText' => 'Log In',
				),
				'registration_form' => array(
					'fields'                => array(
						array(
							'id'       => 'reg001',
							'type'     => 'text',
							'label'    => 'Username',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg002',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg003',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
					),
					'actions'               => array( 'registration', 'redirect' ),
					'registrationUserName'  => 'reg001',
					'registrationEmail'     => 'reg002',
					'registrationPassword'  => 'reg003',
					'registrationRole'      => 'subscriber',
					'registrationAutoLogin' => true,
					'redirect'              => '/welcome',
					'successMessage'        => 'Registration successful!',
					'submitButtonText'      => 'Create Account',
				),
			),
			'notes'                    => array(
				'Field IDs must be 6-char lowercase alphanumeric (same format as element IDs). Bricks uses form-field-{id} as the submission key.',
				'Options for select/checkbox/radio use newline-separated strings: "Option 1\nOption 2\nOption 3" — NOT arrays.',
				'Redirect action always runs last regardless of position in the actions array.',
				'CAPTCHA (reCAPTCHA, hCaptcha, Turnstile) requires API keys configured in Bricks > Settings > API Keys. Honeypot (isHoneypot: true) works without any configuration.',
				'Never set registrationRole to "administrator" — Bricks blocks this for security.',
				'Use {{field_id}} in emailContent/dataTemplate to reference field values. Use {{all_fields}} to include all fields.',
			),
		);

		return apply_filters( 'bricks_mcp_form_schema', $data );
	}
}
