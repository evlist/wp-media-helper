<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use InvalidArgumentException;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class ExternalSourceSettingsPage {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_post_wp_media_helper_save_external_sources', [ $this, 'save' ] );
	}

	public function register(): void {
		add_options_page(
			'WP Media Helper',
			'WP Media Helper',
			'manage_options',
			'wp-media-helper',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = $this->consumePendingSubmission();
		$sources = null === $pending ? $this->loadSources() : $pending['sources'];
		$validationErrors = $this->getValidationErrors( $sources );
		$notices = $this->buildErrorNotices( $sources );

		if ( null !== $pending && '' !== $pending['message'] ) {
			array_unshift( $notices, $pending['message'] );
		}
		?>
		<div class="wrap wp-media-helper-settings">
			<h1><?php echo esc_html( get_admin_page_title() ?: 'WP Media Helper' ); ?></h1>

			<?php if ( [] !== $notices ) : ?>
				<div class="notice notice-error">
					<p><strong>Your changes were not saved.</strong> Please fix the following and try again:</p>
					<ul class="ul-disc">
						<?php foreach ( $notices as $notice ) : ?>
							<li><?php echo esc_html( $notice ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php elseif ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>External media sources saved.</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_media_helper_save_external_sources" />
				<?php wp_nonce_field( 'wp_media_helper_save_external_sources' ); ?>

				<h2>External media sources</h2>
				<p class="description">Add one or more directories that should be included in the external media workflow.</p>

				<p id="wp-media-helper-no-source" class="wp-media-helper-empty-state"<?php echo [] === $sources ? '' : ' hidden'; ?>>
					No external source is configured. The plugin uses the WordPress media library only.
				</p>

				<div id="wp-media-helper-sources" class="wp-media-helper-source-list">
					<?php foreach ( $sources as $index => $source ) : ?>
						<div class="wp-media-helper-source">
							<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $source['id'] ?? '' ) ); ?>" />

							<div class="wp-media-helper-source-header">
								<strong><?php echo esc_html( (string) ( $source['name'] ?? '' ) ?: 'New source' ); ?></strong>
								<label class="wp-media-helper-toggle">
									<input type="checkbox" name="sources[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
									Enabled
								</label>
								<button type="button" class="button-link-delete wp-media-helper-remove-source">Remove</button>
							</div>

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>">Name</label></th>
										<td>
											<input id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['name'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $source['name'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-name-<?php echo esc_attr( $index ); ?>-description">Label used to identify this source in the admin, for example <code>Nextcloud Main</code>.</p>
											<?php if ( isset( $validationErrors[ $index ]['name'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['name'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>">Root directory</label></th>
										<td>
											<input id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['root'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][root]" value="<?php echo esc_attr( (string) ( $source['root'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-root-<?php echo esc_attr( $index ); ?>-description">Absolute path to the external media root, for example <code>/var/www/media</code>.</p>
											<?php if ( isset( $validationErrors[ $index ]['root'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['root'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>">Path pattern <span class="description">(optional)</span></label></th>
										<td>
											<input id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text<?php echo isset( $validationErrors[ $index ]['path_pattern'] ) ? ' is-invalid' : ''; ?>" name="sources[<?php echo esc_attr( $index ); ?>][path_pattern]" value="<?php echo esc_attr( (string) ( $source['path_pattern'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-path-<?php echo esc_attr( $index ); ?>-description">Subdirectory resolved for the requested date, for example <code>{date:Y}/{date:m}/{date:d}</code>. Leave empty to use the source root directly.</p>
											<?php if ( isset( $validationErrors[ $index ]['path_pattern'] ) ) : ?>
												<p class="description wp-media-helper-field-error">
													<?php echo esc_html( $validationErrors[ $index ]['path_pattern'] ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>">Filter pattern <span class="description">(optional)</span></label></th>
										<td>
											<input id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text" name="sources[<?php echo esc_attr( $index ); ?>][filter_pattern]" value="<?php echo esc_attr( (string) ( $source['filter_pattern'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-filter-<?php echo esc_attr( $index ); ?>-description">Filename filter applied once the directory is resolved, for example <code>{date:Ymd}</code>. Leave empty to keep every file in the resolved directory.</p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>">Thumbnail cache directory <span class="description">(optional)</span></label></th>
										<td>
											<input id="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>" type="text" class="regular-text" name="sources[<?php echo esc_attr( $index ); ?>][thumbnail_cache]" value="<?php echo esc_attr( (string) ( $source['thumbnail_cache'] ?? '' ) ); ?>" aria-describedby="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>-description" />
											<p class="description" id="wp-media-helper-source-cache-<?php echo esc_attr( $index ); ?>-description">Writable directory storing thumbnails, for example <code>/var/www/media-cache</code>. Required only when the source directory is read-only.</p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit">
					<button type="button" id="wp-media-helper-add-source" class="button">Add source</button>
					<button type="submit" class="button button-primary">Save changes</button>
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

		<script>
		(function () {
			const container = document.getElementById('wp-media-helper-sources');
			const addButton = document.getElementById('wp-media-helper-add-source');
			if (!container || !addButton) {
				return;
			}
			let nextIndex = container.querySelectorAll('.wp-media-helper-source').length;
			const emptyState = document.getElementById('wp-media-helper-no-source');

			const refreshEmptyState = function () {
				if (emptyState) {
					emptyState.hidden = container.querySelectorAll('.wp-media-helper-source').length > 0;
				}
			};

			const buildSourceMarkup = function (index) {
				return `
					<div class="wp-media-helper-source">
						<input type="hidden" name="sources[${index}][id]" value="" />
						<div class="wp-media-helper-source-header">
							<strong>New source</strong>
							<label class="wp-media-helper-toggle">
								<input type="checkbox" name="sources[${index}][enabled]" value="1" checked />
								Enabled
							</label>
							<button type="button" class="button-link-delete wp-media-helper-remove-source">Remove</button>
						</div>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><label for="wp-media-helper-source-name-${index}">Name</label></th>
									<td><input id="wp-media-helper-source-name-${index}" type="text" class="regular-text" name="sources[${index}][name]" value="" aria-describedby="wp-media-helper-source-name-${index}-description" /><p class="description" id="wp-media-helper-source-name-${index}-description">Label used to identify this source in the admin, for example <code>Nextcloud Main</code>.</p></td>
								</tr>
								<tr>
									<th scope="row"><label for="wp-media-helper-source-root-${index}">Root directory</label></th>
									<td><input id="wp-media-helper-source-root-${index}" type="text" class="regular-text" name="sources[${index}][root]" value="" aria-describedby="wp-media-helper-source-root-${index}-description" /><p class="description" id="wp-media-helper-source-root-${index}-description">Absolute path to the external media root, for example <code>/var/www/media</code>.</p></td>
								</tr>
								<tr>
									<th scope="row"><label for="wp-media-helper-source-path-${index}">Path pattern <span class="description">(optional)</span></label></th>
									<td><input id="wp-media-helper-source-path-${index}" type="text" class="regular-text" name="sources[${index}][path_pattern]" value="" aria-describedby="wp-media-helper-source-path-${index}-description" /><p class="description" id="wp-media-helper-source-path-${index}-description">Subdirectory resolved for the requested date, for example <code>{date:Y}/{date:m}/{date:d}</code>. Leave empty to use the source root directly.</p></td>
								</tr>
								<tr>
									<th scope="row"><label for="wp-media-helper-source-filter-${index}">Filter pattern <span class="description">(optional)</span></label></th>
									<td><input id="wp-media-helper-source-filter-${index}" type="text" class="regular-text" name="sources[${index}][filter_pattern]" value="" aria-describedby="wp-media-helper-source-filter-${index}-description" /><p class="description" id="wp-media-helper-source-filter-${index}-description">Filename filter applied once the directory is resolved, for example <code>{date:Ymd}</code>. Leave empty to keep every file in the resolved directory.</p></td>
								</tr>
								<tr>
									<th scope="row"><label for="wp-media-helper-source-cache-${index}">Thumbnail cache directory <span class="description">(optional)</span></label></th>
									<td><input id="wp-media-helper-source-cache-${index}" type="text" class="regular-text" name="sources[${index}][thumbnail_cache]" value="" aria-describedby="wp-media-helper-source-cache-${index}-description" /><p class="description" id="wp-media-helper-source-cache-${index}-description">Writable directory storing thumbnails, for example <code>/var/www/media-cache</code>. Required only when the source directory is read-only.</p></td>
								</tr>
							</tbody>
						</table>
					</div>
				`;
			};

			addButton.addEventListener('click', function () {
				const index = nextIndex++;
				const fragment = document.createElement('div');
				fragment.innerHTML = buildSourceMarkup(index);
				container.appendChild(fragment.firstElementChild);
				refreshEmptyState();
			});

			container.addEventListener('click', function (event) {
				if (!event.target.classList.contains('wp-media-helper-remove-source')) {
					return;
				}
				const card = event.target.closest('.wp-media-helper-source');
				if (!card) {
					return;
				}

				const nameInput = card.querySelector('input[name$="[name]"]');
				const sourceName = nameInput && nameInput.value.trim() ? nameInput.value.trim() : 'this source';
				if (window.confirm('Remove ' + sourceName + '? This change will be saved when you click Save changes.')) {
					card.remove();
					refreshEmptyState();
				}
			});
		})();
		</script>
		<?php
	}

	public function getValidationErrors( array $sources ): array {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		return $settings->validateSources( $sources );
	}

	/**
	 * Flattens field errors into user-facing messages pointing at their source.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return string[]
	 */
	public function buildErrorNotices( array $sources ): array {
		$notices = [];

		foreach ( $this->getValidationErrors( $sources ) as $index => $fieldErrors ) {
			foreach ( $fieldErrors as $message ) {
				$notices[] = sprintf( 'Source #%d: %s', (int) $index + 1, $message );
			}
		}

		return $notices;
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		check_admin_referer( 'wp_media_helper_save_external_sources' );

		$raw = wp_unslash( $_POST['sources'] ?? [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$submitted = array_values( $raw );

		if ( [] !== $this->getValidationErrors( $submitted ) ) {
			$this->redirectBackWithSubmission( $submitted );
		}

		$settings = new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {
				update_option( ExternalSourceSettings::optionKey(), $value );
			}
		);

		try {
			$settings->saveAll( $submitted );
		} catch ( InvalidArgumentException $exception ) {
			$this->redirectBackWithSubmission( $submitted, $exception->getMessage() );
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', $this->pageUrl() ) );
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $submitted
	 */
	private function redirectBackWithSubmission( array $submitted, string $message = '' ): void {
		set_transient(
			$this->pendingSubmissionKey(),
			[
				'sources' => $submitted,
				'message' => $message,
			],
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $this->pageUrl() );
		exit;
	}

	/**
	 * Returns the rejected submission so the form can be redisplayed as filled in.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, message: string}|null
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
		];
	}

	private function pendingSubmissionKey(): string {
		return 'wp_media_helper_pending_sources_' . get_current_user_id();
	}

	private function pageUrl(): string {
		return admin_url( 'options-general.php?page=wp-media-helper' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadSources(): array {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {}
		);

		return $settings->getAll();
	}
}
