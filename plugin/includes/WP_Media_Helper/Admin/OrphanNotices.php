<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\Maintenance\OrphanRegistry;
use WP_Media_Helper\Maintenance\OrphanReport;

/**
 * Tells the administrators about attachments that lost their file: a test of Site Health, and a notice on the
 * few screens where it is useful (the dashboard, the media library, the settings and the tool of this plugin).
 */
final class OrphanNotices {

	private const SCREENS = [ 'dashboard', 'upload', 'settings_page_wp-media-helper' ];

	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'addTest' ] );
		add_filter( 'debug_information', [ $this, 'debugInformation' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public function addTest( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['wp_media_helper_orphans'] = [
			'label' => __( 'Files of the media library', 'wp-media-helper' ),
			'test'  => [ $this, 'healthTest' ],
		];

		return $tests;
	}

	/**
	 * A section of the Info tab of Site Health.
	 *
	 * @param mixed $info
	 * @return mixed
	 */
	public function debugInformation( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$fields = [];
		foreach ( OrphanReport::debugFields( OrphanRegistry::forWordPress()->get(), Pause::isPaused(), time() ) as $label => $value ) {
			$fields[ sanitize_key( $label ) ] = [ 'label' => $label, 'value' => $value ];
		}
		$info['wp-media-helper'] = [
			'label'  => __( 'WP Media Helper', 'wp-media-helper' ),
			'fields' => $fields,
		];

		return $info;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function healthTest(): array {
		$report = OrphanReport::health( OrphanRegistry::forWordPress()->get(), time() );
		$html   = '';
		foreach ( $report['paragraphs'] as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		return [
			'label'       => $report['label'],
			'status'      => $report['status'],
			'badge'       => [ 'label' => __( 'Media', 'wp-media-helper' ), 'color' => 'blue' ],
			'description' => $html,
			'actions'     => $report['problem'] ? sprintf( '<p><a href="%s">%s</a></p>', esc_url( OrphansPage::url() ), esc_html__( 'List and clean up the attachments without a file', 'wp-media-helper' ) ) : '',
			'test'        => 'wp_media_helper_orphans',
		];
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		$paragraphs = OrphanReport::notice( OrphanRegistry::forWordPress()->get() );
		if ( [] === $paragraphs ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<?php foreach ( $paragraphs as $paragraph ) : ?>
				<p><?php echo esc_html( $paragraph ); ?></p>
			<?php endforeach; ?>
			<p><a href="<?php echo esc_url( OrphansPage::url() ); ?>"><?php esc_html_e( 'List and clean up the attachments without a file', 'wp-media-helper' ); ?></a></p>
		</div>
		<?php
	}
}
