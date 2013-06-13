<?php
/*
 * Plugin Name: Text Mode Buttons Editor
 * Plugin URI:
 * Description:
 * Version:
 * Author: Kailey Lampert
 * Author URI: kaileylampert.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * TextDomain: tmbe
 * DomainPath:
 * Network:
 */

$text_mode_buttons_editor = new Text_Mode_Buttons_Editor();

class Text_Mode_Buttons_Editor {

	var $page_name;
	var $td = 'tmbe'; // text domain

	function __construct() {
		add_action( 'admin_footer-post.php', array( &$this, 'post_js' ) );
		add_action( 'admin_footer-post-new.php', array( &$this, 'post_js' ) );
		add_action( 'admin_init', array( &$this, 'init' ) );
		add_action( 'admin_menu', array( &$this, 'menu' ) );
		add_filter( 'contextual_help', array( &$this, 'contextual_help' ), 10, 3 );
		add_action( 'wp_ajax_tmbe_get_new_row', array( &$this, 'get_new_row_cb' ) );
	}

	function post_js( $hook ) {
		global $hook_suffix;
		if ( 'post.php' != $hook_suffix && 'post-new.php' != $hook_suffix ) return;

		?><script type="text/javascript">
		<?php
			$buttons = get_option( 'tmbe-buttons-add', array() );
			foreach ($buttons as $id => $deets ) {
				$a = array_map( array( $this, '_quote_wrap' ), $deets );
				$v = implode( ', ', $a );
				echo "QTags.addButton('tmbe_$id', $v);\n";
			}
			echo "var peb_remove_buttons = ". json_encode( get_option( 'tmbe-buttons-remove', array() ) ) .";";
		?>
		jQuery(document).ready( function($) {
			// change the edButtons global js var
			edButtons = _.filter( edButtons, function( val ) {
				// if the button id does not exists in peb_remove_buttons, it should be kept (true)
				if ( _.indexOf( peb_remove_buttons, val.id ) == -1 ) {
					return true;
				}
				return false;
			});
		});
		</script>
		<?php
	}
	function _quote_wrap( $input ) {
		$input = addslashes( $input );
		return "'$input'";
	}

	function _delete_peb() {
		delete_option( 'peb_caption' );
		delete_option( 'peb_before' );
		delete_option( 'peb_after' );
		delete_option( 'peb_remove' );
	}
	function _import_peb( $type, $peb_buttons ) {
		if ( $type != 'add' && $type != 'remove' ) return;

		$existing = get_option( 'tmbe-buttons-'.$type );
		$buttons = array_merge( $existing, $peb_buttons );
		update_option( 'tmbe-buttons-'.$type, $buttons );
	}

	function init() {
		if ( isset( $_POST['tmbe-review'] ) ) {
			delete_option( 'tmbe-ignore-import' );
		}
		if ( isset( $_POST['tmbe-import-peb'] ) ) {
			$new_buttons = $this->sanitize_add( $_POST['tmbe-buttons-add-import'] );
			$del_buttons = $_POST['tmbe-buttons-remove-import'];
			if ( isset( $_POST['tmbe-import'] ) ) { // import and delete
				$this->_import_peb( 'add', $new_buttons );
				$this->_import_peb( 'remove', $del_buttons );
				$this->_delete_peb();
			} elseif ( isset( $_POST['tmbe-import2'] ) ) { // import and keep
				$this->_import_peb( 'add', $new_buttons );
				$this->_import_peb( 'remove', $del_buttons );
			} elseif ( isset( $_POST['tmbe-ignore'] ) ) { // ignore (make less big)
				update_option( 'tmbe-ignore-import', true );
			} elseif ( isset( $_POST['tmbe-delete'] ) ) { // just delete
				$this->_delete_peb();
			}
		}

		register_setting( 'tmbe-group', 'tmbe-buttons-add', array( &$this, 'sanitize_add' ) );
		register_setting( 'tmbe-group', 'tmbe-buttons-remove', array( &$this, 'sanitize_remove' ) );

		add_settings_section( 'tmbe-section-add', __( 'Add New Buttons', $this->td ), function() { echo ''; }, $this->page_name );
		// $new_btn = get_submit_button( __( 'Add', $this->td ), 'small', 'tmbe-add', false );
		$new_btn = '';
		add_settings_field( 'tmbe-image-row', __( 'Buttons:', $this->td ) . "<br />$new_btn", array( &$this, 'field' ), $this->page_name, 'tmbe-section-add', get_option( 'tmbe-buttons-add', false ) );

		add_settings_section( 'tmbe-section-remove', __( 'Remove Buttons', $this->td ), function() { echo ''; }, $this->page_name );
		add_settings_field( 'tmbe-image-row', __( '', $this->td ), array( &$this, 'field_remove' ), $this->page_name, 'tmbe-section-remove', get_option( 'tmbe-buttons-remove', false ) );
	}

