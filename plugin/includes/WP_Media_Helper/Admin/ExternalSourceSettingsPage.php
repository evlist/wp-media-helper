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

		$sources = $this->loadSources();
		?>
		<div class="wrap">
			<h1>WP Media Helper</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_media_helper_save_external_sources" />
				<?php wp_nonce_field( 'wp_media_helper_save_external_sources' ); ?>
				<h2>External media sources</h2>
				<p>Add one or more directories that should be included in the external media workflow.</p>
				<div id="wp-media-helper-sources">
					<?php foreach ( $sources as $index => $source ) : ?>
						<div class="wp-media-helper-source" style="margin-bottom:1.5rem; border:1px solid #d0d0d0; padding:1rem; max-width:900px;">
							<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $source['id'] ?? '' ) ); ?>" />

							<p>
								<label>
									<strong>Name</strong><br />
									<input type="text" name="sources[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $source['name'] ?? '' ) ); ?>" style="width:100%; max-width:500px;" />
								</label>
							</p>

							<p>
								<label>
									<strong>Root directory</strong><br />
									<input type="text" name="sources[<?php echo esc_attr( $index ); ?>][root]" value="<?php echo esc_attr( (string) ( $source['root'] ?? '' ) ); ?>" style="width:100%; max-width:500px;" />
								</label>
							</p>

							<p>
								<label>
									<strong>Path pattern</strong><br />
									<input type="text" name="sources[<?php echo esc_attr( $index ); ?>][path_pattern]" value="<?php echo esc_attr( (string) ( $source['path_pattern'] ?? '' ) ); ?>" style="width:100%; max-width:500px;" />
								</label>
							</p>

							<p>
								<label>
									<strong>Filter pattern</strong><br />
									<input type="text" name="sources[<?php echo esc_attr( $index ); ?>][filter_pattern]" value="<?php echo esc_attr( (string) ( $source['filter_pattern'] ?? '' ) ); ?>" style="width:100%; max-width:500px;" />
								</label>
							</p>

							<p>
								<label>
									<strong>Thumbnail cache directory</strong><br />
									<input type="text" name="sources[<?php echo esc_attr( $index ); ?>][thumbnail_cache]" value="<?php echo esc_attr( (string) ( $source['thumbnail_cache'] ?? '' ) ); ?>" style="width:100%; max-width:500px;" />
								</label>
							</p>

							<p>
								<label>
									<input type="checkbox" name="sources[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
									Enabled
								</label>
							</p>
						</div>
					<?php endforeach; ?>
				</div>

				<p>
					<button type="submit" class="button button-primary">Save changes</button>
				</p>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		check_admin_referer( 'wp_media_helper_save_external_sources' );

		$raw = $_POST['sources'] ?? [];
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$settings = new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {
				update_option( ExternalSourceSettings::optionKey(), $value );
			}
		);

		try {
			$settings->saveAll( $raw );
		} catch ( InvalidArgumentException $exception ) {
			wp_die( esc_html( $exception->getMessage() ), 'Invalid external source configuration', [ 'back_link' => true ] );
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'options-general.php?page=wp-media-helper' ) ) );
		exit;
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
