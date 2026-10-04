<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use InvalidArgumentException;
use WP_Media_Helper\MediaSource\FileDates;
use WP_Media_Helper\MediaSource\NamePattern;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Index\DayIndex;
use WP_Media_Helper\Maintenance\Reset;
use WP_Media_Helper\Settings\AllowedBase;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\BuiltInExclusions;
use WP_Media_Helper\Settings\GeneralSettings;
use WP_Media_Helper\Settings\NamePatternPresets;
use WP_Media_Helper\Settings\SourceOwnership;
use WP_Media_Helper\Settings\SourceState;
use WP_Media_Helper\Settings\UploadsSource;
use WP_Media_Helper\Thumbnails\ThumbnailCache;

class ExternalSourceSettingsPage {

	private string $hookSuffix = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
		add_action( 'admin_post_wp_media_helper_save_external_sources', [ $this, 'save' ] );
		add_action( 'admin_post_wp_media_helper_rescan', [ $this, 'rescan' ] );
		add_action( 'admin_post_wp_media_helper_reset', [ $this, 'reset' ] );
		add_action( 'admin_post_wp_media_helper_use_uploads', [ $this, 'useUploads' ] );
		add_action( 'wp_ajax_wp_media_helper_test_name_pattern', [ $this, 'testNamePattern' ] );
	}

	public function register(): void {
		$this->hookSuffix = (string) add_options_page(
			__( 'WP Media Helper', 'wp-media-helper' ),
			__( 'WP Media Helper', 'wp-media-helper' ),
			'manage_options',
			'wp-media-helper',
			[ $this, 'render' ]
		);
	}

	public function enqueueAssets( string $hookSuffix ): void {
		if ( '' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix ) {
			return;
		}

		$handle = 'wp-media-helper-external-sources';

		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/js/external-source-settings.js', WP_MEDIA_HELPER_FILE ),
			[ 'wp-i18n' ],
			WP_MEDIA_HELPER_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'wpMediaHelperSettings',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_media_helper_test_name_pattern' ),
			]
		);

		wp_set_script_translations(
			$handle,
			'wp-media-helper',
			plugin_dir_path( WP_MEDIA_HELPER_FILE ) . 'languages'
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = $this->consumePendingSubmission();
		$sources = null === $pending ? $this->loadSources() : $pending['sources'];
		$maxEntries = null !== $pending && null !== $pending['max_entries'] ? $pending['max_entries'] : (string) $this->loadGeneralSettings()->getMaxEntries();
		// Stored sources outside the allowed base are reported as a warning instead.
		$allowedBase = AllowedBase::resolve();
		$enforceBase = null !== $pending;
		$validationErrors = $this->getValidationErrors( $sources, $enforceBase );
		$notices = $this->buildErrorNotices( $sources, $enforceBase );
		$disabledSources = null === $pending ? $this->findDisabledSourceNames( $sources ) : [];
		$shadowed = SourceOwnership::shadowed( $sources );
		$exposedUrls = [];
		foreach ( $sources as $source ) {
			foreach ( [ 'root' ] as $field ) {
				$url = is_array( $source ) && '' !== trim( (string) ( $source[ $field ] ?? '' ) ) ? $this->publicUrlFor( (string) $source[ $field ] ) : null;
				if ( null !== $url ) {
					$exposedUrls[] = $url;
				}
			}
		}

		if ( null !== $pending && '' !== $pending['message'] ) {
			array_unshift( $notices, $pending['message'] );
		}
		?>
		<div class="wrap wp-media-helper-settings">
			<h1><?php echo esc_html( get_admin_page_title() ?: __( 'WP Media Helper', 'wp-media-helper' ) ); ?></h1>

			<?php if ( [] !== $notices ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Your changes were not saved.', 'wp-media-helper' ); ?></strong> <?php esc_html_e( 'Please fix the following and try again:', 'wp-media-helper' ); ?></p>
					<ul class="ul-disc">
						<?php foreach ( $notices as $notice ) : ?>
							<li><?php echo esc_html( $notice ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php elseif ( isset( $_GET['rescan'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'A full scan of the source has been requested. It runs in the background.', 'wp-media-helper' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'External media sources saved.', 'wp-media-helper' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( [] !== $disabledSources ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Some external sources are disabled.', 'wp-media-helper' ); ?></strong>
						<?php
						printf(
							/* translators: %s: allowed base directory path. */
							esc_html__( 'Their root directory is not inside %s. Move them there, or ask the site owner to change the allowed base directory.', 'wp-media-helper' ),
							'<code>' . esc_html( (string) AllowedBase::resolve() ) . '</code>'
						);
						?>
					</p>
					<ul class="ul-disc">
						<?php foreach ( $disabledSources as $disabledName ) : ?>
							<li><?php echo esc_html( $disabledName ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php $uploadsDirectory = $this->uploadsDirectory(); ?>
			<?php if ( null === $pending && null !== $uploadsDirectory && ! UploadsSource::isConfigured( $sources, $uploadsDirectory ) ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'Quick start: use the uploads directory.', 'wp-media-helper' ); ?></strong>
						<?php esc_html_e( 'This adds one source on the WordPress uploads directory, so files added there by FTP, Nextcloud, a camera uploader or another plugin can be listed and imported. Files already in the Media Library show as imported.', 'wp-media-helper' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'The uploads directory may also hold private or technical files. These directories are never listed:', 'wp-media-helper' ); ?>
						<code><?php echo esc_html( implode( ', ', BuiltInExclusions::names() ) ); ?></code>
						<?php esc_html_e( 'The list is a default, not a guarantee: if you keep private files elsewhere in uploads, do not use this, and add a dedicated directory as a source instead.', 'wp-media-helper' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wp_media_helper_use_uploads" />
						<?php wp_nonce_field( 'wp_media_helper_use_uploads' ); ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Use the uploads directory', 'wp-media-helper' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( [] !== $shadowed ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Some sources can never list a file.', 'wp-media-helper' ); ?></strong>
						<?php esc_html_e( 'Their directory is inside the directory of an earlier source, which owns it. Move them before that source, or remove them.', 'wp-media-helper' ); ?>
					</p>
					<ul class="ul-disc">
						<?php foreach ( $shadowed as $shadowIndex => $ownerName ) : ?>
							<li>
								<?php
								printf(
									/* translators: 1: name of the source that never lists a file, 2: name of the earlier source that owns its directory. */
									esc_html__( '%1$s (owned by %2$s)', 'wp-media-helper' ),
									esc_html( (string) ( $sources[ $shadowIndex ]['name'] ?? '' ) ),
									esc_html( $ownerName )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( [] !== $exposedUrls ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'These directories are inside the uploads directory.', 'wp-media-helper' ); ?></strong>
						<?php esc_html_e( 'Web servers usually serve that directory publicly, so anyone who knows or guesses a file URL can download it, and a script file added to these directories could be executed. Block HTTP access to these URLs if the files are private, and disable script execution there, in your web server configuration (see the plugin README).', 'wp-media-helper' ); ?>
					</p>
					<ul class="ul-disc">
						<?php foreach ( array_unique( $exposedUrls ) as $exposedUrl ) : ?>
							<li><code><?php echo esc_html( $exposedUrl ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_media_helper_save_external_sources" />
				<?php wp_nonce_field( 'wp_media_helper_save_external_sources' ); ?>

				<h2><?php esc_html_e( 'General', 'wp-media-helper' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wp-media-helper-max-entries"><?php esc_html_e( 'Maximum entries per page', 'wp-media-helper' ); ?></label></th>
							<td>
								<input id="wp-media-helper-max-entries" type="number" class="small-text" name="general[max_entries]" value="<?php echo esc_attr( $maxEntries ); ?>" min="<?php echo esc_attr( (string) GeneralSettings::MIN_MAX_ENTRIES ); ?>" max="<?php echo esc_attr( (string) GeneralSettings::MAX_MAX_ENTRIES ); ?>" step="1" aria-describedby="wp-media-helper-max-entries-description" />
								<p class="description" id="wp-media-helper-max-entries-description">
									<?php
									printf(
										/* translators: 1: minimum value, 2: maximum value, 3: default value. */
										esc_html__( 'Number of media items shown per page in the editor panel, and the maximum number of items processed by a single bulk action. Between %1$d and %2$d, default %3$d.', 'wp-media-helper' ),
										GeneralSettings::MIN_MAX_ENTRIES,
										GeneralSettings::MAX_MAX_ENTRIES,
										GeneralSettings::DEFAULT_MAX_ENTRIES
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'External media sources', 'wp-media-helper' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Add one or more directories that should be included in the external media workflow. The order matters: a file belongs to the first source whose directory contains it, so put the most specific directories first (for example uploads/photos before uploads). The thumbnail cache directory is never listed.', 'wp-media-helper' ); ?></p>
				<p class="description"><?php esc_html_e( 'Active: its files are listed and can be imported. Disabled: ignored as if it did not exist, its files are handled by the sources that follow. Excluded: not listed, and no other source lists its files either.', 'wp-media-helper' ); ?></p>

				<p id="wp-media-helper-no-source" class="wp-media-helper-empty-state"<?php echo [] === $sources ? '' : ' hidden'; ?>>
					<?php esc_html_e( 'No external source is configured. The plugin uses the WordPress media library only.', 'wp-media-helper' ); ?>
				</p>

				<div id="wp-media-helper-sources" class="wp-media-helper-source-list">
					<?php foreach ( $sources as $index => $source ) : ?>
						<?php $this->renderSource( $index, $source, $validationErrors[ $index ] ?? [], $allowedBase, null === $pending ); ?>
					<?php endforeach; ?>
				</div>

				<template id="wp-media-helper-source-template">
					<?php $this->renderSource( '__INDEX__', [ 'state' => SourceState::ACTIVE ], [], $allowedBase, false ); ?>
				</template>

				<p class="submit">
					<button type="button" id="wp-media-helper-add-source" class="button"><?php esc_html_e( 'Add source', 'wp-media-helper' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'wp-media-helper' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Reset', 'wp-media-helper' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-media-helper-reset">
				<input type="hidden" name="action" value="wp_media_helper_reset" />
				<?php wp_nonce_field( 'wp_media_helper_reset' ); ?>
				<p class="description"><?php esc_html_e( 'Remove what this plugin stored, to start again from nothing. Media files and the files of your sources are never deleted; only thumbnails are.', 'wp-media-helper' ); ?></p>
				<fieldset>
					<?php foreach ( $this->resetChoices() as $item => $choice ) : ?>
						<p>
							<label>
								<input type="checkbox" name="reset_items[]" value="<?php echo esc_attr( $item ); ?>" <?php checked( $choice['default'] ); ?> />
								<strong><?php echo esc_html( $choice['label'] ); ?></strong>
								<span class="description"> &mdash; <?php echo esc_html( $choice['description'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>
				<p>
					<label for="wp-media-helper-reset-confirm"><?php esc_html_e( 'Type RESET to confirm', 'wp-media-helper' ); ?></label>
					<input id="wp-media-helper-reset-confirm" type="text" name="confirm" class="small-text" autocomplete="off" />
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Reset the selected items', 'wp-media-helper' ); ?></button>
				</p>
			</form>
		</div>

		<style>
			.wp-media-helper-source-list {
				display: flex;
				flex-direction: column;
				gap: 1rem;
				margin-top: 1.5rem;
			}
			.wp-media-helper-source {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 1rem 1.25rem;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
			}
			.wp-media-helper-source-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 1rem;
				margin-bottom: 0.5rem;
				padding-bottom: 0.75rem;
				border-bottom: 1px solid #f0f0f1;
			}
			.wp-media-helper-source-header strong {
				margin-right: auto;
			}
			.wp-media-helper-priority {
				color: #646970;
			}
			.wp-media-helper-toggle {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				font-weight: 600;
			}

			.wp-media-helper-source .form-table th {
				width: 190px;
			}
			.wp-media-helper-root-prefix {
				display: inline-block;
				margin-right: 0.25rem;
				vertical-align: middle;
			}
			.is-invalid {
				border-color: #d63638;
				box-shadow: 0 0 0 1px #d63638;
			}
			.wp-media-helper-empty-state {
				margin-top: 1.5rem;
				color: #50575e;
				font-style: italic;
			}
			.wp-media-helper-field-error {
				color: #d63638;
				font-weight: 600;
			}
		</style>

		<?php
	}

	public function getValidationErrors( array $sources, bool $enforceAllowedBase = true ): array {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {},
			static fn(): ?string => AllowedBase::resolve(),
			static fn(): ?string => ThumbnailCache::directory()
		);

		return $settings->validateSources( $sources, $enforceAllowedBase );
	}

	/**
	 * Flattens field errors into user-facing messages pointing at their source.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return string[]
	 */
	public function buildErrorNotices( array $sources, bool $enforceAllowedBase = true ): array {
		$notices = [];

		foreach ( $this->getValidationErrors( $sources, $enforceAllowedBase ) as $index => $fieldErrors ) {
			foreach ( $fieldErrors as $message ) {
				$notices[] = sprintf(
					/* translators: 1: position of the source in the form, 2: validation message. */
					__( 'Source #%1$d: %2$s', 'wp-media-helper' ),
					(int) $index + 1,
					$message
				);
			}
		}

		return $notices;
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-media-helper' ), 403 );
		}

		check_admin_referer( 'wp_media_helper_save_external_sources' );

		$raw = wp_unslash( $_POST['sources'] ?? [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		// The form asks for a path relative to the allowed base; the absolute path is stored.
		$allowedBase = AllowedBase::resolve();
		$submitted = array_map(
			static function ( $source ) use ( $allowedBase ) {
				foreach ( [ 'root' ] as $field ) {
					if ( is_array( $source ) && isset( $source[ $field ] ) && is_string( $source[ $field ] ) ) {
						$source[ $field ] = AllowedBase::toAbsolute( $allowedBase, $source[ $field ] );
					}
				}

				return $source;
			},
			array_values( $raw )
		);

		$rawGeneral = wp_unslash( $_POST['general'] ?? [] );
		$submittedMaxEntries = is_array( $rawGeneral ) && isset( $rawGeneral['max_entries'] ) && is_scalar( $rawGeneral['max_entries'] )
			? (string) $rawGeneral['max_entries']
			: (string) GeneralSettings::DEFAULT_MAX_ENTRIES;

		$maxEntriesError = GeneralSettings::validateMaxEntries( $submittedMaxEntries );
		if ( null !== $maxEntriesError ) {
			$this->redirectBackWithSubmission( $submitted, $maxEntriesError, $submittedMaxEntries );
		}

		if ( [] !== $this->getValidationErrors( $submitted ) ) {
			$this->redirectBackWithSubmission( $submitted, '', $submittedMaxEntries );
		}

		$settings = new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {
				update_option( ExternalSourceSettings::optionKey(), $value );
			},
			static fn(): ?string => AllowedBase::resolve(),
			static fn(): ?string => ThumbnailCache::directory()
		);

		try {
			$settings->saveAll( $submitted );
			$this->loadGeneralSettings( true )->save( [ 'max_entries' => $submittedMaxEntries ] );
		} catch ( InvalidArgumentException $exception ) {
			$this->redirectBackWithSubmission( $submitted, $exception->getMessage(), $submittedMaxEntries );
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', $this->pageUrl() ) );
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $submitted
	 */
	private function redirectBackWithSubmission( array $submitted, string $message = '', ?string $maxEntries = null ): void {
		set_transient(
			$this->pendingSubmissionKey(),
			[
				'sources' => $submitted,
				'message' => $message,
				'max_entries' => $maxEntries,
			],
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $this->pageUrl() );
		exit;
	}

	/**
	 * Returns the rejected submission so the form can be redisplayed as filled in.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, message: string, max_entries: string|null}|null
	 */
	private function consumePendingSubmission(): ?array {
		$pending = get_transient( $this->pendingSubmissionKey() );

		if ( ! is_array( $pending ) || ! isset( $pending['sources'] ) || ! is_array( $pending['sources'] ) ) {
			return null;
		}

		delete_transient( $this->pendingSubmissionKey() );

		return [
			'sources' => array_values( $pending['sources'] ),
			'message' => (string) ( $pending['message'] ?? '' ),
			'max_entries' => isset( $pending['max_entries'] ) && is_string( $pending['max_entries'] ) ? $pending['max_entries'] : null,
		];
	}

	private function pendingSubmissionKey(): string {
		return 'wp_media_helper_pending_sources_' . get_current_user_id();
	}

	private function pageUrl(): string {
		return admin_url( 'options-general.php?page=wp-media-helper' );
	}

	private function loadGeneralSettings( bool $persist = false ): GeneralSettings {
		return new GeneralSettings(
			static fn(): mixed => get_option( GeneralSettings::optionKey(), [] ),
			static function ( array $value ) use ( $persist ): void {
				if ( $persist ) {
					update_option( GeneralSettings::optionKey(), $value );
				}
			}
		);
	}

	private function makeReadOnlySettings(): ExternalSourceSettings {
		return new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {},
			static fn(): ?string => AllowedBase::resolve(),
			static fn(): ?string => ThumbnailCache::directory()
		);
	}

	/**
	 * Names of active sources whose root is outside the allowed base directory.
	 * Such sources are ignored by the editor panel until their root is fixed.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return string[]
	 */
	private function findDisabledSourceNames( array $sources ): array {
		$settings = $this->makeReadOnlySettings();
		$names = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || SourceState::ACTIVE !== SourceState::of( $source ) ) {
				continue;
			}
			$root = trim( (string) ( $source['root'] ?? '' ) );
			if ( '' !== $root && file_exists( $root ) && ! $settings->isSourceAllowed( $source ) ) {
				$names[] = (string) ( $source['name'] ?? $root );
			}
		}

		return $names;
	}

	/**
	 * Public URL prefix under which a source root is reachable when the web
	 * server serves the uploads directory, or null when it is not under it.
	 */
	private function publicUrlFor( string $root ): ?string {
		$uploads = wp_upload_dir( null, false );
		$realRoot = AllowedBase::resolveDirectory( $root );
		$realBase = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		if ( null === $realRoot || false === $realBase || ! PathConfinement::isWithin( $realBase, $realRoot ) ) {
			return null;
		}

		return trailingslashit( (string) $uploads['baseurl'] ) . ltrim( str_replace( '\\', '/', substr( $realRoot, strlen( $realBase ) ) ), '/' ) . '/';
	}

	/**
	 * Renders one source card. Used for the stored sources and, with __INDEX__ as
	 * index, for the template cloned by the "Add source" button, so both always match.
	 *
	 * @param array<string,mixed>  $source
	 * @param array<string,string> $errors
	 */
	private function renderSource( int|string $index, array $source, array $errors, ?string $allowedBase, bool $showIndex ): void {
		?>
		<div class="wp-media-helper-source">
			<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $source['id'] ?? '' ) ); ?>" />

			<div class="wp-media-helper-source-header">
				<strong><?php echo esc_html( (string) ( $source['name'] ?? '' ) ?: __( 'New source', 'wp-media-helper' ) ); ?></strong>
				<span class="wp-media-helper-priority" title="<?php esc_attr_e( 'Priority: the first source owns the files inside its directory.', 'wp-media-helper' ); ?>">#<span class="wp-media-helper-priority-number"><?php echo esc_html( is_int( $index ) ? (string) ( $index + 1 ) : '' ); ?></span></span>
				<label class="wp-media-helper-toggle">
					<span class="screen-reader-text"><?php esc_html_e( 'State', 'wp-media-helper' ); ?></span>
					<select name="sources[<?php echo esc_attr( $index ); ?>][state]">
						<option value="<?php echo esc_attr( SourceState::ACTIVE ); ?>" <?php selected( SourceState::of( $source ), SourceState::ACTIVE ); ?>><?php esc_html_e( 'Active', 'wp-media-helper' ); ?></option>
						<option value="<?php echo esc_attr( SourceState::DISABLED ); ?>" <?php selected( SourceState::of( $source ), SourceState::DISABLED ); ?>><?php esc_html_e( 'Disabled', 'wp-media-helper' ); ?></option>
						<option value="<?php echo esc_attr( SourceState::EXCLUDED ); ?>" <?php selected( SourceState::of( $source ), SourceState::EXCLUDED ); ?>><?php esc_html_e( 'Excluded', 'wp-media-helper' ); ?></option>
					</select>
				</label>
				<button type="button" class="button-link wp-media-helper-move-source" data-direction="-1" aria-label="<?php esc_attr_e( 'Move up', 'wp-media-helper' ); ?>">&uarr;</button>
				<button type="button" class="button-link wp-media-helper-move-source" data-direction="1" aria-label="<?php esc_attr_e( 'Move down', 'wp-media-helper' ); ?>">&darr;</button>
				<button type="button" class="button-link-delete wp-media-helper-remove-source"><?php esc_html_e( 'Remove', 'wp-media-helper' ); ?></button>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Name', 'wp-media-helper' ); ?></label></th>
						<td>
							<input id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $errors['name'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $source['name'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description" />
							<p class="description" id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description">
								<?php
								printf(
									/* translators: %s: example source name, wrapped in a code element. */
									esc_html__( 'Label used to identify this source in the admin, for example %s.', 'wp-media-helper' ),
									'<code>' . esc_html__( 'Nextcloud Main', 'wp-media-helper' ) . '</code>'
								);
								?>
							</p>
							<?php if ( isset( $errors['name'] ) ) : ?>
								<p class="description wp-media-helper-field-error">
									<?php echo esc_html( $errors['name'] ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Root directory', 'wp-media-helper' ); ?></label></th>
						<td>
							<?php if ( null !== $allowedBase ) : ?>
								<code class="wp-media-helper-root-prefix"><?php echo esc_html( rtrim( $allowedBase, '/\\' ) . '/' ); ?></code>
							<?php endif; ?>
							<input id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $errors['root'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][root]" value="<?php echo esc_attr( AllowedBase::toRelative( $allowedBase, (string) ( $source['root'] ?? '' ) ) ); ?>" aria-describedby="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description" />
							<p class="description" id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description">
								<?php
								if ( null !== $allowedBase ) {
									printf(
										/* translators: 1: example directory path relative to the base directory, 2: a dot, both wrapped in a code element. */
										esc_html__( 'Directory of the external media, relative to the base directory shown on the left, for example %1$s. The field cannot be left empty: type %2$s for the base directory itself.', 'wp-media-helper' ),
										'<code>nextcloud/photos</code>',
										'<code>.</code>'
									);
								} else {
									printf(
										/* translators: %s: example directory path, wrapped in a code element. */
										esc_html__( 'Absolute path to the external media root, for example %s.', 'wp-media-helper' ),
										'<code>/var/www/media</code>'
									);
								}
								?>
							</p>
							<?php if ( isset( $errors['root'] ) ) : ?>
								<p class="description wp-media-helper-field-error">
									<?php echo esc_html( $errors['root'] ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Path pattern', 'wp-media-helper' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wp-media-helper' ); ?></span></label></th>
						<td>
							<input id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $errors['path_pattern'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][path_pattern]" value="<?php echo esc_attr( (string) ( $source['path_pattern'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description" />
							<p class="description" id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description">
								<?php
								printf(
									/* translators: %s: example path pattern, wrapped in a code element. */
									esc_html__( 'Subdirectory where the files of the requested date are likely to be, for example %s. It is only a hint, used to find new files quickly: a file is placed on a day by its date, wherever it is. Leave empty to rely on the periodic scan of the whole source.', 'wp-media-helper' ),
									'<code>{date:Y}/{date:m}/{date:d}</code>'
								);
								?>
							</p>
							<?php if ( isset( $errors['path_pattern'] ) ) : ?>
								<p class="description wp-media-helper-field-error">
									<?php echo esc_html( $errors['path_pattern'] ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Name date patterns', 'wp-media-helper' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wp-media-helper' ); ?></span></label></th>
						<td class="wp-media-helper-name-patterns">
							<textarea id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>" class="large-text code<?php echo isset( $errors['name_patterns'] ) ? ' is-invalid' : ''; ?>" rows="3" name="sources[<?php echo esc_attr( $index ); ?>][name_patterns]" aria-describedby="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description"><?php echo esc_textarea( implode( "\n", FileDates::patternStrings( $source ) ) ); ?></textarea>
							<p class="description" id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description">
								<?php
								printf(
									/* translators: 1: example pattern, 2: example pattern with an optional time. */
									esc_html__( 'One pattern per line, tried in order; the first that matches a file name gives its date. A pattern says how the date is written in the name, for example %1$s, with %2$s for an optional time. [ ] marks an optional part, * any characters. Common forms such as 20261002_121549 or 2026-10-02 are recognised without a pattern, after yours. A name with a date alone is placed at 12:00.', 'wp-media-helper' ),
									'<code>{date:Ymd}</code>',
									'<code>{date:Ymd}[_{date:His}]</code>'
								);
								?>
							</p>
							<p class="wp-media-helper-pattern-tools">
								<label class="screen-reader-text" for="wp-media-helper-source-preset-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Preset', 'wp-media-helper' ); ?></label>
								<select id="wp-media-helper-source-preset-<?php echo esc_attr( $index ); ?>" class="wp-media-helper-preset">
									<option value=""><?php esc_html_e( 'Add a preset…', 'wp-media-helper' ); ?></option>
									<?php foreach ( NamePatternPresets::all() as $presetId => $preset ) : ?>
										<option value="<?php echo esc_attr( $presetId ); ?>" data-patterns="<?php echo esc_attr( (string) wp_json_encode( $preset['patterns'] ) ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<label class="screen-reader-text" for="wp-media-helper-source-test-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'File name to test', 'wp-media-helper' ); ?></label>
								<input id="wp-media-helper-source-test-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text wp-media-helper-test-name" placeholder="<?php esc_attr_e( 'A file name to test, e.g. IMG_20261002_121549.jpg', 'wp-media-helper' ); ?>" />
								<button type="button" class="button wp-media-helper-test-pattern"><?php esc_html_e( 'Test', 'wp-media-helper' ); ?></button>
								<span class="wp-media-helper-test-result" role="status"></span>
							</p>
							<?php if ( isset( $errors['name_patterns'] ) ) : ?>
								<p class="description wp-media-helper-field-error">
									<?php echo esc_html( $errors['name_patterns'] ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Files without a date in their name', 'wp-media-helper' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][mtime_fallback]" value="0" />
								<input type="checkbox" name="sources[<?php echo esc_attr( $index ); ?>][mtime_fallback]" value="1" <?php checked( ! array_key_exists( 'mtime_fallback', $source ) || filter_var( $source['mtime_fallback'], FILTER_VALIDATE_BOOLEAN ) ); ?> />
								<?php esc_html_e( 'Use the modification time of the file', 'wp-media-helper' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When unchecked, a file whose name has no date is not placed on any day.', 'wp-media-helper' ); ?></p>
						</td>
					</tr>
					<?php if ( $showIndex && ! empty( $source['id'] ) ) : ?>
						<?php $indexStatus = $this->indexStatus( $source ); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Index', 'wp-media-helper' ); ?></th>
							<td>
								<p><?php echo esc_html( $indexStatus ); ?></p>
								<p>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_media_helper_rescan&source=' . rawurlencode( (string) $source['id'] ) ), 'wp_media_helper_rescan_' . (string) $source['id'] ) ); ?>"><?php esc_html_e( 'Re-scan now', 'wp-media-helper' ); ?></a>
								</p>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Where the index of a source stands, as a sentence.
	 *
	 * @param array<string, mixed> $source
	 */
	private function indexStatus( array $source ): string {
		$status = DayIndex::forWordPress()->manager()->status( $source );

		if ( ! $status['indexed'] ) {
			return sprintf(
				/* translators: 1: number of files, 2: number of directories. */
				__( 'Not indexed yet: the first scan runs in the background (%1$d files in %2$d directories found so far).', 'wp-media-helper' ),
				$status['files'],
				$status['directories']
			);
		}

		$text = sprintf(
			/* translators: 1: number of files, 2: number of directories, 3: time since the last scan, such as "2 hours". */
			__( '%1$d files in %2$d directories. Last scan finished %3$s ago.', 'wp-media-helper' ),
			$status['files'],
			$status['directories'],
			human_time_diff( $status['finished_at'], time() )
		);

		if ( $status['in_progress'] || '' !== $status['pending'] ) {
			$text .= ' ' . __( 'A scan is in progress or waiting.', 'wp-media-helper' );
		}

		return $text;
	}

	/**
	 * Asks for a full scan of a source, run in the background.
	 */
	/**
	 * What can be reset, in the order shown.
	 *
	 * @return array<string, array{label:string, description:string, default:bool}>
	 */
	private function resetChoices(): array {
		return [
			Reset::SETTINGS    => [ 'label' => __( 'Settings and sources', 'wp-media-helper' ), 'description' => __( 'the list of sources and the general settings', 'wp-media-helper' ), 'default' => true ],
			Reset::INDEX       => [ 'label' => __( 'File index', 'wp-media-helper' ), 'description' => __( 'the index tables, the scan state and the scheduled scans (rebuilt when a source is added)', 'wp-media-helper' ), 'default' => true ],
			Reset::USER_DATA   => [ 'label' => __( 'Dates and preferences', 'wp-media-helper' ), 'description' => __( 'the date of each post, and the filters and panel mode of each user', 'wp-media-helper' ), 'default' => true ],
			Reset::THUMBNAILS  => [ 'label' => __( 'Thumbnails of imported media', 'wp-media-helper' ), 'description' => __( 'the cached sizes of the attachments imported by this plugin', 'wp-media-helper' ), 'default' => true ],
			Reset::CACHE       => [ 'label' => __( 'The whole thumbnail cache', 'wp-media-helper' ), 'description' => __( 'every file of the cache directory, including previews of files never imported and files written by other tools such as Thumbnails Folder', 'wp-media-helper' ), 'default' => false ],
			Reset::ATTACHMENTS => [ 'label' => __( 'Media imported by this plugin', 'wp-media-helper' ), 'description' => __( 'removes them from the Media Library (the files stay on disk) and detaches them from their posts', 'wp-media-helper' ), 'default' => false ],
		];
	}

	/**
	 * The WordPress uploads directory when a source may be rooted there, else null.
	 */
	private function uploadsDirectory(): ?string {
		$uploads = wp_upload_dir( null, false );
		$base    = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';

		return '' !== $base && is_dir( $base ) && $this->makeReadOnlySettings()->isRootAllowed( $base ) ? $base : null;
	}

	/**
	 * Adds the uploads directory as the last source.
	 */
	public function useUploads(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-media-helper' ), 403 );
		}
		check_admin_referer( 'wp_media_helper_use_uploads' );

		$base    = $this->uploadsDirectory();
		$sources = $this->loadSources();
		if ( null === $base ) {
			$this->redirectBackWithSubmission( $sources, __( 'The uploads directory cannot be used as a source on this site.', 'wp-media-helper' ) );
		}

		if ( ! UploadsSource::isConfigured( $sources, (string) $base ) ) {
			$sources  = UploadsSource::append( $sources, (string) $base, __( 'Uploads', 'wp-media-helper' ) );
			$settings = new ExternalSourceSettings(
				static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
				static function ( array $value ): void {
					update_option( ExternalSourceSettings::optionKey(), $value );
				},
				static fn(): ?string => AllowedBase::resolve(),
				static fn(): ?string => ThumbnailCache::directory()
			);

			try {
				$settings->saveAll( $sources );
			} catch ( InvalidArgumentException $exception ) {
				$this->redirectBackWithSubmission( $sources, $exception->getMessage() );
			}
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', $this->pageUrl() ) );
		exit;
	}

	/**
	 * Says which date a file name gives with the patterns being edited, so a pattern can
	 * be checked before it is saved. Nothing is stored.
	 */
	public function testNamePattern(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		check_ajax_referer( 'wp_media_helper_test_name_pattern', 'nonce' );

		$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$patterns = FileDates::patternStrings( [ 'name_patterns' => wp_unslash( $_POST['patterns'] ?? '' ) ] );
		if ( '' === $name || count( $patterns ) > ExternalSourceSettings::MAX_NAME_PATTERNS ) {
			wp_send_json_error( [ 'message' => __( 'Enter a file name and at most ten patterns.', 'wp-media-helper' ) ] );
		}

		$now      = time();
		$timezone = wp_timezone();
		foreach ( $patterns as $position => $pattern ) {
			$compiled = NamePattern::compile( $pattern );
			if ( null === $compiled ) {
				wp_send_json_error( [ 'message' => sprintf( /* translators: %d: position of an invalid pattern. */ __( 'Pattern %d is not valid.', 'wp-media-helper' ), $position + 1 ) ] );
			}
			$found = $compiled->match( $name, $timezone, $now );
			if ( null !== $found ) {
				wp_send_json_success( [ 'date' => $found['date']->format( 'Y-m-d H:i:s' ), 'precision' => $found['precision'], 'pattern' => $position + 1 ] );
			}
		}

		$found = NamePattern::generic()->match( $name, $timezone, $now );
		if ( null !== $found ) {
			wp_send_json_success( [ 'date' => $found['date']->format( 'Y-m-d H:i:s' ), 'precision' => $found['precision'], 'pattern' => 0 ] );
		}

		wp_send_json_success( [ 'date' => null, 'precision' => null, 'pattern' => null ] );
	}

	public function reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-media-helper' ), 403 );
		}
		check_admin_referer( 'wp_media_helper_reset' );

		if ( 'RESET' !== trim( (string) wp_unslash( $_POST['confirm'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'reset_confirm', '1', $this->pageUrl() ) );
			exit;
		}

		$items  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['reset_items'] ?? [] ) );
		$report = Reset::run( $items );
		set_transient( 'wp_media_helper_reset_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'reset', '1', $this->pageUrl() ) );
		exit;
	}

	private function resetMessage(): string {
		$key    = 'wp_media_helper_reset_report_' . get_current_user_id();
		$report = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $report ) || [] === $report ) {
			return __( 'Nothing was selected, so nothing was reset.', 'wp-media-helper' );
		}

		$labels = $this->resetChoices();
		$parts  = [];
		foreach ( $report as $item => $count ) {
			$parts[] = sprintf( '%s: %d', $labels[ $item ]['label'] ?? $item, (int) $count );
		}

		/* translators: %s: list of reset items with a count each. */
		return sprintf( __( 'Reset done. %s.', 'wp-media-helper' ), implode( '; ', $parts ) );
	}

	public function rescan(): void {
		$sourceId = sanitize_text_field( wp_unslash( $_GET['source'] ?? '' ) );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-media-helper' ), 403 );
		}

		check_admin_referer( 'wp_media_helper_rescan_' . $sourceId );

		foreach ( $this->loadSources() as $source ) {
			if ( is_array( $source ) && (string) ( $source['id'] ?? '' ) === $sourceId ) {
				DayIndex::forWordPress()->manager()->requestPass( $source, true );
				break;
			}
		}

		wp_safe_redirect( add_query_arg( 'rescan', 'true', $this->pageUrl() ) );
		exit;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadSources(): array {
		return $this->makeReadOnlySettings()->getAll();
	}
}