	function sanitize_add( $input ) {
		$newinput = array();
		foreach( $input as $k => $deets ) {

			extract( array_map( 'trim', $deets ) );

			if ( empty( $caption ) || empty( $before ) ) {
				unset( $input[ $k ] ); continue;
			}

			$caption = sanitize_title( $caption );
			$before = stripslashes( wp_filter_post_kses( $before ) );
			$after = stripslashes( wp_filter_post_kses( $after ) );


			unset( $input[ $k ] );
			$newinput[ $caption ] = compact( 'caption', 'before', 'after' );
		}

		return $newinput;
	}

	function sanitize_remove( $input ) {
		return $input;
	}
	function field( $args ) {
		// saved fields
		// print_r( $args );
		if ( $args != false ) foreach( $args as $id => $params ) {
			echo $this->__fields( $params, $id );
		}
		// empty set for new
		echo $this->__fields();

		echo '<span id="tmbe-row-flag"></span>'; //helps with inserting new row via js

		// change type to 'button' so [enter] from an input doesn't create a new row
		$btn = str_replace( 'type="submit"', 'type="button"', get_submit_button( __( 'Add', $this->td ), 'small', 'tmbe-add', false ) );
		echo $btn;
	}

	function __fields( $values=array(), $key_id='blah' ) {
		$defaults = array( 'caption' => '', 'before' => '', 'after' => '');
		$values = wp_parse_args( $values, $defaults );

		$values = array_map( 'stripslashes', $values );
		$values = array_map( 'esc_attr', $values );

		$html = "<p class='tmbe-field-row'>".
				"<input type='text' name='tmbe-buttons-add[{$key_id}][caption]' value='{$values['caption']}' placeholder='caption' />".
				"<input type='text' name='tmbe-buttons-add[{$key_id}][before]' value='{$values['before']}' placeholder='before' />".
				"<input type='text' name='tmbe-buttons-add[{$key_id}][after]' value='{$values['after']}' placeholder='after' />".
				" <a href='#' class='hide-if-no-js tmbe-delete'>". __( 'Delete', $this->td ) . "</a>".
				"</p>";
		return $html;
	}

	function field_remove( $args ) {
		// print_r( $args );

		$core_buttons = array( 'strong', 'em', 'link', 'block', 'del', 'ins', 'img', 'ul', 'ol', 'li', 'code', 'more', 'spell', 'close' );
		echo '<input type="hidden" name="tmbe-buttons-remove[]" value="" />';
		foreach( $core_buttons as $btn ) {
			$c = in_array( $btn, (array) $args ) ? ' checked="checked"' : '';
			echo "<label style='width:31%; margin: 0 1%; float:left;'><input type='checkbox' name='tmbe-buttons-remove[]' value='$btn'$c /> $btn</label>";
		}

	}

	function menu() {
		$this->page_name = add_options_page( __( 'Editor Buttons', $this->td ), __( 'Editor Buttons', $this->td ), 'edit_posts', __CLASS__, array( &$this, 'page' ) );
	}

