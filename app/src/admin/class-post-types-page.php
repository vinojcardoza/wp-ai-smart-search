<?php
/**
 * Post Types page.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Admin;

use WP_AI_Smart_Search\Indexer;
use WP_Post_Type;

/**
 * AI Smart Search → Post Types: choose which post types are indexed.
 *
 * Posts and pages are enabled by default; every other public post type is
 * optional. Switching a type off removes its posts from the index
 * (Hooks\Indexing); switching one on indexes its posts as they are saved,
 * or all at once with a re-index.
 *
 * @package WP_AI_Smart_Search
 */
class Post_Types_Page {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	public const GROUP = 'wpaismse_post_types';

	/**
	 * Register the option and how it is sanitized. Runs on admin_init.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Indexer::POST_TYPES_OPTION,
			array(
				'type'              => 'array',
				'default'           => Indexer::DEFAULT_POST_TYPES,
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Keep only names of available post types.
	 *
	 * When types are newly switched on, the success message also suggests a
	 * re-index so their existing posts are added.
	 *
	 * @param mixed $value Submitted value (array of names; null when every box is unticked).
	 * @return string[]
	 */
	public function sanitize( $value ): array {
		$selected = is_array( $value ) ? array_map( 'sanitize_key', array_map( 'strval', $value ) ) : array();
		$types    = array_values( array_intersect( array_keys( self::available() ), $selected ) );

		// WordPress can run a sanitize callback twice on the first save; only add the message once.
		static $noticed = false;

		$before = get_option( Indexer::POST_TYPES_OPTION, Indexer::DEFAULT_POST_TYPES );
		$added  = array_diff( $types, is_array( $before ) ? $before : Indexer::DEFAULT_POST_TYPES );

		if ( ! $noticed && array() !== $added && function_exists( 'add_settings_error' ) ) {
			$noticed = true;
			$labels  = array_map( fn( string $name ): string => self::available()[ $name ]->labels->name, $added );

			add_settings_error(
				Indexer::POST_TYPES_OPTION,
				'post_types_added',
				sprintf(
					/* translators: %s: comma-separated post type names. */
					__( 'Settings saved. Run a re-index to add existing content of: %s.', 'wpaismse' ),
					implode( ', ', $labels )
				),
				'success'
			);
		}

		return $types;
	}

	/**
	 * Public post types that can be indexed (attachments excluded), keyed by name.
	 *
	 * @return array<string, WP_Post_Type>
	 */
	public static function available(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );

		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = Indexer::post_types();

		Layout::open( Layout::POST_TYPES_PAGE, __( 'Post Types', 'wpaismse' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php's redirect.
		if ( isset( $_GET['settings-updated'] ) && ! get_settings_errors() ) {
			add_settings_error( 'general', 'settings_updated', __( 'Settings saved.', 'wpaismse' ), 'success' );
		}

		settings_errors();
		?>
		<div class="wpaismse-grid">
			<form class="wpaismse-col" action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<section class="wpaismse-card">
					<div class="wpaismse-card__head">
						<span class="wpaismse-icon"><?php Layout::icon( 'layers' ); ?></span>
						<div>
							<h2 class="wpaismse-card__title"><?php esc_html_e( 'Indexed post types', 'wpaismse' ); ?></h2>
							<p class="wpaismse-card__sub"><?php esc_html_e( 'Only published content of the types switched on here is indexed and searchable.', 'wpaismse' ); ?></p>
						</div>
					</div>
					<div class="wpaismse-card__body">
						<?php foreach ( self::available() as $name => $type ) : ?>
							<?php
							$id        = 'wpaismse-post-type-' . $name;
							$published = (int) ( wp_count_posts( $name )->publish ?? 0 );
							$default   = in_array( $name, Indexer::DEFAULT_POST_TYPES, true );
							?>
							<div class="wpaismse-row">
								<div class="wpaismse-row__text">
									<label class="wpaismse-row__label" for="<?php echo esc_attr( $id ); ?>">
										<?php echo esc_html( $type->labels->name ); ?>
										<?php if ( $default ) : ?>
											<span class="wpaismse-badge"><?php esc_html_e( 'Default', 'wpaismse' ); ?></span>
										<?php endif; ?>
									</label>
									<p class="wpaismse-row__desc" id="<?php echo esc_attr( $id ); ?>-desc">
										<code><?php echo esc_html( $name ); ?></code>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of published posts. */
												_n( '%s published', '%s published', $published, 'wpaismse' ),
												number_format_i18n( $published )
											)
										);
										?>
									</p>
								</div>
								<input
									class="wpaismse-switch"
									type="checkbox"
									role="switch"
									id="<?php echo esc_attr( $id ); ?>"
									name="<?php echo esc_attr( Indexer::POST_TYPES_OPTION ); ?>[]"
									value="<?php echo esc_attr( $name ); ?>"
									aria-describedby="<?php echo esc_attr( $id ); ?>-desc"
									<?php checked( in_array( $name, $enabled, true ) ); ?>
								/>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="wpaismse-savebar">
					<span><?php esc_html_e( 'Switching a type off removes its posts from the index right away.', 'wpaismse' ); ?></span>
					<div class="wpaismse-actions">
						<button type="reset" class="wpaismse-btn wpaismse-btn--ghost"><?php esc_html_e( 'Discard', 'wpaismse' ); ?></button>
						<button type="submit" class="wpaismse-btn wpaismse-btn--primary"><?php esc_html_e( 'Save changes', 'wpaismse' ); ?></button>
					</div>
				</div>
			</form>

			<aside class="wpaismse-col">
				<section class="wpaismse-card wpaismse-card--aside">
					<h2 class="wpaismse-card__title"><?php esc_html_e( 'How it works', 'wpaismse' ); ?></h2>
					<p class="wpaismse-note"><?php esc_html_e( 'Posts and pages are on by default. Other public post types are optional.', 'wpaismse' ); ?></p>
					<p class="wpaismse-note"><?php esc_html_e( 'Turning a type on indexes its posts as they are saved. To add existing ones now, run a re-index.', 'wpaismse' ); ?></p>
					<p class="wpaismse-note">
						<a href="<?php echo esc_url( Layout::url( Layout::REINDEX_PAGE ) ); ?>"><?php esc_html_e( 'Go to Re-Index →', 'wpaismse' ); ?></a>
					</p>
				</section>
			</aside>
		</div>
		<?php
		Layout::close();
	}
}
