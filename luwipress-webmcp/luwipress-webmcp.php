<?php
/**
 * Plugin Name: LuwiPress WebMCP
 * Plugin URI: https://luwi.dev/luwipress-webmcp
 * Description: Model Context Protocol (MCP) server for LuwiPress — exposes 140+ REST tools to AI agents (Claude Code, OpenAI, custom clients) via Streamable HTTP transport. Requires the core LuwiPress plugin.
 * Version: 1.0.52
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress-webmcp
 * Requires at least: 5.6
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUWIPRESS_WEBMCP_VERSION', '1.0.52' );
define( 'LUWIPRESS_WEBMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUWIPRESS_WEBMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUWIPRESS_WEBMCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Is the core LuwiPress plugin loaded? The companion piggybacks on core's
 * Permission class, REST route registration, and admin menu parent.
 */
function luwipress_webmcp_core_active() {
	return class_exists( 'LuwiPress' ) && class_exists( 'LuwiPress_Permission' );
}

/**
 * Activation hook. As of 1.0.28 WebMCP activates standalone (without
 * LuwiPress Core) in "Lite mode" — only the categories that don't depend
 * on LuwiPress-owned classes register their tools (system / plugin_theme /
 * settings / menu / meta / taxonomy / comment / media / woo / elementor —
 * the last two are themselves class-gated to WooCommerce / Elementor).
 * The AI-pipeline categories (content / seo / aeo / translation / crm /
 * knowledge_graph / scheduler / etc) silently skip via the existing
 * `class_exists()` gates inside their `register_*_tools` methods, which
 * the audit surface (`webmcp_deploy_audit`) reports explicitly.
 *
 * No more wp_die() — install-time-only friction blocking distribution on
 * WordPress.org (the dependency wasn't usable through the .org plugin
 * directory anyway).
 */
function luwipress_webmcp_activate() {
	// No-op. Lite mode is the new default when Core is absent.
}
register_activation_hook( __FILE__, 'luwipress_webmcp_activate' );

/**
 * Conditional Permission shim. When the core LuwiPress plugin isn't
 * installed, the `LuwiPress_Permission` class doesn't exist — but every
 * MCP route in this companion calls into it. To run standalone we provide
 * a stand-in with the exact same surface (check_token / is_admin /
 * check_token_or_admin / is_token_authenticated) backed by a WebMCP-owned
 * option key (`luwipress_webmcp_api_token`). When Core IS active, the
 * real class loads first and this stub is never declared.
 *
 * @since 1.0.28
 */
function luwipress_webmcp_register_permission_shim() {
	if ( class_exists( 'LuwiPress_Permission' ) ) {
		return; // Core handles it.
	}
	require_once LUWIPRESS_WEBMCP_PLUGIN_DIR . 'includes/class-luwipress-permission-shim.php';
}

/**
 * Soft inline notice on the Plugins screen only — surfaces the "Lite mode"
 * + upgrade-to-Core path in a non-alarming way. Replaces the legacy "WebMCP
 * is paused" warning that fired before standalone mode existed.
 */
