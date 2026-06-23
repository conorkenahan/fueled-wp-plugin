<?php
/**
 * Front-end template for the Featured Content Block.
 *
 * Included via ob_start() / include in FCB_Block_Register::render().
 * Variables available in scope (set by render() before include):
 *
 * @var array  $posts              Shaped post objects from the REST endpoint.
 * @var array  $attributes         Raw (sanitized) block attributes.
 * @var string $wrapper_attributes Output of get_block_wrapper_attributes() — already escaped.
 *
 * Never echo unsanitised data. All $posts values were escaped by FCB_REST_API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() handles escaping internally ?>>
	<?php foreach ( $posts as $post ) : ?>
	<article class="fcb-card">

		<?php if ( ! empty( $post['featured_image_url'] ) ) : ?>
		<figure class="fcb-card__figure">
			<a href="<?php echo esc_url( $post['permalink'] ); ?>" tabindex="-1" aria-hidden="true">
				<img
					class="fcb-card__image"
					src="<?php echo esc_url( $post['featured_image_url'] ); ?>"
					alt="<?php echo esc_attr( $post['title'] ); ?>"
					loading="lazy"
					decoding="async"
				/>
			</a>
		</figure>
		<?php endif; ?>

		<header class="fcb-card__header">
			<h3 class="fcb-card__title">
				<a class="fcb-card__link" href="<?php echo esc_url( $post['permalink'] ); ?>">
					<?php echo esc_html( $post['title'] ); ?>
				</a>
			</h3>

			<?php if ( ! empty( $attributes['showDate'] ) ) : ?>
			<time class="fcb-card__date" datetime="<?php echo esc_attr( $post['date'] ); ?>">
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post['date'] ) ) ); ?>
			</time>
			<?php endif; ?>
		</header>

		<?php if ( ! empty( $attributes['showExcerpt'] ) && ! empty( $post['excerpt'] ) ) : ?>
		<p class="fcb-card__excerpt"><?php echo esc_html( $post['excerpt'] ); ?></p>
		<?php endif; ?>

	</article>
	<?php endforeach; ?>
</section>
