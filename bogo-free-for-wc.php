<?php
/**
 * Plugin Name: BOGO Free for WC
 * Description: Automatically adds one or more free products to the cart when a specific product or category is purchased.
 * Version: 1.0
 * Author: Andrea Capretti
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bogo-free-for-wc
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add the admin settings page
function bogo_add_admin_menu() {
    add_menu_page(
        'BOGO Free for WC',
        'BOGO Free for WC',
        'manage_options',
        'bogo_free_for_wc_settings',
        'bogo_settings_page'
    );
}
add_action( 'admin_menu', 'bogo_add_admin_menu' );

// Display the settings page
function bogo_settings_page() {
    // Check user permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Check nonce for security
    if ( isset( $_POST['bogo_settings_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bogo_settings_nonce'] ) ), 'bogo_save_settings' ) ) {
        die( 'Security check failed.' );
    }

    // Save settings if the form is submitted
    if ( isset( $_POST['bogo_target_product_ids'], $_POST['bogo_free_product_ids'], $_POST['bogo_target_categories'] ) ) {
        // Sanitize and unslash input
        $target_ids = sanitize_text_field( wp_unslash( $_POST['bogo_target_product_ids'] ) );
        $free_product_ids = sanitize_text_field( wp_unslash( $_POST['bogo_free_product_ids'] ) );
        $target_categories = array_map( 'intval', wp_unslash( $_POST['bogo_target_categories'] ) );

        // Update plugin options
        update_option( 'bogo_target_product_ids', $target_ids );
        update_option( 'bogo_free_product_ids', $free_product_ids );
        update_option( 'bogo_target_categories', $target_categories );

        echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'bogo-free-for-wc') . '</p></div>';
    }

    // Retrieve plugin options
    $target_product_ids = esc_attr( get_option( 'bogo_target_product_ids' ) );
    $free_product_ids = esc_attr( get_option( 'bogo_free_product_ids' ) );
    $selected_categories = get_option( 'bogo_target_categories', array() );

    // Get available categories
    $categories = get_terms( array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ) );

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('BOGO Free for WC Settings', 'bogo-free-for-wc'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'bogo_save_settings', 'bogo_settings_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Target Product IDs (comma-separated)', 'bogo-free-for-wc'); ?></th>
                    <td><input type="text" name="bogo_target_product_ids" value="<?php echo esc_attr( $target_product_ids ); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Target Categories', 'bogo-free-for-wc'); ?></th>
                    <td>
                        <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
                            <?php foreach ( $categories as $category ) : ?>
                                <label>
                                    <input type="checkbox" name="bogo_target_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php echo in_array( $category->term_id, $selected_categories ) ? 'checked' : ''; ?>>
                                    <?php echo esc_html( $category->name ); ?>
                                </label><br>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p><?php echo esc_html__('No categories available', 'bogo-free-for-wc'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Free Product IDs (comma-separated)', 'bogo-free-for-wc'); ?></th>
                    <td><input type="text" name="bogo_free_product_ids" value="<?php echo esc_attr( $free_product_ids ); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register plugin settings
function bogo_register_settings() {
    register_setting( 'bogo_settings_group', 'bogo_target_product_ids' );
    register_setting( 'bogo_settings_group', 'bogo_free_product_ids' );
    register_setting( 'bogo_settings_group', 'bogo_target_categories' );
}
add_action( 'admin_init', 'bogo_register_settings' );

// Uninstall function to remove plugin options
function bogo_uninstall() {
    delete_option( 'bogo_target_product_ids' );
    delete_option( 'bogo_free_product_ids' );
    delete_option( 'bogo_target_categories' );
}
register_uninstall_hook( __FILE__, 'bogo_uninstall' );

// Add or remove free products in the cart
function bogo_add_or_remove_free_products( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    $target_ids = get_option( 'bogo_target_product_ids' );
    $free_product_ids = get_option( 'bogo_free_product_ids' );
    $target_categories = get_option( 'bogo_target_categories', array() );

    if ( ( ! $target_ids && empty( $target_categories ) ) || ! $free_product_ids ) {
        return;
    }

    $add_free = false;
    $target_ids_array = array_map( 'intval', explode( ',', $target_ids ) );
    $free_product_ids_array = array_map( 'intval', explode( ',', $free_product_ids ) );

    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
        $product_id = $cart_item['product_id'];

        if ( in_array( $product_id, $target_ids_array ) ) {
            $add_free = true;
            break;
        }

        $product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( array_intersect( $target_categories, $product_categories ) ) {
            $add_free = true;
            break;
        }
    }

    foreach ( $free_product_ids_array as $free_product_id ) {
        $free_present = false;
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( $cart_item['product_id'] == $free_product_id ) {
                $free_present = true;
                if ( ! $add_free ) {
                    $cart->remove_cart_item( $cart_item_key );
                }
            }
        }

        if ( $add_free && ! $free_present ) {
            $cart->add_to_cart( $free_product_id, 1, '', array(), array( 'free_gift' => true ) );
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'bogo_add_or_remove_free_products', 10, 1 );

// Set the free products' price to zero
function bogo_set_free_product_price( $cart_object ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    foreach ( $cart_object->get_cart() as $cart_item ) {
        if ( isset( $cart_item['free_gift'] ) && $cart_item['free_gift'] === true ) {
            $cart_item['data']->set_price( 0 );
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'bogo_set_free_product_price', 20, 1 );
