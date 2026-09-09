<?php

namespace Pinova\Integrations\Wordpress\Exports;

use PhpOffice\PhpSpreadsheet\Exception as PhpOfficeException;
use PhpOffice\PhpSpreadsheet\Writer\Exception as PhpOfficeWriterException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Pinova\Integrations\Wordpress\ExportUsers;
use Pinova\Services\UserService;
use WP_User;


class Excel {

	private const ACTION = 'pinova_export_users_excel';

	private const FONT_FAMILY = 'Vazirmatn';
	private const NONCE_ACTION = 'pinova_export_users_excel';

	public function __construct() {
		add_action( 'admin_footer', [ $this, 'inject_export_button' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_export' ] );
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دریافت خروجی کاربران را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE_ACTION );

		try {
			$this->output(
				$this->generate(
					ExportUsers::get_users()
				)
			);
		} catch ( PhpOfficeWriterException | PhpOfficeException | \RuntimeException $e ) {
			wp_die( 'خطا در ایجاد فایل اکسل: ' . esc_html( $e->getMessage() ) );
		}

		exit;
	}

	public function inject_export_button(): void {
		global $pagenow;

		if ( 'users.php' !== $pagenow || ! current_user_can( 'list_users' ) ) {
			return;
		}

		$export_url = add_query_arg(
			array_merge( $_GET, [ 'action' => self::ACTION ] ),
			admin_url( 'admin-post.php' )
		);
		$export_url = wp_nonce_url( $export_url, self::NONCE_ACTION );
		?>
		<script>
            document.addEventListener("DOMContentLoaded", function () {
                const add_new = document.querySelector(".page-title-action");
                if (!add_new) return;

                const btn = document.createElement("a");
                btn.className = "page-title-action button-primary";
                btn.textContent = "برون‌بری اکسل";
                btn.style.marginInlineStart = "5px";
                btn.style.color = "var(--wp-admin-theme-color)";
                btn.href = "<?php echo esc_url_raw( $export_url ); ?>";

                add_new.insertAdjacentElement("afterend", btn);
            });
		</script>
		<?php
	}

	/**
	 * @throws PhpOfficeWriterException
	 */
	protected function output( Spreadsheet $spreadsheet ): void {

		$writer          = new Xlsx( $spreadsheet );
		$export_filename = 'pinova-exported-users-' . wp_date( 'Y-m-d' ) . '.xlsx';

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $export_filename . '"' );
		header( 'Cache-Control: max-age=0' );

		$writer->save( 'php://output' );

		exit;
	}

	/**
	 *
	 * @param WP_User[] $users
	 *
	 * @return Spreadsheet
	 * @throws PhpOfficeException
	 */
	protected function generate( array $users ): Spreadsheet {

		$spreadsheet = $this->create_spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();

		$metadata = $this->build_metadata();

		foreach ( $metadata as $index => $row ) {
			foreach ( $row as $column_index => $value ) {
				$sheet->setCellValueExplicit(
					[ $column_index + 1, $index + 2 ],
					(string) $value,
					DataType::TYPE_STRING
				);
			}
		}

		$meta_start_row = 2;

		foreach ( $metadata as $index => $row ) {

			if ( empty( $row[1] ) ) {
				continue;
			}

			if ( ExportUsers::contains_latin( $row[1] ) ) {

				$row_number = $meta_start_row + $index;

				$sheet->getStyle( 'B' . $row_number )
				      ->getAlignment()
				      ->setReadOrder( Alignment::READORDER_LTR );

			}

		}

		$meta_row_count  = count( $metadata ) + 2;
		$table_start_row = $meta_row_count + 1;
		$meta_range      = 'A1:B' . ( $meta_row_count - 1 );

		$this->apply_metadata_styles( $sheet, $meta_range, $meta_row_count );

		$columns = $this->columns();

		$this->render_table_header( $sheet, $columns, $table_start_row );
		$this->render_table_rows( $sheet, $columns, $users, $table_start_row + 1 );

		$last_column = Coordinate::stringFromColumnIndex( count( $columns ) );
		$last_row    = $sheet->getHighestRow();

		$this->apply_table_styles( $sheet, $table_start_row, $last_column, $last_row );

		return $spreadsheet;
	}

