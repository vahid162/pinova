<?php

namespace Nabik\Utils\V1;

use Automattic\Jetpack\Constants;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Nabik\Utils\V1\Settings' ) ) {

	/**
	 * Class Settings
	 *
	 * @todo    Change file name to Settings when minimum php version set to 8.0.0
	 *
	 * @author  Nabik
	 */
	abstract class Settings {

		const VERSION = '1.0.0';

		public function __construct() {
			$this->register();
		}

		private function register() {

			foreach ( $this->get_sections() as $section ) {
				register_setting( $section['id'], $section['id'], [
					'type'              => 'array',
					'sanitize_callback' => [ $this, 'sanitize_options' ],
				] );
			}

		}

		public function init() {
			$this->enqueue();
			$this->admin_init();
		}

		/**
		 * Enqueue scripts and styles
		 */
		public function enqueue() {
			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_style( 'wp-color-picker' );

			if ( defined( 'WC_PLUGIN_FILE' ) ) {
				wp_register_script( 'select2-js', plugins_url( 'assets/js/select2/select2.js', WC_PLUGIN_FILE ), [ 'jquery' ], Constants::get_constant( 'WC_VERSION' ) );
				wp_register_style( 'select2-css', plugins_url( 'assets/css/select2.css', WC_PLUGIN_FILE ), [], Constants::get_constant( 'WC_VERSION' ) );
			}

			wp_enqueue_script( 'select2-js' );
			wp_enqueue_style( 'select2-css' );

			wp_enqueue_media();
		}

		/**
		 * Get settings sections
		 *
		 * @return array
		 */
		abstract public function get_sections(): array;

		/**
		 * Get settings fields
		 *
		 * @return array
		 */
		abstract public function get_fields(): array;

		/**
		 * Initialize and registers the settings sections and fileds to WordPress
		 *
		 * Usually this should be called at `admin_init` hook.
		 *
		 * This function gets the initiated settings sections and fields. Then
		 * registers them to WordPress and ready for use.
		 */
		function admin_init() {

			// Register settings sections
			foreach ( $this->get_sections() as $section ) {

				if ( false == get_option( $section['id'] ) ) {
					add_option( $section['id'] );
				}

				if ( ! empty( $section['desc'] ) ) {
					$section['desc'] = '<div class="inside">' . $section['desc'] . '</div>';
					$callback        = function () use ( $section ) {
						echo $this->esc_html( str_replace( '"', '\"', $section['desc'] ) );
					};
				} else if ( isset( $section['callback'] ) ) {
					$callback = $section['callback'];
				} else {
					$callback = null;
				}

				add_settings_section( $section['id'], $section['title'], $callback, $section['id'] );
			}

			// Register settings fields
			foreach ( $this->get_fields() as $section => $fields ) {

				foreach ( $fields as $option ) {

					$id    = $option['id'] ?? null;
					$type  = $option['type'] ?? null;
					$label = $option['label'] ?? null;

					if ( empty( $id ) || empty( $type ) ) {
						continue;
					}

					$args = [
						'id'                => $id,
						'type'              => $type,
						'label'             => $label,
						'label_for'         => $args['label_for'] ?? "{$section}[{$id}]",
						'desc'              => $option['desc'] ?? '',
						'section'           => $section,
						'size'              => $option['size'] ?? null,
						'options'           => $option['options'] ?? '',
						'std'               => $option['default'] ?? '',
						'sanitize_callback' => $option['sanitize_callback'] ?? '',
						'placeholder'       => $option['placeholder'] ?? '',
						'class'             => $option['class'] ?? null,
						'field_class'       => $option['field_class'] ?? null,
						'attributes'        => $option['attributes'] ?? [],
					];

					$callback_method = 'callback_' . $type;

					if ( ! method_exists( $this, $callback_method ) ) {
						$args['desc']    = sprintf( 'فیلد %s یافت نشد. لطفا تمام افزونه‌های نابیک را به آخرین نسخه بروزرسانی نمایید.', $type );
						$callback_method = 'callback_error';
					}

					add_settings_field( $section . '[' . $id . ']', $label, [
						$this,
						$callback_method,
					], $section, $section, $args );
				}

			}
		}

		/**
		 * Get field description for display
		 *
		 * @param array $args settings field args
		 */
		public function get_field_description( array $args ) {
			if ( ! empty( $args['desc'] ) ) {
				$desc = sprintf( '<p class="description">%s</p>', $args['desc'] );
			} else {
				$desc = '';
			}

			return $desc;
		}

		function callback_error( array $args ) {
			echo $this->esc_html( $this->get_field_description( $args ) );
		}

		/**
		 * Displays a text field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_text( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';
			$type  = isset( $args['type'] ) ? $args['type'] : 'text';

			$html = sprintf( '<input type="%1$s" class="%2$s-text %3$s" id="%4$s[%5$s]" name="%4$s[%5$s]" value="%6$s" placeholder="%7$s"/>', $type, $size, $args['field_class'], $args['section'], $args['id'], $value, $args['placeholder'] );
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a url field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_url( array $args ) {
			$this->callback_text( $args );
		}

		/**
		 * Displays a number field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_number( array $args ) {
			$this->callback_text( $args );
		}

		/**
		 * Displays a checkbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_checkbox( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );

			$html = '<fieldset>';
			$html .= sprintf( '<label for="wpuf-%1$s[%2$s]">', $args['section'], $args['id'] );
			$html .= sprintf( '<input type="hidden" name="%1$s[%2$s]" value="0" />', $args['section'], $args['id'] );
			$html .= sprintf( '<input type="checkbox" class="checkbox" id="wpuf-%1$s[%2$s]" name="%1$s[%2$s]" value="1" %3$s />', $args['section'], $args['id'], checked( $value, '1', false ) );
			$html .= sprintf( '%1$s</label>', $args['desc'] );
			$html .= '</fieldset>';

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a multicheckbox a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_multicheck( array $args ) {

			$value = $this->get_option( $args );
			$html  = '<fieldset>';

			foreach ( $args['options'] as $key => $label ) {
				$checked = isset( $value[ $key ] ) ? $value[ $key ] : '0';
				$html    .= sprintf( '<label for="wpuf-%1$s[%2$s][%3$s]">', $args['section'], $args['id'], $key );
				$html    .= sprintf( '<input type="checkbox" class="checkbox" id="wpuf-%1$s[%2$s][%3$s]" name="%1$s[%2$s][%3$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked( $checked, $key, false ) );
				$html    .= sprintf( '%1$s</label><br>', $label );
			}

			$html .= $this->get_field_description( $args );
			$html .= '</fieldset>';

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a multicheckbox a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_radio( array $args ) {

			$value = $this->get_option( $args );
			$html  = '<fieldset>';

			foreach ( $args['options'] as $key => $label ) {
				$html .= sprintf( '<label for="wpuf-%1$s[%2$s][%3$s]">', $args['section'], $args['id'], $key );
				$html .= sprintf( '<input type="radio" class="radio" id="wpuf-%1$s[%2$s][%3$s]" name="%1$s[%2$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked( $value, $key, false ) );
				$html .= sprintf( '%1$s</label><br>', $label );
			}

			$html .= $this->get_field_description( $args );
			$html .= '</fieldset>';

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a selectbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_select( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';
			$html  = sprintf( '<select class="%1$s" name="%2$s[%3$s]" id="%2$s[%3$s]" style="width: 25em">', $size, $args['section'], $args['id'] );

			foreach ( $args['options'] as $key => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', $key, selected( $value, $key, false ), $label );
			}

			$html .= sprintf( '</select>' );
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a selectbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_select2( array $args ) {

			$section = $args['section'];
			$id      = $args['id'];

			$value = $this->get_option( $args );

			if ( ! is_array( $value ) ) {
				$value = [ $value ];
			}

			$value = array_map( 'esc_attr', $value );

			$size     = $args['size'] ?? 'regular';
			$multiple = isset( $args['attributes']['multiple'] ) ? 'multiple' : '';

			$attr_id   = sprintf( '%s[%s]', $section, $id );
			$attr_name = sprintf( '%s[%s]', $section, $id );

			if ( $multiple ) {
				$attr_name .= '[]';
			}

			$html = sprintf( '<select class="%s select2" name="%s" id="%s" %s placeholder="%s" style="width: 25em">', $size, $attr_name, $attr_id, $multiple, $args['placeholder'] );

			foreach ( $args['options'] as $key => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', $key, selected( in_array( $key, $value ), true, false ), $label );
			}

			$html .= sprintf( '</select>' );
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a textarea for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_textarea( array $args ) {

			$value = sanitize_textarea_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';

			$html = sprintf(
				'<textarea rows="5" cols="55" class="%1$s-text %5$s" id="%2$s[%3$s]" name="%2$s[%3$s]" placeholder="%6$s">%4$s</textarea>',
				$size,
				$args['section'],
				$args['id'],
				$value,
				$args['field_class'],
				$args['placeholder']
			);
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a textarea for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_html( array $args ) {
			echo $this->esc_html( $this->get_field_description( $args ) );
		}

		/**
		 * Displays a rich text textarea for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_wysiwyg( array $args ) {

			$value = $this->get_option( $args );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : '500px';

			printf( '<div style="max-width: %s;">', esc_attr( $size ) );

			$editor_settings = [
				'teeny'         => true,
				'textarea_name' => $args['section'] . '[' . $args['id'] . ']',
				'textarea_rows' => 10,
			];

			if ( isset( $args['options'] ) && is_array( $args['options'] ) ) {
				$editor_settings = array_merge( $editor_settings, $args['options'] );
			}

			wp_editor( $value, $args['section'] . '-' . $args['id'], $editor_settings );

			echo '</div>';

			echo $this->esc_html( $this->get_field_description( $args ) );
		}

		/**
		 * Displays a file upload field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_file( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';
			$id    = $args['section'] . '[' . $args['id'] . ']';
			$label = isset( $args['options']['button_label'] ) ? $args['options']['button_label'] : 'انتخاب فایل';

			$html = sprintf( '<input type="text" class="%1$s-text wpsa-url" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value );
			$html .= '<input type="button" class="button wpsa-browse" value="' . $label . '" />';
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * @param array $args settings field args
		 */
		function callback_photo( array $args ) {

			$image_url = sanitize_url( $this->get_option( $args ) );

			$defaults = [
				'button' => 'انتخاب لوگو',
			];

			$value = wp_parse_args( $args, $defaults );

			$description = $value['desc'];

			$id = md5( $value['id'] );

			$display = $image_url ? 'inline-block' : 'none';

			?>
			<img id="<?php echo esc_attr( $id ); ?>_image" src="<?php echo esc_url( $image_url ); ?>"
			     style="max-width:50%; display:block; margin-bottom: 10px;"/>

			<a href="#" id="<?php echo esc_attr( $id ); ?>_upload_button" class="button"
			   style="display: <?php echo strlen( $image_url ) ? 'none' : 'inherit'; ?>">
				<?php echo esc_attr( $value['button'] ); ?>
			</a>

			<input type="hidden"
			       name="<?php echo esc_attr( $value['section'] ); ?>[<?php echo esc_attr( $value['id'] ); ?>]"
			       id="<?php echo esc_attr( $value['section'] ); ?>[<?php echo esc_attr( $value['id'] ); ?>]"
			       value="<?php echo esc_url( $image_url ); ?>"/>

			<a href="#" id="<?php echo esc_attr( $id ); ?>_remove_button"
			   style="display: <?php echo esc_attr( $display ); ?>">حذف تصویر</a>

			<?php echo $this->esc_html( $value['suffix'] ?? '' ); ?>
			<?php echo $this->esc_html( $description ); // WPCS: XSS ok. ?>

			<script>
                jQuery(function ($) {

                    $('body').on('click', '#<?php echo esc_js( $id ); ?>_upload_button', function (e) {
                        e.preventDefault();

                        let button = $(this),
                            custom_uploader = wp.media({
                                title: 'درج فایل',
                                library: {
                                    type: 'image'
                                },
                                button: {
                                    text: 'انتخاب' // button label text
                                },
                                multiple: false // for multiple image selection set to true
                            }).on('select', function () { // it also has "open" and "close" events
                                let attachment = custom_uploader.state().get('selection').first().toJSON();
                                $('#<?php echo esc_js( $id ); ?>_image').attr('src', attachment.url).next().hide().next().val(attachment.url).next().show();
                            })
                                .open();
                    });

                    $('body').on('click', '#<?php echo esc_js( $id ); ?>_remove_button', function () {
                        $(this).hide().prev().val('').prev().show().prev().attr('src', '');
                        return false;
                    });

                });
			</script>
			<?php
		}

		/**
		 * Displays a password field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_password( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';

			$html = sprintf( '<input type="password" class="%1$s-text" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value );
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Displays a color picker field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_color( array $args ) {

			$value = sanitize_text_field( $this->get_option( $args ) );
			$size  = isset( $args['size'] ) && ! is_null( $args['size'] ) ? $args['size'] : 'regular';

			$html = sprintf( '<input type="text" class="%1$s-text wp-color-picker-field" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s" data-default-color="%5$s" />', $size, $args['section'], $args['id'], $value, $args['std'] );
			$html .= $this->get_field_description( $args );

			echo $this->esc_html( $html );
		}

		/**
		 * Sanitize callback for Settings API
		 */
		function sanitize_options( $options ) {

			if ( ! is_array( $options ) ) {
				return $options;
			}

			foreach ( $options as $option_slug => $option_value ) {
				$sanitize_callback = $this->get_sanitize_callback( $option_slug );

				// If callback is set, call it
				if ( $sanitize_callback ) {
					$options[ $option_slug ] = call_user_func( $sanitize_callback, $option_value );
				}
			}

			return $options;
		}

		/**
		 * Get sanitization callback for given option slug
		 *
		 * @param string $slug option slug
		 *
		 * @return mixed string or bool false
		 */
		function get_sanitize_callback( $slug = '' ) {
			if ( empty( $slug ) ) {
				return false;
			}

			// Iterate over registered fields and see if we can find proper callback
			foreach ( $this->get_fields() as $section => $options ) {
				foreach ( array_filter( $options ) as $option ) {
					if ( $option['id'] != $slug ) {
						continue;
					}

					// Return the callback name
					return isset( $option['sanitize_callback'] ) && is_callable( $option['sanitize_callback'] ) ? $option['sanitize_callback'] : false;
				}
			}

			return false;
		}

		/**
		 * Get the value of a settings field
		 *
		 * @param array $args
		 *
		 * @return string
		 */
		function get_option( array $args ) {

			if ( array_key_exists( 'value', $args ) ) {
				return $args['value'];
			}

			$option  = $args['id'];
			$section = $args['section'];
			$default = $args['std'];

			$options = get_option( $section );

			if ( isset( $options[ $option ] ) ) {
				return $options[ $option ];
			}

			return $default;
		}

		/**
		 * Show navigations as tab
		 *
		 * Shows all the settings section labels as tab
		 */
		function show_navigation() {

			if ( count( $this->get_sections() ) == 1 ) {
				return false;
			}

			$tab = strval( $_GET['tab'] ?? '' );

			$html = '<h2 class="nav-tab-wrapper">';

			foreach ( $this->get_sections() as $section ) {

				if ( isset( $section['callback'] ) ) {
					$url   = add_query_arg( 'tab', $section['id'] );
					$class = ' nav-tab ';

					if ( $tab == $section['id'] ) {
						$class .= 'nav-tab-active';
					}

					$html .= sprintf( '<a href="%s" class="%s" id="%s-tab">%s</a>', $url, $class, $section['id'], $section['title'] );
				} elseif ( $tab ) {
					$html .= sprintf( '<a href="%1$s#%2$s" class="inside nav-tab" id="%2$s-tab">%3$s</a>', remove_query_arg( 'tab' ), $section['id'], $section['title'] );
				} else {
					$html .= sprintf( '<a href="#%1$s" class="inside nav-tab" id="%1$s-tab">%2$s</a>', $section['id'], $section['title'] );
				}

			}

			$html .= '</h2>';

			echo $this->esc_html( $html );
		}

		/**
		 * Show the section settings forms
		 *
		 * This function displays every sections in a different form
		 */
		function show_forms() {
			do_action( 'nabik_settings_top_form' );

			$tab = strval( $_GET['tab'] ?? '' );

			$style = $tab ? '' : 'display: none';

			?>
			<div class="metabox-holder">
				<?php foreach ( $this->get_sections() as $section ) {

					if ( $tab && $tab != $section['id'] ) {
						continue; // Prevent tabbing
					} elseif ( ! $tab && isset( $section['callback'] ) ) {
						continue; // Prevent load callbacks
					}

					?>
					<div id="<?php echo esc_attr( $section['id'] ); ?>" class="group"
					     style="<?php echo esc_attr( $style ); ?>">
						<form method="post" action="options.php">
							<?php
							settings_fields( $section['id'] );
							do_settings_sections( $section['id'] );

							if ( ! isset( $_GET['tab'] ) ) {
								?>
								<div style="padding-left: 10px">
									<?php submit_button(); ?>
								</div>
							<?php } ?>
						</form>
					</div>
				<?php } ?>
			</div>
			<?php
			do_action( 'nabik_settings_bottom_form' );

			if ( empty( $tab ) ) {
				$this->tab_scripts();
			}

			$this->fields_scripts();
			$this->styles();
		}

		function tab_scripts() {
			?>
			<script>
                jQuery(document).ready(function ($) {

                    // Switches option sections
                    $('.group').hide();
                    let active_tab = '';

                    if (new URL(document.URL).hash) {
                        active_tab = new URL(document.URL).hash;

                        if (typeof (localStorage) != 'undefined') {
                            localStorage.setItem('active_tab', active_tab);
                        }

                    } else if (typeof (localStorage) != 'undefined' && localStorage.getItem('active_tab')) {
                        active_tab = localStorage.getItem('active_tab');
                    }

                    if (active_tab.indexOf('#') === 0 && $(active_tab).length) {
                        $(active_tab).fadeIn();
                    } else {
                        $('.group:first').fadeIn();
                    }

                    $('.group .collapsed').each(function () {
                        $(this).find('input:checked').parent().parent().parent().nextAll().each(
                            function () {
                                if ($(this).hasClass('last')) {
                                    $(this).removeClass('hidden');
                                    return false;
                                }
                                $(this).filter('.hidden').removeClass('hidden');
                            });
                    });

                    if (active_tab.indexOf('#') === 0 && $(active_tab + '-tab').length) {
                        $(active_tab + '-tab').addClass('nav-tab-active');
                    } else {
                        $('.nav-tab-wrapper a:first').addClass('nav-tab-active');
                    }

                    $('.nav-tab-wrapper a.inside').click(function (evt) {
                        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                        $(this).addClass('nav-tab-active').blur();
                        let clicked_group = $(this).attr('href');

                        if (typeof (localStorage) != 'undefined') {
                            localStorage.setItem('active_tab', $(this).attr('href'));
                        }

                        $('.group').hide();
                        $(clicked_group).fadeIn();
                        evt.preventDefault();
                    });
                });
			</script>
			<?php
		}

		function fields_scripts() {
			?>
			<script>
                jQuery(document).ready(function ($) {

                    $('.select2').each((index, element) => {
                        let select2_args = {
                            placeholder: $(element).attr('data-placeholder') || $(element).attr('placeholder') || '',
                        };

                        $(element).select2(select2_args);
                    });

                    //Initiate Color Picker
                    $('.wp-color-picker-field').wpColorPicker();

                    $('.wpsa-browse').on('click', function (event) {
                        event.preventDefault();

                        var self = $(this);

                        // Create the media frame.
                        var file_frame = wp.media.frames.file_frame = wp.media({
                            title: self.data('uploader_title'),
                            button: {
                                text: self.data('uploader_button_text'),
                            },
                            multiple: false
                        });

                        file_frame.on('select', function () {
                            attachment = file_frame.state().get('selection').first().toJSON();

                            self.prev('.wpsa-url').val(attachment.url);
                        });

                        // Finally, open the modal
                        file_frame.open();
                    });

                });
			</script>
			<?php
		}

		public function esc_html( string $input ): string {

			$allowed_tags = [
				'input',
				'textarea',
				'p',
				'div',
				'span',
				'select',
				'option',
				'ul',
				'ol',
				'a',
				'b',
				'i',
				'img',
				'li',
				'fieldset',
				'label',
				'hr',
				'br',
				'h2',
				'form',
			];

			$allowed_attributes = [
				'src',
				'href',
				'style',
				'class',
				'title',
				'alt',
				'type',
				'value',
				'name',
				'id',
				'placeholder',
				'rows',
				'cols',
				'target',
				'for',
				'selected',
				'checked',
				'readonly',
				'disabled',
				'multiple',
			];

			$allowed_attributes = array_combine( $allowed_attributes, array_pad( [], count( $allowed_attributes ), true ) );

			$allowed_html = [];

			foreach ( $allowed_tags as $tag ) {
				$allowed_html[ $tag ] = $allowed_attributes;
			}

			return wp_kses( $input, $allowed_html );
		}

		public function styles() {
			?>
			<style>
                .metabox-holder form > h2 {
                    display: none;
                }
			</style>
			<?php
		}

	}

}