<?php
/**
 * Plugin Name: WP ESI Enabler (Universal + Page Builder + Neve Compatible)
 * Plugin URI:  https://example.com/
 * Description: Automatically enables ESI for both the WooCommerce mini-cart and main cart, with extra Neve theme compatibility. Supports many page builders as well. No shortcodes needed!
 * Version:     1.8.0
 * Author:      Your Name
 * License:     GPLv2 or later
 * Text Domain: wp-esi-enabler-universal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WP_ESI_Enabler_Universal {

    public function __construct() {
        // 1. Add Surrogate-Control for non-admin HTML
        add_action( 'send_headers', [ $this, 'add_esi_header' ] );

        // 2. Output buffering: intercept final HTML to insert ESI tags
        add_action( 'template_redirect', [ $this, 'start_ob_replacement' ], 0 );

        // 3. REST API routes for mini-cart & main cart fragments
        add_action( 'rest_api_init', [ $this, 'register_esi_rest_routes' ] );
    }

    /**
     * 1) Add 'Surrogate-Control: ESI/1.0' for front-end HTML
     */
    public function add_esi_header() {
        if ( ! is_admin() && ! headers_sent() && $this->is_html_request() ) {
            header( 'Surrogate-Control: ESI/1.0' );
        }
    }

    /**
     * Determines if the request is likely HTML (not feeds, etc.)
     */
    private function is_html_request() {
        return ! is_feed();
    }

    /**
     * 2) Start buffering output to detect & replace cart blocks
     */
    public function start_ob_replacement() {
        if ( ! is_admin() ) {
            ob_start( [ $this, 'do_esi_replacements' ] );
        }
    }

    /**
     * The main callback: replaces mini-cart & main cart in the final HTML
     */
    public function do_esi_replacements( $html ) {
        $html = $this->replace_mini_cart( $html );
        $html = $this->replace_main_cart( $html );
        return $html;
    }

    /**
     * Helper function: Insert ESI comment for detected mini-cart HTML
     */
    private function replace_mini_cart( $html ) {
        $esi_url = '/wp-json/wp-esi-enabler/v1/cart-fragment';
        $esi_tag = sprintf(
            '<!--esi
<esi:include src="%s" />
-->',
            esc_url( $esi_url )
        );

        /**
         * Patterns for mini-cart blocks, including references to:
         * - Common WooCommerce classes
         * - Multiple page builders
         * - Neve theme classes (nv-header-cart, nv-shopping-cart, nv-cart-dropdown)
         * - JetWooBuilder, Crocoblock, etc.
         */
        $default_patterns = [
            // 1) Standard WooCommerce widget sidebar mini-cart
            '/<div[^>]+class="[^"]*widget_shopping_cart_content[^"]*"[^>]*>.*?<\/div>/is',

            // 2) .woocommerce-mini-cart or .cart_list
            '/<(?:section|div)[^>]+class="[^"]*(woocommerce-mini-cart|cart_list)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 3) Page builder combos (Elementor, Divi, Beaver, WPBakery, Oxygen, Bricks, Avada, etc.)
            //    with references to cart, cartwidget, or mini-cart
            '/<(?:section|div)[^>]+class="[^"]*(elementor|wpb|fl-builder|et_pb|oxy-woo|brxe|jet|fusion|divi|sp-pagebuilder|thrive|vc_|kc_)[^"]*(woocommerce|cart|cartwidget|mini-cart)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 4) Direct fallback: anything with "mini-cart" in class name
            '/<(?:section|div)[^>]+class="[^"]*mini-cart[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 5) Neve theme classes for mini-cart elements
            '/<(?:section|div)[^>]+class="[^"]*(nv-header-cart|nv-shopping-cart|nv-cart-dropdown)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 6) JetWooBuilder / Crocoblock specifics
            '/<(?:section|div)[^>]+class="[^"]*(jet-cart__container|jet-woo-builder-cart)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',
        ];

        // Filter to allow custom additions/overrides
        $patterns = apply_filters( 'wp_esi_enabler_minicart_patterns', $default_patterns );

        foreach ( $patterns as $pattern ) {
            $new_html = preg_replace( $pattern, $esi_tag, $html );
            if ( $new_html !== null && $new_html !== $html ) {
                $html = $new_html;
            }
        }

        return $html;
    }

    /**
     * Helper function: Insert ESI comment for detected main cart HTML
     */
    private function replace_main_cart( $html ) {
        $esi_url = '/wp-json/wp-esi-enabler/v1/full-cart-fragment';
        $esi_tag = sprintf(
            '<!--esi
<esi:include src="%s" />
-->',
            esc_url( $esi_url )
        );

        /**
         * Patterns for the main cart form/container, including references to
         * major page builders, typical WooCommerce classes, and Neve cart markup
         */
        $default_patterns = [
            // 1) Standard WooCommerce cart form (.woocommerce-cart-form, .cart..., etc.)
            '/<form[^>]+class="[^"]*(woocommerce-cart-form|cart\W|woocommerce-cart)[^"]*"[^>]*>.*?<\/form>/is',

            // 2) .woocommerce-cart or .cart-page in a <div>/<section>
            '/<(?:section|div)[^>]+class="[^"]*(woocommerce-cart|cart-page)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 3) Additional combos for page builders
            '/<(?:section|div)[^>]+class="[^"]*(elementor|et_pb|fl-builder|wpb|oxy-woo|brxe|fusion|divi|sp-pagebuilder|thrive|vc_|kc_)[^"]*(cart-page|woocommerce-cart|cart__container)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 4) Neve-specific cart container classes (if any)
            '/<(?:section|div)[^>]+class="[^"]*(nv-cart-area|nv-cart-page)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',

            // 5) JetWooBuilder / Crocoblock for the main cart
            '/<(?:section|div)[^>]+class="[^"]*(jet-woo-builder-cart|jet-cart__container)[^"]*"[^>]*>.*?<\/(?:section|div)>/is',
        ];

        // Filter to allow custom additions/overrides
        $patterns = apply_filters( 'wp_esi_enabler_cart_patterns', $default_patterns );

        foreach ( $patterns as $pattern ) {
            $new_html = preg_replace( $pattern, $esi_tag, $html );
            if ( $new_html !== null && $new_html !== $html ) {
                $html = $new_html;
            }
        }

        return $html;
    }

    /**
     * 3) REST API endpoints for mini-cart & main cart fragments
     */
    public function register_esi_rest_routes() {
        // A) Mini-cart
        register_rest_route(
            'wp-esi-enabler/v1',
            '/cart-fragment',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'render_mini_cart' ],
                'permission_callback' => '__return_true',
            ]
        );

        // B) Full cart
        register_rest_route(
            'wp-esi-enabler/v1',
            '/full-cart-fragment',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'render_full_cart' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Return the mini-cart markup for the ESI subrequest
     */
    public function render_mini_cart( $request ) {
        if ( function_exists( 'wc_get_template_part' ) ) {
            ob_start();
            wc_get_template_part( 'cart/mini-cart' ); // Standard mini-cart template
            return ob_get_clean();
        }
        return 'WooCommerce not active.';
    }

    /**
     * Return the main cart layout for the ESI subrequest
     */
    public function render_full_cart( $request ) {
        if ( ! function_exists( 'WC' ) ) {
            return 'WooCommerce not active.';
        }

        ob_start();
        // Load the typical "cart" template from WooCommerce
        wc_get_template( 'cart/cart.php' );
        return ob_get_clean();
    }
}

// Instantiate the plugin
new WP_ESI_Enabler_Universal();