	protected function columns(): array {
		return [
			'id'           => [
				'label' => 'شناسه',

				'value' => fn( WP_User $user ) => $user->get( 'ID' ),
			],
			'login'        => [
				'label' => 'نام کاربری',
				'value' => fn( WP_User $user ) => $user->get( 'user_login' ),
			],
			'first_name'   => [
				'label' => 'نام',
				'value' => fn( WP_User $user ) => $user->get( 'first_name' ),
			],
			'last_name'    => [
				'label' => 'نام خانوادگی',
				'value' => fn( WP_User $user ) => $user->get( 'last_name' ),
			],
			'mobile'       => [
				'label' => 'تلفن همراه',
				'value' => fn( WP_User $user ) => UserService::get_mobile( $user->get( 'ID' ) ),
			],
			'email'        => [
				'label' => 'ایمیل',
				'value' => fn( WP_User $user ) => $user->get( 'user_email' ),
			],
			'display_name' => [
				'label' => 'نام نمایشی',
				'value' => fn( WP_User $user ) => $user->get( 'display_name' ),
			],
			'roles'        => [
				'label' => 'نقش (ها)',
				'value' => fn( WP_User $user ) => implode( '، ', ExportUsers::translate_roles( $user->roles ) ),
			],
			'registered'   => [
				'label' => 'زمان ثبت نام',
				'value' => fn( WP_User $user ) => wp_date( 'Y-m-d H:i', strtotime( $user->get( 'user_registered' ) ) ),
			],
		];
	}

	public function build_metadata(): array {

		$current_user = wp_get_current_user();

		$metadata_rows = [
			[ 'افزونه:', 'پینوا' ],
			[ 'زمان:', wp_date( 'Y/m/d H:i:s' ) ],
			[ 'توسط:', $current_user->get( 'display_name' ) ?? '-' ],
			[],
		];


		$query_parameters = array_filter( $_GET );

		foreach ( $query_parameters as $parameter_key => $parameter_value ) {

			if ( in_array( $parameter_key, [ 'action', '_wpnonce' ] ) ) {
				continue;
			}

			$formatted_label = ucwords( str_replace( '_', ' ', $parameter_key ) ) . ':';

			$formatted_value = is_array( $parameter_value )
				? implode( ', ', array_map( 'sanitize_text_field', $parameter_value ) )
				: sanitize_text_field( $parameter_value );

			switch ( $parameter_key ) {

				case 's':
					$formatted_label = 'جست‌و‌جو:';
					break;

				case 'role':
					$formatted_label = 'نقش کاربر:';

					if ( ! empty( $parameter_value ) ) {

						$role  = sanitize_text_field( $parameter_value );
						$roles = wp_roles();

						if ( isset( $roles->roles[ $role ]['name'] ) ) {
							$formatted_value = translate_user_role( $roles->roles[ $role ]['name'] );
						} else {
							$formatted_value = $role;
						}
					}
					break;

				case 'orderby':
					$formatted_label = 'مرتب‌سازی بر اساس:';
					$orderby_map     = [
						'login'        => 'نام کاربری',
						'email'        => 'ایمیل',
						'name'         => 'نام',
						'registered'   => 'زمان ثبت نام',
						'display_name' => 'نام نمایشی',
						'id'           => 'شناسه',
					];

					$key = strtolower( $parameter_value );

					if ( isset( $orderby_map[ $key ] ) ) {
						$formatted_value = $orderby_map[ $key ];
					}

					break;

				case 'order':
					$formatted_label = 'نوع مرتب‌سازی:';

					$order_map       = [
						'asc'  => 'صعودی',
						'desc' => 'نزولی',
					];
					$formatted_value = $order_map[ strtolower( $parameter_value ) ] ?? $parameter_value;
					break;
			}

			$metadata_rows[] = [
				$formatted_label,
				$formatted_value,
			];

		}

		return $metadata_rows;
	}

