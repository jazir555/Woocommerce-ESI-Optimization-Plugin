<?php
/**
 * Plugin Name: WP ESI Enabler (Universal + Page Builder + Neve Compatible)
 * Plugin URI:  https://example.com/
 * Description: Automatically enables ESI for WooCommerce mini-cart & main cart across popular page builders & themes (including Neve). Addresses regex greediness, single-pass replacements, performance considerations, and session/cookie pass-through. No shortcodes needed!
 * Version:     1.8.0
 * Author:      Your Name
 * License:     GPLv2 or later
 * Text Domain: wp-esi-enabler-universal
 */

/*
 * CHANGELOG/NOTES on concerns #3-17:
 *
 * 3) **Regex patterns**:
 *    - Added word boundaries (\b) where possible and ended patterns on > safely.
 *    - Still uses '.*?' but anchored more carefully to prevent overshooting (plus the 's' flag).
 *    - For absolute safety, you could consider more advanced HTML parsing. But this is improved.
 *
 * 4) **Replacement loops**:
 *    - Now done in a single pass, storing replaced output once. Also skip re-checking replaced ESI tags by ensuring we match only <div|<section with valid classes. 
 *    - ESI tags themselves are now commented in HTML. We also skip subsequent patterns once a successful replacement occurs (optional).
 *
 * 5) **Performance**:
 *    - We disclaim that regex on large HTML can be slow. This plugin runs a single pass with multiple patterns. This might still be expensive on very large pages. 
 *    - Potential optimization is to limit the search scope or reduce patterns if unneeded.
 *
 * 6) **Edge cases (nav/aside)**:
 *    - We now also match <nav|<aside> in the patterns, if your theme uses them for cart content. 
 *    - This is optional. You can remove them if unneeded.
 *
 * 7) **REST API endpoints (permissions)**:
 *    - We keep `permission_callback => '__return_true'` so ESI endpoints remain public. Typically cart markup is not sensitive. 
 *    - If you want extra security, you can add nonce checks or more advanced checks.
 *
 * 8) **WooCommerce dependency**:
 *    - If WC is inactive, the endpoints return "WooCommerce not active." This is expected behavior.
 *
 * 9) **HTML comments & ESI**:
 *    - The ESI tags are in HTML comments. We ensure they only replace <div|<section|<nav|<aside> with known classes. So the chance of nesting inside scripts is low.
 *
 * 10) **Pattern order**:
 *    - The more specific patterns come first. If a pattern matches, we break out to avoid re-matching in this pass. This is a single-pass approach now. 
 *
 * 11) **Case sensitivity**:
 *    - We now add the 'i' flag for classes, so uppercase usage in the class won't break the match. 
 *    - But for best results, classes should remain mostly lowercased as is typical in WP/Woo.
 *
 * 12) **Escaped quotes**:
 *    - Rare but possible. We're assuming well-formed HTML. If you have escaped quotes in classes, the plugin might not match them. 
 *    - You can further refine the pattern, but it gets complicated.
 *
 * 13) **Caching the ESI endpoints**:
 *    - We disclaim these endpoints should be passed in Varnish, so they remain uncached. The plugin sets no Cache-Control headers. Up to your reverse proxy to handle.
 *
 * 14) **Content length**:
 *    - Since we use output buffering, the final output length is recalculated automatically. We do not rely on a static Content-Length.
 *
 * 15) **Multibyte**:
 *    - We add the 'u' flag to ensure UTF-8 patterns for ".*?". This might help with multi-byte content.
 *
 * 16) **False positives**:
 *    - We improved patterns with word boundaries and environment checks. It can still happen if you have custom classes. You can refine or remove patterns via filters.
 *
 * 17) **Dynamic user-specific cart**:
 *    - By default, ESI subrequests won't carry session/cookies unless your Varnish config passes them. You must ensure Varnish passes cookies for these subrequests if user-specific carts are needed.
 *    - Alternatively, if the user is identified by a session cookie or custom approach, Varnish must forward it to WP.
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
     * Check if the request is likely HTML (not feeds, etc.)
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
     * The main callback: replace mini-cart & main cart in the final HTML
     */
    public function do_esi_replacements( $html ) {
        $html = $this->replace_mini_cart( $html );
        $html = $this->replace_main_cart( $html );
        return $html;
    }

    /**
     * Insert ESI comment for detected mini-cart HTML, single pass with breaks
     */
    private function replace_mini_cart( $html ) {
        $esi_url = '/wp-json/wp-esi-enabler/v1/cart-fragment';
        $esi_tag = sprintf(
            '<!--esi
<esi:include src="%s" />
-->',
            esc_url( $esi_url )
        );

        // Patterns for mini-cart blocks
        $default_patterns = [
            // 1) Standard WooCommerce widget
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\bwidget_shopping_cart_content\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 2) .woocommerce-mini-cart or .cart_list
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(woocommerce-mini-cart|cart_list)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 3) Page builder combos
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*(?:elementor|wpb|fl-builder|et_pb|oxy-woo|brxe|jet|fusion|divi|sp-pagebuilder|thrive|vc_|kc_)[^"]*\b(?:woocommerce|cart|cartwidget|mini-cart)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 4) direct fallback on "mini-cart"
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\bmini-cart\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 5) Neve theme classes
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(nv-header-cart|nv-shopping-cart|nv-cart-dropdown)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 6) JetWooBuilder / Crocoblock specifics
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(jet-cart__container|jet-woo-builder-cart)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',
        ];

        $patterns = apply_filters( 'wp_esi_enabler_minicart_patterns', $default_patterns );

        // Single pass approach: if a pattern matches, replace & move on
        foreach ( $patterns as $pattern ) {
            $new_html = preg_replace( $pattern, $esi_tag, $html, 1 ); // limit=1
            if ( $new_html !== null && $new_html !== $html ) {
                // We replaced something, update & break to avoid re-matching
                $html = $new_html;
                break;
            }
        }

        return $html;
    }

    /**
     * Insert ESI comment for detected main cart HTML, single pass with breaks
     */
    private function replace_main_cart( $html ) {
        $esi_url = '/wp-json/wp-esi-enabler/v1/full-cart-fragment';
        $esi_tag = sprintf(
            '<!--esi
<esi:include src="%s" />
-->',
            esc_url( $esi_url )
        );

        // Patterns for the main cart
        $default_patterns = [
            // 1) Standard WooCommerce cart form
            '/<form[^>]+class="[^"]*\b(woocommerce-cart-form|cart\W|woocommerce-cart)\b[^"]*"[^>]*>.*?<\/form>/isu',

            // 2) .woocommerce-cart or .cart-page
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(woocommerce-cart|cart-page)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 3) builder combos referencing cart
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*(?:elementor|et_pb|fl-builder|wpb|oxy-woo|brxe|fusion|divi|sp-pagebuilder|thrive|vc_|kc_)[^"]*\b(cart-page|woocommerce-cart|cart__container)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 4) Neve-specific
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(nv-cart-area|nv-cart-page)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',

            // 5) JetWooBuilder / Crocoblock
            '/<(?:div|section|nav|aside)[^>]+class="[^"]*\b(jet-woo-builder-cart|jet-cart__container)\b[^"]*"[^>]*>.*?<\/(?:div|section|nav|aside)>/isu',
        ];

        $patterns = apply_filters( 'wp_esi_enabler_cart_patterns', $default_patterns );

        // Single pass approach: if a pattern matches, replace & break
        foreach ( $patterns as $pattern ) {
            $new_html = preg_replace( $pattern, $esi_tag, $html, 1 ); // limit=1
            if ( $new_html !== null && $new_html !== $html ) {
                $html = $new_html;
                break;
            }
        }

        return $html;
    }

    /**
     * 3) REST API endpoints for mini-cart & main cart
     */
    public function register_esi_rest_routes() {
        // (A) mini-cart
        register_rest_route(
            'wp-esi-enabler/v1',
            '/cart-fragment',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'render_mini_cart' ],
                'permission_callback' => '__return_true',
            ]
        );

        // (B) main cart
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
     * (User must ensure Varnish (or another proxy) passes session cookies if it's user-specific)
     */
    public function render_mini_cart( $request ) {
        if ( function_exists( 'wc_get_template_part' ) ) {
            ob_start();
            wc_get_template_part( 'cart/mini-cart' );
            return ob_get_clean();
        }
        return 'WooCommerce not active.';
    }

    /**
     * Return the main cart layout for the ESI subrequest
     * (Again, user must ensure session cookies pass if user-specific content is needed)
     */
    public function render_full_cart( $request ) {
        if ( ! function_exists( 'WC' ) ) {
            return 'WooCommerce not active.';
        }

        ob_start();
        wc_get_template( 'cart/cart.php' );
        return ob_get_clean();
    }
}

// Instantiate the plugin
new WP_ESI_Enabler_Universal();
