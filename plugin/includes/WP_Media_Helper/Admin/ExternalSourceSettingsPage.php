<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use InvalidArgumentException;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Settings\AllowedBase;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\GeneralSettings;

class ExternalSourceSettingsPage {

	private string $hookSuffix = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
		add_action( 'admin_post_wp_media_helper_save_external_sources', [ $this, 'save' ] );
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
		$enforceBase = null !== $pending;
		$validationErrors = $this->getValidationErrors( $sources, $enforceBase );
		$notices = $this->buildErrorNotices( $sources, $enforceBase );
		$disabledSources = null === $pending ? $this->findDisabledSourceNames( $sources ) : [];
		$exposedUrls = [];
		foreach ( $sources as $source ) {
			$url = is_array( $source ) ? $this->publicUrlFor( (string) ( $source['root'] ?? '' ) ) : null;
			if ( null !== $url ) {
				$exposedUrls[] = $url;
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

			<?php if ( [] !== $exposedUrls ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'These directories are inside the uploads directory.', 'wp-media-helper' ); ?></strong>
						<?php esc_html_e( 'Web servers usually serve that directory publicly, so anyone who knows or guesses a file URL can download it. If the files are private, block HTTP access to these URLs in your web server configuration (see the plugin README).', 'wp-media-helper' ); ?>
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
				<p class="description"><?php esc_html_e( 'Add one or more directories that should be included in the external media workflow.', 'wp-media-helper' ); ?></p>

				<p id="wp-media-helper-no-source" class="wp-media-helper-empty-state"<?php echo [] === $sources ? '' : ' hidden'; ?>>
					<?php esc_html_e( 'No external source is configured. The plugin uses the WordPress media library only.', 'wp-media-helper' ); ?>
				</p>

				<div id="wp-media-helper-sources" class="wp-media-helper-source-list">
					<?php foreach ( $sources as $index => $source ) : ?>
						<div class="wp-media-helper-source">
							<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $source['id'] ?? '' ) ); ?>" />

							<div class="wp-media-helper-source-header">
								<strong><?php echo esc_html( (string) ( $source['name'] ?? '' ) ?: __( 'New source', 'wp-media-helper' ) ); ?></strong>
								<label class="wp-media-helper-toggle">
									<input type="checkbox" name="sources[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
									<?php esc_html_e( 'Enabled', 'wp-media-helper' ); ?>
								</label>
								<button type="button" class="button-link-delete wp-media-helper-remove-source"><?php esc_html_e( 'Remove', 'wp-media-helper' ); ?></button>
							</div>

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Name', 'wp-media-helper' ); ?></label></th>
										<td>
											<input id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['name'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $source['name'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description">
												<?php
												printf(
													/* translators: %s: example source name, wrapped in a code element. */
													esc_html__( 'Label used to identify this source in the admin, for example %s.', 'wp-media-helper' ),
													'<code>' . esc_html__( 'Nextcloud Main', 'wp-media-helper' ) . '</code>'
												);
												?>
											</p>
											<?php if ( isset( $validationErrors[ $index ]['name'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['name'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Root directory', 'wp-media-helper' ); ?></label></th>
										<td>
											<input id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['root'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][root]" value="<?php echo esc_attr( (string) ( $source['root'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description">
												<?php
												printf(
													/* translators: %s: example directory path, wrapped in a code element. */
													esc_html__( 'Absolute path to the external media root, for example %s.', 'wp-media-helper' ),
													'<code>' . esc_html( ( AllowedBase::resolve() ?? '/var/www' ) . '/media' ) . '</code>'
												);
												if ( null !== AllowedBase::resolve() ) {
													echo ' ';
													printf(
														/* translators: %s: allowed base directory path, wrapped in a code element. */
														esc_html__( 'It must be a sub-directory of %s.', 'wp-media-helper' ),
														'<code>' . esc_html( (string) AllowedBase::resolve() ) . '</code>'
													);
												}
												?>
											</p>
											<?php if ( isset( $validationErrors[ $index ]['root'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['root'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Path pattern', 'wp-media-helper' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wp-media-helper' ); ?></span></label></th>
										<td>
											<input id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['path_pattern'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][path_pattern]" value="<?php echo esc_attr( (string) ( $source['path_pattern'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description">
												<?php
												printf(
													/* translators: %s: example path pattern, wrapped in a code element. */
													esc_html__( 'Subdirectory resolved for the requested date, for example %s. Leave empty to use the source root directly.', 'wp-media-helper' ),
													'<code>{date:Y}/{date:m}/{date:d}</code>'
												);
												?>
											</p>
											<?php if ( isset( $validationErrors[ $index ]['path_pattern'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['path_pattern'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Filter pattern', 'wp-media-helper' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wp-media-helper' ); ?></span></label></th>
										<td>
											<input id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text" name="sources[<?php echo esc_attr( $index ); ?>][filter_pattern]" value="<?php echo esc_attr( (string) ( $source['filter_pattern'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description">
												<?php
												printf(
													/* translators: %s: example filename filter, wrapped in a code element. */
													esc_html__( 'Filename filter applied once the directory is resolved, for example %s. Leave empty to keep every file in the resolved directory.', 'wp-media-helper' ),
													'<code>{date:Ymd}</code>'
												);
												?>
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Thumbnail cache directory', 'wp-media-helper' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'wp-media-helper' ); ?></span></label></th>
										<td>
											<input id="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text" name="sources[<?php echo esc_attr( $index ); ?>][thumbnail_cache]" value="<?php echo esc_attr( (string) ( $source['thumbnail_cache'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>-description">
												<?php
												printf(
													/* translators: %s: example directory path, wrapped in a code element. */
													esc_html__( 'Writable directory storing thumbnails, for example %s. Required only when the source directory is read-only.', 'wp-media-helper' ),
													'<code>/var/www/media-cache</code>'
												);
												?>
											</p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit">
					<button type="button" id="wp-media-helper-add-source" class="button"><?php esc_html_e( 'Add source', 'wp-media-helper' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'wp-media-helper' ); ?></button>
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
			.wp-media-helper-toggle {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				font-weight: 600;
			}
			.wp-media-helper-remove-source {
				margin-left: auto;
			}
			.wp-media-helper-source .form-table th {
				width: 190px;
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
			static fn(): ?string => AllowedBase::resolve()
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

		$submitted = array_values( $raw );

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
			static fn(): ?string => AllowedBase::resolve()
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
			static fn(): ?string => AllowedBase::resolve()
		);
	}

	/**
	 * Names of enabled sources whose root is outside the allowed base directory.
	 * Such sources are ignored by the editor panel until their root is fixed.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return string[]
	 */
	private function findDisabledSourceNames( array $sources ): array {
		$settings = $this->makeReadOnlySettings();
		$names = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || ! filter_var( $source['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN ) ) {
				continue;
			}
			$root = trim( (string) ( $source['root'] ?? '' ) );
			if ( '' !== $root && file_exists( $root ) && ! $settings->isRootAllowed( $root ) ) {
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
		$realRoot = realpath( $root );
		$realBase = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		if ( false === $realRoot || false === $realBase || ! PathConfinement::isWithin( $realBase, $realRoot ) ) {
			return null;
		}

		return trailingslashit( (string) $uploads['baseurl'] ) . ltrim( str_replace( '\\', '/', substr( $realRoot, strlen( $realBase ) ) ), '/' ) . '/';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadSources(): array {
		return $this->makeReadOnlySettings()->getAll();
	}
}
