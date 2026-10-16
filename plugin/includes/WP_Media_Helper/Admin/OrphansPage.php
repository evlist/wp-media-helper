<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Attachment\Methods;
use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\Maintenance\AttachmentCleaner;
use WP_Media_Helper\Maintenance\OrphanCheck;
use WP_Media_Helper\Maintenance\OrphanRegistry;
use WP_Media_Helper\MediaSource\RecordRemover;

/**
 * The tool that lists the attachments whose file is missing and cleans them up: Tools > Media orphans.
 *
 * Cleaning removes the records, never a file. Each removal is checked again against the disk first.
 */
final class OrphansPage {

	public const SLUG = 'wp-media-helper-orphans';
	private const PER_PAGE = 50;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_post_wp_media_helper_orphans_check', [ $this, 'check' ] );
		add_action( 'admin_post_wp_media_helper_orphans_action', [ $this, 'act' ] );
		add_action( 'admin_post_wp_media_helper_orphans_csv', [ $this, 'csv' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'tools.php' ) );
	}

	public function register(): void {
		add_management_page(
			__( 'Media orphans', 'wp-media-helper' ),
			__( 'Media orphans', 'wp-media-helper' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-media-helper' ), 403 );
		}

		$registry = OrphanRegistry::forWordPress();
		$state    = $registry->get();
		$ignored  = 'ignored' === ( $_GET['view'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids      = $ignored ? $registry->ignored() : $registry->open();
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$pages    = max( 1, (int) ceil( count( $ids ) / self::PER_PAGE ) );
		$page     = min( $page, $pages );
		$shown    = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$rows     = $this->rows( $shown );
		$uploads  = wp_upload_dir( null, false );
		$cleaner  = new AttachmentCleaner( is_array( $uploads ) ? (string) $uploads['basedir'] : '' );
		$used     = $cleaner->usedBy( $shown );
		$links    = $cleaner->linksOf( $shown );
		$methods  = Methods::all();
		$elsewhere = [];
		foreach ( $cleaner->sameNameElsewhere( array_values( array_unique( array_map( static fn ( array $row ): string => basename( $row['path'] ), $rows ) ) ) ) as $name => $paths ) {
			$elsewhere[ $name ] = $paths;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media orphans', 'wp-media-helper' ); ?></h1>
			<p><?php esc_html_e( 'Attachments of the media library whose file is not on the disk. Removing them removes the record in the library, never a file.', 'wp-media-helper' ); ?></p>

			<?php $this->messages(); ?>

			<?php if ( Pause::isPaused() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The indexing is paused, so the check is paused too.', 'wp-media-helper' ); ?></p></div>
			<?php endif; ?>
			<?php if ( [] !== $state['unavailable'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: list of directories. */
							__( 'Not checked, because they are not readable, empty, or none of their attachments has its file (a directory that is not mounted?): %s. Their attachments are not listed below.', 'wp-media-helper' ),
							implode( ', ', array_keys( $state['unavailable'] ) )
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<p>
				<?php
				echo esc_html(
					$state['checked_at'] > 0
						? sprintf(
							/* translators: 1: time since the last check, 2: number of attachments checked. */
							__( 'Last check %1$s ago, %2$d attachments checked.', 'wp-media-helper' ),
							human_time_diff( $state['checked_at'], time() ),
							$state['scanned']
						)
						: __( 'No check has run yet.', 'wp-media-helper' )
				);
				if ( $state['started_at'] > 0 ) {
					echo ' ' . esc_html__( 'A check is under way.', 'wp-media-helper' );
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="wp_media_helper_orphans_check">
				<?php wp_nonce_field( 'wp_media_helper_orphans_check' ); ?>
				<button class="button"><?php esc_html_e( 'Check now', 'wp-media-helper' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_media_helper_orphans_csv' . ( $ignored ? '&view=ignored' : '' ) ), 'wp_media_helper_orphans_csv' ) ); ?>"><?php esc_html_e( 'Export as CSV', 'wp-media-helper' ); ?></a>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::url() ); ?>"<?php echo $ignored ? '' : ' class="current"'; ?>><?php echo esc_html( sprintf( /* translators: %d: number of attachments. */ __( 'Without a file (%d)', 'wp-media-helper' ), count( $registry->open() ) ) ); ?></a> |</li>
				<li><a href="<?php echo esc_url( self::url( [ 'view' => 'ignored' ] ) ); ?>"<?php echo $ignored ? ' class="current"' : ''; ?>><?php echo esc_html( sprintf( /* translators: %d: number of attachments. */ __( 'Ignored (%d)', 'wp-media-helper' ), count( $registry->ignored() ) ) ); ?></a></li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return this.elements.bulk.value !== 'remove' || window.confirm('<?php echo esc_js( __( 'Remove the records of the selected attachments from the media library? No file is deleted.', 'wp-media-helper' ) ); ?>');">
				<input type="hidden" name="action" value="wp_media_helper_orphans_action">
				<?php wp_nonce_field( 'wp_media_helper_orphans_action' ); ?>
				<div class="tablenav top">
					<select name="bulk" id="bulk">
						<option value=""><?php esc_html_e( 'Bulk actions', 'wp-media-helper' ); ?></option>
						<?php if ( $ignored ) : ?>
							<option value="unignore"><?php esc_html_e( 'Stop ignoring', 'wp-media-helper' ); ?></option>
						<?php else : ?>
							<option value="ignore"><?php esc_html_e( 'Ignore', 'wp-media-helper' ); ?></option>
						<?php endif; ?>
						<option value="remove"><?php esc_html_e( 'Remove the records from the library', 'wp-media-helper' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'wp-media-helper' ); ?></button>
					<span class="displaying-num"><?php echo esc_html( sprintf( /* translators: %d: number of attachments. */ _n( '%d item', '%d items', count( $ids ), 'wp-media-helper' ), count( $ids ) ) ); ?></span>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" onclick="for (const box of this.form.querySelectorAll('input[name=&quot;ids[]&quot;]')) box.checked = this.checked;"></td>
						<th><?php esc_html_e( 'Attachment', 'wp-media-helper' ); ?></th>
						<th><?php esc_html_e( 'Registered path', 'wp-media-helper' ); ?></th>
						<th><?php esc_html_e( 'Attached to', 'wp-media-helper' ); ?></th>
						<th><?php esc_html_e( 'Used', 'wp-media-helper' ); ?></th>
						<th><?php esc_html_e( 'Same file name elsewhere', 'wp-media-helper' ); ?></th>
						<th><?php esc_html_e( 'Seen missing', 'wp-media-helper' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( [] === $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Nothing to show.', 'wp-media-helper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>"></th>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( '' === $row['title'] ? '#' . $row['id'] : $row['title'] ); ?></a> <span class="description">#<?php echo esc_html( (string) $row['id'] ); ?> <?php echo esc_html( $row['mime'] ); ?></span></td>
							<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
							<td>
								<?php if ( $row['parent'] > 0 ) : ?><a href="<?php echo esc_url( (string) get_edit_post_link( $row['parent'] ) ); ?>"><?php echo esc_html( get_the_title( $row['parent'] ) ?: '#' . $row['parent'] ); ?></a><br><?php endif; ?>
								<?php foreach ( $links[ $row['id'] ] ?? [] as $methodId => $postIds ) : ?>
									<span class="description"><?php echo esc_html( isset( $methods[ $methodId ] ) ? $methods[ $methodId ]->label() : $methodId ); ?>:</span>
									<?php foreach ( $postIds as $postId ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $postId ) ); ?>"><?php echo esc_html( get_the_title( $postId ) ?: '#' . $postId ); ?></a>
									<?php endforeach; ?>
									<br>
								<?php endforeach; ?>
								<?php if ( $row['parent'] <= 0 && [] === ( $links[ $row['id'] ] ?? [] ) ) : ?>&mdash;<?php endif; ?>
							</td>
							<td>
								<?php
								$where = $used[ $row['id'] ] ?? [ 'featured' => [], 'content' => [] ];
								$parts = [];
								if ( [] !== $where['featured'] ) {
									$parts[] = sprintf( /* translators: %d: number of posts. */ _n( 'featured image of %d post', 'featured image of %d posts', count( $where['featured'] ), 'wp-media-helper' ), count( $where['featured'] ) );
								}
								if ( [] !== $where['content'] ) {
									$parts[] = sprintf( /* translators: %d: number of posts. */ _n( 'in the content of %d post', 'in the content of %d posts', count( $where['content'] ), 'wp-media-helper' ), count( $where['content'] ) );
								}
								echo [] === $parts ? '&mdash;' : esc_html( implode( ', ', $parts ) );
								?>
							</td>
							<td>
								<?php foreach ( $elsewhere[ basename( $row['path'] ) ] ?? [] as $other ) : ?>
									<?php if ( $other !== $row['path'] ) : ?><code><?php echo esc_html( $other ); ?></code><br><?php endif; ?>
								<?php endforeach; ?>
							</td>
							<td><?php echo esc_html( isset( $state['ids'][ $row['id'] ] ) ? sprintf( /* translators: %s: time since. */ __( '%s ago', 'wp-media-helper' ), human_time_diff( (int) $state['ids'][ $row['id'] ], time() ) ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							[
								'base'    => add_query_arg( 'paged', '%#%', self::url( $ignored ? [ 'view' => 'ignored' ] : [] ) ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							]
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function messages(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['removed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: 1: number removed, 2: number left alone. */
					__( 'Records removed: %1$d. Left alone: %2$d (the file is back, its directory could not be checked, or an attachment method refused: see below).', 'wp-media-helper' ),
					(int) $_GET['removed'],
					(int) ( $_GET['skipped'] ?? 0 )
				)
			) . '</p></div>';
		}
		$refused = get_transient( 'wp_media_helper_orphans_refused_' . get_current_user_id() );
		if ( is_array( $refused ) && [] !== $refused ) {
			delete_transient( 'wp_media_helper_orphans_refused_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'These attachments were not removed because an attachment method refused:', 'wp-media-helper' ) . '</p><ul class="ul-disc">';
			foreach ( array_slice( $refused, 0, 20, true ) as $id => $reason ) {
				echo '<li>#' . (int) $id . ': ' . esc_html( (string) $reason ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( isset( $_GET['checking'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( 'paused' === $_GET['checking'] ? __( 'The indexing is paused: the check was not started.', 'wp-media-helper' ) : ( 'more' === $_GET['checking'] ? __( 'The check is under way; it goes on in the background. Reload this page in a moment.', 'wp-media-helper' ) : __( 'The check is done.', 'wp-media-helper' ) ) ) . '</p></div>';
		}
		// phpcs:enable
	}

	public function check(): void {
		$this->authorize( 'wp_media_helper_orphans_check' );

		$checking = 'paused';
		if ( ! Pause::isPaused() ) {
			$checking = OrphanCheck::runWithin( 25.0, true ) ? 'more' : 'done';
			if ( 'more' === $checking && false === wp_next_scheduled( OrphanCheck::HOOK, [ 'continue' ] ) ) {
				wp_schedule_single_event( time() + 5, OrphanCheck::HOOK, [ 'continue' ] );
			}
		}
		wp_safe_redirect( self::url( [ 'checking' => $checking ] ) );
		exit;
	}

	public function act(): void {
		$this->authorize( 'wp_media_helper_orphans_action' );

		$registry = OrphanRegistry::forWordPress();
		$bulk     = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) );
		$ids      = array_values( array_unique( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ?? [] ) ) ) ) );
		$args     = [];

		if ( 'ignore' === $bulk ) {
			$registry->ignore( $ids );
		} elseif ( 'unignore' === $bulk ) {
			$registry->unignore( $ids );
			$args['view'] = 'ignored';
		} elseif ( 'remove' === $bulk && [] !== $ids ) {
			$safe = $this->stillOrphans( $registry, $ids );
			$done = RecordRemover::removeGuarded( $safe, Methods::all() );
			$registry->forget( $done['removed'] );
			if ( [] !== $done['refused'] ) {
				set_transient( 'wp_media_helper_orphans_refused_' . get_current_user_id(), $done['refused'], 300 );
			}
			$args['removed'] = count( $done['removed'] );
			$args['skipped'] = count( $ids ) - count( $done['removed'] );
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * The IDs that are still known as orphans, are still attachments and still have no file on the disk, in a directory
	 * that is not reported as unavailable.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private function stillOrphans( OrphanRegistry $registry, array $ids ): array {
		$state   = $registry->get();
		$uploads = wp_upload_dir( null, false );
		$baseDir = is_array( $uploads ) ? (string) realpath( (string) $uploads['basedir'] ) : '';
		if ( '' === $baseDir ) {
			return [];
		}

		$safe = [];
		foreach ( $this->rows( array_values( array_intersect( $ids, array_map( 'intval', array_keys( $state['ids'] ) ) ) ) ) as $row ) {
			$group = $state['where'][ $row['id'] ] ?? '.';
			if ( isset( $state['unavailable'][ $group ] ) || is_file( $baseDir . '/' . $row['path'] ) ) {
				continue;
			}
			$safe[] = $row['id'];
		}

		return $safe;
	}

	public function csv(): void {
		$this->authorize( 'wp_media_helper_orphans_csv', 'GET' );

		$registry = OrphanRegistry::forWordPress();
		$state    = $registry->get();
		$ignored  = 'ignored' === ( $_GET['view'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids      = $ignored ? $registry->ignored() : $registry->open();
		$uploads  = wp_upload_dir( null, false );
		$cleaner  = new AttachmentCleaner( is_array( $uploads ) ? (string) $uploads['basedir'] : '' );
		$rows     = $this->rows( $ids );
		$used     = $cleaner->usedBy( $ids );
		$links    = $cleaner->linksOf( $ids );
		$elsewhere = $cleaner->sameNameElsewhere( array_values( array_unique( array_map( static fn ( array $row ): string => basename( $row['path'] ), $rows ) ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="media-orphans-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [ 'id', 'title', 'path', 'mime', 'parent', 'date', 'seen_missing', 'featured_in', 'content_of', 'linked_by_methods', 'same_name_elsewhere' ] );
		foreach ( $rows as $row ) {
			$where = $used[ $row['id'] ] ?? [ 'featured' => [], 'content' => [] ];
			fputcsv(
				$out,
				array_map(
					[ self::class, 'cell' ],
					[
						$row['id'],
						$row['title'],
						$row['path'],
						$row['mime'],
						$row['parent'],
						$row['date'],
						isset( $state['ids'][ $row['id'] ] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['ids'][ $row['id'] ] ) : '',
						implode( ' ', $where['featured'] ),
						implode( ' ', $where['content'] ),
						implode( ' ', array_map( static fn ( string $method, array $posts ): string => $method . ':' . implode( ',', $posts ), array_keys( $links[ $row['id'] ] ?? [] ), array_values( $links[ $row['id'] ] ?? [] ) ) ),
						implode( ' ', array_diff( $elsewhere[ basename( $row['path'] ) ] ?? [], [ $row['path'] ] ) ),
					]
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * A cell that a spreadsheet would take for a formula is made harmless (titles are typed by people).
	 *
	 * @param mixed $value
	 */
	public static function cell( $value ): string {
		$text = (string) $value;

		return '' !== $text && str_contains( "=+-@\t\r", $text[0] ) ? "'" . $text : $text;
	}

	private function authorize( string $nonce, string $method = 'POST' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-media-helper' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array{id:int, path:string, parent:int, mime:string, date:string, title:string}>
	 */
	private function rows( array $ids ): array {
		global $wpdb;

		$rows = [];
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$found        = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID AS id, pm.meta_value AS path, p.post_parent AS parent, p.post_mime_type AS mime, p.post_date AS date, p.post_title AS title
					FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
					WHERE p.post_type = 'attachment' AND p.ID IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$chunk
				),
				ARRAY_A
			);
			foreach ( is_array( $found ) ? $found : [] as $row ) {
				$rows[ (int) $row['id'] ] = [
					'id'     => (int) $row['id'],
					'path'   => (string) $row['path'],
					'parent' => (int) $row['parent'],
					'mime'   => (string) $row['mime'],
					'date'   => (string) $row['date'],
					'title'  => (string) $row['title'],
				];
			}
		}

		// The order of the list.
		$ordered = [];
		foreach ( $ids as $id ) {
			if ( isset( $rows[ $id ] ) ) {
				$ordered[] = $rows[ $id ];
			}
		}

		return $ordered;
	}
}