	/**
	 * @throws PhpOfficeException
	 */
	private function create_spreadsheet(): Spreadsheet {

		$spreadsheet = new Spreadsheet();

		$spreadsheet->getDefaultStyle()->applyFromArray(
			[
				'font'      => [
					'name' => self::FONT_FAMILY,
					'size' => 11,
				],
				'alignment' => [
					'horizontal'   => Alignment::HORIZONTAL_CENTER,
					'vertical'     => Alignment::VERTICAL_CENTER,
					'readingOrder' => Alignment::READORDER_RTL,
				],
			]
		);

		$sheet = $spreadsheet->getActiveSheet();

		$sheet->setRightToLeft( true );

		$sheet->getStyle( 'A:Z' )
		      ->getAlignment()
		      ->setReadOrder( Alignment::READORDER_RTL );

		$sheet
			->getStyle( 'A:Z' )
			->getAlignment()
			->setHorizontal( Alignment::HORIZONTAL_CENTER );

		return $spreadsheet;
	}

	/**
	 * @throws PhpOfficeException
	 */
	private function apply_metadata_styles( Worksheet $sheet, string $meta_range, int $meta_row_count ): void {

		$sheet->getStyle( $meta_range )->applyFromArray(
			[
				'font' => [
					'bold' => true,
				],
			]
		);

		$sheet
			->getStyle( 'A2:A' . ( $meta_row_count - 1 ) )
			->getFont()
			->setBold( true );
	}

	private function render_table_header( Worksheet $sheet, array $columns, int $table_start_row ): void {

		$current_column_index = 1;

		foreach ( $columns as $column ) {

			$sheet->setCellValue(
				[ $current_column_index, $table_start_row ],
				$column['label']
			);

			$current_column_index ++;

		}

	}

	private function render_table_rows( Worksheet $sheet, array $columns, array $users, int $start_row ): void {

		$current_row_index = $start_row;

		foreach ( $users as $user ) {

			$current_column_index = 1;

			foreach ( $columns as $column_key => $column ) {

				$cell_value = $column['value']( $user );

				$cell_coordinates = [ $current_column_index, $current_row_index ];

					if ( 'id' !== $column_key ) {

					$sheet->setCellValueExplicit(
						$cell_coordinates,
						(string) $cell_value,
						DataType::TYPE_STRING
					);

				} else {

					$sheet->setCellValue(
						$cell_coordinates,
						$cell_value
					);
				}

				if ( is_string( $cell_value ) && ExportUsers::contains_latin( $cell_value ) ) {
					$sheet->getStyle( $cell_coordinates )
					      ->getAlignment()
					      ->setReadOrder( Alignment::READORDER_LTR );
				}

				$current_column_index ++;

			}

			$current_row_index ++;

		}

	}

	/**
	 * @throws PhpOfficeException
	 */
	private function apply_table_styles( Worksheet $sheet, int $table_start_row, string $last_column, int $last_row ): void {

		$data_range   = "A{$table_start_row}:{$last_column}{$last_row}";
		$header_range = "A{$table_start_row}:{$last_column}{$table_start_row}";

		$sheet
			->getStyle( $data_range )
			->getFont()
			->setName( self::FONT_FAMILY );

		$sheet->freezePane( 'A' . ( $table_start_row + 1 ) );

		$sheet->getStyle( $header_range )->applyFromArray(
			[
				'font'      => [
					'bold' => true,
					'size' => 11,
					'name' => self::FONT_FAMILY,
				],
				'alignment' => [
					'horizontal' => Alignment::HORIZONTAL_CENTER,
					'vertical'   => Alignment::VERTICAL_CENTER,
				],
				'fill'      => [
					'fillType'   => Fill::FILL_SOLID,
					'startColor' => [
						'rgb' => 'E7F3FF',
					],
				],
			]
		);

		$sheet
			->getStyle( $data_range )
			->getBorders()
			->getAllBorders()
			->setBorderStyle( Border::BORDER_THIN );

		for ( $row_index = $table_start_row; $row_index <= $last_row; $row_index ++ ) {
			$sheet->getRowDimension( $row_index )->setRowHeight( 22 );
		}

		foreach ( range( 'A', $last_column ) as $column_letter ) {
			$sheet->getColumnDimension( $column_letter )->setAutoSize( true );
		}

	}
}
