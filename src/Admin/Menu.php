<?php

namespace Pinova\Admin;

class Menu {

	public string $plugin_file = 'pinova/pinova.php';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 20 );
		add_filter( 'plugin_action_links_' . $this->plugin_file, [ $this, 'settings_action' ], 100 );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );

		Settings::instance();
		new Logs();
	}

	public function admin_menu() {

		add_menu_page( 'پینوا', 'پینوا', 'manage_options', 'pinova', '__return_null', PINOVA_URL . 'assets/images/pinova.svg', 55.8 );

		$submenus = [
			10 => [
				'title'      => 'تنظیمات',
				'capability' => 'manage_options',
				'slug'       => 'pinova',
				'callback'   => [ Settings::class, 'render' ],
			],
			20 => [
				'title'      => 'مسدودی‌ها',
				'capability' => 'manage_options',
				'slug'       => 'pinova-blocks',
				'callback'   => function () {
					include PINOVA_DIR . '/templates/admin/blocks.php';
				},
			],
			30 => [
				'title'      => 'گزارش‌ها',
				'capability' => 'manage_options',
				'slug'       => 'pinova-logs',
				'callback'   => [ Logs::class, 'render' ],
			],
		];

		$submenus = apply_filters( 'pinova/submenus', $submenus );

		foreach ( $submenus as $submenu ) {
			add_submenu_page( 'pinova', $submenu['title'], $submenu['title'], $submenu['capability'], $submenu['slug'], $submenu['callback'] );
		}

	}

	public function settings_action( array $actions ): array {

		$actions['settings'] = sprintf( '<a href="%s" target="blank">%s</a>', admin_url( 'admin.php?page=pinova' ), 'تنظیمات' );

		$brand = [
			'woo_ir' => sprintf( '<a href="%s" target="blank" style="background: #763ec2;color: white;padding: 0px 5px;border-radius: 2px;">%s</a>', 'https://woocommerce.ir', 'ووکامرس فارسی' ),
		];

		return $brand + $actions;
	}

	public function plugin_row_meta( array $plugin_meta, $plugin_file ): array {

		if ( $plugin_file != $this->plugin_file ) {
			return $plugin_meta;
		}

		$plugin_meta['document'] = sprintf(
			'<a href="%s" target="_blank"><span class="dashicons dashicons-media-document"></span> مستندات</a>',
			esc_url( 'https://www.aparat.com/playlist/25107706/' )
		);

		return $plugin_meta;
	}
}