	function page() {
		add_action( 'admin_footer', array( &$this, 'admin_footer' ) );
		?><div class="wrap">
		<h2><?php _e( 'Text Mode Buttons Editor', $this->td ); ?></h2>

		<?php if ( count( get_option( 'peb_caption', array() ) ) > 0 ) {
			if ( ! get_option( 'tmbe-ignore-import', false ) ) { ?>
			<form method="post">
			<h3><?php _e( 'Import from Post Editor Buttons (Fork)', $this->td ); ?></h3>
			<p><?php _e( 'Looks like you have some buttons from Post Editor Buttons, would you like to import?', $this->td ); ?></p>
			<?php
				$caption = get_option('peb_caption', array() );
				$before = get_option('peb_before', array() );
				$after = get_option('peb_after', array() );
				echo '<table>';
				for ( $i = 0; $i < count( $caption ); $i++ ) {
					$uid = uniqid() . $i;
					?><tr>
					<td><input type="text" readonly name="tmbe-buttons-add-import[<?php echo $uid; ?>][caption]" value="<?php echo esc_attr( stripslashes( $caption[ $i ] ) ); ?>" /></td>
					<td><input type="text" readonly name="tmbe-buttons-add-import[<?php echo $uid; ?>][before]" value="<?php echo esc_attr( stripslashes( $before[ $i ] ) ); ?>" /></td>
					<td><input type="text" readonly name="tmbe-buttons-add-import[<?php echo $uid; ?>][after]" value="<?php echo esc_attr( stripslashes( $after[ $i ] ) ); ?>" /></td>
					</tr><?php
				}
				echo '<tr><td colspan="3">';
				$rem = array_filter( get_option( 'peb_remove', array() ) );
				foreach( $rem as $r ) {
					echo "<input type='hidden' name='tmbe-buttons-remove-import[]' value='$r' />";
				}
				echo __( 'Remove: ', $this->td ) . implode( ', ', $rem );
				echo '</td></tr>';
				echo '</table>';

				echo '<p><input type="hidden" name="tmbe-import-peb" />';
				submit_button( __( 'Import &amp; Delete', $this->td ), 'primary', 'tmbe-import', false );
				echo ' ';
				submit_button( __( 'Import &amp; Keep', $this->td ), 'secondary', 'tmbe-import2', false );
				echo ' ';
				submit_button( __( 'Ignore', $this->td ), 'secondary', 'tmbe-ignore', false );
				echo ' ';
				submit_button( __( 'Delete', $this->td ), 'small', 'tmbe-delete', false );
				echo '</p>';
			?>
			</form>
		<?php } else { ?>
			<form method="post">
			<p>Looks like you have some buttons from Post Editor Buttons, would you like to import?
			<?php submit_button( __('Review', $this->td ), 'small', 'tmbe-review', false ); ?></p>
			</form>
		<?php }
		} ?>

		<form method="post" action="options.php">
		<?php
			settings_fields( 'tmbe-group' );
			do_settings_sections( $this->page_name );
			echo '<p>';
			submit_button( __( 'Save', $this->td ), 'primary', 'tmbe-submit', false );
			echo ' ';
			// submit_button( __( 'Add', $this->td ), 'small', 'tmbe-add', false );
			echo '</p>';
		?>
		</form>
		</div><?php
	}

	function admin_footer() {
		?><script>
		jQuery(document).ready(function($){

			$('.wrap').on( 'click', '#tmbe-add', function(ev) {
				ev.preventDefault();
				console.log( ev );
				$.post( ajaxurl, {
					action: 'tmbe_get_new_row',
					nonce: '<?php echo wp_create_nonce('tmbe-new-row'); ?>'
				}, function( resp ) {
					if ( resp == '-1' )
						alert( '<?php _e( 'Not Allowed' ); ?>' );
					else
						$('#tmbe-row-flag').before( resp );
				});
			});
			$('.wrap').on( 'click', '.tmbe-delete', function(ev) {
				ev.preventDefault();
				$(this).closest( '.tmbe-field-row').fadeOut( function() {
					$(this).remove();
				})
			});
		});
		</script><?php
	}

	function contextual_help( $old, $id, $object ) {
		if ( $id != $this->page_name ) return $old;
		$help_text = '';
		$help_text .= '<p>'. __( 'Captions must be unique', $this->td ) .'</p>';
		$object->add_help_tab( array(
			'id' => 'tmbe-help',
			'title' => __( 'Overview', $this->td ),
			'content' => $help_text
		) );
	}

	function get_new_row_cb() {
		if ( check_ajax_referer( 'tmbe-new-row', 'nonce' ) )
			die( $this->__fields( array(), uniqid() ) );
	}

}

//eof