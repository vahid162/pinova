<?php
/** Standalone verification page uses the normal WordPress asset lifecycle. */
/** @var string $content */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php esc_html_e( 'تأیید تلفن همراه', 'pinova' ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped first-party template rendered above. ?></main>
<?php wp_footer(); ?>
</body>
</html>