function luwipress_webmcp_lite_mode_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<strong><?php esc_html_e( 'LuwiPress WebMCP — Lite mode.', 'luwipress-webmcp' ); ?></strong>
			<?php esc_html_e( 'Running standalone with the WordPress-native toolset (system, plugins, themes, menus, taxonomies, comments, media, WooCommerce + Elementor when present).', 'luwipress-webmcp' ); ?>
			<?php
			printf(
				/* translators: %s: link to LuwiPress core */
				wp_kses_post( __( 'Install <a href="%s">LuwiPress core</a> to unlock the full ~210-tool catalog (AI enrichment, SEO meta, AEO schema, translation pipelines, knowledge graph, marketplace sync, content scheduler).', 'luwipress-webmcp' ) ),
				esc_url( 'https://luwi.dev/luwipress' )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Is the WebMCP companion licensed/entitled on this site?
 *
 * Forward-compatible: against a license-unaware core (or no core at all) this
 * returns true, so behaviour is unchanged everywhere it ships today. Only a
 * license-aware core with enforcement ON can gate WebMCP — and even then only
 * when the active tier excludes `webmcp`. (When the site is fully hard-blocked,
 * core's own rest_pre_dispatch guard already 402s /luwipress/v1/mcp; this gate
 * additionally covers the tier case, where the license is active but the tier
 * simply doesn't include the tool server.)
 */
function luwipress_webmcp_feature_enabled() {
	if ( ! class_exists( 'LuwiPress_License' ) ) {
		return true;
	}
	return LuwiPress_License::feature_enabled( 'webmcp' );
}

/**
 * License-required notice (Plugins screen only) — shown when core is active but
 * the license tier does not include WebMCP.
 */
function luwipress_webmcp_license_required_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html__( 'LuwiPress WebMCP is locked.', 'luwipress-webmcp' )
		. '</strong> '
		. esc_html__( 'Your LuwiPress license tier does not include the WebMCP tool server. Activate or upgrade your license under LuwiPress → Settings → License.', 'luwipress-webmcp' )
		. '</p></div>';
}

/**
 * Bootstrap. Runs on plugins_loaded priority 20 so core (priority 10) is
 * ready when it's present. Standalone mode loads the same class with a
 * Permission shim — class_exists gates inside register_*_tools handle the
 * rest of the graceful degradation.
 */
function luwipress_webmcp_init() {
	luwipress_webmcp_register_permission_shim();

	if ( ! luwipress_webmcp_core_active() ) {
		// Lite mode banner only on the Plugins screen — no admin-page noise.
		add_action( 'admin_notices', 'luwipress_webmcp_lite_mode_notice' );
	}

	require_once LUWIPRESS_WEBMCP_PLUGIN_DIR . 'includes/class-luwipress-webmcp.php';

	if ( ! LuwiPress_WebMCP::is_enabled() ) {
		return;
	}

	if ( luwipress_webmcp_feature_enabled() ) {
		LuwiPress_WebMCP::get_instance();
	} else {
		// Core active + enforcement on + tier excludes WebMCP.
		add_action( 'admin_notices', 'luwipress_webmcp_license_required_notice' );
	}
}
add_action( 'plugins_loaded', 'luwipress_webmcp_init', 20 );

/**
 * Admin menu. When Core is active we attach as a submenu under the
 * LuwiPress parent (legacy behaviour preserved). When Core is absent
 * (Lite mode) we create a top-level menu so the operator can find the
 * MCP server settings + tool catalog + endpoint URL.
 */
function luwipress_webmcp_admin_menu() {
	if ( luwipress_webmcp_core_active() ) {
		add_submenu_page(
			'luwipress',
			__( 'WebMCP', 'luwipress-webmcp' ),
			__( 'WebMCP', 'luwipress-webmcp' ),
			'manage_options',
			'luwipress-webmcp',
			'luwipress_webmcp_render_admin_page'
		);
		return;
	}

	// Lite mode top-level menu — own slug + own dashicon, position right
	// below "Plugins" so the user finds it where MCP servers usually live.
	add_menu_page(
		__( 'LuwiPress MCP', 'luwipress-webmcp' ),
		__( 'LuwiPress MCP', 'luwipress-webmcp' ),
		'manage_options',
		'luwipress-webmcp',
		'luwipress_webmcp_render_admin_page',
		'dashicons-rest-api',
		68
	);
}
add_action( 'admin_menu', 'luwipress_webmcp_admin_menu', 20 );

/**
 * Render the WebMCP admin page from the companion's bundled template.
 */
function luwipress_webmcp_render_admin_page() {
	include LUWIPRESS_WEBMCP_PLUGIN_DIR . 'admin/webmcp-page.php';
}

/**
 * Enqueue the WebMCP client JS on the WebMCP admin page only.
 */
function luwipress_webmcp_admin_enqueue( $hook ) {
	if ( strpos( $hook, 'luwipress-webmcp' ) === false ) {
		return;
	}
	// Inherit core LuwiPress admin CSS (dashboard/card tokens) so layout stays consistent.
	if ( defined( 'LUWIPRESS_PLUGIN_URL' ) && defined( 'LUWIPRESS_VERSION' ) ) {
		wp_enqueue_style(
			'luwipress-admin',
			LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LUWIPRESS_VERSION
		);
	}
	wp_enqueue_style(
		'luwipress-webmcp-admin',
		LUWIPRESS_WEBMCP_PLUGIN_URL . 'assets/css/webmcp-admin.css',
		array( 'luwipress-admin' ),
		LUWIPRESS_WEBMCP_VERSION
	);
	wp_enqueue_script(
		'luwipress-webmcp-client',
		LUWIPRESS_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-client.js',
		array(),
		LUWIPRESS_WEBMCP_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'luwipress_webmcp_admin_enqueue' );
