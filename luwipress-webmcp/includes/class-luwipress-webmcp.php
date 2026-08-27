<?php
/**
 * N8nPress WebMCP Server
 *
 * Implements the MCP (Model Context Protocol) Streamable HTTP transport,
 * exposing all LuwiPress REST API endpoints as MCP tools that AI agents
 * can discover and invoke over a single HTTP endpoint.
 *
 * Spec: https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 *
 * @package    N8nPress
 * @subpackage WebMCP
 * @since      1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_WebMCP {

    /* ──────────────────────────── Singleton ──────────────────────────── */

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ──────────────────────────── Constants ──────────────────────────── */

    const MCP_PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME          = 'luwipress-webmcp';
    const SERVER_VERSION       = '2.0.3';
    const ENDPOINT_PATH        = '/luwipress/v1/mcp';

    /* ──────────────────────────── Properties ────────────────────────── */

    /** @var array Registered MCP tool definitions, keyed by tool name. */
    private $tools = array();

    /**
     * Per-category tool-registration audit. Populated by register_all_tools()
     * after every category's register_* method runs. Used to surface deploy
     * integrity issues (e.g. a class-gated category silently registering 0
     * tools because the required core class isn't loaded — usually a partial
     * ZIP upload). Exposed via the `webmcp_deploy_audit` MCP tool.
     *
     * Shape: [ category => [ method, added, skipped, gate_class?, gate_class_exists? ] ]
     *
     * @since 1.0.27
     * @var array
     */
    private $registration_audit = array();

    /** @var array Map of tool name → internal handler callable. */
    private $handlers = array();

    /** @var array Resource template definitions. */
    private $resource_templates = array();

    /** @var string|null Active session ID for Streamable HTTP session management. */
    private $session_id = null;

    /* ──────────────────────────── Bootstrap ──────────────────────────── */

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_mcp_endpoint' ) );
        // Deferred term-edit cascade — fired off-request by taxonomy_update_term's
        // description-only path so a slow 3rd-party edited_term listener (WPML
        // term-sync, Rank Math sitemap regeneration) can't blow the MCP client's
        // ~4-minute response timeout. See defer_term_edit_cascade().
        add_action( 'luwipress_webmcp_term_edit_cascade', array( $this, 'run_term_edit_cascade' ), 10, 3 );
        $this->register_all_tools();
    }

    /**
     * Register the single MCP endpoint that handles POST, GET, and DELETE.
     */
    public function register_mcp_endpoint() {
        // POST — client sends JSON-RPC messages
        register_rest_route( 'luwipress/v1', '/mcp', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_post' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_get' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'handle_delete' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
        ) );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  PERMISSION CHECK
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Validate MCP request authentication.
     *
     * Accepts:
     *  1. WordPress admin cookie (for browser-based clients)
     *  2. Bearer token matching the LuwiPress API token
     *
     * Also validates Origin header to prevent DNS rebinding (per MCP spec).
     */
    public function check_mcp_permission( $request ) {
        // Origin validation (MCP spec security requirement — DNS rebinding protection)
        if ( ! $this->validate_origin( $request ) ) {
            return new WP_Error( 'origin_denied', 'Origin not allowed', array( 'status' => 403 ) );
        }

        // Admin cookie or API token
        if ( LuwiPress_Permission::check_token_or_admin( $request ) ) {
            return true;
        }

        return new WP_Error( 'mcp_unauthorized', 'Invalid credentials', array( 'status' => 401 ) );
    }

    /**
     * Validate Origin header to prevent DNS rebinding attacks.
     */
    private function validate_origin( $request ) {
        $origin = $request->get_header( 'origin' );
        if ( empty( $origin ) ) {
            return true; // No origin = server-to-server (CLI, workflow runners, etc.)
        }

        $allowed = array(
            home_url(),
            site_url(),
            admin_url(),
        );

        // Allow configured additional origins
        $extra = get_option( 'luwipress_webmcp_allowed_origins', '' );
        if ( ! empty( $extra ) ) {
            $allowed = array_merge( $allowed, array_map( 'trim', explode( "\n", $extra ) ) );
        }

        foreach ( $allowed as $allowed_origin ) {
            $allowed_host = wp_parse_url( $allowed_origin, PHP_URL_HOST );
            $origin_host  = wp_parse_url( $origin, PHP_URL_HOST );
            if ( $allowed_host && $origin_host && $allowed_host === $origin_host ) {
                return true;
            }
        }

        return false;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  HTTP HANDLERS (Streamable HTTP Transport)
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * POST handler — receives JSON-RPC messages from client.
     *
     * Per MCP spec: returns either application/json or text/event-stream.
     * We use application/json for simplicity (valid per spec).
     */
    public function handle_post( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return $this->jsonrpc_error( null, -32700, 'Parse error' );
        }

        // Batch request (array of messages)
        if ( isset( $body[0] ) && is_array( $body[0] ) ) {
            $responses = array();
            foreach ( $body as $message ) {
                $response = $this->dispatch_jsonrpc( $message, $request );
                if ( null !== $response ) {
                    $responses[] = $response;
                }
            }
            // All notifications → 202
            if ( empty( $responses ) ) {
                return new WP_REST_Response( null, 202 );
            }
            return rest_ensure_response( $responses );
        }

        // Single message
        $response = $this->dispatch_jsonrpc( $body, $request );

        // Notification or response → 202
        if ( null === $response ) {
            return new WP_REST_Response( null, 202 );
        }

        // Attach session header if we have one
        $rest_response = rest_ensure_response( $response );
        if ( $this->session_id ) {
            $rest_response->header( 'Mcp-Session-Id', $this->session_id );
        }

        return $rest_response;
    }

    /**
     * GET handler — opens SSE stream for server-initiated messages.
     *
     * For now returns 405 since we don't push server-initiated messages.
     * This can be extended later for real-time notifications.
     */
    public function handle_get( $request ) {
        $accept = $request->get_header( 'accept' );

        // MCP spec: if server doesn't offer SSE, return 405
        if ( strpos( $accept, 'text/event-stream' ) !== false ) {
            return new WP_REST_Response(
                array( 'error' => 'SSE streaming not yet supported. Use POST for all interactions.' ),
                405
            );
        }

        // Non-SSE GET: return server info
        return rest_ensure_response( array(
            'name'             => self::SERVER_NAME,
            'version'          => self::SERVER_VERSION,
            'protocolVersion'  => self::MCP_PROTOCOL_VERSION,
            'status'           => 'ready',
            'tools_count'      => count( $this->tools ),
            'endpoint'         => rest_url( self::ENDPOINT_PATH ),
        ) );
    }

    /**
     * DELETE handler — terminates session.
     */
    public function handle_delete( $request ) {
        $session = $request->get_header( 'mcp-session-id' );
        if ( $session ) {
            delete_transient( 'luwipress_mcp_session_' . $session );
        }
        return new WP_REST_Response( null, 204 );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  JSON-RPC DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Route a single JSON-RPC message to the appropriate handler.
     *
     * @return array|null  JSON-RPC response, or null for notifications.
     */
    private function dispatch_jsonrpc( $message, $request ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;
        $params = $message['params'] ?? array();

        // JSON-RPC 2.0 spec: the jsonrpc field MUST be exactly "2.0".
        // Reject 1.0 / missing / mistyped values with -32600 Invalid Request
        // so misconfigured clients fail loudly instead of silently working.
        $jsonrpc_version = $message['jsonrpc'] ?? null;
        if ( '2.0' !== $jsonrpc_version ) {
            return $this->jsonrpc_error(
                $id,
                -32600,
                "Invalid Request: jsonrpc field must be exactly '2.0'."
            );
        }

        // Notifications and responses have no 'id' expectation
        if ( null === $id && ! isset( $message['method'] ) ) {
            return null; // Response from client — acknowledge
        }
        if ( null === $id ) {
            // Notification — process but don't respond
            $this->handle_notification( $method, $params );
            return null;
        }

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $id, $params, $request );

            case 'tools/list':
                return $this->handle_tools_list( $id, $params );

            case 'tools/call':
                return $this->handle_tools_call( $id, $params );

            case 'resources/list':
                return $this->handle_resources_list( $id );

            case 'resources/read':
                return $this->handle_resources_read( $id, $params );

            case 'resources/templates/list':
                return $this->handle_resource_templates_list( $id, $params );

            case 'completion/complete':
                return $this->handle_completion( $id, $params );

            case 'ping':
                return $this->jsonrpc_result( $id, new stdClass() );

            default:
                return $this->jsonrpc_error( $id, -32601, "Method not found: {$method}" );
        }
    }

    /**
     * Handle notifications (no response expected).
     */
    private function handle_notification( $method, $params ) {
        switch ( $method ) {
            case 'notifications/initialized':
                LuwiPress_Logger::log( 'WebMCP client initialized', 'info' );
                break;

            case 'notifications/cancelled':
                // Client cancelled a request — no action needed for sync responses
                break;
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  MCP LIFECYCLE METHODS
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `initialize` — first message from client.
     */
    private function handle_initialize( $id, $params, $request ) {
        $client_info    = $params['clientInfo'] ?? array();
        $client_version = $params['protocolVersion'] ?? self::MCP_PROTOCOL_VERSION;

        // Accept client's protocol version if we support it (backward compat)
        $supported = array( '2025-03-26', '2025-11-25', '2024-11-05' );
        $negotiated_version = in_array( $client_version, $supported, true )
            ? $client_version
            : self::MCP_PROTOCOL_VERSION;

        // Generate session ID
        $this->session_id = wp_generate_uuid4();
        set_transient(
            'luwipress_mcp_session_' . $this->session_id,
            array(
                'client'     => $client_info,
                'created_at' => current_time( 'mysql' ),
                'ip'         => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ),
            ),
            HOUR_IN_SECONDS
        );

        LuwiPress_Logger::log( 'WebMCP session started', 'info', array(
            'session_id' => $this->session_id,
            'client'     => $client_info['name'] ?? 'unknown',
        ) );

        $result = array(
            'protocolVersion' => $negotiated_version,
            'capabilities'    => array(
                'tools'     => array(
                    'listChanged' => false,  // Static tool list — no runtime changes
                ),
                'resources' => array(
                    'subscribe'   => false,  // No real-time resource subscriptions yet
                    'listChanged' => false,
                ),
                'logging'     => new stdClass(),  // Server can emit log messages
                'completions' => new stdClass(),  // Supports argument autocompletion
            ),
            'serverInfo'      => array(
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ),
            'instructions'    => $this->get_server_instructions(),
        );

        $response = $this->jsonrpc_result( $id, $result );

        // Session ID is attached via header in handle_post()
        return $response;
    }

    /**
     * Server instructions for the AI agent — describes what LuwiPress can do.
     */
    private function get_server_instructions() {
        $site_name = get_bloginfo( 'name' );
        return "You are connected to {$site_name} via LuwiPress WebMCP. "
             . 'LuwiPress is AI-powered automation for WooCommerce that provides: '
             . 'content enrichment, SEO optimization (Rank Math/Yoast), AEO (FAQ/HowTo/Speakable schema), '
             . 'translation management (WPML/Polylang), CRM analytics, email sending, '
             . 'and AI-powered content generation. '
             . 'Use tools/list to discover available operations, then tools/call to execute them.';
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  TOOLS — LIST & CALL
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `tools/list` — return all registered tool schemas.
     *
     * Supports cursor-based pagination per MCP spec.
     */
    private function handle_tools_list( $id, $params ) {
        $cursor     = $params['cursor'] ?? null;
        // Page size 500 (was 200). MCP spec supports cursor-based pagination
        // but several popular clients (mcp-remote ≤ 0.x, Claude Desktop
        // some builds) don't follow `nextCursor` reliably — they take the
        // first page and stop. That left our last ~12 tools invisible once
        // the catalog grew past 200 in 1.0.28 (search_*, taxonomy_meta_set,
        // taxonomy_meta_delete, webmcp_deploy_audit all registered after
        // position 200 in insertion order). Vendor-Tapadum FR-006/FR-007
        // smoking gun: server-side audit showed the tools registered,
        // operator-side tool_search couldn't find them — page 2 was the
        // dead zone. 500 accommodates catalog growth to ~500 tools before
        // pagination matters again; revisit when we approach that ceiling.
        $page_size  = 500;
        $tool_names = array_keys( $this->tools );

        // Cursor = base64-encoded offset
        $offset = 0;
        if ( $cursor ) {
            $decoded = intval( base64_decode( $cursor ) );
            if ( $decoded > 0 ) {
                $offset = $decoded;
            }
        }

        $slice       = array_slice( $tool_names, $offset, $page_size );
        $tools_page  = array();
        foreach ( $slice as $name ) {
            $tools_page[] = $this->tools[ $name ];
        }

        $result = array( 'tools' => $tools_page );

        // Next cursor if more tools remain
        $next_offset = $offset + $page_size;
        if ( $next_offset < count( $tool_names ) ) {
            $result['nextCursor'] = base64_encode( (string) $next_offset );
        }

        return $this->jsonrpc_result( $id, $result );
    }

    /**
     * Handle `tools/call` — execute a tool and return the result.
     */
    private function handle_tools_call( $id, $params ) {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? array();

        if ( ! isset( $this->handlers[ $tool_name ] ) ) {
            return $this->jsonrpc_error( $id, -32602, "Unknown tool: {$tool_name}" );
        }

        $start = microtime( true );

        try {
            $result = call_user_func( $this->handlers[ $tool_name ], $arguments );
            $ms     = round( ( microtime( true ) - $start ) * 1000, 2 );

            LuwiPress_Logger::log( "WebMCP tool called: {$tool_name}", 'info', array(
                'tool'           => $tool_name,
                'execution_ms'   => $ms,
            ) );

            // Normalize result to MCP content format
            return $this->jsonrpc_result( $id, array(
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
                    ),
                ),
            ) );
        } catch ( Exception $e ) {
            LuwiPress_Logger::log( "WebMCP tool error: {$tool_name} — {$e->getMessage()}", 'error' );

            return $this->jsonrpc_result( $id, array(
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => wp_json_encode( array( 'error' => $e->getMessage() ) ),
                    ),
                ),
                'isError' => true,
            ) );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  RESOURCES — LIST & READ
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `resources/list` — expose key WordPress resources.
     */
    private function handle_resources_list( $id ) {
        $resources = array(
            array(
                'uri'         => 'luwipress://site-config',
                'name'        => 'Site Configuration',
                'description' => 'Full WordPress + WooCommerce + plugin environment snapshot',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'luwipress://health',
                'name'        => 'Health Check',
                'description' => 'Server health status (database, filesystem, memory)',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'luwipress://aeo-coverage',
                'name'        => 'AEO Coverage Report',
                'description' => 'FAQ/HowTo/Speakable schema coverage across products',
                'mimeType'    => 'application/json',
            ),
        );

        return $this->jsonrpc_result( $id, array( 'resources' => $resources ) );
    }

    /**
     * Handle `resources/templates/list` — parameterized resource URIs.
     */
    private function handle_resource_templates_list( $id, $params ) {
        $templates = array(
            array(
                'uriTemplate'  => 'luwipress://post/{post_id}',
                'name'         => 'WordPress Post',
                'description'  => 'Read a specific post/product by ID',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'luwipress://seo-meta/{post_id}',
                'name'         => 'SEO Meta',
                'description'  => 'Rank Math / Yoast SEO meta for a post',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'luwipress://translation-status/{post_id}',
                'name'         => 'Translation Status',
                'description'  => 'Translation status for a post across all languages',
                'mimeType'     => 'application/json',
            ),
        );

        return $this->jsonrpc_result( $id, array( 'resourceTemplates' => $templates ) );
    }

    /**
     * Handle `completion/complete` — argument autocompletion for resource templates.
     */
    private function handle_completion( $id, $params ) {
        $ref      = $params['ref'] ?? array();
        $argument = $params['argument'] ?? array();
        $arg_name = $argument['name'] ?? '';
        $arg_val  = $argument['value'] ?? '';

        // Autocomplete post_id by searching post titles
        if ( 'post_id' === $arg_name && strlen( $arg_val ) >= 2 ) {
            $query = new WP_Query( array(
                's'              => sanitize_text_field( $arg_val ),
                'post_type'      => array( 'post', 'page', 'product' ),
                'posts_per_page' => 10,
                'fields'         => 'ids',
            ) );

            $values = array();
            foreach ( $query->posts as $pid ) {
                $values[] = array(
                    'value'       => (string) $pid,
                    'description' => get_the_title( $pid ),
                );
            }

            return $this->jsonrpc_result( $id, array(
                'completion' => array(
                    'values'  => $values,
                    'hasMore' => $query->found_posts > 10,
                ),
            ) );
        }

        return $this->jsonrpc_result( $id, array(
            'completion' => array( 'values' => array() ),
        ) );
    }

    /**
     * Handle `resources/read` — return resource content.
     */
    private function handle_resources_read( $id, $params ) {
        $uri = $params['uri'] ?? '';

        switch ( $uri ) {
            case 'luwipress://site-config':
                $config  = LuwiPress_Site_Config::get_instance();
                $request = new WP_REST_Request( 'GET', '/luwipress/v1/site-config' );
                $data    = $config->get_site_config( $request );
                break;

            case 'luwipress://health':
                $api  = LuwiPress_API::get_instance();
                $data = $api->health_check();
                break;

            case 'luwipress://aeo-coverage':
                if ( class_exists( 'LuwiPress_AEO' ) ) {
                    $aeo     = LuwiPress_AEO::get_instance();
                    $request = new WP_REST_Request( 'GET', '/luwipress/v1/aeo/coverage' );
                    $data    = $aeo->get_aeo_coverage( $request );
                } else {
                    $data = array( 'error' => 'AEO module not loaded' );
                }
                break;

            default:
                // Handle parameterized resource templates
                $data = $this->resolve_resource_template( $uri );
                if ( null === $data ) {
                    return $this->jsonrpc_error( $id, -32002, "Resource not found: {$uri}" );
                }
        }

        // Unwrap WP_REST_Response if needed
        if ( $data instanceof WP_REST_Response ) {
            $data = $data->get_data();
        }

        return $this->jsonrpc_result( $id, array(
            'contents' => array(
                array(
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
                ),
            ),
        ) );
    }

    /**
     * Resolve a parameterized resource URI template.
     *
     * @param string $uri The resource URI (e.g., 'luwipress://post/42').
     * @return array|null  Resource data, or null if not matched.
     */
    private function resolve_resource_template( $uri ) {
        // luwipress://post/{post_id}
        if ( preg_match( '#^luwipress://post/(\d+)$#', $uri, $m ) ) {
            $post = get_post( intval( $m[1] ) );
            if ( ! $post ) {
                return null;
            }
            return array(
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'content'  => $post->post_content,
                'excerpt'  => $post->post_excerpt,
                'status'   => $post->post_status,
                'type'     => $post->post_type,
                'date'     => $post->post_date,
                'modified' => $post->post_modified,
                'url'      => get_permalink( $post->ID ),
                'author'   => get_the_author_meta( 'display_name', $post->post_author ),
            );
        }

        // luwipress://seo-meta/{post_id}
        if ( preg_match( '#^luwipress://seo-meta/(\d+)$#', $uri, $m ) ) {
            $post_id = intval( $m[1] );
            if ( ! get_post( $post_id ) ) {
                return null;
            }
            $keys = array(
                'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
                '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
                '_luwipress_schema', '_luwipress_faq', '_luwipress_howto',
            );
            $meta = array();
            foreach ( $keys as $key ) {
                $val = get_post_meta( $post_id, $key, true );
                if ( '' !== $val && false !== $val ) {
                    $meta[ $key ] = $val;
                }
            }
            return array( 'post_id' => $post_id, 'seo_meta' => $meta );
        }

        // luwipress://translation-status/{post_id}
        if ( preg_match( '#^luwipress://translation-status/(\d+)$#', $uri, $m ) ) {
            $post_id = intval( $m[1] );
            if ( ! get_post( $post_id ) ) {
                return null;
            }
            if ( class_exists( 'LuwiPress_Translation' ) ) {
                $trans   = LuwiPress_Translation::get_instance();
                $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/status' );
                $request->set_param( 'post_id', $post_id );
                $data = $trans->get_translation_status( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            }
            return array( 'post_id' => $post_id, 'error' => 'Translation module not loaded' );
        }

        return null;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  TOOL REGISTRATION
     * ═══════════════════════════════════════════════════════════════════
     *
     * Each tool wraps an existing LuwiPress REST endpoint.
     * Tools are grouped by domain: content, seo, aeo, translation,
     * crm, email, system, workflow, elementor.
     */

    /**
     * Migration / SEO-ops tools (3.12.0+): Rank Math Redirections CRUD,
     * internal-link audit, and WooCommerce product snapshot / rollback.
     * Built for DNS-swap + bulk-edit safety.
     */
    private function register_forms_tools() {
        $this->register_tool( 'forms_list', array(
            'description' => 'List Fluent Forms forms: id, title, status, entry count, [fluentform id] shortcode. Use to find a form to embed or edit. Read-only.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'List Forms', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            return $this->proxy_rest_post( 'LuwiPress_Forms', 'rest_list', array() );
        } );

        $this->register_tool( 'forms_get', array(
            'description' => 'Get one Fluent Forms form: title, status, full form_fields JSON (the canonical FF field/step layout), appearance settings, shortcode. Read an existing form to learn the exact field-JSON shape for this FF version before creating/updating. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Form ID (required).' ) ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Get Form', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Forms', 'rest_get', $args );
        } );

        $this->register_tool( 'forms_create', array(
            'description' => 'Create a Fluent Forms form. form_fields is the canonical FF layout JSON — an object {"fields":[...],"submitButton":{...}}; multi-step wizards use form_step container fields. Tip: forms_get an existing form first to copy the exact shape for this FF version. Returns the new id + [fluentform id] shortcode + editor URL.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'               => array( 'type' => 'string', 'description' => 'Form title (required).' ),
                    'form_fields'         => array( 'description' => 'FF field layout: object {fields:[...],submitButton:{...}} or its JSON string (required).' ),
                    'status'              => array( 'type' => 'string', 'description' => "'published' (default) | 'unpublished'." ),
                    'appearance_settings' => array( 'description' => 'Optional FF appearance settings object.' ),
                ),
                'required'   => array( 'title', 'form_fields' ),
            ),
            'annotations' => array( 'title' => 'Create Form', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Forms', 'rest_create', $args );
        } );

        $this->register_tool( 'forms_update', array(
            'description' => 'Update a Fluent Forms form (partial). Send id + any of: title, form_fields (full FF layout JSON — replaces the layout), status, appearance_settings.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'                  => array( 'type' => 'integer', 'description' => 'Form ID (required).' ),
                    'title'               => array( 'type' => 'string' ),
                    'form_fields'         => array( 'description' => 'Full FF field layout JSON (replaces current layout).' ),
                    'status'              => array( 'type' => 'string', 'description' => "'published' | 'unpublished'." ),
                    'appearance_settings' => array( 'description' => 'FF appearance settings object.' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Update Form', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Forms', 'rest_update', $args );
        } );

        $this->register_tool( 'forms_entries', array(
            'description' => 'Read recent submissions (DB entries) for a Fluent Forms form — paged. Each entry has its decoded response data + status + created_at. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'       => array( 'type' => 'integer', 'description' => 'Form ID (required).' ),
                    'per_page' => array( 'type' => 'integer', 'description' => '1..100 (default 20).' ),
                    'page'     => array( 'type' => 'integer', 'description' => 'Page (default 1).' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Form Entries', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Forms', 'rest_entries', $args );
        } );
    }

    private function register_backup_tools() {
        $this->register_tool( 'backup_diag', array(
            'description' => 'UpdraftPlus availability + health on THIS site: version, updraft_dir (writable?), history class, whether the backup hooks are attached, and WP-Cron status (a real backup only completes if cron processes updraft_backup_resume). Call before triggering a backup or a migration pull. Read-only.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Backup Diag', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_diag', array() );
        } );

        $this->register_tool( 'backup_run', array(
            'description' => 'Trigger an UpdraftPlus backup on THIS site (the migration SOURCE). scope: full (db+files, default) | db | files. nocloud=true (default) keeps the archive local so it can be pulled by another site. Returns the job nonce (may be null until history is written — poll backup_status). Backup starts synchronously; completion of a large backup depends on WP-Cron.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'scope'    => array( 'type' => 'string',  'description' => "'full' (default) | 'db' | 'files'." ),
                    'nocloud'  => array( 'type' => 'boolean', 'description' => 'Keep archive local only, no remote upload (default true).' ),
                    'label'    => array( 'type' => 'string',  'description' => 'Human label stored on the backup set.' ),
                    'entities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => "Optional file-entity subset for scope=full|files: any of plugins,themes,uploads,others." ),
                ),
            ),
            'annotations' => array( 'title' => 'Run Backup', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_run', $args );
        } );

        $this->register_tool( 'backup_status', array(
            'description' => 'Live backup job status on THIS site: jobstatus, finished flag, coarse percent, resume_due. Omit nonce to get the current LIVE (unfinished) job; pass a nonce to inspect a specific job even after it finished. Poll after backup_run until finished==true. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array( 'nonce' => array( 'type' => 'string', 'description' => 'Specific job nonce (optional).' ) ),
            ),
            'annotations' => array( 'title' => 'Backup Status', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_status', $args );
        } );

        $this->register_tool( 'backup_list', array(
            'description' => 'List backup sets on THIS site with per-archive on-disk status, size, and an authenticated download_url for each file. complete=true means every archive is on local disk (pullable); false means some live only in remote storage. This is the source for a cross-server pull. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array( 'limit' => array( 'type' => 'integer', 'description' => '1..100 (default 20).' ) ),
            ),
            'annotations' => array( 'title' => 'List Backups', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_list', $args );
        } );

        $this->register_tool( 'backup_pull', array(
            'description' => 'TARGET-side migration ingest: this site reaches out to a trusted SOURCE LuwiPress site and streams the named backup archives into its own wp-content/updraft/. Provide source_url (base, no trailing slash), source_token (the source bearer), and files[] (filenames from the source backup_list). Run on the NEW site (e.g. arsha-vps). Then call backup_rescan, then backup_restore.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'source_url'   => array( 'type' => 'string', 'description' => 'Source site base URL, no trailing slash (required).' ),
                    'source_token' => array( 'type' => 'string', 'description' => "Source site's LuwiPress bearer token (required)." ),
                    'files'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Archive filenames to pull, from the source backup_list (required).' ),
                ),
                'required'   => array( 'source_url', 'source_token', 'files' ),
            ),
            'annotations' => array( 'title' => 'Pull Backup', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_pull', $args );
        } );

        $this->register_tool( 'backup_rescan', array(
            'description' => 'TARGET-side: make UpdraftPlus re-enumerate wp-content/updraft/ so freshly-pulled archives become a restorable set. Returns the now-visible sets (with timestamp + nonce) — pick one for backup_restore.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Rescan Backups', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_rescan', $args );
        } );

        $this->register_tool( 'backup_restore', array(
            'description' => 'TARGET-side restore guidance (mode=assist, default & SAFE): verifies a rescanned set is restorable and returns the set to click Restore on in the UpdraftPlus UI PLUS the exact `wp search-replace` command to fix the domain (free UpdraftPlus does not auto-rewrite URLs). It does NOT auto-run the restore — a programmatic restore is unsupported and can brick the site mid-request. Provide timestamp (from backup_rescan), old_url (source domain) and new_url (this site).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'mode'      => array( 'type' => 'string', 'description' => "'assist' (default, safe) | 'execute' (intentionally not auto-run)." ),
                    'timestamp' => array( 'type' => 'integer', 'description' => 'Backup set timestamp from backup_rescan (required).' ),
                    'old_url'   => array( 'type' => 'string', 'description' => 'Source domain to replace, e.g. https://demo.flybydeniz.com.' ),
                    'new_url'   => array( 'type' => 'string', 'description' => 'This site URL (defaults to home_url()).' ),
                ),
                'required'   => array( 'timestamp' ),
            ),
            'annotations' => array( 'title' => 'Restore (assist)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Backup_Bridge', 'handle_restore', $args );
        } );
    }

    private function register_migration_tools() {
        // ── FR-042: Rank Math Redirections CRUD ──────────────────────────
        $this->register_tool( 'redirect_diag', array(
            'description' => 'Rank Math Redirections availability + counts (active/inactive/all) and supported header codes / comparisons. Use before/after a DNS swap to confirm the redirect engine is reachable. Read-only.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Redirect Diag', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_diag', array() );
        } );

        $this->register_tool( 'redirect_list', array(
            'description' => 'List Rank Math redirects (paginated). Filter by status (all|active|inactive) and a search string; order by id/url_to/hits/created. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'   => array( 'type' => 'string',  'description' => 'Match source/target substring.' ),
                    'status'   => array( 'type' => 'string',  'description' => 'all (default) | active | inactive.' ),
                    'per_page' => array( 'type' => 'integer', 'description' => '1..200 (default 50).' ),
                    'paged'    => array( 'type' => 'integer', 'description' => 'Page (default 1).' ),
                    'orderby'  => array( 'type' => 'string',  'description' => 'id|url_to|header_code|hits|last_accessed|created|updated.' ),
                    'order'    => array( 'type' => 'string',  'description' => 'ASC|DESC (default DESC).' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Redirects', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_list', $args );
        } );

        $this->register_tool( 'redirect_create', array(
            'description' => 'Create a Rank Math redirect. sources: a path/slug string, a list of strings, or {pattern, comparison} objects (comparison: exact|contains|start|end|regex, default exact). url_to: target (relative or absolute; omit for 410/451). header_code: 301|302|307|410|451 (default 301). status: active|inactive.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'sources'     => array( 'description' => 'Source pattern(s): string, string[], or {pattern,comparison}[].' ),
                    'url_to'      => array( 'type' => 'string',  'description' => 'Target URL/path (required unless 410/451).' ),
                    'header_code' => array( 'type' => 'integer', 'description' => '301|302|307|410|451 (default 301).' ),
                    'status'      => array( 'type' => 'string',  'description' => 'active (default) | inactive.' ),
                ),
                'required'   => array( 'sources' ),
            ),
            'annotations' => array( 'title' => 'Create Redirect', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_create', $args );
        } );

        $this->register_tool( 'redirect_batch', array(
            'description' => 'Bulk-create Rank Math redirects in one call (max 200). The DNS-swap workhorse: pass rows of { sources, url_to, header_code?, status? }. Returns per-row created ids + any errors. Each row is validated independently; a bad row is skipped, not fatal.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'rows' => array( 'type' => 'array', 'description' => 'Array of { sources, url_to, header_code?, status? } (<=200).' ),
                ),
                'required'   => array( 'rows' ),
            ),
            'annotations' => array( 'title' => 'Batch Redirects', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_batch', $args );
        } );

        $this->register_tool( 'redirect_update', array(
            'description' => 'Update a Rank Math redirect by id (partial: send only the fields to change — sources, url_to, header_code, status).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'          => array( 'type' => 'integer', 'description' => 'Redirection id (required).' ),
                    'sources'     => array( 'description' => 'New source pattern(s).' ),
                    'url_to'      => array( 'type' => 'string',  'description' => 'New target.' ),
                    'header_code' => array( 'type' => 'integer', 'description' => '301|302|307|410|451.' ),
                    'status'      => array( 'type' => 'string',  'description' => 'active|inactive.' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Update Redirect', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_update', $args );
        } );

        $this->register_tool( 'redirect_delete', array(
            'description' => 'Delete one or more Rank Math redirects by id. DESTRUCTIVE — the redirect rows are removed.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'ids' => array( 'type' => 'array', 'description' => 'Redirection ids to delete.' ),
                ),
                'required'   => array( 'ids' ),
            ),
            'annotations' => array( 'title' => 'Delete Redirects', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Redirections', 'handle_delete', $args );
        } );

        // ── FR-044: internal-link audit ─────────────────────────────────
        $this->register_tool( 'internal_link_search_audit', array(
            'description' => 'Find every post that links to a target URL / path / slug (content, and optionally meta — Elementor/ACF). Multi-needle: a full URL also matches its relative-path + bare-slug forms. Returns matches with occurrence counts, a context snippet, and edit links. Read-only. Use before a slug change or DNS swap to see what points at the old URL.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'target'       => array( 'type' => 'string',  'description' => 'URL / path / slug to search for (required).' ),
                    'post_types'   => array( 'type' => 'string',  'description' => 'CSV of post types (default post,page,product).' ),
                    'status'       => array( 'type' => 'string',  'description' => 'Post status (default publish; "any" widens it).' ),
                    'per_page'     => array( 'type' => 'integer', 'description' => '1..200 (default 50).' ),
                    'include_meta' => array( 'type' => 'boolean', 'description' => 'Also scan post meta (default false).' ),
                ),
                'required'   => array( 'target' ),
            ),
            'annotations' => array( 'title' => 'Internal Link Audit', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Internal_Link_Audit', 'handle_audit', $args );
        } );

        // ── FR-043: WooCommerce product snapshot / rollback ─────────────
        $this->register_tool( 'product_snapshot_create', array(
            'description' => 'Snapshot a WooCommerce product before a risky edit: captures post fields, ALL meta, gallery, term assignments, and every variation into a 10-deep ring buffer. Non-destructive. Returns a snapshot_id for rollback.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product post id (required).' ),
                    'label'      => array( 'type' => 'string',  'description' => 'Optional snapshot label.' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Snapshot Product', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Product_Snapshot', 'handle_create', $args );
        } );

        $this->register_tool( 'product_snapshot_list', array(
            'description' => 'List the snapshots stored for a WooCommerce product (id, label, timestamp, variation/meta counts). Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product post id (required).' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'List Snapshots', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Product_Snapshot', 'handle_list', $args );
        } );

        $this->register_tool( 'product_rollback', array(
            'description' => 'Roll a WooCommerce product back to a snapshot (most recent if snapshot_id omitted). DESTRUCTIVE — overwrites current post fields, meta, terms and variations. A pre-rollback snapshot is taken automatically. replace_meta=true wipes meta keys absent from the snapshot (default false = overlay).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'   => array( 'type' => 'integer', 'description' => 'Product post id (required).' ),
                    'snapshot_id'  => array( 'type' => 'string',  'description' => 'Snapshot id (default: most recent).' ),
                    'replace_meta' => array( 'type' => 'boolean', 'description' => 'Full meta replace vs overlay (default false).' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Rollback Product', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Product_Snapshot', 'handle_rollback', $args );
        } );
    }

    private function register_all_tools() {
        // Category → register method. Iterating instead of straight-line calls
        // so we can wrap each method in a count diff for the deploy audit
        // (3.3.1+). Order preserved exactly from the pre-1.0.27 implementation.
        $categories = array(
            'system'           => 'register_system_tools',
            'content'          => 'register_content_tools',
            'seo'              => 'register_seo_tools',
            'aeo'              => 'register_aeo_tools',
            'translation'      => 'register_translation_tools',
            'crm'              => 'register_crm_tools',
            'email'            => 'register_email_tools',
            'workflow'         => 'register_workflow_tools',
            'token'            => 'register_token_tools',
            'review'           => 'register_review_tools',
            'linker'           => 'register_linker_tools',
            'knowledge_graph'  => 'register_knowledge_graph_tools',
            'elementor'        => 'register_elementor_tools',
            'admin'            => 'register_admin_tools',
            'taxonomy'         => 'register_taxonomy_tools',
            'woo'              => 'register_woo_tools',
            'media'            => 'register_media_tools',
            'comment'          => 'register_comment_tools',
            'settings'         => 'register_settings_tools',
            'plugin_theme'     => 'register_plugin_theme_tools',
            'menu'             => 'register_menu_tools',
            'meta'             => 'register_meta_tools',
            'search'           => 'register_search_tools',
            'attribution'      => 'register_attribution_tools',
            'ucp'              => 'register_ucp_tools',
            'vendors'          => 'register_vendors_tools',
            'booking'          => 'register_booking_tools',
            'forms'            => 'register_forms_tools',
            'backup'           => 'register_backup_tools',
            'migration'        => 'register_migration_tools',
        );

        foreach ( $categories as $cat => $method ) {
            $before = count( $this->tools );
            $this->$method();
            $after = count( $this->tools );
            $this->registration_audit[ $cat ] = array(
                'method'  => $method,
                'added'   => $after - $before,
                'skipped' => ( $after - $before ) === 0,
            );
        }

        // Annotate categories whose register_*_tools early-returns when a
        // required core class isn't loaded. If the audit shows 0 tools AND
        // class_exists() is false, that's the deploy-integrity smoking gun
        // (partial ZIP upload — main plugin file says new version but class
        // files weren't refreshed). Vendor-FR-006 + FR-007 root cause.
        $gates = array(
            'crm'              => 'LuwiPress_CRM_Bridge',
            'review'           => 'LuwiPress_Review_Analytics',
            'linker'           => 'LuwiPress_Internal_Linker',
            'knowledge_graph'  => 'LuwiPress_Knowledge_Graph',
            'elementor'        => 'LuwiPress_Elementor',
            'woo'              => 'WooCommerce',
            'search'           => 'LuwiPress_Search_Index',
            'attribution'      => 'LuwiPress_ACP_Attribution',
            'vendors'          => 'LuwiPress_Vendors',
            'booking'          => 'LuwiPress_Booking',
        );
        foreach ( $gates as $cat => $cls ) {
            if ( ! empty( $this->registration_audit[ $cat ] ) && ! empty( $this->registration_audit[ $cat ]['skipped'] ) ) {
                $this->registration_audit[ $cat ]['gate_class']        = $cls;
                $this->registration_audit[ $cat ]['gate_class_exists'] = class_exists( $cls );
            }
        }

        // Single warning log if ANY known gate failed (deploy integrity hint).
        $missing = array();
        foreach ( $this->registration_audit as $cat => $info ) {
            if ( ! empty( $info['skipped'] ) && isset( $info['gate_class'] ) && empty( $info['gate_class_exists'] ) ) {
                $missing[ $cat ] = $info['gate_class'];
            }
        }
        if ( ! empty( $missing ) && class_exists( 'LuwiPress_Logger' ) ) {
            LuwiPress_Logger::log(
                sprintf(
                    'WebMCP %s: %d tool %s registered 0 tools — required class(es) missing. Likely a partial ZIP deploy. Check /webmcp/deploy-audit.',
                    LUWIPRESS_WEBMCP_VERSION,
                    count( $missing ),
                    count( $missing ) === 1 ? 'category' : 'categories'
                ),
                'warning',
                array( 'missing' => $missing )
            );
        }

        // The deploy-audit tool itself ships last so the audit array is
        // fully populated when the tool's data is read.
        $this->register_deploy_audit_tool();

        /**
         * Allow third-party extensions to register additional MCP tools.
         *
         * @param LuwiPress_WebMCP $webmcp  The WebMCP instance.
         */
        do_action( 'luwipress_webmcp_register_tools', $this );
    }

    /**
     * Public accessor for the registration audit (read-only).
     *
     * @since 1.0.27
     * @return array
     */
    public function get_registration_audit() {
        return $this->registration_audit;
    }

    /**
     * Register the `webmcp_deploy_audit` MCP tool. Surfaces the per-category
     * registration outcome so operators (and Tapadum-style remote vendors)
     * can diagnose silent class_exists-gated skips without server-side log
     * access. See Vendor-FR-006 + FR-007 closure notes.
     *
     * @since 1.0.27
     */
    private function register_deploy_audit_tool() {
        $this->register_tool( 'webmcp_deploy_audit', array(
            'description' => 'WebMCP deploy integrity audit: per-category tool registration count + which class-gated categories registered 0 tools because their required core class is missing. Use this when a tool you expect to see is absent from tool_search — a `skipped: true, gate_class_exists: false` entry means the deployed ZIP is partial (main plugin file at new version but the gated module class file is stale or missing). Returns: { webmcp_version, total_tools, categories: { <name>: { method, added, skipped, gate_class?, gate_class_exists? } }, missing_classes: [ ... ] }.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'WebMCP Deploy Audit', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $audit   = $this->registration_audit;
            $missing = array();
            foreach ( $audit as $cat => $info ) {
                if ( ! empty( $info['skipped'] ) && isset( $info['gate_class'] ) && empty( $info['gate_class_exists'] ) ) {
                    $missing[] = array(
                        'category'   => $cat,
                        'gate_class' => $info['gate_class'],
                    );
                }
            }
            return array(
                'webmcp_version'  => defined( 'LUWIPRESS_WEBMCP_VERSION' ) ? LUWIPRESS_WEBMCP_VERSION : 'unknown',
                'core_version'    => defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : 'unknown',
                'total_tools'     => count( $this->tools ),
                'categories'      => $audit,
                'missing_classes' => $missing,
            );
        } );
    }

    /**
     * Public API: register a custom MCP tool.
     *
     * @param string   $name        Tool name (e.g., 'my_custom_tool').
     * @param array    $schema      MCP tool schema with keys:
     *                              - description (string, required)
     *                              - inputSchema (object, required)
     *                              - annotations (array, optional) — MCP 2025-03-26 tool hints:
     *                                  - title (string) — human-readable display name
     *                                  - readOnlyHint (bool) — tool doesn't modify state
     *                                  - destructiveHint (bool) — tool may delete/destroy data
     *                                  - idempotentHint (bool) — safe to call multiple times
     *                                  - openWorldHint (bool) — tool interacts with external entities
     * @param callable $handler     Function that receives arguments array and returns data.
     */
    public function register_tool( $name, $schema, $handler ) {
        $schema['name']          = $name;
        $this->tools[ $name ]    = $schema;
        $this->handlers[ $name ] = $handler;
    }

    /**
     * Public read-only access to the tool registry for cross-plugin consumers.
     * Used by LuwiPress core's Abilities API bridge to mirror MCP tools as
     * abilities (`wp_register_ability`) without duplicating definitions.
     *
     * @return array<string, array{schema: array, handler: callable}>
     */
    public function get_tool_registry() {
        $out = array();
        foreach ( $this->tools as $name => $schema ) {
            if ( ! isset( $this->handlers[ $name ] ) ) {
                continue;
            }
            $out[ $name ] = array(
                'schema'  => $schema,
                'handler' => $this->handlers[ $name ],
            );
        }
        return $out;
    }

    /* ───────────────────── System Tools ─────────────────────────────── */

    private function register_system_tools() {
        $this->register_tool( 'system_status', array(
            'description' => 'Get LuwiPress plugin status, version, and server info',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'System Status',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $api = LuwiPress_API::get_instance();
            return $api->get_status();
        } );

        $this->register_tool( 'system_health', array(
            'description' => 'Run health check (database, filesystem, memory)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Health Check',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $api = LuwiPress_API::get_instance();
            return $api->health_check();
        } );

        $this->register_tool( 'system_logs', array(
            'description' => 'Retrieve recent log entries from LuwiPress',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max entries (default 50, max 200)' ),
                    'level' => array( 'type' => 'string', 'enum' => array( 'info', 'warning', 'error' ), 'description' => 'Filter by level' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'View Logs',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/logs' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $request->set_param( 'level', $args['level'] ?? '' );
            $api = LuwiPress_API::get_instance();
            return $api->get_recent_logs( $request );
        } );

        $this->register_tool( 'site_config', array(
            'description' => 'Get full WordPress + WooCommerce + plugin environment snapshot. Returns detected plugins, languages, SEO settings, WC config, etc.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Site Configuration',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/site-config' );
            $data    = $config->get_site_config( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'health_score_get', array(
            'description' => 'Get the LuwiPress Content Health Score — the composite store-health number plus its per-pillar breakdown (SEO Coverage, AEO Coverage, Translation Health, Schema Coverage, Brand Voice, Content Depth). Also returns the pillar config (weight, target, action threshold). Mirrors GET /health/score + /health/pillars.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'force' => array(
                        'type'        => 'boolean',
                        'description' => 'Bypass the 15-minute cache and recompute from scratch (default false).',
                    ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Content Health Score',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Health_Score' ) ) {
                return array( 'error' => 'Content Health Score module is not available on this site.' );
            }
            $force = ! empty( $args['force'] );
            $hs    = LuwiPress_Health_Score::get_instance();
            return array(
                'score'   => $hs->compute( $force ),
                'pillars' => array_values( $hs->get_pillars() ),
            );
        } );

        // ─── CPT Engine — generic custom-post-type management (3.7.x+) ───────
        $this->register_tool( 'cpt_types_list', array(
            'description' => 'List all LuwiPress CPT Engine type definitions — the Vendors preset plus any operator-defined CPTs (Events, Team, Venues, …). Each entry returns post_type, labels, field_schema (custom attributes), taxonomies, schema mapping and WooCommerce relationship. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'CPT Types', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            return array( 'types' => array_values( LuwiPress_CPT_Engine::get_instance()->get_types() ) );
        } );

        $this->register_tool( 'cpt_type_set', array(
            'description' => 'Create or update an operator-defined CPT in the LuwiPress CPT Engine (e.g. Events, Team). The post type registers on the next request and rewrite rules are flushed. field_schema entries: { key, type (text|textarea|number|url|email|image|date|datetime|select|relationship), label, translatable }. taxonomies entries: { slug, label, hierarchical, translatable, is_attribute }. Reserved/existing post types and the lwp_vendor preset cannot be overridden.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'          => array( 'type' => 'string',  'description' => 'Stable engine key, e.g. "events" (required).' ),
                    'post_type'    => array( 'type' => 'string',  'description' => 'Post type slug, <=20 chars, [a-z0-9_-] (defaults to key).' ),
                    'labels'       => array( 'type' => 'object',  'description' => '{ singular, plural, menu_icon (dashicons-*) }' ),
                    'permalink'    => array( 'type' => 'object',  'description' => '{ archive_slug, with_front }' ),
                    'field_schema' => array( 'type' => 'array',   'description' => 'Custom fields / attributes for this CPT.' ),
                    'taxonomies'   => array( 'type' => 'array',   'description' => 'Taxonomies (incl. attribute-taxonomies) for this CPT.' ),
                    'enabled'      => array( 'type' => 'boolean', 'description' => 'Default true.' ),
                ),
                'required'   => array( 'key' ),
            ),
            'annotations' => array( 'title' => 'Create/Update CPT', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_set_type', $args );
        } );

        $this->register_tool( 'cpt_type_delete', array(
            'description' => 'Delete an operator-defined CPT type from the LuwiPress CPT Engine by its engine key. Stops registering the CPT (existing posts are not deleted). The Vendors preset cannot be deleted.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key' => array( 'type' => 'string', 'description' => 'Engine key to delete (required).' ),
                ),
                'required'   => array( 'key' ),
            ),
            'annotations' => array( 'title' => 'Delete CPT', 'readOnlyHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_delete_type', $args );
        } );

        $this->register_tool( 'cpt_wpml_config_get', array(
            'description' => 'Get the WPML/Polylang language configuration the CPT Engine derives from every enabled type (Vendors, Events, and any operator-defined CPTs): translatable post types, taxonomies, and custom fields (translate vs copy). Returns a ready-to-paste wpml-config.xml string. Presets ship in the plugin\'s wpml-config.xml (auto-read by WPML + Polylang) and Polylang gets all engine types via filters; this payload is for pasting operator-defined CPTs into WPML → Settings → Custom XML Configuration. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'WPML/Polylang Config', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $engine = LuwiPress_CPT_Engine::get_instance();
            return array(
                'config'          => $engine->build_wpml_config(),
                'xml'             => $engine->build_wpml_config_xml(),
                'wpml_active'     => defined( 'ICL_SITEPRESS_VERSION' ),
                'polylang_active' => function_exists( 'pll_languages_list' ),
            );
        } );

        // ─── FR-035 + FR-034: relationship fields + WPML auto-register ──────
        $this->register_tool( 'cpt_related_get', array(
            'description' => 'Query a CPT Engine relationship field. direction=forward returns the posts a given post references via the field; direction=reverse returns the posts that reference the given post via that field (bidirectional, e.g. "which products list this teacher"). Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'          => array( 'type' => 'integer', 'description' => 'Post to look up (required).' ),
                    'field'            => array( 'type' => 'string',  'description' => 'Relationship meta key (required).' ),
                    'direction'        => array( 'type' => 'string',  'description' => 'forward (default) or reverse.' ),
                    'source_post_type' => array( 'type' => 'string',  'description' => 'reverse only: constrain referencing posts to this type.' ),
                ),
                'required'   => array( 'post_id', 'field' ),
            ),
            'annotations' => array( 'title' => 'Get Related', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_related_get', $args );
        } );

        $this->register_tool( 'cpt_related_set', array(
            'description' => 'Set the forward references of a CPT Engine relationship field on a post (e.g. attach teachers to a course, set a vendor\'s parent atelier). Each id is validated against the field\'s target post type; the value is stored as a canonical JSON id array. Pass an empty ids array to clear.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post to write to (required).' ),
                    'field'   => array( 'type' => 'string',  'description' => 'Relationship meta key (required).' ),
                    'ids'     => array( 'type' => 'array',   'description' => 'Referenced post IDs.' ),
                ),
                'required'   => array( 'post_id', 'field' ),
            ),
            'annotations' => array( 'title' => 'Set Related', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_related_set', $args );
        } );

        $this->register_tool( 'cpt_wpml_autoregister_set', array(
            'description' => 'Toggle the BEST-EFFORT WPML runtime auto-register for operator-defined CPTs (the wpml_config_array filter). LOW-CONFIDENCE: WPML has no stable runtime API and its config array shape is version-sensitive — OFF by default. The supported path is cpt_wpml_config_get (pasteable XML). Enable only to test on a specific WPML version; verify translations register before relying on it.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled' => array( 'type' => 'boolean', 'description' => 'true to enable runtime injection, false to disable (default).' ),
                ),
                'required'   => array( 'enabled' ),
            ),
            'annotations' => array( 'title' => 'WPML Auto-register', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $on = ! empty( $args['enabled'] );
            update_option( 'luwipress_cpt_wpml_autoregister', $on );
            return array(
                'success'           => true,
                'wpml_autoregister' => $on,
                'note'              => $on
                    ? 'Runtime wpml_config_array injection enabled (applies next request). Verify your operator CPTs become translatable on this WPML version; if not, disable and paste cpt_wpml_config_get XML instead.'
                    : 'Runtime injection disabled (default). Use cpt_wpml_config_get for the pasteable XML.',
            );
        } );

        $this->register_tool( 'cpt_attribution_reconcile_scan', array(
            'description' => 'Scan (read-only) for WooCommerce products whose vendor/CPT attribution TAXONOMY term is not reflected in the canonical attribution META (e.g. _lwp_vendor_ids). These are products attributed term-first whose meta is empty/divergent, so meta readers (theme work grid, Knowledge Graph, grids) miss them. Returns per-type counts (missing/divergent/ok) + a sample. Pairs with the FR-017 schema fix.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'description' => 'Limit to one CPT post type (e.g. lwp_vendor); omit for all attribution types.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Attribution Reconcile Scan', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $pt = isset( $args['post_type'] ) ? sanitize_key( (string) $args['post_type'] ) : '';
            return LuwiPress_CPT_Engine::get_instance()->reconcile_attribution_scan( $pt );
        } );

        $this->register_tool( 'cpt_attribution_reconcile_run', array(
            'description' => 'Write canonical attribution META from the TAXONOMY terms for products where they diverge -- additive union, never removes attribution. DRY-RUN BY DEFAULT (set dry_run=false to apply). Batched via limit. Fixes the meta side so theme work grids + Knowledge Graph see term-attributed products. Run cpt_attribution_reconcile_scan first.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string',  'description' => 'Limit to one CPT post type; omit for all.' ),
                    'dry_run'   => array( 'type' => 'boolean', 'description' => 'Preview without writing (default true).' ),
                    'limit'     => array( 'type' => 'integer', 'description' => 'Max products to change per call (default 500).' ),
                ),
            ),
            'annotations' => array( 'title' => 'Attribution Reconcile Run', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $pt      = isset( $args['post_type'] ) ? sanitize_key( (string) $args['post_type'] ) : '';
            $dry_run = ! isset( $args['dry_run'] ) ? true : (bool) $args['dry_run'];
            $limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 500;
            return LuwiPress_CPT_Engine::get_instance()->reconcile_attribution_run( $pt, $dry_run, $limit );
        } );

        $this->register_tool( 'cpt_type_get', array(
            'description' => 'Read a single CPT Engine type definition by its engine key (e.g. "team", "vendors"): post_type, labels, field_schema, taxonomies, schema_mapping, woocommerce. Use this before cpt_field_set / cpt_schema_map to see the current field keys. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key' => array( 'type' => 'string', 'description' => 'Engine key (required).' ),
                ),
                'required'   => array( 'key' ),
            ),
            'annotations' => array( 'title' => 'Get CPT Type', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $key = isset( $args['key'] ) ? sanitize_key( (string) $args['key'] ) : '';
            $def = LuwiPress_CPT_Engine::get_instance()->get_type( $key );
            if ( ! $def ) {
                return array( 'error' => "No CPT type with key '{$key}'." );
            }
            return array( 'type' => $def );
        } );

        $this->register_tool( 'cpt_field_set', array(
            'description' => 'Add or update ONE field (attribute) on an operator-defined CPT WITHOUT resending the whole definition (unlike cpt_type_set, which is full-replace and drops any omitted field). Fetches the type, upserts the field by key, and saves. field: { key (required), type (text|textarea|number|url|email|image|date|datetime|select|relationship), label, translatable, in_elementor, schema_prop }. Only operator-defined types can be edited (Vendors / Events presets are protected).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'type_key' => array( 'type' => 'string', 'description' => 'Engine key of the CPT to edit (required).' ),
                    'field'    => array( 'type' => 'object', 'description' => 'Field to add/update; must include "key".' ),
                ),
                'required'   => array( 'type_key', 'field' ),
            ),
            'annotations' => array( 'title' => 'Set CPT Field', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $key   = isset( $args['type_key'] ) ? sanitize_key( (string) $args['type_key'] ) : '';
            $field = ( isset( $args['field'] ) && is_array( $args['field'] ) ) ? $args['field'] : array();
            if ( '' === $key || empty( $field['key'] ) ) {
                return array( 'error' => 'type_key and field.key are required.' );
            }
            $engine = LuwiPress_CPT_Engine::get_instance();
            $def    = $engine->get_type( $key );
            if ( ! $def ) {
                return array( 'error' => "No CPT type with key '{$key}'." );
            }
            if ( 'option' !== ( $def['source'] ?? '' ) ) {
                return array( 'error' => "Type '{$key}' is a preset and cannot be edited via MCP — configure it in its own settings." );
            }
            $fields = ( isset( $def['field_schema'] ) && is_array( $def['field_schema'] ) ) ? $def['field_schema'] : array();
            $fkey   = sanitize_key( (string) $field['key'] );
            $found  = false;
            foreach ( $fields as &$f ) {
                if ( isset( $f['key'] ) && $f['key'] === $fkey ) {
                    $f        = array_merge( $f, $field );
                    $f['key'] = $fkey;
                    $found    = true;
                    break;
                }
            }
            unset( $f );
            if ( ! $found ) {
                $field['key'] = $fkey;
                $fields[]     = $field;
            }
            $def['field_schema'] = $fields;
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_set_type', $def );
        } );

        $this->register_tool( 'cpt_schema_map', array(
            'description' => 'Make an operator-defined CPT emit Schema.org JSON-LD on the front-end: set its schema_mapping @type (Person, Organization, LocalBusiness, Event, …) and, optionally, map each field onto a schema.org property — in ONE call, without resending the whole definition. field_props is { field_key: schema_property }, e.g. { "_lwp_team_role":"jobTitle", "_lwp_team_specialty":"knowsAbout", "_lwp_team_facebook":"sameAs", "_lwp_team_instagram":"sameAs", "_lwp_team_location":"address.addressLocality" }. Several fields mapped to sameAs collect into one array; knowsAbout/keywords comma-split into arrays; dotted props (address.addressLocality) nest into objects (an address parent is typed PostalAddress). Only operator-defined types.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'type_key'    => array( 'type' => 'string', 'description' => 'Engine key of the CPT (required).' ),
                    'schema_type' => array( 'type' => 'string', 'description' => 'Schema.org @type, e.g. "Person" (required).' ),
                    'field_props' => array( 'type' => 'object', 'description' => 'Optional { field_key: schema_property } map.' ),
                ),
                'required'   => array( 'type_key', 'schema_type' ),
            ),
            'annotations' => array( 'title' => 'Map CPT Schema', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_CPT_Engine' ) ) {
                return array( 'error' => 'CPT Engine is not available on this site.' );
            }
            $key    = isset( $args['type_key'] ) ? sanitize_key( (string) $args['type_key'] ) : '';
            $stype  = isset( $args['schema_type'] ) ? trim( (string) $args['schema_type'] ) : '';
            $fprops = ( isset( $args['field_props'] ) && is_array( $args['field_props'] ) ) ? $args['field_props'] : array();
            if ( '' === $key || '' === $stype ) {
                return array( 'error' => 'type_key and schema_type are required.' );
            }
            $engine = LuwiPress_CPT_Engine::get_instance();
            $def    = $engine->get_type( $key );
            if ( ! $def ) {
                return array( 'error' => "No CPT type with key '{$key}'." );
            }
            if ( 'option' !== ( $def['source'] ?? '' ) ) {
                return array( 'error' => "Type '{$key}' is a preset and cannot be edited via MCP." );
            }
            $mapping               = ( isset( $def['schema_mapping'] ) && is_array( $def['schema_mapping'] ) ) ? $def['schema_mapping'] : array();
            $mapping['type']       = sanitize_text_field( $stype );
            $def['schema_mapping'] = $mapping;
            if ( ! empty( $fprops ) ) {
                $fields = ( isset( $def['field_schema'] ) && is_array( $def['field_schema'] ) ) ? $def['field_schema'] : array();
                foreach ( $fields as &$f ) {
                    $fk = isset( $f['key'] ) ? (string) $f['key'] : '';
                    if ( '' !== $fk && array_key_exists( $fk, $fprops ) ) {
                        $f['schema_prop'] = sanitize_text_field( (string) $fprops[ $fk ] );
                    }
                }
                unset( $f );
                $def['field_schema'] = $fields;
            }
            return $this->proxy_rest_post( 'LuwiPress_CPT_Engine', 'rest_set_type', $def );
        } );

        // ─── Events — CPT Engine preset #2 (3.9.0+; dormant until enabled) ───
        $this->register_tool( 'event_settings_get', array(
            'description' => 'Read the LuwiPress Events module config (CPT Engine preset #2): enabled state, archive slug, labels, field toggles, and published event count. Events is dormant by default — enable it with event_settings_set. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Events Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Events' ) ) {
                return array( 'error' => 'Events module is not available on this site.' );
            }
            $data = LuwiPress_Events::get_instance()->rest_get_settings();
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'event_settings_set', array(
            'description' => 'Create/update the LuwiPress Events module config. Set { "enabled": true } to register the lwp_event CPT (flushes rewrite rules). Other keys: archive_slug, singular_label, plural_label, menu_icon, archive_enabled, category_enabled, show_venue/show_online/show_offers/show_organizer/show_performer (booleans).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled'        => array( 'type' => 'boolean', 'description' => 'Register the Events CPT + menu.' ),
                    'archive_slug'   => array( 'type' => 'string',  'description' => 'URL base, e.g. "events".' ),
                    'singular_label' => array( 'type' => 'string' ),
                    'plural_label'   => array( 'type' => 'string' ),
                    'menu_icon'      => array( 'type' => 'string',  'description' => 'A dashicons-* class.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Set Events Settings', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Events', 'rest_update_settings', $args );
        } );

        $this->register_tool( 'events_list', array(
            'description' => 'List LuwiPress events (lwp_event) with their date/venue/ticketing meta and attached organizer/performer vendor IDs. Returns empty when the Events module is disabled. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit'   => array( 'type' => 'integer', 'description' => '1-200, default 50.' ),
                    'orderby' => array( 'type' => 'string',  'description' => 'date (default) | title | menu_order.' ),
                    'order'   => array( 'type' => 'string',  'description' => 'ASC | DESC (default).' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Events', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Events', 'rest_list', $args );
        } );

        $this->register_tool( 'event_meta_set', array(
            'description' => 'Write event fields on a single lwp_event post. Date fields are ISO 8601 (e.g. "2026-07-15T19:30" or "2026-07-15"). Vendor links are arrays of lwp_vendor IDs.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'            => array( 'type' => 'integer', 'description' => 'Event post ID (required).' ),
                    'start'         => array( 'type' => 'string',  'description' => 'Start (ISO 8601) — required for schema + ICS.' ),
                    'end'           => array( 'type' => 'string',  'description' => 'End (ISO 8601).' ),
                    'status'        => array( 'type' => 'string',  'description' => 'EventScheduled|EventCancelled|EventPostponed|EventRescheduled|EventMovedOnline.' ),
                    'attendance'    => array( 'type' => 'string',  'description' => 'offline | online | mixed.' ),
                    'venue_name'    => array( 'type' => 'string' ),
                    'venue_address' => array( 'type' => 'string' ),
                    'online_url'    => array( 'type' => 'string' ),
                    'ticket_url'    => array( 'type' => 'string' ),
                    'price'         => array( 'type' => 'string' ),
                    'currency'      => array( 'type' => 'string',  'description' => '3-letter code, e.g. USD.' ),
                    'organizers'    => array( 'type' => 'array',   'description' => 'lwp_vendor IDs organizing the event.' ),
                    'performers'    => array( 'type' => 'array',   'description' => 'lwp_vendor IDs performing at the event.' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Set Event Meta', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Events', 'rest_set_meta', $args );
        } );

        $this->register_tool( 'event_ics', array(
            'description' => 'Return the RFC 5545 iCalendar (.ics) text for a single event plus a public download URL. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id' => array( 'type' => 'integer', 'description' => 'Event post ID (required).' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Event ICS', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Events', 'rest_get_ics', $args );
        } );

        $this->register_tool( 'cache_purge', array(
            'description' => 'Purge caches across LiteSpeed, WP Rocket, W3TC, WP Super Cache, Elementor CSS, and WordPress object cache. Use after bulk content or style updates to make new output visible immediately.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'targets' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'all', 'elementor', 'litespeed', 'wp_rocket', 'w3tc', 'super_cache', 'object_cache' ) ),
                        'description' => 'Which caches to clear. Default: ["all"].',
                    ),
                    'post_id' => array( 'type' => 'integer', 'description' => 'Optional post ID for per-post regeneration (Elementor CSS).' ),
                ),
            ),
            'annotations' => array( 'title' => 'Purge Caches', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/cache/purge' );
            $request->set_param( 'targets', $args['targets'] ?? array( 'all' ) );
            if ( ! empty( $args['post_id'] ) ) {
                $request->set_param( 'post_id', absint( $args['post_id'] ) );
            }
            $data = $api->handle_cache_purge( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Content Tools ────────────────────────────── */

    private function register_content_tools() {
        $this->register_tool( 'content_get_posts', array(
            'description' => 'Search and retrieve WordPress posts/products with filtering',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type slug — any registered public type: post, page, product, lwp_vendor, lwp_event, lwp_team, or any operator-defined CPT Engine type (cpt_types_list shows them). Default: post.' ),
                    'status'    => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'any' ), 'description' => 'Post status (default: any)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search query for title/content' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 100)' ),
                    'page'      => array( 'type' => 'integer', 'description' => 'Page number' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Search Posts',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $api = LuwiPress_API::get_instance();
            $data = array(
                'action'     => 'get_posts',
                'auth_token' => 'internal',
                'request_id' => uniqid( 'mcp_' ),
                'post_type'  => $args['post_type'] ?? 'post',
                'status'     => $args['status'] ?? 'any',
                'search'     => $args['search'] ?? '',
                'per_page'   => $args['per_page'] ?? 10,
                'page'       => $args['page'] ?? 1,
            );
            // Use WP_Query directly for cleaner MCP access
            return $this->query_posts( $data );
        } );

        $this->register_tool( 'content_create_post', array(
            'description' => 'Create a new WordPress post or page',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'     => array( 'type' => 'string', 'description' => 'Post title (required)' ),
                    'content'   => array( 'type' => 'string', 'description' => 'Post content (HTML)' ),
                    'excerpt'   => array( 'type' => 'string', 'description' => 'Post excerpt' ),
                    'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'description' => 'Post status' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type slug — any registered public type: post, page, product, lwp_vendor, lwp_event, lwp_team, or any operator-defined CPT Engine type (cpt_types_list shows them). Default: post.' ),
                    'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs' ),
                    'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names' ),
                ),
                'required'   => array( 'title' ),
            ),
            'annotations' => array(
                'title'            => 'Create Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            return $this->create_post_internal( $args );
        } );

        $this->register_tool( 'content_update_post', array(
            'description' => 'Update an existing WordPress post. Tags and categories replace the existing set; omit them to leave term assignments untouched.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'title'      => array( 'type' => 'string', 'description' => 'New title' ),
                    'content'    => array( 'type' => 'string', 'description' => 'New content (HTML)' ),
                    'excerpt'    => array( 'type' => 'string', 'description' => 'New excerpt' ),
                    'status'     => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'description' => 'New status' ),
                    'slug'       => array( 'type' => 'string', 'description' => 'New URL slug (post_name). WPML translation links are preserved — slug is per-language.' ),
                    'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs — replaces existing categories when provided' ),
                    'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names — replaces existing tags when provided. Pass [] to clear all tags.' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array(
                'title'            => 'Update Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            return $this->update_post_internal( $args );
        } );

        $this->register_tool( 'content_delete_post', array(
            'description' => 'Delete a WordPress post (moves to trash by default)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'           => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'force_delete' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing (default: false)' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array(
                'title'            => 'Delete Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => true,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            $post_id = intval( $args['id'] );
            $force   = ! empty( $args['force_delete'] );
            // Switch WPML to all languages so we can find any post regardless of language
            do_action( 'wpml_switch_language', 'all' );
            $post = get_post( $post_id );
            if ( ! $post ) {
                do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
                throw new Exception( 'Post not found' );
            }
            $result = wp_delete_post( $post_id, $force );
            do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete post' );
            }
            return array( 'id' => $post_id, 'deleted' => true, 'force' => $force, 'title' => $post->post_title );
        } );

        $this->register_tool( 'content_opportunities', array(
            'description' => 'Find content gaps: missing SEO meta, thin content, stale content, missing translations, missing alt text',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max items per category (default 50)' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Content Opportunities',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $data = $config->get_content_opportunities( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'content_stale', array(
            'description' => 'List stale content (posts/products) that have not been modified within the freshness window. Default: products older than 180 days.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'days'      => array( 'type' => 'integer', 'description' => 'Freshness window in days (default 180)', 'minimum' => 1, 'maximum' => 3650 ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to scan (default product)' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Max items to return (default 50, max 200)', 'minimum' => 1, 'maximum' => 200 ),
                ),
            ),
        ), function ( $args ) {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/stale' );
            if ( isset( $args['days'] ) )      { $request->set_param( 'days',      intval( $args['days'] ) ); }
            if ( isset( $args['post_type'] ) ) { $request->set_param( 'post_type', sanitize_key( $args['post_type'] ) ); }
            if ( isset( $args['per_page'] ) )  { $request->set_param( 'per_page',  intval( $args['per_page'] ) ); }
            $data    = $ai->handle_stale_content( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── content_promotional_phrase_audit (1.0.32+) ──────────────
        // Daily GMC compliance scan: surfaces promotional-pressure phrases
        // ("free shipping", "limited time", "şimdi al", …) across meta
        // title/description (high severity → GMC disapproval risk), post
        // title/excerpt (medium → feed-syndicated fallback) and body
        // content (low → editorial cleanup). Multilingual phrase bank
        // (en/tr/fr/it/es); per-post language auto-detect via WPML/Polylang.
        $this->register_tool( 'content_promotional_phrase_audit', array(
            'description' => 'Scan posts for promotional/urgency phrases that trigger Google Merchant Center disapprovals. Severity ladder: high (meta title/desc), medium (post title/excerpt), low (body). Pass post_id for a single post, or post_type + category_id for a sweep. Multilingual; per-post language auto-detect via WPML/Polylang. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'         => array( 'type' => 'integer', 'description' => 'Audit a single post only' ),
                    'post_type'       => array( 'type' => 'string',  'description' => 'Post type to scan (default product)' ),
                    'category_id'     => array( 'type' => 'integer', 'description' => 'Restrict to a product_cat term (only when post_type=product)' ),
                    'lang'            => array( 'type' => 'string',  'description' => 'Force language code (en/tr/fr/it/es); default = per-post detect' ),
                    'scope'           => array( 'type' => 'string',  'description' => 'meta | body | all (default all)', 'enum' => array( 'meta', 'body', 'all' ) ),
                    'limit'           => array( 'type' => 'integer', 'description' => 'Max posts to scan (default 50, max 500)', 'minimum' => 1, 'maximum' => 500 ),
                    'offset'          => array( 'type' => 'integer', 'description' => 'Pagination offset', 'minimum' => 0 ),
                    'only_violations' => array( 'type' => 'boolean', 'description' => 'Return only posts with findings (default true)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Promotional Phrase Audit', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Content_Audit', 'rest_promotional_phrase_audit', $args );
        } );

        // ─── content_promotional_phrase_bank (1.0.32+) ───────────────
        // Read-only introspection of the phrase bank itself. Useful for the
        // operator to confirm coverage before running a sweep, and for the
        // agentic loop to surface which phrases will be matched.
        $this->register_tool( 'content_promotional_phrase_bank', array(
            'description' => 'Return the canonical promotional-phrase bank used by content_promotional_phrase_audit. Read-only; lists per-language phrase counts and the full bank for inspection.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new \stdClass(),
            ),
            'annotations' => array( 'title' => 'Promotional Phrase Bank', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function () {
            $audit   = LuwiPress_Content_Audit::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/promotional-phrase-bank' );
            $data    = $audit->rest_phrase_bank( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── content_ai_tell_audit ───────────────────────────────────
        // Twin of content_promotional_phrase_audit, different phrase bank:
        // detects LLM "tell" phrases ("In the world of…", "stands as one of
        // the most…", "In conclusion,") that flag machine-written copy and
        // weaken brand voice. Multilingual (en/tr/fr/it/es); per-post detect.
        $this->register_tool( 'content_ai_tell_audit', array(
            'description' => 'Scan posts for AI-tell phrases — the giveaway LLM boilerplate ("In the world of…", "stands as one of the most…", "In conclusion,") that signals machine-written copy and weakens brand voice. Same engine as content_promotional_phrase_audit, different phrase bank. Pass post_id for a single post, or post_type + category_id for a sweep. Multilingual; per-post language auto-detect via WPML/Polylang. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'         => array( 'type' => 'integer', 'description' => 'Audit a single post only' ),
                    'post_type'       => array( 'type' => 'string',  'description' => 'Post type to scan (default product)' ),
                    'category_id'     => array( 'type' => 'integer', 'description' => 'Restrict to a product_cat term (only when post_type=product)' ),
                    'lang'            => array( 'type' => 'string',  'description' => 'Force language code (en/tr/fr/it/es); default = per-post detect' ),
                    'scope'           => array( 'type' => 'string',  'description' => 'meta | body | all (default all)', 'enum' => array( 'meta', 'body', 'all' ) ),
                    'limit'           => array( 'type' => 'integer', 'description' => 'Max posts to scan (default 50, max 500)', 'minimum' => 1, 'maximum' => 500 ),
                    'offset'          => array( 'type' => 'integer', 'description' => 'Pagination offset', 'minimum' => 0 ),
                    'only_violations' => array( 'type' => 'boolean', 'description' => 'Return only posts with findings (default true)' ),
                ),
            ),
            'annotations' => array( 'title' => 'AI-Tell Phrase Audit', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Content_Audit', 'rest_ai_tell_audit', $args );
        } );

        // ─── content_ai_tell_bank ────────────────────────────────────
        $this->register_tool( 'content_ai_tell_bank', array(
            'description' => 'Return the canonical AI-tell phrase bank used by content_ai_tell_audit. Read-only; lists per-language phrase counts and the full bank for inspection.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new \stdClass(),
            ),
            'annotations' => array( 'title' => 'AI-Tell Phrase Bank', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function () {
            $audit   = LuwiPress_Content_Audit::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/ai-tell-bank' );
            $data    = $audit->rest_ai_tell_bank( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── SEO / Enrichment Tools ──────────────────── */

    private function register_seo_tools() {
        $this->register_tool( 'seo_enrich_product', array(
            'description' => 'Trigger AI enrichment for a WooCommerce product (generates descriptions, meta, FAQ, schema via the LuwiPress AI pipeline). Pass force_regen_faq=true to clear existing FAQ meta before the AI call so the pipeline genuinely regenerates instead of echoing cached content (3.1.42-hotfix3, BUG-007).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'      => array( 'type' => 'integer', 'description' => 'WooCommerce product ID (required)' ),
                    'force_regen_faq' => array( 'type' => 'boolean', 'description' => 'Clear existing _luwipress_faq before enrichment so the AI cannot return cached/identical answers. Default false.' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array(
                'title'            => 'Enrich Product (AI)',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => true,  // Triggers async AI pipeline + external API call
            ),
        ), function ( $args ) {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/product/enrich' );
            $body = array( 'product_id' => intval( $args['product_id'] ) );
            $opts = array();
            if ( ! empty( $args['force_regen_faq'] ) ) {
                $opts['force_regen_faq'] = true;
            }
            if ( $opts ) {
                $body['options'] = $opts;
            }
            $request->set_body_params( $body );
            foreach ( $body as $k => $v ) {
                $request->set_param( $k, $v );
            }
            $data = $ai->handle_enrich_request( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_enrich_batch', array(
            'description' => 'Trigger AI enrichment for multiple products at once',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_ids' => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                        'description' => 'Array of product IDs to enrich',
                    ),
                ),
                'required'   => array( 'product_ids' ),
            ),
        ), function ( $args ) {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/product/enrich-batch' );
            $request->set_body_params( array( 'product_ids' => array_map( 'intval', $args['product_ids'] ) ) );
            $data = $ai->handle_batch_enrich_request( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_batch_status', array(
            'description' => 'Check status of a batch enrichment job',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/product/enrich-batch/status' );
            $data    = $ai->handle_batch_status( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_rank_math_meta', array(
            'description' => 'Get Rank Math / Yoast SEO meta fields for a post',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post/product ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/test/rank-math' );
            $request->set_param( 'post_id', intval( $args['post_id'] ) );
            $data = $api->test_rank_math_meta( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_write_meta', array(
            'description' => 'Write SEO meta fields (title, description, focus keyword) for a post via detected plugin (Rank Math, Yoast, AIOSEO)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'          => array( 'type' => 'integer', 'description' => 'Post/product ID (required)' ),
                    'meta_title'       => array( 'type' => 'string', 'description' => 'SEO title' ),
                    'meta_description' => array( 'type' => 'string', 'description' => 'SEO meta description' ),
                    'focus_keyword'    => array( 'type' => 'string', 'description' => 'Focus keyword' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'            => 'Write SEO Meta',
                'readOnlyHint'     => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            // Partial-update semantics: only forward fields the caller actually sent.
            // Coercing missing args to '' caused the handler to clear existing meta on the target post (1.0.13 bug).
            $payload = array( 'post_id' => intval( $args['post_id'] ) );
            if ( array_key_exists( 'meta_title', $args ) ) {
                $payload['title'] = $args['meta_title'];
            }
            if ( array_key_exists( 'meta_description', $args ) ) {
                $payload['description'] = $args['meta_description'];
            }
            if ( array_key_exists( 'focus_keyword', $args ) ) {
                $payload['focus_keyword'] = $args['focus_keyword'];
            }
            return $this->proxy_rest_post( 'LuwiPress_API', 'handle_set_seo_meta', $payload );
        } );

        // ─── seo_meta_bulk (1.0.24+) ──────────────────────────────────
        // Bulk wrapper over POST /seo/meta-bulk. Powers the CSV reverse
        // flow (export → edit offline → re-upload) and any pre-launch
        // sweep that needs to write SEO meta on dozens of posts in one
        // call. Cap: 500 rows/request (enforced server-side). Missing
        // per-row fields leave existing values untouched.
        $this->register_tool( 'seo_meta_bulk', array(
            'description' => 'Bulk-write SEO meta (title, description, focus keyword) to up to 500 posts in a single call. Each row is { post_id, title?, description?, focus_keyword? }; missing fields leave existing values untouched. Returns { applied, skipped, error_rows, total }. Use this for CSV round-trip workflows and pre-launch category sweeps; for one-off writes use seo_write_meta.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'rows' => array(
                        'type'        => 'array',
                        'description' => 'Up to 500 rows, each: { post_id (required), title?, description?, focus_keyword? }.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'post_id'       => array( 'type' => 'integer', 'description' => 'Post/product ID (required per row).' ),
                                'title'         => array( 'type' => 'string', 'description' => 'SEO meta title.' ),
                                'description'   => array( 'type' => 'string', 'description' => 'SEO meta description.' ),
                                'focus_keyword' => array( 'type' => 'string', 'description' => 'Focus keyword.' ),
                            ),
                            'required' => array( 'post_id' ),
                        ),
                    ),
                ),
                'required' => array( 'rows' ),
            ),
            'annotations' => array(
                'title'            => 'Bulk Write SEO Meta',
                'readOnlyHint'     => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            $rows = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
            if ( empty( $rows ) ) {
                return array( 'error' => 'no_rows', 'message' => 'rows array is required (each: { post_id, title?, description?, focus_keyword? }).' );
            }
            if ( count( $rows ) > 500 ) {
                return array( 'error' => 'too_many_rows', 'message' => 'Max 500 rows per request; split and retry.', 'submitted' => count( $rows ) );
            }
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/seo/meta-bulk' );
            $request->set_param( 'rows', $rows );
            $data = $api->handle_bulk_seo_meta( $request );
            if ( is_wp_error( $data ) ) {
                return array(
                    'error'   => $data->get_error_code(),
                    'message' => $data->get_error_message(),
                );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── taxonomy_seo_meta_bulk (1.0.35+) ────────────────────────
        // Sibling of seo_meta_bulk for taxonomy terms — Rank Math title /
        // description / focus_keyword written to up to 500 terms per call.
        // Unblocks the Multi-language Taxonomy Editor (52 categories × 4
        // languages × 3 fields = 624 sequential taxonomy_meta_set calls
        // collapsed into one). WPML/Polylang siblings are resolved by the
        // caller (via wpml_term_translation_get); each (term, language)
        // pair is one row carrying its own term_id.
        $this->register_tool( 'taxonomy_seo_meta_bulk', array(
            'description' => 'Bulk-write Rank Math SEO meta (title, description, focus keyword) to up to 500 taxonomy terms in a single call. Each row is { term_id, taxonomy, title?, description?, focus_keyword? }; missing fields leave existing values untouched. Returns { applied, skipped, error_rows, total }. WPML/Polylang: each language is a separate term_id — caller resolves siblings (wpml_term_translation_get) and passes one row per (term, language) pair.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'rows' => array(
                        'type'        => 'array',
                        'description' => 'Up to 500 rows, each: { term_id (required), taxonomy (required), title?, description?, focus_keyword? }.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'term_id'       => array( 'type' => 'integer', 'description' => 'Term ID (required per row).' ),
                                'taxonomy'      => array( 'type' => 'string',  'description' => 'Taxonomy slug — e.g. product_cat (required per row).' ),
                                'title'         => array( 'type' => 'string',  'description' => 'SEO meta title (rank_math_title).' ),
                                'description'   => array( 'type' => 'string',  'description' => 'SEO meta description (rank_math_description; HTML preserved).' ),
                                'focus_keyword' => array( 'type' => 'string',  'description' => 'Focus keyword (rank_math_focus_keyword).' ),
                            ),
                            'required' => array( 'term_id', 'taxonomy' ),
                        ),
                    ),
                ),
                'required' => array( 'rows' ),
            ),
            'annotations' => array(
                'title'            => 'Bulk Write Taxonomy SEO Meta',
                'readOnlyHint'     => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            $rows = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
            if ( empty( $rows ) ) {
                return array( 'error' => 'no_rows', 'message' => 'rows array is required (each: { term_id, taxonomy, title?, description?, focus_keyword? }).' );
            }
            if ( count( $rows ) > 500 ) {
                return array( 'error' => 'too_many_rows', 'message' => 'Max 500 rows per request; split and retry.', 'submitted' => count( $rows ) );
            }
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/taxonomy/seo-meta-bulk' );
            $request->set_param( 'rows', $rows );
            $data = $api->handle_bulk_taxonomy_seo_meta( $request );
            if ( is_wp_error( $data ) ) {
                return array(
                    'error'   => $data->get_error_code(),
                    'message' => $data->get_error_message(),
                );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── media_alt_bulk (1.0.38+) ────────────────────────────────
        // Sibling of taxonomy_seo_meta_bulk for image alt text — wraps
        // POST /media/alt-bulk (core 3.5.6) so the Image Alt Bulk sweep /
        // CSV round-trip runs in one call. Each row is { attachment_id,
        // alt_text }; an empty/omitted alt_text CLEARS the existing alt.
        $this->register_tool( 'media_alt_bulk', array(
            'description' => 'Bulk-write image alt text to up to 500 media attachments in a single call. Each row is { attachment_id, alt_text }; an empty or omitted alt_text CLEARS the existing alt text. Returns { success, applied, skipped, error_rows, total }. Use this for the Image Alt Bulk sweep / CSV round-trip.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'rows' => array(
                        'type'        => 'array',
                        'description' => 'Up to 500 rows, each: { attachment_id (required), alt_text? }.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'attachment_id' => array( 'type' => 'integer', 'description' => 'Media attachment ID (required per row).' ),
                                'alt_text'      => array( 'type' => 'string',  'description' => 'Alt text; an empty or omitted value clears the existing alt.' ),
                            ),
                            'required' => array( 'attachment_id' ),
                        ),
                    ),
                ),
                'required' => array( 'rows' ),
            ),
            'annotations' => array(
                'title'            => 'Bulk Write Image Alt Text',
                'readOnlyHint'     => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            $rows = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
            if ( empty( $rows ) ) {
                return array( 'error' => 'no_rows', 'message' => 'rows array is required (each: { attachment_id, alt_text? }).' );
            }
            if ( count( $rows ) > 500 ) {
                return array( 'error' => 'too_many_rows', 'message' => 'Max 500 rows per request; split and retry.', 'submitted' => count( $rows ) );
            }
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/media/alt-bulk' );
            $request->set_param( 'rows', $rows );
            $data = $api->handle_bulk_media_alt( $request );
            if ( is_wp_error( $data ) ) {
                return array(
                    'error'   => $data->get_error_code(),
                    'message' => $data->get_error_message(),
                );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── AEO Tools ───────────────────────────────── */

    private function register_aeo_tools() {
        $this->register_tool( 'aeo_generate_faq', array(
            'description' => 'Trigger AI FAQ generation for a product (runs the LuwiPress AI pipeline)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'trigger_faq_generation', array(
                'product_id' => intval( $args['product_id'] ),
            ) );
        } );

        $this->register_tool( 'aeo_generate_howto', array(
            'description' => 'Trigger AI HowTo schema generation for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'trigger_howto_generation', array(
                'product_id' => intval( $args['product_id'] ),
            ) );
        } );

        $this->register_tool( 'aeo_coverage', array(
            'description' => 'Get AEO coverage report: which products have FAQ, HowTo, Speakable schema',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_AEO' ) ) {
                throw new Exception( 'AEO module not loaded' );
            }
            $aeo     = LuwiPress_AEO::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/aeo/coverage' );
            $data    = $aeo->get_aeo_coverage( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'aeo_save_faq', array(
            'description' => 'Save FAQ schema (JSON-LD FAQPage) for a product OR a taxonomy term. Pass product_id for product pages; pass term_id+taxonomy for category archives (Vendor-FR-013, 3.4.0+).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (post path). Required if term_id not given.' ),
                    'term_id'    => array( 'type' => 'integer', 'description' => 'Term ID (term path — typically product_cat). Required if product_id not given.' ),
                    'taxonomy'   => array( 'type' => 'string', 'description' => 'Taxonomy slug (defaults to product_cat). Used with term_id.' ),
                    'faqs'       => array( 'type' => 'array', 'description' => 'Array of {question, answer} objects', 'items' => array( 'type' => 'object' ) ),
                ),
                'required'   => array( 'faqs' ),
            ),
            'annotations' => array( 'title' => 'Save FAQ Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_faq_data', $args );
        } );

        $this->register_tool( 'aeo_save_howto', array(
            'description' => 'Save HowTo schema data for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                    'name'       => array( 'type' => 'string', 'description' => 'HowTo title' ),
                    'steps'      => array( 'type' => 'array', 'description' => 'Array of {name, text} step objects', 'items' => array( 'type' => 'object' ) ),
                ),
                'required'   => array( 'product_id', 'name', 'steps' ),
            ),
            'annotations' => array( 'title' => 'Save HowTo Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_howto_data', $args );
        } );

        $this->register_tool( 'aeo_save_speakable', array(
            'description' => 'Save Speakable schema data for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'     => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                    'css_selectors'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'CSS selectors for speakable content' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Save Speakable Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_speakable_data', $args );
        } );

        // ─── Schema Registry (Vendor-FR-1 + FR-013 + MCP-4, 3.4.0+) ──────

        $this->register_tool( 'aeo_save_schema', array(
            'description' => 'Generic schema save — registers a JSON-LD schema for any registered type (Event, LocalBusiness, Service, Course, Review, AggregateRating, plus FAQ/HowTo/Speakable). Works on posts OR terms. WPML-aware: write to each language sibling post_id for multilingual schema. Use aeo_list_schema_types to discover available types and their allowed contexts.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'schema_type' => array( 'type' => 'string', 'description' => 'Type slug — one of: faq, howto, speakable, event, localbusiness, service, course, review, aggregaterating. For event, data = {name, startDate, endDate?, eventStatus?, eventAttendanceMode?, location:{name,address}|{url}, organizer?, description?, offers?:{price,priceCurrency,url,availability?}}.' ),
                    'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => 'Storage object type (default: post)' ),
                    'object_id'   => array( 'type' => 'integer', 'description' => 'Post ID or Term ID (required — or use post_id / term_id aliases)' ),
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Alias for object_id when object_type=post' ),
                    'term_id'     => array( 'type' => 'integer', 'description' => 'Alias for object_id when object_type=term' ),
                    'data'        => array( 'type' => 'object', 'description' => 'Schema data — fully-formed schema.org array (for passthrough types) or {faqs:[...]} / {name,steps:[...]} shape for FAQ/HowTo.' ),
                    'schema_json' => array( 'type' => 'string', 'description' => 'Alternative to `data` — JSON string that decodes to the data object.' ),
                ),
                'required'   => array( 'schema_type' ),
            ),
            'annotations' => array( 'title' => 'Save Schema (Generic)', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Schema_Registry', 'rest_save_schema', $args );
        } );

        $this->register_tool( 'aeo_get_schema', array(
            'description' => 'Read stored schema for a post or term. If schema_type is omitted, returns every registered schema type that has data on the object.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'schema_type' => array( 'type' => 'string', 'description' => 'Type slug (optional — omit to dump all)' ),
                    'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => 'Storage object type (default: post)' ),
                    'object_id'   => array( 'type' => 'integer', 'description' => 'Post ID or Term ID' ),
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Alias for object_id' ),
                    'term_id'     => array( 'type' => 'integer', 'description' => 'Alias for object_id when object_type=term' ),
                ),
                'required'   => array(),
            ),
            'annotations' => array( 'title' => 'Get Schema', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Schema_Registry', 'rest_get_schema', $args );
        } );

        $this->register_tool( 'aeo_delete_schema', array(
            'description' => 'Remove stored schema for a post or term.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'schema_type' => array( 'type' => 'string', 'description' => 'Type slug (required)' ),
                    'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ) ),
                    'object_id'   => array( 'type' => 'integer' ),
                    'post_id'     => array( 'type' => 'integer' ),
                    'term_id'     => array( 'type' => 'integer' ),
                ),
                'required'   => array( 'schema_type' ),
            ),
            'annotations' => array( 'title' => 'Delete Schema', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Schema_Registry', 'rest_delete_schema', $args );
        } );

        $this->register_tool( 'aeo_list_schema_types', array(
            'description' => 'List every registered schema type — slug, schema.org @type, allowed contexts (where it can be saved), deprecation flag.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Schema Types', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function () {
            return $this->proxy_rest_post( 'LuwiPress_Schema_Registry', 'rest_list_types', array() );
        } );

        $this->register_tool( 'seo_schema_render', array(
            'description' => 'Diagnostic — fetch a URL and dump every <script type="application/ld+json"> block found. Saves the round-trip through DevTools for schema audits. Pass either url, or post_id, or term_id+taxonomy.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'url'      => array( 'type' => 'string', 'description' => 'Full URL to fetch' ),
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Resolve URL from post permalink' ),
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Resolve URL from term archive (requires taxonomy)' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug for term_id resolution' ),
                ),
                'required'   => array(),
            ),
            'annotations' => array( 'title' => 'Render Schema (Diagnostic)', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Schema_Registry', 'rest_diagnostic_render', $args );
        } );

        // ─── lwp_frontend_render_dump (1.0.32+) ──────────────────────
        // Broader sibling of seo_schema_render. Fetches a URL once and
        // returns head (title/canonical/robots/hreflang/og/twitter/meta),
        // content (word count, h-counts, image alt, link counts), meta
        // (response headers including cache layer markers + X-Robots-Tag)
        // and schema scopes. Replaces ~5 chrome-devtools-mcp round-trips
        // per audit; designed for daily SEO QA + post-write verification +
        // multilingual render parity probes.
        $this->register_tool( 'lwp_frontend_render_dump', array(
            'description' => 'Fetch a live URL with cache-bypass and dump structured data across head / content / meta / schema scopes in one call. Default scopes=[head,content,meta,schema]. Pass either url, or post_id, or term_id+taxonomy. Replaces multiple DevTools probes for SEO audits. Read-only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'url'      => array( 'type' => 'string',  'description' => 'Full URL to fetch' ),
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Resolve URL from post permalink' ),
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Resolve URL from term archive (requires taxonomy)' ),
                    'taxonomy' => array( 'type' => 'string',  'description' => 'Taxonomy slug for term_id resolution' ),
                    'scopes'   => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'head', 'content', 'meta', 'schema' ) ),
                        'description' => 'Which scopes to extract. Default: all four.',
                    ),
                ),
            ),
            'annotations' => array( 'title' => 'Frontend Render Dump', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Frontend_Inspector', 'rest_render_dump', $args );
        } );
    }

    /* ───────────────────── Translation Tools ───────────────────────── */

    private function register_translation_tools() {
        // ── Theme String Translation (theme-i18n, core 3.15.0+) ──
        $this->register_tool( 'theme_translation_coverage', array(
            'description' => "Theme UI-string (.pot/.po) translation coverage. For the active theme (or a given text_domain) returns the total translatable string count and, per active language, how many are translated vs missing. Read-only — use before/after theme_translate_strings to see what Loco's free tier left unfinished.",
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'text_domain' => array( 'type' => 'string', 'description' => 'Theme text domain (default: active theme).' ),
                ),
            ),
            'annotations' => array( 'title' => 'Theme i18n Coverage', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Theme_I18n', 'rest_coverage', $args );
        } );

        $this->register_tool( 'theme_translate_strings', array(
            'description' => "AI-translate a theme's MISSING UI strings (the part Loco's free tier leaves unfinished) and write .po + compiled .mo. Translates only untranslated msgids from the theme .pot, preserving placeholders (%s, %1\$s), HTML and whitespace. save_location 'system' (default) writes to wp-content/languages/themes/{domain}-{locale}.(po|mo) — update-safe; 'theme' writes the theme's own languages/ folder. Use dry_run to preview counts. Bounded to 400 strings per call — re-run until coverage is 100%.",
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'text_domain'      => array( 'type' => 'string', 'description' => 'Theme text domain (default: active theme).' ),
                    'target_languages' => array( 'description' => 'Language code(s) to fill, or "all" (default) for every active language. Array or comma string.' ),
                    'save_location'    => array( 'type' => 'string', 'description' => "'system' (default, update-safe) | 'theme'." ),
                    'limit'            => array( 'type' => 'integer', 'description' => 'Max strings this call (default + cap 400).' ),
                    'dry_run'          => array( 'type' => 'boolean', 'description' => 'Preview counts without writing.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Translate Theme Strings (AI)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Theme_I18n', 'rest_translate', $args );
        } );

        $this->register_tool( 'theme_clear_fuzzy', array(
            'description' => "Activate theme translations that were entered (e.g. in Loco Translate) but left marked 'fuzzy'. Fuzzy entries are excluded from the compiled .mo, so they never display even though the .po already holds the text. For each active language this finds the existing .po (system dir, the theme's languages/ folder, or Loco's wp-content/languages/loco/themes/ dir), strips the fuzzy flag from every translated entry and recompiles the .mo — no re-translation, no AI cost. Use dry_run first to see how many fuzzy entries each locale has. After applying, purge page cache to see the strings live.",
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'text_domain'      => array( 'type' => 'string', 'description' => 'Theme text domain (default: active theme).' ),
                    'target_languages' => array( 'description' => 'Language code(s) to process, or "all" (default) for every active language. Array or comma string.' ),
                    'dry_run'          => array( 'type' => 'boolean', 'description' => 'Count fuzzy entries without writing.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Activate Fuzzy Theme Translations', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Theme_I18n', 'rest_clear_fuzzy', $args );
        } );

        $this->register_tool( 'translation_missing', array(
            'description' => 'Get products/posts missing translations for a specific language',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'language'  => array( 'type' => 'string', 'description' => 'Target language code (e.g., fr, de, ar)' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to check (default: product)' ),
                ),
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing' );
            if ( ! empty( $args['language'] ) ) {
                $request->set_param( 'target_language', $args['language'] );
            }
            if ( ! empty( $args['post_type'] ) ) {
                $request->set_param( 'post_type', $args['post_type'] );
            }
            $data = $trans->get_missing_translations( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_missing_all', array(
            'description' => 'Get all missing translations across all languages',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
            $data    = $trans->get_missing_translations_all( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_request', array(
            'description' => 'Request AI translation for a post/product to one or more target languages (runs the LuwiPress AI pipeline). Accepts a single language or a list.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'          => array( 'type' => 'integer', 'description' => 'Source post ID (required)' ),
                    'target_language'  => array( 'type' => 'string', 'description' => 'A single target language code, e.g. fr, de, ar. Use this OR target_languages.' ),
                    'target_languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple target language codes, e.g. ["fr","de","ar"]. Use this OR target_language.' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'            => 'Request Translation (AI)',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => true,  // Triggers async AI pipeline + external API call
            ),
        ), function ( $args ) {
            // Normalise to a clean array of language codes. The previous version
            // read only $args['target_language'] and ran sanitize_text_field() on
            // it — when a caller passed the ARRAY form (target_languages), the
            // single key was undefined, sanitize_text_field(null) returned '',
            // and the pipeline created a bogus empty-language ('') translation
            // (flybydeniz 2026-06-12). Accept both shapes and forward an array.
            $langs = array();
            if ( ! empty( $args['target_languages'] ) && is_array( $args['target_languages'] ) ) {
                $langs = $args['target_languages'];
            } elseif ( ! empty( $args['target_language'] ) ) {
                // single code or accidental comma-string.
                $langs = is_array( $args['target_language'] )
                    ? $args['target_language']
                    : explode( ',', (string) $args['target_language'] );
            }
            $langs = array_values( array_filter( array_map( static function ( $l ) {
                return sanitize_text_field( trim( (string) $l ) );
            }, $langs ) ) );
            if ( empty( $langs ) ) {
                throw new Exception( 'Provide target_language (a code) or target_languages (an array of codes).' );
            }
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'request_translation', array(
                'product_id'       => intval( $args['post_id'] ),
                'target_languages' => $langs,
            ) );
        } );

        $this->register_tool( 'translation_status', array(
            'description' => 'Site-wide translation queue aggregate. Returns processing/completed post counts per target language. Use translation_post_siblings to resolve WPML/Polylang sibling IDs for a single post.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new \stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Translation Queue Aggregate',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/status' );
            $data = $trans->get_translation_status( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── translation_post_siblings (1.0.22+) ──────────────────────
        // Post-tarafı muadili of taxonomy_term_get's sibling resolver.
        // Returns the full WPML/Polylang language→post_id pair map for a
        // post/page/product, regardless of which language ID was given.
        // Vendor-FR (Tapadum, 2026-05-20): unblocks DB-level pair resolution
        // for cross-language SEO sweeps, retranslation drift cleanup, and
        // any audit that needs sibling IDs without XML+fuzzy slug matching.
        $this->register_tool( 'translation_post_siblings', array(
            'description' => 'Resolve all WPML/Polylang sibling post IDs for a single post/page/product. Pass any sibling ID (source or translation), receive the full {lang: post_id} map. Works on any post_type. Returns plugin + source_lang of the input + default_lang for diagnostics. Read-only counterpart to translation_request — use this when you have an EN product ID and need the IT/FR/ES counterparts (or vice versa) without scraping the XML export or fuzzy-matching slugs.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Any sibling post ID (source or translation). Required.' ),
                ),
                'required' => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Resolve Translation Siblings',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $post_id = (int) ( $args['post_id'] ?? 0 );
            if ( $post_id <= 0 ) {
                return array( 'error' => 'invalid_post_id' );
            }
            $post = get_post( $post_id );
            if ( ! ( $post instanceof WP_Post ) ) {
                return array( 'error' => 'post_not_found', 'post_id' => $post_id );
            }
            $post_type = $post->post_type;

            // WPML path
            if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                $element_type = 'post_' . $post_type;
                $trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
                if ( empty( $trid ) ) {
                    // Post not registered with WPML — single-language map.
                    $default_lang = apply_filters( 'wpml_default_language', null );
                    return array(
                        'post_id'      => $post_id,
                        'post_type'    => $post_type,
                        'plugin'       => 'wpml',
                        'siblings'     => array( $default_lang => $post_id ),
                        'source_lang'  => $default_lang,
                        'default_lang' => $default_lang,
                        'resolved_via' => 'wpml_no_trid',
                        'trid'         => null,
                    );
                }
                $translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
                $siblings = array();
                $source_lang = null;
                $original_id = null;
                if ( is_array( $translations ) ) {
                    foreach ( $translations as $lang_code => $row ) {
                        $tid = is_object( $row ) ? (int) ( $row->element_id ?? 0 ) : (int) ( $row['element_id'] ?? 0 );
                        if ( $tid > 0 ) {
                            $siblings[ (string) $lang_code ] = $tid;
                        }
                        $is_original = is_object( $row ) ? ! empty( $row->original ) : ! empty( $row['original'] );
                        if ( $is_original ) {
                            $original_id = $tid;
                            $source_lang = (string) $lang_code;
                        }
                    }
                }
                // Reverse-lookup the input post's own language code.
                $input_lang = null;
                foreach ( $siblings as $code => $sid ) {
                    if ( $sid === $post_id ) {
                        $input_lang = $code;
                        break;
                    }
                }
                return array(
                    'post_id'      => $post_id,
                    'post_type'    => $post_type,
                    'plugin'       => 'wpml',
                    'siblings'     => $siblings,
                    'input_lang'   => $input_lang,
                    'source_lang'  => $source_lang,
                    'original_id'  => $original_id,
                    'default_lang' => apply_filters( 'wpml_default_language', null ),
                    'trid'         => (int) $trid,
                    'resolved_via' => 'wpml',
                );
            }

            // Polylang path
            if ( function_exists( 'pll_get_post_translations' ) ) {
                $translations = pll_get_post_translations( $post_id );
                $siblings = array();
                if ( is_array( $translations ) ) {
                    foreach ( $translations as $lang_code => $tid ) {
                        if ( (int) $tid > 0 ) {
                            $siblings[ (string) $lang_code ] = (int) $tid;
                        }
                    }
                }
                $input_lang = null;
                if ( function_exists( 'pll_get_post_language' ) ) {
                    $input_lang = pll_get_post_language( $post_id );
                }
                $default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : null;
                if ( empty( $siblings ) && $input_lang ) {
                    $siblings = array( $input_lang => $post_id );
                }
                return array(
                    'post_id'      => $post_id,
                    'post_type'    => $post_type,
                    'plugin'       => 'polylang',
                    'siblings'     => $siblings,
                    'input_lang'   => $input_lang,
                    'source_lang'  => $default_lang,
                    'default_lang' => $default_lang,
                    'resolved_via' => 'polylang',
                );
            }

            // No translation plugin active.
            return array(
                'post_id'      => $post_id,
                'post_type'    => $post_type,
                'plugin'       => 'none',
                'siblings'     => array(),
                'resolved_via' => 'none',
                'error'        => 'no_translation_plugin',
            );
        } );

        $this->register_tool( 'translation_quality_check', array(
            'description' => 'Trigger quality check on existing translation',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Translated post ID (required)' ),
                    'language' => array( 'type' => 'string', 'description' => 'Language code of the translation' ),
                ),
                'required'   => array( 'post_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'trigger_quality_check', $args );
        } );

        $this->register_tool( 'translation_taxonomy', array(
            'description' => 'Request translation for taxonomy terms (categories, tags, attributes)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'        => array( 'type' => 'string', 'description' => 'Taxonomy name (e.g., product_cat)' ),
                    'target_language' => array( 'type' => 'string', 'description' => 'Target language code (required)' ),
                ),
                'required'   => array( 'target_language' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'request_taxonomy_translation', $args );
        } );

        $this->register_tool( 'translation_taxonomy_missing', array(
            'description' => 'Get taxonomy terms missing translations',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'language' => array( 'type' => 'string', 'description' => 'Target language code' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy to check' ),
                ),
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/taxonomy-missing' );
            if ( ! empty( $args['language'] ) ) {
                $request->set_param( 'target_languages', $args['language'] );
            }
            if ( ! empty( $args['taxonomy'] ) ) {
                $request->set_param( 'taxonomy', $args['taxonomy'] );
            }
            $data = $trans->get_missing_taxonomy_terms_api( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_batch', array(
            'description' => 'Translate N untranslated posts for one or more target languages in a single call. Powers the "Translate N missing products" action on Knowledge Graph language nodes.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Target language codes (required)' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to translate (default: product)' ),
                    'limit'     => array( 'type' => 'integer', 'description' => 'Max posts to translate (default 50, max 200)' ),
                ),
                'required' => array( 'languages' ),
            ),
            'annotations' => array( 'title' => 'Batch Translate', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/batch' );
            $request->set_param( 'languages', $args['languages'] ?? array() );
            $request->set_param( 'post_type', $args['post_type'] ?? 'product' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $data = $trans->batch_translate_missing( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // Detect translated posts whose body is still in the source language —
        // the silent failure mode that makes existence-based coverage report
        // 100% even when blogs are broken English. Returns scored items, the
        // operator (or AI agent) feeds the source IDs back into
        // translation_force_retranslate to fix.
        $this->register_tool( 'translation_language_drift', array(
            'description' => 'Detect translated posts whose body content is still in the source language (existence-based coverage lies — the post exists but never got translated). Scores each body via stop-word ratio; returns posts below the threshold. Pair with translation_force_retranslate to fix.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to scan (default: post). Common values: post, page, product.' ),
                    'languages' => array( 'type' => 'string', 'description' => 'Comma-separated target language codes. Defaults to every active non-source language.' ),
                    'limit'     => array( 'type' => 'integer', 'description' => 'Max items to return (default 200, max 1000).' ),
                    'threshold' => array( 'type' => 'number', 'description' => 'Below this target-language score (0..1) the post is flagged as drifted. Default 0.45.' ),
                    'min_words' => array( 'type' => 'integer', 'description' => 'Skip posts whose body has fewer words than this (default 30).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Detect Translation Language Drift',
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/language-drift' );
            if ( ! empty( $args['post_type'] ) ) { $request->set_param( 'post_type', sanitize_text_field( $args['post_type'] ) ); }
            if ( ! empty( $args['languages'] ) ) { $request->set_param( 'languages', sanitize_text_field( $args['languages'] ) ); }
            if ( isset( $args['limit'] ) )       { $request->set_param( 'limit', (int) $args['limit'] ); }
            if ( isset( $args['threshold'] ) )   { $request->set_param( 'threshold', (float) $args['threshold'] ); }
            if ( isset( $args['min_words'] ) )   { $request->set_param( 'min_words', (int) $args['min_words'] ); }
            $data = $trans->get_language_drift( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // Force-retranslate: clears the elementor "already-translated" guard
        // meta on every target translation post and re-runs the AI pipeline.
        // Bypasses /translation/missing-all gating — pass an explicit list of
        // source IDs (default-language post IDs from translation_language_drift).
        $this->register_tool( 'translation_force_retranslate', array(
            'description' => 'Force-retranslate posts that already have a translation post (overwrites). Clears the Elementor "already-translated" guard meta and re-fires the AI pipeline. Pass source-language post IDs (typically from translation_language_drift). Async wp_cron path is automatic when work units > 5.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Source-language post IDs to retranslate (required).' ),
                    'languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Target language codes to overwrite (required).' ),
                    'async'     => array( 'type' => 'boolean', 'description' => 'Queue via wp_cron when batch is large (default true).' ),
                ),
                'required' => array( 'post_ids', 'languages' ),
            ),
            'annotations' => array(
                'title'           => 'Force-Retranslate Posts (Drift Sweep)',
                'readOnlyHint'    => false,
                'destructiveHint' => true,  // Overwrites translation post bodies
                'idempotentHint'  => false,
                'openWorldHint'   => true,  // Triggers AI pipeline + external API calls
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/force-retranslate' );
            $request->set_param( 'post_ids', $args['post_ids'] ?? array() );
            $request->set_param( 'languages', $args['languages'] ?? array() );
            if ( isset( $args['async'] ) ) { $request->set_param( 'async', (bool) $args['async'] ); }
            $data = $trans->force_retranslate( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── TRANSLATION SYNC AUDIT (3.1.54+ orchestrator) ───
        // Unified surface that orchestrates drift + outdated + structural_gap +
        // schema_parity detection under a single REST endpoint, and a single
        // fix dispatcher.

        $this->register_tool( 'translation_sync_audit', array(
            'description' => 'Unified cross-language sync audit. Detects drift (target body in source language), outdated (source edited after translating), structural_gap (translation missing sections source has), and schema_parity (FAQ/HowTo only on some languages). Returns ranked findings with finding_id strings ready for translation_sync_fix. type="all" runs all four routines.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'type'      => array( 'type' => 'string', 'enum' => array( 'all', 'drift', 'outdated', 'structural_gap', 'schema_parity' ), 'description' => 'Detect type. Default: all.' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to scan (default product).' ),
                    'languages' => array( 'type' => 'string', 'description' => 'Comma-separated target languages. Default: all active non-source.' ),
                    'limit'     => array( 'type' => 'integer', 'description' => 'Max findings (default 200, capped 500).' ),
                    'threshold' => array( 'type' => 'number', 'description' => 'Drift threshold 0..1. Overrides stored setting for this call.' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Translation Sync Audit',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Translation_Sync' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'LuwiPress_Translation_Sync requires core 3.1.54+.' );
            }
            $sync = LuwiPress_Translation_Sync::get_instance();
            $req  = new WP_REST_Request( 'GET', '/luwipress/v1/translation/sync-audit' );
            foreach ( array( 'type', 'post_type', 'languages', 'limit', 'threshold' ) as $k ) {
                if ( isset( $args[ $k ] ) ) {
                    $req->set_param( $k, $args[ $k ] );
                }
            }
            $resp = $sync->rest_audit( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'translation_sync_fix', array(
            'description' => 'Execute the fix action for one or more finding_ids returned by translation_sync_audit. Server re-resolves the finding (does not trust client-provided fix_args) and routes to force-retranslate / sync-structure / copy-schema as appropriate. Async by default — fixes that require AI fire via wp_cron.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'finding_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of finding_id strings (required).' ),
                    'async'       => array( 'type' => 'boolean', 'description' => 'Queue via wp_cron (true, default) or run inline (false).' ),
                ),
                'required' => array( 'finding_ids' ),
            ),
            'annotations' => array(
                'title'           => 'Translation Sync Fix',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'openWorldHint'   => true,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Translation_Sync' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'LuwiPress_Translation_Sync requires core 3.1.54+.' );
            }
            $sync = LuwiPress_Translation_Sync::get_instance();
            $req  = new WP_REST_Request( 'POST', '/luwipress/v1/translation/sync-fix' );
            $req->set_param( 'finding_ids', $args['finding_ids'] ?? array() );
            $req->set_param( 'async', isset( $args['async'] ) ? (bool) $args['async'] : true );
            $resp = $sync->rest_fix( $req );
            if ( is_wp_error( $resp ) ) {
                return array( 'error' => $resp->get_error_code(), 'message' => $resp->get_error_message() );
            }
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'translation_sync_settings', array(
            'description' => 'Get cross-language sync configuration: drift threshold, hourly sweep toggle, autofix toggle, next sweep time, last audit summary.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Translation Sync Settings (Read)',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Translation_Sync' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $sync = LuwiPress_Translation_Sync::get_instance();
            $req  = new WP_REST_Request( 'GET', '/luwipress/v1/translation/sync-settings' );
            $resp = $sync->rest_settings_get( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'translation_sync_settings_set', array(
            'description' => 'Update cross-language sync configuration. drift_threshold is 0..1 (default 0.45 = flag posts whose target-lang stop-word share is below 45%). sweep_enabled turns on hourly wp_cron audit. sweep_autofix lets the sweep auto-dispatch high-severity findings (caps at 20/sweep).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'drift_threshold' => array( 'type' => 'number', 'description' => '0.0..1.0, lower = stricter detection.' ),
                    'sweep_enabled'   => array( 'type' => 'boolean', 'description' => 'Schedule hourly audit sweep (default off).' ),
                    'sweep_autofix'   => array( 'type' => 'boolean', 'description' => 'Auto-fix high-severity findings during sweep (default off).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Translation Sync Settings (Write)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Translation_Sync' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $sync = LuwiPress_Translation_Sync::get_instance();
            $req  = new WP_REST_Request( 'POST', '/luwipress/v1/translation/sync-settings' );
            foreach ( array( 'drift_threshold', 'sweep_enabled', 'sweep_autofix' ) as $k ) {
                if ( array_key_exists( $k, $args ) ) {
                    $req->set_param( $k, $args[ $k ] );
                }
            }
            $resp = $sync->rest_settings_set( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        // ─── Customer Chat session/history (FR-003) ───────────────────
        // Surfaces the wp_luwipress_chat_conversations + chat_messages
        // tables (populated by the storefront chat widget since 3.0.x)
        // as 3 MCP tools so AI agents can audit tone, search for pain
        // points, and pull individual session transcripts without
        // touching the DB directly. All three are admin-only.

        $this->register_tool( 'chat_sessions_list', array(
            'description' => 'List recent Customer Chat sessions with pagination + filters. Each row carries session_id, customer_email, customer_name, status, escalated_to, page_url (where chat was opened), ip_address, created_at, updated_at, and message_count. Use this as the entry point for chat-tone reviews and audit sweeps. For one session\'s transcript call chat_session_get.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit'          => array( 'type' => 'integer', 'description' => '1-200, default 50.' ),
                    'offset'         => array( 'type' => 'integer', 'description' => 'Pagination offset (default 0).' ),
                    'status'         => array( 'type' => 'string', 'description' => 'Filter by conversation status (e.g. "active", "escalated").' ),
                    'escalated_only' => array( 'type' => 'boolean', 'description' => 'Only sessions that have been escalated to WhatsApp/Telegram.' ),
                    'customer_email' => array( 'type' => 'string', 'description' => 'Partial-match against customer_email (LIKE).' ),
                    'since'          => array( 'type' => 'string', 'description' => 'ISO datetime — only sessions created_at >= since.' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'List Chat Sessions',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Customer_Chat' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'Customer Chat module not loaded.' );
            }
            $chat    = LuwiPress_Customer_Chat::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/chat/sessions' );
            foreach ( array( 'limit', 'offset', 'status', 'escalated_only', 'customer_email', 'since' ) as $k ) {
                if ( array_key_exists( $k, $args ) ) {
                    $request->set_param( $k, $args[ $k ] );
                }
            }
            $data = $chat->handle_list_sessions( $request );
            if ( $data instanceof WP_Error ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'chat_session_get', array(
            'description' => 'Fetch a single chat session\'s full transcript (up to last 50 messages, oldest-first). Returns { exists, status, escalated_to, messages: [{ role, content, source, created_at }, ...] }. Pair with chat_sessions_list to surface session_id candidates.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'session_id' => array( 'type' => 'string', 'description' => 'Hex session identifier ([a-f0-9]{32,64}); required.' ),
                ),
                'required' => array( 'session_id' ),
            ),
            'annotations' => array(
                'title'           => 'Get Chat Session',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Customer_Chat' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'Customer Chat module not loaded.' );
            }
            $session_id = sanitize_text_field( (string) ( $args['session_id'] ?? '' ) );
            if ( '' === $session_id ) {
                return array( 'error' => 'invalid_session_id' );
            }
            $chat    = LuwiPress_Customer_Chat::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/chat/session/' . $session_id );
            $request->set_param( 'session_id', $session_id );
            $data = $chat->handle_get_session( $request );
            if ( $data instanceof WP_Error ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'chat_messages_search', array(
            'description' => 'Plain-LIKE search across stored chat messages. Returns { query, total, items[], limit } where each item carries session_id, role, content_snippet (≤240 chars, centered on match), source, created_at, page_url, customer_email. Use for pain-point analysis ("customers asking about shipping costs"), brand-voice audits across FR/IT/ES, or finding sessions to escalate.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'description' => 'Substring to match (≥2 chars). Required.' ),
                    'limit' => array( 'type' => 'integer', 'description' => '1-200, default 50.' ),
                    'role'  => array( 'type' => 'string', 'description' => 'Filter by message role (user / assistant / system).' ),
                    'since' => array( 'type' => 'string', 'description' => 'ISO datetime — only messages created_at >= since.' ),
                ),
                'required' => array( 'query' ),
            ),
            'annotations' => array(
                'title'           => 'Search Chat Messages',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Customer_Chat' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'Customer Chat module not loaded.' );
            }
            $q = trim( (string) ( $args['query'] ?? '' ) );
            if ( mb_strlen( $q ) < 2 ) {
                return array( 'error' => 'invalid_query', 'message' => 'query must be at least 2 characters.' );
            }
            $chat    = LuwiPress_Customer_Chat::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/chat/messages/search' );
            $request->set_param( 'q', $q );
            foreach ( array( 'limit', 'role', 'since' ) as $k ) {
                if ( array_key_exists( $k, $args ) ) {
                    $request->set_param( $k, $args[ $k ] );
                }
            }
            $data = $chat->handle_search_messages( $request );
            if ( $data instanceof WP_Error ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── Slug Resolver (3.1.56+) ──────────────────────────────────
        // Core engine that 301-redirects legacy `/<slug>/` page URLs to
        // their matching `/product-category/<slug>/` archive. Migrated
        // from `luwipress-gold` theme to core so every theme inherits
        // the same behaviour. Five MCP tools below mirror the REST surface.

        $this->register_tool( 'slug_resolver_diag', array(
            'description' => 'Diagnostic runtime snapshot of the slug-collision resolver: toggle state, hook attachment, map size, WPML/Polylang detect, last build, sample slug probes (returns what each slug would redirect to if hit). Use for cross-customer migration troubleshooting WITHOUT needing server-side log access.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'probe' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional list of slugs to probe (default: percussions, duduk, ney, winds, persian-kamancheh).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Slug Resolver Diagnostic',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Slug_Resolver' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'LuwiPress_Slug_Resolver requires core 3.1.56+.' );
            }
            $r   = LuwiPress_Slug_Resolver::get_instance();
            $req = new WP_REST_Request( 'GET', '/luwipress/v1/slug-resolver/diag' );
            if ( isset( $args['probe'] ) ) {
                $req->set_param( 'probe', $args['probe'] );
            }
            $resp = $r->rest_diag( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'slug_resolver_map', array(
            'description' => 'Return the full slug→target redirect map (auto-discovered + operator overrides, composed). Useful for auditing what every page slug currently resolves to before flipping the toggle on.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array(
                'title'           => 'Slug Resolver Map',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Slug_Resolver' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $r   = LuwiPress_Slug_Resolver::get_instance();
            $req = new WP_REST_Request( 'GET', '/luwipress/v1/slug-resolver/map' );
            $resp = $r->rest_map( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'slug_resolver_force_rebuild', array(
            'description' => 'Bust the discovery transient and re-run all six passes. Use after editing the page tree or product_cat hierarchy if you don\'t want to wait for the 1-hour TTL.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array(
                'title'           => 'Slug Resolver Force Rebuild',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Slug_Resolver' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $r   = LuwiPress_Slug_Resolver::get_instance();
            $req = new WP_REST_Request( 'POST', '/luwipress/v1/slug-resolver/rebuild' );
            $resp = $r->rest_rebuild( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'slug_resolver_override_set', array(
            'description' => 'Set or remove an explicit operator override for a slug. Target may be: integer term_id (redirect to that term\'s archive), URL string (redirect to URL), true (auto-target /product-category/<slug>/), false (suppress auto redirect), null (remove the override). Overrides win over auto-discovery.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'   => array( 'type' => 'string', 'description' => 'Page slug (no leading slash).' ),
                    'target' => array( 'description' => 'Redirect target — int term_id, URL string, true (auto), false (suppress), or null (remove).' ),
                ),
                'required' => array( 'slug' ),
            ),
            'annotations' => array(
                'title'           => 'Slug Resolver Override',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Slug_Resolver' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $r   = LuwiPress_Slug_Resolver::get_instance();
            $req = new WP_REST_Request( 'POST', '/luwipress/v1/slug-resolver/override' );
            $req->set_param( 'slug',   $args['slug']   ?? '' );
            $req->set_param( 'target', $args['target'] ?? null );
            $resp = $r->rest_override( $req );
            if ( is_wp_error( $resp ) ) {
                return array( 'error' => $resp->get_error_code(), 'message' => $resp->get_error_message() );
            }
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'slug_resolver_settings_set', array(
            'description' => 'Enable or disable the slug-collision resolver site-wide. When disabled, the map is still built on demand but no redirects fire.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled' => array( 'type' => 'boolean', 'description' => 'true = enable redirects, false = disable.' ),
                ),
                'required' => array( 'enabled' ),
            ),
            'annotations' => array(
                'title'           => 'Slug Resolver Settings (Write)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Slug_Resolver' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $r   = LuwiPress_Slug_Resolver::get_instance();
            $req = new WP_REST_Request( 'POST', '/luwipress/v1/slug-resolver/settings' );
            $req->set_body_params( array( 'enabled' => ! empty( $args['enabled'] ) ) );
            $resp = $r->rest_settings_set( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        // Redirect-loop audit: scrapes every link out of one or more WP nav
        // menus, GETs each with redirects disabled, follows the chain up to
        // `max_hops`, surfaces non-200 endpoints, redirect chains > 1 hop,
        // and any `X-LWP-SR:` trace headers. This is the same sweep a
        // pre-DNS-swap audit script would otherwise have to re-implement
        // per customer — folding it into MCP means it ships with every
        // LuwiPress site and any future migration gets a one-shot
        // "is my redirect map clean?" check.
        $this->register_tool( 'slug_resolver_redirect_audit', array(
            'description' => 'Sweep every link out of one or more navigation menus (or arbitrary URLs), follow redirects up to max_hops, report 404s, redirect chains > 1 hop, and decode any X-LWP-SR trace headers. Use before a DNS swap or after editing the slug-resolver map to catch loops/dead-ends. Returns a per-URL trail with hop count, status codes, final URL, and SR-trace tokens.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'menu_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Menu IDs to extract URLs from. Omit to sweep every nav menu on the site.' ),
                    'urls'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Explicit URLs to probe (in addition to menu_ids). Useful for one-off slug checks.' ),
                    'max_hops' => array( 'type' => 'integer', 'description' => 'Max redirect hops per URL before giving up (default 5).' ),
                    'cache_bust' => array( 'type' => 'boolean', 'description' => 'Append a random ?lwp_audit=… query to bypass page cache (default true).' ),
                    'parallel'   => array( 'type' => 'boolean', 'description' => 'Probe URLs in parallel via curl_multi (default true). Set false to force the legacy sequential wp_remote_get loop (slower but uses WP HTTP API stack — useful when curl is unavailable or debugging transport issues).' ),
                    'batch_size' => array( 'type' => 'integer', 'description' => 'Concurrent requests per parallel round (default 20, max 50). Higher values complete faster but pressure the worker. Ignored when parallel=false.' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Slug Resolver Redirect Audit',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => true,
            ),
        ), function ( $args ) {
            $max_hops   = isset( $args['max_hops'] ) ? max( 1, min( 10, (int) $args['max_hops'] ) ) : 5;
            $cache_bust = isset( $args['cache_bust'] ) ? (bool) $args['cache_bust'] : true;
            $parallel   = isset( $args['parallel'] ) ? (bool) $args['parallel'] : true;
            $batch_size = isset( $args['batch_size'] ) ? max( 1, min( 50, (int) $args['batch_size'] ) ) : 20;
            $use_curl_multi = $parallel && function_exists( 'curl_multi_init' );

            // Gather URLs.
            $urls = array();
            if ( ! empty( $args['urls'] ) && is_array( $args['urls'] ) ) {
                foreach ( $args['urls'] as $u ) {
                    $u = (string) $u;
                    if ( $u !== '' ) $urls[ $u ] = true;
                }
            }
            $menu_ids = array();
            if ( isset( $args['menu_ids'] ) && is_array( $args['menu_ids'] ) ) {
                foreach ( $args['menu_ids'] as $mid ) {
                    $mid = (int) $mid; if ( $mid > 0 ) $menu_ids[] = $mid;
                }
            } else {
                // Default: every nav menu on the site.
                $menus = wp_get_nav_menus();
                if ( is_array( $menus ) ) {
                    foreach ( $menus as $m ) $menu_ids[] = (int) $m->term_id;
                }
            }
            foreach ( $menu_ids as $mid ) {
                $items = wp_get_nav_menu_items( $mid );
                if ( ! is_array( $items ) ) continue;
                foreach ( $items as $it ) {
                    $u = isset( $it->url ) ? (string) $it->url : '';
                    if ( $u === '' || $u === '#' ) continue;
                    $host = (string) wp_parse_url( $u, PHP_URL_HOST );
                    if ( $host !== '' && $host !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) continue;
                    $u = strtok( $u, '#' );
                    $urls[ $u ] = true;
                }
            }
            $urls = array_keys( $urls );
            sort( $urls );

            $stats = array(
                'total'         => count( $urls ),
                'end_200'       => 0,
                'end_404'       => 0,
                'end_other'     => 0,
                'chains_gt_1'   => 0,
                'loops_5plus'   => 0,
                'with_sr'       => 0,
                'mode'          => $use_curl_multi ? 'parallel' : 'sequential',
                'batch_size'    => $use_curl_multi ? $batch_size : 1,
                'rounds'        => 0,
                'elapsed_ms'    => 0,
            );

            $t_start = microtime( true );
            $results = array();

            if ( $use_curl_multi ) {
                // ── Parallel round-based curl_multi ─────────────────────
                // Each URL has a state machine: cur, trail, saw_sr, done.
                // Per round we fire all not-yet-done URLs in batches of
                // batch_size; any that 3xx push their next URL into the
                // pending set for the next round. Capped at max_hops rounds.
                $state = array();
                foreach ( $urls as $start ) {
                    $state[ $start ] = array(
                        'start'   => $start,
                        'cur'     => $start,
                        'trail'   => array(),
                        'saw_sr'  => false,
                        'done'    => false,
                    );
                }

                for ( $round = 0; $round < $max_hops; $round++ ) {
                    $pending_keys = array();
                    foreach ( $state as $key => $s ) {
                        if ( ! $s['done'] ) {
                            $pending_keys[] = $key;
                        }
                    }
                    if ( empty( $pending_keys ) ) {
                        break;
                    }
                    $stats['rounds'] = $round + 1;

                    foreach ( array_chunk( $pending_keys, $batch_size ) as $chunk ) {
                        $mh      = curl_multi_init();
                        $handles = array();
                        foreach ( $chunk as $key ) {
                            $probe_url = $state[ $key ]['cur'];
                            if ( $cache_bust ) {
                                $sep        = ( strpos( $probe_url, '?' ) === false ) ? '?' : '&';
                                $probe_url .= $sep . 'lwp_audit=' . wp_generate_uuid4();
                            }
                            $ch = curl_init();
                            curl_setopt_array( $ch, array(
                                CURLOPT_URL            => $probe_url,
                                CURLOPT_FOLLOWLOCATION => false,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_HEADER         => true,
                                CURLOPT_NOBODY         => false,
                                CURLOPT_TIMEOUT        => 15,
                                CURLOPT_CONNECTTIMEOUT => 5,
                                CURLOPT_SSL_VERIFYPEER => true,
                                CURLOPT_SSL_VERIFYHOST => 2,
                                CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
                                CURLOPT_USERAGENT      => 'LuwiPress-SlugAudit/1.0',
                            ) );
                            curl_multi_add_handle( $mh, $ch );
                            $handles[ $key ] = $ch;
                        }

                        $running = null;
                        do {
                            $status = curl_multi_exec( $mh, $running );
                            if ( $running > 0 ) {
                                curl_multi_select( $mh, 0.5 );
                            }
                        } while ( $running > 0 && $status === CURLM_OK );

                        foreach ( $handles as $key => $ch ) {
                            $err  = curl_error( $ch );
                            $body = curl_multi_getcontent( $ch );
                            $info = curl_getinfo( $ch );
                            $code = (int) ( $info['http_code'] ?? 0 );

                            // Parse Location + X-LWP-SR from raw response headers.
                            $location  = '';
                            $sr_traces = array();
                            $hdr_size  = (int) ( $info['header_size'] ?? 0 );
                            if ( $body !== false && $hdr_size > 0 ) {
                                // Some servers emit multiple HTTP/1.1 blocks (e.g. on 100-continue or
                                // proxy chains). Walk all header lines so we don't lose Location
                                // when it's in the final block.
                                $hdr_raw = substr( $body, 0, $hdr_size );
                                foreach ( explode( "\r\n", $hdr_raw ) as $line ) {
                                    if ( stripos( $line, 'Location:' ) === 0 ) {
                                        $location = trim( substr( $line, 9 ) );
                                    } elseif ( stripos( $line, 'X-LWP-SR:' ) === 0 ) {
                                        $sr_traces[] = trim( substr( $line, 9 ) );
                                    }
                                }
                            }

                            if ( ! empty( $sr_traces ) ) {
                                $state[ $key ]['saw_sr'] = true;
                            }
                            $entry = array(
                                'code' => $err ? -1 : $code,
                                'url'  => $state[ $key ]['cur'],
                                'sr'   => $err ? $err : implode( ', ', $sr_traces ),
                            );
                            $state[ $key ]['trail'][] = $entry;

                            if ( $err ) {
                                $state[ $key ]['done'] = true;
                            } elseif ( in_array( $code, array( 301, 302, 307, 308 ), true ) && $location !== '' ) {
                                $state[ $key ]['cur'] = $location;
                                // Stays pending for next round.
                            } else {
                                $state[ $key ]['done'] = true;
                            }

                            curl_multi_remove_handle( $mh, $ch );
                            curl_close( $ch );
                        }
                        curl_multi_close( $mh );
                    }
                }

                // Materialise state → results in the original input order.
                foreach ( $urls as $start ) {
                    $s         = $state[ $start ];
                    $last_code = ! empty( $s['trail'] ) ? (int) end( $s['trail'] )['code'] : 0;
                    if ( $last_code === 200 ) $stats['end_200']++;
                    elseif ( $last_code === 404 ) $stats['end_404']++;
                    else $stats['end_other']++;
                    if ( count( $s['trail'] ) > 2 ) $stats['chains_gt_1']++;
                    if ( count( $s['trail'] ) >= $max_hops ) $stats['loops_5plus']++;
                    if ( $s['saw_sr'] ) $stats['with_sr']++;
                    $results[] = array(
                        'start'      => $start,
                        'final_url'  => ! empty( $s['trail'] ) ? (string) end( $s['trail'] )['url'] : '',
                        'final_code' => $last_code,
                        'hops'       => max( 0, count( $s['trail'] ) - 1 ),
                        'looped'     => count( $s['trail'] ) >= $max_hops,
                        'trail'      => $s['trail'],
                    );
                }
            } else {
                // ── Sequential fallback (wp_remote_get, legacy path) ────
                // Triggered when parallel=false OR curl_multi is unavailable.
                foreach ( $urls as $start ) {
                    $trail  = array();
                    $cur    = $start;
                    $hops   = 0;
                    $saw_sr = false;
                    while ( $hops < $max_hops ) {
                        $probe_url = $cur;
                        if ( $cache_bust ) {
                            $sep        = ( strpos( $probe_url, '?' ) === false ) ? '?' : '&';
                            $probe_url .= $sep . 'lwp_audit=' . wp_generate_uuid4();
                        }
                        $resp = wp_remote_get( $probe_url, array(
                            'redirection' => 0,
                            'timeout'     => 15,
                            'sslverify'   => true,
                            'headers'     => array( 'Cache-Control' => 'no-cache' ),
                        ) );
                        if ( is_wp_error( $resp ) ) {
                            $trail[] = array( 'code' => -1, 'url' => $cur, 'sr' => $resp->get_error_message() );
                            break;
                        }
                        $code   = (int) wp_remote_retrieve_response_code( $resp );
                        $sr_arr = wp_remote_retrieve_header( $resp, 'x-lwp-sr' );
                        if ( $sr_arr ) $saw_sr = true;
                        $sr_str  = is_array( $sr_arr ) ? implode( ', ', $sr_arr ) : (string) $sr_arr;
                        $trail[] = array( 'code' => $code, 'url' => $cur, 'sr' => $sr_str );
                        if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
                            $loc = wp_remote_retrieve_header( $resp, 'location' );
                            if ( is_array( $loc ) ) $loc = $loc[0] ?? '';
                            if ( ! $loc ) break;
                            $cur = (string) $loc;
                            $hops++;
                            continue;
                        }
                        break;
                    }
                    $last_code = ! empty( $trail ) ? (int) end( $trail )['code'] : 0;
                    if ( $last_code === 200 ) $stats['end_200']++;
                    elseif ( $last_code === 404 ) $stats['end_404']++;
                    else $stats['end_other']++;
                    if ( count( $trail ) > 2 ) $stats['chains_gt_1']++;
                    if ( count( $trail ) >= $max_hops ) $stats['loops_5plus']++;
                    if ( $saw_sr ) $stats['with_sr']++;
                    $results[] = array(
                        'start'      => $start,
                        'final_url'  => ! empty( $trail ) ? (string) end( $trail )['url'] : '',
                        'final_code' => $last_code,
                        'hops'       => count( $trail ) - 1,
                        'looped'     => count( $trail ) >= $max_hops,
                        'trail'      => $trail,
                    );
                }
            }

            $stats['elapsed_ms'] = (int) ( ( microtime( true ) - $t_start ) * 1000 );

            // Issues bucket — anything operator should look at.
            $issues = array();
            foreach ( $results as $r ) {
                $reason = '';
                if ( $r['looped'] )                    $reason = 'redirect_loop';
                elseif ( $r['final_code'] === 404 )    $reason = '404';
                elseif ( $r['final_code'] >= 500 )     $reason = 'server_error';
                elseif ( $r['final_code'] === 0 || $r['final_code'] === -1 ) $reason = 'transport_error';
                elseif ( $r['hops'] > 1 )              $reason = 'multi_hop_chain';
                if ( $reason ) $issues[] = array_merge( array( 'reason' => $reason ), $r );
            }

            return array(
                'menu_ids'  => $menu_ids,
                'max_hops'  => $max_hops,
                'cache_bust'=> $cache_bust,
                'stats'     => $stats,
                'issues'    => $issues,
                'results'   => $results,
            );
        } );

        // WPML/Polylang term-translation lookup. Last session we needed
        // this to find the ES sibling of the EN Black Sea Kemenche term;
        // the existing `translation_taxonomy` tool only triggers AI
        // translation, it doesn't READ the WPML term-translation map.
        // Read-only sibling discovery without admin UI dance.
        $this->register_tool( 'wpml_term_translation_get', array(
            'description' => 'Return the WPML or Polylang sibling translations of a single term across every active language. For each language returns: code, term_id (or null if missing), slug, name, edit_url. Useful when you need to know "what is the ES/IT/FR equivalent of term 99" without diving into wp_admin.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Source term ID (required).' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy (default: product_cat).' ),
                ),
                'required' => array( 'term_id' ),
            ),
            'annotations' => array(
                'title'           => 'WPML Term Translation Lookup',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $term_id  = (int) ( $args['term_id'] ?? 0 );
            $taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : 'product_cat';
            if ( $term_id <= 0 ) {
                return array( 'error' => 'invalid_term_id' );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! ( $term instanceof WP_Term ) ) {
                return array( 'error' => 'term_not_found', 'term_id' => $term_id, 'taxonomy' => $taxonomy );
            }

            $lang_codes = array();
            $engine = 'none';
            if ( function_exists( 'icl_get_languages' ) ) {
                $list = icl_get_languages( 'skip_missing=0' );
                if ( is_array( $list ) ) {
                    $engine = 'wpml';
                    $lang_codes = array_keys( $list );
                }
            } elseif ( function_exists( 'pll_languages_list' ) ) {
                $list = pll_languages_list();
                if ( is_array( $list ) ) {
                    $engine = 'polylang';
                    $lang_codes = array_map( 'strval', $list );
                }
            }

            $translations = array();
            foreach ( $lang_codes as $code ) {
                $tid = $term_id;
                if ( $engine === 'wpml' && function_exists( 'icl_object_id' ) ) {
                    $resolved = icl_object_id( $term_id, $taxonomy, false, $code );
                    $tid = $resolved ? (int) $resolved : 0;
                } elseif ( $engine === 'polylang' && function_exists( 'pll_get_term' ) ) {
                    $resolved = pll_get_term( $term_id, $code );
                    $tid = $resolved ? (int) $resolved : 0;
                }
                $entry = array(
                    'lang'        => $code,
                    'term_id'     => $tid ?: null,
                    'slug'        => null,
                    'name'        => null,
                    'description' => null,
                    'count'       => null,
                    'link'        => null,
                );
                if ( $tid > 0 ) {
                    $t = get_term( $tid, $taxonomy );
                    if ( $t instanceof WP_Term ) {
                        $entry['slug']        = $t->slug;
                        $entry['name']        = $t->name;
                        $entry['description'] = (string) $t->description;
                        $entry['count']       = (int) $t->count;
                        $link = get_term_link( $t );
                        $entry['link']        = is_wp_error( $link ) ? null : (string) $link;
                    }
                }
                $translations[] = $entry;
            }
            return array(
                'engine'       => $engine,
                'source'       => array(
                    'term_id'  => (int) $term->term_id,
                    'slug'     => $term->slug,
                    'name'     => $term->name,
                    'taxonomy' => $taxonomy,
                ),
                'translations' => $translations,
            );
        } );

        // ─── taxonomy_term_get (1.0.22+) ──────────────────────────────
        // Read-before-write companion to taxonomy_update_term. Returns the
        // full core fields of a single term by ID, including the core
        // `description` field that taxonomy_list_terms is scoped to the
        // default WPML language and wpml_term_translation_get previously
        // omitted. Lang parameter is optional — pass it only when you want
        // to fetch a *sibling* term in a specific language (we resolve via
        // icl_object_id / pll_get_term). If lang is omitted, returns the
        // term at the given ID as-is.
        $this->register_tool( 'taxonomy_term_get', array(
            'description' => 'Read a single term by ID with all core fields (name, slug, description, parent, count). Optional lang parameter resolves to the WPML/Polylang sibling in that language. Use this as the read counterpart of taxonomy_update_term — fetch the existing description before rewriting.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID (required). If lang is also passed, treated as the source-term ID and resolved to the lang sibling.' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy (default: product_cat).' ),
                    'lang'     => array( 'type' => 'string', 'description' => 'Optional WPML/Polylang language code (e.g. fr, it, es). When set, resolves term_id to the sibling in that language before reading.' ),
                ),
                'required' => array( 'term_id' ),
            ),
            'annotations' => array(
                'title'           => 'Get Term (core fields)',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $term_id  = (int) ( $args['term_id'] ?? 0 );
            $taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : 'product_cat';
            $lang     = isset( $args['lang'] ) ? sanitize_text_field( (string) $args['lang'] ) : '';
            if ( $term_id <= 0 ) {
                return array( 'error' => 'invalid_term_id' );
            }
            $resolved_id = $term_id;
            $resolved_via = 'direct';
            if ( '' !== $lang ) {
                if ( function_exists( 'icl_object_id' ) ) {
                    $r = icl_object_id( $term_id, $taxonomy, false, $lang );
                    if ( $r ) {
                        $resolved_id  = (int) $r;
                        $resolved_via = 'wpml';
                    }
                } elseif ( function_exists( 'pll_get_term' ) ) {
                    $r = pll_get_term( $term_id, $lang );
                    if ( $r ) {
                        $resolved_id  = (int) $r;
                        $resolved_via = 'polylang';
                    }
                }
            }
            $term = get_term( $resolved_id, $taxonomy );
            if ( ! ( $term instanceof WP_Term ) ) {
                return array(
                    'error'         => 'term_not_found',
                    'term_id'       => $term_id,
                    'resolved_id'   => $resolved_id,
                    'resolved_via'  => $resolved_via,
                    'taxonomy'      => $taxonomy,
                    'lang'          => $lang,
                );
            }
            $link = get_term_link( $term );
            return array(
                'term_id'      => (int) $term->term_id,
                'taxonomy'     => $term->taxonomy,
                'slug'         => $term->slug,
                'name'         => $term->name,
                'description'  => (string) $term->description,
                'parent'       => (int) $term->parent,
                'count'        => (int) $term->count,
                'link'         => is_wp_error( $link ) ? null : (string) $link,
                'lang'         => $lang ?: null,
                'resolved_via' => $resolved_via,
            );
        } );

        // ─── Bot Account Cleaner (3.1.60+) ────────────────────────────
        // Score-based detection + safe deletion of fake user accounts.
        // Eligibility gated to subscriber/customer; admin roles + WC
        // customers with orders are never scored. 7 tools mirror the
        // REST surface under /luwipress/v1/bot-accounts/*.

        $this->register_tool( 'bot_account_scan', array(
            'description' => 'Run a fresh bot-account scan: scores every subscriber/customer user across signals (disposable email domain, random-entropy username, 0 orders + 0 comments + 0 logins, stale registration age, burst registration, missing real name, etc.). Writes rows into wp_luwipress_bot_account_scores. Returns aggregate counts {scanned, flagged, protected, threshold}. Pass limit to cap the batch.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Cap on accounts to score this run (default: settings.scan_batch_size, typically 500).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account Scan',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable', 'message' => 'LuwiPress_Bot_Account_Cleaner requires core 3.1.60+.' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            $limit = isset( $args['limit'] ) ? (int) $args['limit'] : null;
            return $c->run_scan( $limit );
        } );

        $this->register_tool( 'bot_account_list', array(
            'description' => 'List flagged bot-suspect accounts with score >= threshold, ordered by score desc. Each row includes user_id, score, signals (signal→weight), status, user_login, user_email, display_name, user_registered, roles. Paginated.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'page'      => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Rows per page, max 200 (default 50).' ),
                    'min_score' => array( 'type' => 'integer', 'description' => 'Override the configured threshold for this query (0-100).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account List',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            return $c->list_suspects(
                isset( $args['page'] ) ? (int) $args['page'] : 1,
                isset( $args['per_page'] ) ? (int) $args['per_page'] : 50,
                isset( $args['min_score'] ) ? (int) $args['min_score'] : null
            );
        } );

        $this->register_tool( 'bot_account_score', array(
            'description' => 'Compute (without persisting) the bot-likelihood score for a single user id. Returns {score 0-100, signals map, protected bool, reason?}. Useful for spot-checking why a user was/was-not flagged before bulk action.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id' => array( 'type' => 'integer', 'description' => 'WP user ID to score.' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account Score (single)',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            return $c->score_user( (int) ( $args['user_id'] ?? 0 ) );
        } );

        $this->register_tool( 'bot_account_delete', array(
            'description' => 'Delete bot-suspect user accounts. DRY-RUN BY DEFAULT — pass confirm=true to actually execute. Re-scores each user on the server side and refuses to delete any protected account (admin/editor/shop_manager role, whitelisted, or has WC orders) regardless of caller intent. Reassigns deleted-user content to user ID 1. Returns {deleted, skipped, errors, dry_run}.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'WP user IDs to delete.' ),
                    'confirm'  => array( 'type' => 'boolean', 'description' => 'false (default) = dry-run preview; true = actually delete.' ),
                ),
                'required' => array( 'user_ids' ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account Delete',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            $ids = isset( $args['user_ids'] ) && is_array( $args['user_ids'] ) ? array_map( 'intval', $args['user_ids'] ) : array();
            return $c->delete_users( $ids, ! empty( $args['confirm'] ) );
        } );

        $this->register_tool( 'bot_account_whitelist', array(
            'description' => 'Add or remove a user from the bot-account whitelist. Whitelisted users are skipped on every scan and cannot be deleted through this surface. Use action="add" (default) or action="remove".',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id' => array( 'type' => 'integer', 'description' => 'WP user ID.' ),
                    'action'  => array( 'type' => 'string', 'enum' => array( 'add', 'remove' ), 'description' => 'add (default) or remove.' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account Whitelist',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            $uid    = (int) ( $args['user_id'] ?? 0 );
            $action = ( $args['action'] ?? 'add' ) === 'remove' ? 'remove' : 'add';
            if ( $action === 'remove' ) {
                $c->whitelist_remove( $uid );
            } else {
                $c->whitelist_add( $uid );
            }
            return array(
                'whitelist' => $c->get_whitelist(),
                'action'    => $action,
                'user_id'   => $uid,
            );
        } );

        $this->register_tool( 'bot_account_stats', array(
            'description' => 'Aggregate bot-account stats: by_status (scored/whitelisted/deleted counts), flagged count, threshold, score-bucket histogram (high 80+, medium 60-79, low 40-59, noise <40), whitelist size, last_scan summary.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array(
                'title'           => 'Bot Account Stats',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            return LuwiPress_Bot_Account_Cleaner::get_instance()->get_stats();
        } );

        $this->register_tool( 'bot_account_settings_set', array(
            'description' => 'Update bot-account scanner settings (partial-update). Tunable: threshold (0-100, default 60), min_age_days (default 30), scan_batch_size (50-5000, default 500), allowed_roles (default ["subscriber","customer"]). Protected roles + WC-order guard are NOT operator-tweakable safety invariants.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'threshold'       => array( 'type' => 'integer', 'description' => '0-100 score threshold for flagging.' ),
                    'min_age_days'    => array( 'type' => 'integer', 'description' => 'Grace period for newly registered accounts.' ),
                    'scan_batch_size' => array( 'type' => 'integer', 'description' => '50-5000 accounts per scan run.' ),
                    'allowed_roles'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Roles eligible for scoring (default subscriber, customer).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Bot Account Settings (Write)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Account_Cleaner' ) ) {
                return array( 'error' => 'unavailable' );
            }
            $c = LuwiPress_Bot_Account_Cleaner::get_instance();
            return $c->update_settings( (array) $args );
        } );

        // ─── Cookie Consent (3.2.0+) ──────────────────────────────────
        // GDPR/ePrivacy banner + consent log + AI policy generator.

        $this->register_tool( 'cookie_consent_settings_get', array(
            'description' => 'Read the current Cookie Consent module settings: enabled flag, mode (info/opt-in/opt-out), position, theme, button toggles, policy URLs, retention, categories, and all banner text strings.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Cookie Consent Settings (Read)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Cookie_Consent' ) ) return array( 'error' => 'unavailable', 'message' => 'requires core 3.2.0+' );
            return LuwiPress_Cookie_Consent::get_instance()->get_settings();
        } );

        $this->register_tool( 'cookie_consent_settings_set', array(
            'description' => 'Partial-update Cookie Consent settings. Common patterns: {enabled:true, mode:"opt-in"} to turn on, {position:"bottom-right", theme:"dark"} to restyle, {policy_url:"..."} to link the policy page. Texts may be overridden via {texts:{title:"…", body:"…", accept_all:"…"}}.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'enabled'  => array( 'type' => 'boolean' ),
                    'mode'     => array( 'type' => 'string', 'enum' => array( 'info', 'opt-in', 'opt-out' ) ),
                    'position' => array( 'type' => 'string', 'enum' => array( 'bottom', 'top', 'bottom-left', 'bottom-right' ) ),
                    'theme'    => array( 'type' => 'string', 'enum' => array( 'auto', 'light', 'dark' ) ),
                    'show_reject_button' => array( 'type' => 'boolean' ),
                    'show_preferences'   => array( 'type' => 'boolean' ),
                    'policy_url'   => array( 'type' => 'string' ),
                    'privacy_url'  => array( 'type' => 'string' ),
                    'imprint_url'  => array( 'type' => 'string' ),
                    'log_retention_days' => array( 'type' => 'integer' ),
                    'categories_enabled' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'texts' => array( 'type' => 'object' ),
                ),
            ),
            'annotations' => array( 'title' => 'Cookie Consent Settings (Write)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Cookie_Consent' ) ) return array( 'error' => 'unavailable' );
            return LuwiPress_Cookie_Consent::get_instance()->update_settings( (array) $args );
        } );

        $this->register_tool( 'cookie_consent_stats', array(
            'description' => 'Aggregate consent log stats: total records, last_30_days count, by_source breakdown (banner-accept / banner-reject / preferences), per-category accept-rate (analytics, marketing, personalization, necessary), sample size used for the rate calculation, current settings.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Cookie Consent Stats', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Cookie_Consent' ) ) return array( 'error' => 'unavailable' );
            return LuwiPress_Cookie_Consent::get_instance()->get_stats();
        } );

        $this->register_tool( 'cookie_consent_log', array(
            'description' => 'Paginated consent log. Returns visitor decisions with hashed IP, source (banner-accept/banner-reject/preferences/preferences-accept-all), choices map, country_code (when behind Cloudflare), language, consent_id, created_at.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'page'     => array( 'type' => 'integer' ),
                    'per_page' => array( 'type' => 'integer' ),
                ),
            ),
            'annotations' => array( 'title' => 'Cookie Consent Log', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Cookie_Consent' ) ) return array( 'error' => 'unavailable' );
            $page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
            $per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
            return LuwiPress_Cookie_Consent::get_instance()->get_log( $page, $per_page );
        } );

        $this->register_tool( 'cookie_consent_policy_generate', array(
            'description' => 'Generate a site-specific cookie policy paragraph via the AI engine. The prompt is enriched with the list of analytics/marketing/Meta tags actually detected on the site (LuwiPress_Plugin_Detector). Returns {text, detected, language}. Operator pastes the text into their Cookie Policy page.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'language' => array( 'type' => 'string', 'description' => 'ISO 639-1 code (en/fr/de/tr/...). Defaults to site language.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Cookie Policy AI Generator', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Cookie_Consent' ) ) return array( 'error' => 'unavailable' );
            $lang = $args['language'] ?? null;
            $out = LuwiPress_Cookie_Consent::get_instance()->generate_policy_text( $lang ? (string) $lang : null );
            if ( is_wp_error( $out ) ) {
                return array( 'error' => $out->get_error_code(), 'message' => $out->get_error_message() );
            }
            return $out;
        } );

        // ─── Bot Shield (3.2.0+) ──────────────────────────────────────
        // Front-edge bot/scraper filter: UA blocklist + rate limit + honeypot
        // + REST/XML-RPC enumeration block.

        $this->register_tool( 'bot_shield_stats', array(
            'description' => 'Bot Shield stats: enabled flag, active_blocks count, today_denials, 14-day by_day daily breakdown, by_reason top reasons (ua_blocklist:NAME / rate_limit / honeypot / blocked), allowlist payload.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Bot Shield Stats', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable', 'message' => 'requires core 3.2.0+' );
            return LuwiPress_Bot_Shield::get_instance()->get_stats();
        } );

        $this->register_tool( 'bot_shield_settings_get', array(
            'description' => 'Read Bot Shield settings: enabled, block_ua_scrapers, rate_limit_enabled/threshold/window, block_ttl_minutes, block_user_enumeration, disable_xmlrpc, honeypot_enabled, verify_search_engines, sensitive_paths[], honeypot_paths[], ua_blocklist[], log_block_events.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'Bot Shield Settings (Read)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            return LuwiPress_Bot_Shield::get_instance()->get_settings();
        } );

        $this->register_tool( 'bot_shield_settings_set', array(
            'description' => 'Partial-update Bot Shield settings. Most common: {enabled:true} to turn on; {rate_limit_threshold:30, rate_limit_window:60} to tighten throttle; {ua_blocklist:["AhrefsBot","SemrushBot"]} to replace the list. Hard guards (logged-in admins, private IP space) are non-toggleable.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'enabled'                => array( 'type' => 'boolean' ),
                    'block_ua_scrapers'      => array( 'type' => 'boolean' ),
                    'rate_limit_enabled'     => array( 'type' => 'boolean' ),
                    'rate_limit_threshold'   => array( 'type' => 'integer', 'minimum' => 1 ),
                    'rate_limit_window'      => array( 'type' => 'integer', 'minimum' => 1 ),
                    'block_ttl_minutes'      => array( 'type' => 'integer', 'minimum' => 1 ),
                    'block_user_enumeration' => array( 'type' => 'boolean' ),
                    'disable_xmlrpc'         => array( 'type' => 'boolean' ),
                    'honeypot_enabled'       => array( 'type' => 'boolean' ),
                    'verify_search_engines'  => array( 'type' => 'boolean' ),
                    'sensitive_paths'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'honeypot_paths'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'ua_blocklist'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                ),
            ),
            'annotations' => array( 'title' => 'Bot Shield Settings (Write)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            return LuwiPress_Bot_Shield::get_instance()->update_settings( (array) $args );
        } );

        $this->register_tool( 'bot_shield_blocks_list', array(
            'description' => 'Paginated list of currently blocked IPs. Each row: ip, reason, hit_count, first_seen, last_seen, expires_at (null = permanent), source (auto|manual), ua_sample, path_sample. Use to spot-check what the shield has caught.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'page'     => array( 'type' => 'integer' ),
                    'per_page' => array( 'type' => 'integer' ),
                ),
            ),
            'annotations' => array( 'title' => 'Bot Shield Blocks List', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
            $per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
            return LuwiPress_Bot_Shield::get_instance()->list_blocks( $page, $per_page );
        } );

        $this->register_tool( 'bot_shield_block', array(
            'description' => 'Manually block an IP. ttl_minutes default 1440 (24h); pass 0 for a permanent block. reason is a free-text tag (default "manual"). DESTRUCTIVE: deny-lists real visitors if misused.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'ip'          => array( 'type' => 'string', 'description' => 'IPv4 or IPv6 address.' ),
                    'reason'      => array( 'type' => 'string' ),
                    'ttl_minutes' => array( 'type' => 'integer' ),
                ),
                'required' => array( 'ip' ),
            ),
            'annotations' => array( 'title' => 'Bot Shield Block IP', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $ip = (string) ( $args['ip'] ?? '' );
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return array( 'error' => 'invalid_ip', 'ip' => $ip );
            }
            $reason = (string) ( $args['reason'] ?? 'manual' );
            $ttl    = isset( $args['ttl_minutes'] ) ? (int) $args['ttl_minutes'] : 1440;
            LuwiPress_Bot_Shield::get_instance()->manual_block( $ip, $reason, $ttl );
            return array( 'ok' => true, 'ip' => $ip, 'reason' => $reason, 'ttl_minutes' => $ttl );
        } );

        $this->register_tool( 'bot_shield_unblock', array(
            'description' => 'Remove an IP from the block list (auto or manual).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array( 'ip' => array( 'type' => 'string' ) ),
                'required' => array( 'ip' ),
            ),
            'annotations' => array( 'title' => 'Bot Shield Unblock IP', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $ip = (string) ( $args['ip'] ?? '' );
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return array( 'error' => 'invalid_ip', 'ip' => $ip );
            }
            LuwiPress_Bot_Shield::get_instance()->unblock( $ip );
            return array( 'ok' => true, 'ip' => $ip );
        } );

        $this->register_tool( 'bot_shield_test', array(
            'description' => 'Dry-run a (IP, UA, path) tuple against the current rule set without firing any deny. Returns {verdict: allow|deny, reason}. Use to tune thresholds without risking lockout. Reasons: private_ip / allowlisted / already_blocked / honeypot:<path> / ua_blocklist:<needle> / sensitive_path_rate_limited.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'ip'   => array( 'type' => 'string' ),
                    'ua'   => array( 'type' => 'string' ),
                    'path' => array( 'type' => 'string' ),
                ),
            ),
            'annotations' => array( 'title' => 'Bot Shield Test', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $req = new WP_REST_Request( 'POST', '/luwipress/v1/bot-shield/test' );
            if ( isset( $args['ip'] ) )   $req->set_param( 'ip',   (string) $args['ip'] );
            if ( isset( $args['ua'] ) )   $req->set_param( 'ua',   (string) $args['ua'] );
            if ( isset( $args['path'] ) ) $req->set_param( 'path', (string) $args['path'] );
            $resp = LuwiPress_Bot_Shield::get_instance()->rest_test( $req );
            return ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        } );

        // ─── Bot Shield: comment review (3.3.0+) ──────────────────────
        $this->register_tool( 'bot_shield_comments_recent', array(
            'description' => 'List recent bot-suspect comment events caught by Bot Shield (last 100 max). Returns score, action taken (moderated/spam/rejected), the matched signals (link density / spam tokens / author shape / duplicate / etc.), and a 240-char snippet of the body. IPs are masked (last octet zeroed) for GDPR.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max events to return (1..100, default 50).' ),
                ),
            ),
            'annotations' => array( 'title' => 'Bot Shield: Recent Comment Events', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
            $items = LuwiPress_Bot_Shield::get_instance()->get_recent_comment_events( $limit );
            return array( 'items' => $items, 'count' => count( $items ) );
        } );

        $this->register_tool( 'bot_shield_comments_test', array(
            'description' => 'Dry-run a comment payload against the current Bot Shield comment scorer. Returns {verdict, score, threshold, signals, mode}. Verdicts: allow (below threshold) / moderate (held) / spam (queue) / reject (silent 403). Useful for tuning thresholds + spam-token lists without touching live submissions.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'author'  => array( 'type' => 'string',  'description' => 'Comment author name (display).' ),
                    'email'   => array( 'type' => 'string',  'description' => 'Author email.' ),
                    'url'     => array( 'type' => 'string',  'description' => 'Author URL field (often a spam tell).' ),
                    'content' => array( 'type' => 'string',  'description' => 'Comment body (required).' ),
                    'ip'      => array( 'type' => 'string',  'description' => 'Simulated client IP (defaults to 0.0.0.0).' ),
                    'post_id' => array( 'type' => 'integer', 'description' => 'Target post id (optional, not scored against currently).' ),
                ),
                'required' => array( 'content' ),
            ),
            'annotations' => array( 'title' => 'Bot Shield: Test Comment', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Bot_Shield' ) ) return array( 'error' => 'unavailable' );
            $payload = array(
                'author'  => isset( $args['author'] )  ? (string) $args['author']  : '',
                'email'   => isset( $args['email'] )   ? (string) $args['email']   : '',
                'url'     => isset( $args['url'] )     ? (string) $args['url']     : '',
                'content' => isset( $args['content'] ) ? (string) $args['content'] : '',
                'ip'      => isset( $args['ip'] )      ? (string) $args['ip']      : '0.0.0.0',
                'post_id' => isset( $args['post_id'] ) ? (int) $args['post_id']    : 0,
            );
            return LuwiPress_Bot_Shield::get_instance()->test_comment_payload( $payload );
        } );

        $this->register_tool( 'translation_fix_elementor', array(
            'description' => 'Repair WPML/Polylang translated posts whose Elementor data was dropped or mis-copied. Re-links translation copies to their source Elementor data so structural changes propagate. Pass "all" to fix every translated post, or a comma-separated list of post IDs.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_ids' => array( 'type' => 'string', 'description' => 'Comma-separated translated post IDs, or "all" (default: all)' ),
                    'language' => array( 'type' => 'string', 'description' => 'Restrict to a single target language code (e.g. fr, it, es). Omit for all languages.' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Fix Elementor in Translated Posts',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/fix-elementor' );
            $request->set_param( 'post_ids', sanitize_text_field( $args['post_ids'] ?? 'all' ) );
            if ( ! empty( $args['language'] ) ) {
                $request->set_param( 'language', sanitize_text_field( $args['language'] ) );
            }
            $data = $trans->fix_elementor_translated_posts( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── CRM Tools ───────────────────────────────── */

    private function register_crm_tools() {
        if ( ! class_exists( 'LuwiPress_CRM_Bridge' ) ) {
            return;
        }

        $this->register_tool( 'crm_overview', array(
            'description' => 'Get CRM overview: total customers, segments, revenue stats',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/overview' );
            $data    = $crm->handle_overview( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_settings_get', array(
            'description' => 'Read CRM segmentation thresholds: vip_spend, loyal_orders, active_days, at_risk_days, dormant_days, new_days. Returns current values + the schema (defaults + descriptions). Mirrors GET /crm/settings.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'CRM Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/settings' );
            $data    = $crm->handle_get_settings( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_settings_set', array(
            'description' => 'Update CRM segmentation thresholds (partial update — only the keys you pass change). After changing thresholds, call crm_refresh_segments to reclassify existing customers. Mirrors POST /crm/settings.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'vip_spend'    => array( 'type' => 'number',  'description' => 'Lifetime spend (store currency) at which a customer becomes VIP.' ),
                    'loyal_orders' => array( 'type' => 'integer', 'description' => 'Order count at which a repeat customer becomes Loyal.' ),
                    'active_days'  => array( 'type' => 'integer', 'description' => 'Recency window (days) during which a customer is Active.' ),
                    'at_risk_days' => array( 'type' => 'integer', 'description' => 'Max days since last order before At Risk.' ),
                    'dormant_days' => array( 'type' => 'integer', 'description' => 'Max days since last order before Dormant.' ),
                    'new_days'     => array( 'type' => 'integer', 'description' => 'First-time customer window (days since first order) tagged as New.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Update CRM Settings', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CRM_Bridge', 'handle_update_settings', $args );
        } );

        $this->register_tool( 'crm_refresh_segments', array(
            'description' => 'Recompute customer segments now, applying the current thresholds to every customer. Run this after crm_settings_set so existing customers pick up the new thresholds. Mirrors POST /crm/refresh-segments.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Refresh CRM Segments', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_CRM_Bridge', 'handle_refresh_segments', $args );
        } );

        $this->register_tool( 'crm_segments', array(
            'description' => 'List customer segments (VIP, at-risk, new, churned, etc.)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/segments' );
            $data    = $crm->handle_segments( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_segment_customers', array(
            'description' => 'Get customers in a specific segment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'segment' => array( 'type' => 'string', 'description' => 'Segment slug: vip, at_risk, new, churned, etc. (required)' ),
                ),
                'required'   => array( 'segment' ),
            ),
        ), function ( $args ) {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $segment = sanitize_text_field( $args['segment'] ?? '' );
            $limit   = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/segment/' . $segment );
            $request->set_url_params( array( 'segment' => $segment ) );
            $request->set_query_params( array( 'segment' => $segment, 'limit' => $limit ) );
            $data    = $crm->handle_segment_customers( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_customer_profile', array(
            'description' => 'Get detailed customer profile with purchase history and analytics',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'customer_id' => array( 'type' => 'integer', 'description' => 'Customer/user ID (required)' ),
                ),
                'required'   => array( 'customer_id' ),
            ),
        ), function ( $args ) {
            $crm         = LuwiPress_CRM_Bridge::get_instance();
            $customer_id = intval( $args['customer_id'] ?? 0 );
            $request     = new WP_REST_Request( 'GET', '/luwipress/v1/crm/customer/' . $customer_id );
            $request->set_url_params( array( 'customer_id' => $customer_id ) );
            $request->set_query_params( array( 'customer_id' => $customer_id ) );
            $data    = $crm->handle_customer_profile( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_suspicious_bots', array(
            'description' => 'Read-only audit of suspected bot/fake-customer registrations (random emails, disposable domains, zero orders, never-logged-in). Operator reviews and deletes via WP Users admin. Ships with 3.1.42 — purge action planned for 3.1.43.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max flagged customers to return (default 100, max 500)', 'minimum' => 1, 'maximum' => 500 ),
                ),
            ),
            'annotations' => array( 'title' => 'Suspicious Bot Customers (audit)', 'readOnlyHint' => true ),
        ), function ( $args ) {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $limit   = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/suspicious-bots' );
            $request->set_param( 'limit', $limit );
            $request->set_query_params( array( 'limit' => $limit ) );
            $data = $crm->handle_suspicious_bots( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_lifecycle_queue', array(
            'description' => 'Get pending CRM lifecycle events (welcome, review request, win-back emails queued for sending)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Lifecycle Queue', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/lifecycle-queue' );
            $data    = $crm->handle_lifecycle_queue( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Email Tools ──────────────────────────────── */

    private function register_email_tools() {
        $this->register_tool( 'send_email', array(
            'description' => 'Send an email via WordPress wp_mail() using the site\'s configured SMTP. Supports plain text and WooCommerce HTML template.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'to'       => array( 'type' => 'string', 'format' => 'email', 'description' => 'Recipient email (required)' ),
                    'subject'  => array( 'type' => 'string', 'description' => 'Email subject (required)' ),
                    'body'     => array( 'type' => 'string', 'description' => 'Email body — HTML or plain text (required)' ),
                    'template' => array( 'type' => 'string', 'enum' => array( 'plain', 'woocommerce' ), 'description' => 'Email template (default: plain)' ),
                ),
                'required'   => array( 'to', 'subject', 'body' ),
            ),
            'annotations' => array(
                'title'            => 'Send Email',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => true,   // Sends real email to external recipient
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Email_Proxy', 'send_email', $args );
        } );
    }

    /* ───────────────────── Workflow Tools ──────────────────────────── */

    private function register_workflow_tools() {
        $this->register_tool( 'workflow_report_result', array(
            'description' => 'Report a workflow execution result back to LuwiPress (status, message, token usage)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'workflow'     => array( 'type' => 'string', 'description' => 'Workflow name (required)' ),
                    'status'       => array( 'type' => 'string', 'description' => 'success, error, warning' ),
                    'message'      => array( 'type' => 'string', 'description' => 'Result message (required)' ),
                    'execution_id' => array( 'type' => 'string', 'description' => 'Workflow execution ID' ),
                    'token_usage'  => array( 'type' => 'object', 'description' => 'Token usage data: provider, model, input_tokens, output_tokens' ),
                ),
                'required'   => array( 'workflow', 'message' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_API', 'handle_workflow_result', $args );
        } );

        $this->register_tool( 'content_schedule_list', array(
            'description' => 'Get scheduled content items',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Content_Scheduler' ) ) {
                throw new Exception( 'Content Scheduler not loaded' );
            }
            $sched   = LuwiPress_Content_Scheduler::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/schedule/list' );
            $data    = $sched->get_schedule_list( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'content_schedule_create', array(
            'description' => 'Queue a single blog post for AI generation and scheduled publishing. The AI runs in the background (wp-cron); this call returns immediately with a schedule_id.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'topic'         => array( 'type' => 'string',  'description' => 'Main topic / title of the article (required)' ),
                    'keywords'      => array( 'type' => 'string',  'description' => 'Comma-separated SEO keywords (optional)' ),
                    'publish_date'  => array( 'type' => 'string',  'description' => 'YYYY-MM-DD — when the post should go live' ),
                    'publish_time'  => array( 'type' => 'string',  'description' => 'HH:MM — publish hour (default "09:00")' ),
                    'post_type'     => array( 'type' => 'string',  'description' => 'post | page (default "post")' ),
                    'language'      => array( 'type' => 'string',  'description' => 'Target language code (default: site default)' ),
                    'tone'          => array( 'type' => 'string',  'description' => 'professional | casual | academic | creative | persuasive | informative' ),
                    'depth'         => array( 'type' => 'string',  'description' => 'standard | deep | editorial (depth preset; editorial = essay with voice, 2000-3500+ words)' ),
                    'word_count'    => array( 'type' => 'integer', 'description' => 'Target word count (default 1500)' ),
                    'generate_image'=> array( 'type' => 'boolean', 'description' => 'Generate a featured image (default true)' ),
                ),
                'required' => array( 'topic', 'publish_date' ),
            ),
            'annotations' => array(
                'title'            => 'Schedule Blog Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => true,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Content_Scheduler' ) ) {
                throw new Exception( 'Content Scheduler not loaded' );
            }
            $topic        = sanitize_text_field( $args['topic'] ?? '' );
            $publish_date = sanitize_text_field( $args['publish_date'] ?? '' );
            if ( empty( $topic ) || empty( $publish_date ) ) {
                throw new Exception( 'topic and publish_date are required' );
            }

            $depth = in_array( ( $args['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true ) ? $args['depth'] : 'standard';
            $publish_time = sanitize_text_field( $args['publish_time'] ?? '09:00' );

            $schedule_id = wp_insert_post( array(
                'post_type'   => 'luwipress_schedule',
                'post_title'  => $topic,
                'post_status' => 'publish',
                'meta_input'  => array(
                    '_luwipress_schedule_status'   => 'pending',
                    '_luwipress_schedule_topic'    => $topic,
                    '_luwipress_schedule_keywords' => sanitize_text_field( $args['keywords'] ?? '' ),
                    '_luwipress_schedule_type'     => sanitize_text_field( $args['post_type'] ?? 'post' ),
                    '_luwipress_schedule_date'     => $publish_date . ' ' . $publish_time,
                    '_luwipress_schedule_image'    => ! empty( $args['generate_image'] ) ? 1 : 0,
                    '_luwipress_schedule_language' => sanitize_text_field( $args['language'] ?? get_option( 'luwipress_target_language', 'en' ) ),
                    '_luwipress_schedule_tone'     => sanitize_text_field( $args['tone'] ?? 'professional' ),
                    '_luwipress_schedule_words'    => absint( $args['word_count'] ?? 1500 ),
                    '_luwipress_schedule_depth'    => $depth,
                    '_luwipress_schedule_created'  => current_time( 'mysql' ),
                    '_luwipress_schedule_user'     => get_current_user_id(),
                ),
            ), true );

            if ( is_wp_error( $schedule_id ) ) {
                throw new Exception( 'Failed to create schedule: ' . $schedule_id->get_error_message() );
            }

            // Fire AI generation in background
            wp_schedule_single_event( time(), 'luwipress_generate_single', array( (int) $schedule_id ) );
            spawn_cron();

            return array(
                'success'     => true,
                'schedule_id' => (int) $schedule_id,
                'topic'       => $topic,
                'depth'       => $depth,
                'publish_at'  => $publish_date . ' ' . $publish_time,
                'status'      => 'pending',
                'message'     => 'Queued for AI generation. Check status with content_schedule_status.',
            );
        } );

        $this->register_tool( 'content_bulk_queue', array(
            'description' => 'Queue up to 50 blog post topics for AI generation and staggered publishing. Each topic becomes an individual schedule row. Publish dates are auto-spread across the chosen interval; AI generation is staggered to avoid bursting the daily AI budget.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'topics'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of topic strings (one per post, max 50). Optional "topic | keywords" pipe syntax per item.' ),
                    'start_date'      => array( 'type' => 'string',  'description' => 'YYYY-MM-DD — first publish date (defaults to tomorrow)' ),
                    'start_time'      => array( 'type' => 'string',  'description' => 'HH:MM — publish hour (default "09:00")' ),
                    'interval_unit'   => array( 'type' => 'string',  'description' => 'day | hour (spacing unit between posts, default "day")' ),
                    'interval_value'  => array( 'type' => 'integer', 'description' => 'Spacing value (e.g. 1 = every day, 6 = every 6 hours)' ),
                    'generate_offset' => array( 'type' => 'integer', 'description' => 'Minutes between AI generation runs (budget-friendly staggering; default 5)' ),
                    'post_type'       => array( 'type' => 'string',  'description' => 'post | page (default "post")' ),
                    'language'        => array( 'type' => 'string',  'description' => 'Target language code (default: site default)' ),
                    'tone'            => array( 'type' => 'string',  'description' => 'professional | casual | academic | creative | persuasive | informative' ),
                    'depth'           => array( 'type' => 'string',  'description' => 'standard | deep | editorial (depth preset)' ),
                    'word_count'      => array( 'type' => 'integer', 'description' => 'Target word count per post (default 1500)' ),
                    'generate_image'  => array( 'type' => 'boolean', 'description' => 'Generate featured images (default true)' ),
                ),
                'required' => array( 'topics' ),
            ),
            'annotations' => array(
                'title'            => 'Bulk Queue Blog Posts',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => true,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Content_Scheduler' ) ) {
                throw new Exception( 'Content Scheduler not loaded' );
            }
            $topics = $args['topics'] ?? array();
            if ( ! is_array( $topics ) || empty( $topics ) ) {
                throw new Exception( 'topics array is required' );
            }
            if ( count( $topics ) > 50 ) {
                throw new Exception( 'Maximum 50 topics per call; split and retry' );
            }

            // Shared settings
            $post_type       = sanitize_text_field( $args['post_type'] ?? 'post' );
            $generate_image  = isset( $args['generate_image'] ) ? (bool) $args['generate_image'] : true;
            $language        = sanitize_text_field( $args['language'] ?? get_option( 'luwipress_target_language', 'en' ) );
            $tone            = sanitize_text_field( $args['tone'] ?? 'professional' );
            $word_count      = absint( $args['word_count'] ?? 1500 );
            $depth           = in_array( ( $args['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true ) ? $args['depth'] : 'standard';
            $interval_unit   = in_array( ( $args['interval_unit'] ?? 'day' ), array( 'hour', 'day' ), true ) ? $args['interval_unit'] : 'day';
            $interval_value  = max( 1, absint( $args['interval_value'] ?? 1 ) );
            $generate_offset = max( 0, absint( $args['generate_offset'] ?? 5 ) );

            $start_date = sanitize_text_field( $args['start_date'] ?? '' );
            $start_time = sanitize_text_field( $args['start_time'] ?? '09:00' );
            if ( empty( $start_date ) ) {
                $start_date = date_i18n( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
            }
            $cursor_ts = strtotime( $start_date . ' ' . $start_time );
            if ( ! $cursor_ts ) {
                throw new Exception( 'Invalid start_date/start_time' );
            }

            $step_sec = ( 'hour' === $interval_unit ) ? $interval_value * HOUR_IN_SECONDS : $interval_value * DAY_IN_SECONDS;

            $created = array();
            $errors  = array();

            foreach ( $topics as $idx => $line ) {
                $line  = trim( (string) $line );
                if ( empty( $line ) ) {
                    $errors[] = array( 'row' => $idx, 'error' => 'empty topic' );
                    continue;
                }
                $parts    = array_map( 'trim', explode( '|', $line, 2 ) );
                $topic    = sanitize_text_field( $parts[0] ?? '' );
                $keywords = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';

                $publish_ts   = $cursor_ts + ( $idx * $step_sec );
                $publish_date = date( 'Y-m-d H:i:s', $publish_ts );

                $schedule_id = wp_insert_post( array(
                    'post_type'   => 'luwipress_schedule',
                    'post_title'  => $topic,
                    'post_status' => 'publish',
                    'meta_input'  => array(
                        '_luwipress_schedule_status'   => 'pending',
                        '_luwipress_schedule_topic'    => $topic,
                        '_luwipress_schedule_keywords' => $keywords,
                        '_luwipress_schedule_type'     => $post_type,
                        '_luwipress_schedule_date'     => $publish_date,
                        '_luwipress_schedule_image'    => $generate_image ? 1 : 0,
                        '_luwipress_schedule_language' => $language,
                        '_luwipress_schedule_tone'     => $tone,
                        '_luwipress_schedule_words'    => $word_count,
                        '_luwipress_schedule_depth'    => $depth,
                        '_luwipress_schedule_created'  => current_time( 'mysql' ),
                        '_luwipress_schedule_user'     => get_current_user_id(),
                        '_luwipress_schedule_batch'    => 1,
                    ),
                ), true );

                if ( is_wp_error( $schedule_id ) ) {
                    $errors[] = array( 'row' => $idx, 'error' => $schedule_id->get_error_message() );
                    continue;
                }

                $gen_at = time() + ( count( $created ) * $generate_offset * MINUTE_IN_SECONDS );
                wp_schedule_single_event( $gen_at, 'luwipress_generate_single', array( (int) $schedule_id ) );

                $created[] = array(
                    'schedule_id'  => (int) $schedule_id,
                    'topic'        => $topic,
                    'publish_date' => $publish_date,
                    'generate_at'  => date( 'Y-m-d H:i:s', $gen_at ),
                );
            }

            spawn_cron();

            return array(
                'success' => true,
                'queued'  => count( $created ),
                'skipped' => count( $errors ),
                'depth'   => $depth,
                'items'   => $created,
                'errors'  => $errors,
            );
        } );

        $this->register_tool( 'content_schedule_status', array(
            'description' => 'Get the status of a single scheduled content item by ID. Returns title, current status (pending/generating/ready/published/failed), publish date, and the published post ID once available.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'schedule_id' => array( 'type' => 'integer', 'description' => 'Schedule item ID (returned from content_schedule_create or content_bulk_queue)' ),
                ),
                'required' => array( 'schedule_id' ),
            ),
            'annotations' => array(
                'title'            => 'Schedule Item Status',
                'readOnlyHint'     => true,
                'idempotentHint'   => true,
            ),
        ), function ( $args ) {
            $schedule_id = absint( $args['schedule_id'] ?? 0 );
            if ( ! $schedule_id ) {
                throw new Exception( 'schedule_id is required' );
            }
            $post = get_post( $schedule_id );
            if ( ! $post || 'luwipress_schedule' !== $post->post_type ) {
                throw new Exception( 'Schedule item not found' );
            }
            return array(
                'schedule_id'       => $schedule_id,
                'topic'             => get_post_meta( $schedule_id, '_luwipress_schedule_topic', true ),
                'status'            => get_post_meta( $schedule_id, '_luwipress_schedule_status', true ),
                'depth'             => get_post_meta( $schedule_id, '_luwipress_schedule_depth', true ) ?: 'standard',
                'language'          => get_post_meta( $schedule_id, '_luwipress_schedule_language', true ),
                'tone'              => get_post_meta( $schedule_id, '_luwipress_schedule_tone', true ),
                'word_count'        => (int) get_post_meta( $schedule_id, '_luwipress_schedule_words', true ),
                'publish_date'      => get_post_meta( $schedule_id, '_luwipress_schedule_date', true ),
                'published_post_id' => (int) get_post_meta( $schedule_id, '_luwipress_published_post_id', true ),
                'error'             => get_post_meta( $schedule_id, '_luwipress_schedule_error', true ),
                'created'           => get_post_meta( $schedule_id, '_luwipress_schedule_created', true ),
            );
        } );

        $this->register_tool( 'content_schedule_delete', array(
            'description' => 'Delete (cancel) a scheduled content item before it publishes. If the post has already been published, this only removes the schedule tracking row — the published post remains.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'schedule_id' => array( 'type' => 'integer', 'description' => 'Schedule item ID to delete' ),
                ),
                'required' => array( 'schedule_id' ),
            ),
            'annotations' => array(
                'title'            => 'Cancel Scheduled Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => true,
                'idempotentHint'   => true,
            ),
        ), function ( $args ) {
            $schedule_id = absint( $args['schedule_id'] ?? 0 );
            if ( ! $schedule_id ) {
                throw new Exception( 'schedule_id is required' );
            }
            $post = get_post( $schedule_id );
            if ( ! $post || 'luwipress_schedule' !== $post->post_type ) {
                throw new Exception( 'Schedule item not found' );
            }
            // Unschedule any pending cron event for this item
            $next = wp_next_scheduled( 'luwipress_generate_single', array( $schedule_id ) );
            if ( $next ) {
                wp_unschedule_event( $next, 'luwipress_generate_single', array( $schedule_id ) );
            }
            wp_delete_post( $schedule_id, true );
            return array( 'success' => true, 'schedule_id' => $schedule_id );
        } );

        $this->register_tool( 'content_run_pending_now', array(
            'description' => 'Immediately trigger AI generation for all pending scheduled content (up to 10 per call). Shortcuts wp-cron when you want to process the queue right away.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'            => 'Run Pending Queue Now',
                'readOnlyHint'     => false,
                'idempotentHint'   => false,
                'openWorldHint'    => true,
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Content_Scheduler' ) ) {
                throw new Exception( 'Content Scheduler not loaded' );
            }
            $sched = LuwiPress_Content_Scheduler::get_instance();
            $pending = get_posts( array(
                'post_type'      => 'luwipress_schedule',
                'posts_per_page' => 10,
                'meta_query'     => array(
                    array( 'key' => '_luwipress_schedule_status', 'value' => 'pending' ),
                ),
            ) );
            $processed = 0;
            foreach ( $pending as $p ) {
                $sched->cron_generate_single( $p->ID );
                $processed++;
            }
            return array( 'processed' => $processed );
        } );
    }

    /* ───────────────────── Token Usage Tools ──────────────────────── */

    private function register_token_tools() {
        $this->register_tool( 'token_usage_stats', array(
            'description' => 'Get AI token usage statistics (cost, providers, models breakdown)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'days' => array( 'type' => 'integer', 'description' => 'Number of days to report (default 30)' ),
                ),
            ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-usage' );
            $request->set_param( 'days', $args['days'] ?? 30 );
            return $api->handle_get_token_usage( $request );
        } );

        $this->register_tool( 'token_limit_check', array(
            'description' => 'Check if daily AI token spending limit allows more API calls',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-usage/check' );
            return $api->handle_check_limit( $request );
        } );

        $this->register_tool( 'token_recent_calls', array(
            'description' => 'Get recent AI API calls with cost breakdown',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-recent' );
            return $api->handle_get_token_recent( $request );
        } );
    }

    /* ───────────────────── Review Analytics Tools ──────────────────── */

    private function register_review_tools() {
        if ( ! class_exists( 'LuwiPress_Review_Analytics' ) ) {
            return;
        }

        $this->register_tool( 'review_analytics', array(
            'description' => 'Get review analytics: sentiment distribution, average rating, response rates',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $ra      = LuwiPress_Review_Analytics::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/review/analytics' );
            $data    = $ra->handle_get_analytics( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'review_summary', array(
            'description' => 'Get AI-generated review summary for a product or across all products',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (optional — omit for all)' ),
                ),
            ),
        ), function ( $args ) {
            $ra      = LuwiPress_Review_Analytics::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/review/summary' );
            if ( ! empty( $args['product_id'] ) ) {
                $request->set_param( 'product_id', intval( $args['product_id'] ) );
            }
            $data = $ra->handle_get_summary( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Internal Linker Tools ──────────────────── */

    private function register_linker_tools() {
        if ( ! class_exists( 'LuwiPress_Internal_Linker' ) ) {
            return;
        }

        $this->register_tool( 'linker_resolve', array(
            'description' => 'Process the pre-computed internal-linking backlog for a single post: replace placeholder anchors with concrete URLs once the linker module has matched them against the catalogue. Returns { resolved, remaining }. This is a backlog processor, not an on-demand AI call — if the post has no entries in linker_unresolved the response is {resolved:0, remaining:0}. Pair with linker_unresolved to see what is waiting.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID whose unresolved-link backlog should be processed (required).' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Resolve Internal-Link Backlog',
                'readOnlyHint'    => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Internal_Linker', 'handle_resolve_links', array(
                'post_id' => intval( $args['post_id'] ),
            ) );
        } );

        $this->register_tool( 'linker_unresolved', array(
            'description' => 'List posts with unresolved internal linking suggestions',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $linker  = LuwiPress_Internal_Linker::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/unresolved-links' );
            $data    = $linker->handle_get_unresolved( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Knowledge Graph Tools ──────────────────── */

    private function register_knowledge_graph_tools() {
        if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
            return;
        }

        $this->register_tool( 'knowledge_graph', array(
            'description' => 'Get the site knowledge graph — entity relationships, product connections, and semantic structure',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $kg      = LuwiPress_Knowledge_Graph::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph' );
            $data    = $kg->handle_knowledge_graph( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── KG Candidates (Action Queue v2 surface) ──────────────────
        // Modern signal-driven opportunity list — RECENTLY_REGRESSED +
        // STALE_ENRICHED + MISSING_FAQ + filter-injected companion
        // candidates. Distinct from content_opportunities (legacy static
        // 5-category sweep) — that tool only inspects schema state, this
        // one watches the KG events stream + decay + theme bridge filter.
        $this->register_tool( 'kg_candidates', array(
            'description' => 'Action Queue v2 candidate list. Returns ROI-ranked opportunities derived from KG signals: RECENTLY_REGRESSED (pages with traffic/coverage drop in the last window), STALE_ENRICHED (products enriched >90 days ago whose source content changed), MISSING_FAQ + MISSING_HOWTO + MISSING_SCHEMA (schema-shaped gaps), and theme-companion candidates injected via the luwipress_kg_action_queue_external_candidates filter. Each item carries id, type, title, body, impact, effort_min, roi, tier, confidence_score (0-100), and a `why` block with primary/supporting signals + baseline comparison. Snoozed/dismissed candidates are already filtered out. For the legacy static 5-category report (missing_seo_meta, missing_translations, stale_content, thin_content, missing_alt_text), use content_opportunities instead.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => '1-24, default 6 (mirrors the dashboard Action Queue card cap).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'KG Action Queue Candidates',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $kg      = LuwiPress_Knowledge_Graph::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph/candidates' );
            if ( isset( $args['limit'] ) ) {
                $request->set_param( 'limit', (int) $args['limit'] );
            }
            $data = $kg->handle_kg_candidates( $request );
            if ( $data instanceof WP_Error ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ─── KG candidate queue management (snooze / dismiss) ─────────
        // Lets an agent ACT on the Action Queue, not just read it. Snooze
        // pulls a candidate off the queue for `hours`; dismiss removes it
        // permanently. Both take the candidate `id` from kg_candidates.
        $this->register_tool( 'kg_candidate_snooze', array(
            'description' => 'Snooze a KG Action Queue candidate for a number of hours so it temporarily drops off the queue. Pass the candidate `id` (from kg_candidates) and `hours`. Mirrors POST /knowledge-graph/candidate/snooze.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'    => array( 'type' => 'string',  'description' => 'Candidate id from kg_candidates (required).' ),
                    'hours' => array( 'type' => 'integer', 'description' => 'How long to snooze, in hours (e.g. 24).' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Snooze KG Candidate', 'readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Knowledge_Graph', 'handle_kg_candidate_snooze', $args );
        } );

        $this->register_tool( 'kg_candidate_dismiss', array(
            'description' => 'Permanently dismiss a KG Action Queue candidate so it no longer surfaces. Pass the candidate `id` (from kg_candidates). Mirrors POST /knowledge-graph/candidate/dismiss.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id' => array( 'type' => 'string', 'description' => 'Candidate id from kg_candidates (required).' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Dismiss KG Candidate', 'readOnlyHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Knowledge_Graph', 'handle_kg_candidate_dismiss', $args );
        } );

        // ─── KG Events stream + summary ───────────────────────────────
        // The signal layer that feeds KG candidates. Read the raw event
        // stream (enrich/seo/translate/schema_added) or fetch an aggregate
        // summary for the last N hours. Useful for verifying that the
        // signal layer is recording activity, and for cross-referencing
        // candidate types against their underlying triggers.
        $this->register_tool( 'kg_events', array(
            'description' => 'KG event stream (raw rows) or per-window aggregate summary. Event types: enrich, seo, translate, schema_added. Pass summary=true to get count-by-type over the last `hours` window instead of the row stream. Use this to verify the signal layer is actually recording activity — if event counts are zero, the v2 candidate types (RECENTLY_REGRESSED / STALE_ENRICHED) will be empty regardless of candidate generator state.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit'       => array( 'type' => 'integer', 'description' => 'Max rows returned (default 50). Ignored when summary=true.' ),
                    'since'       => array( 'type' => 'string', 'description' => 'MySQL datetime floor in UTC (e.g. "2026-05-01 00:00:00").' ),
                    'event_types' => array( 'type' => 'string', 'description' => 'Comma-separated whitelist: enrich, seo, translate, schema_added.' ),
                    'entity_id'   => array( 'type' => 'integer', 'description' => 'Scope stream to a single post/product ID.' ),
                    'summary'     => array( 'type' => 'boolean', 'description' => 'When true, return aggregated counts over `hours` window instead of the row stream.' ),
                    'hours'       => array( 'type' => 'integer', 'description' => 'Window size (hours) for summary aggregate (default 24, ignored when summary=false).' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'KG Events Stream',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $kg      = LuwiPress_Knowledge_Graph::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph/events' );
            foreach ( array( 'limit', 'since', 'event_types', 'entity_id', 'summary', 'hours' ) as $k ) {
                if ( array_key_exists( $k, $args ) ) {
                    $request->set_param( $k, $args[ $k ] );
                }
            }
            $data = $kg->handle_kg_events( $request );
            if ( $data instanceof WP_Error ) {
                return array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Elementor Tools ──────────────────────────── */

    private function register_elementor_tools() {
        if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
            return;
        }

        $this->register_tool( 'elementor_read_page', array(
            'description' => 'Read an Elementor page — returns full structure: sections, columns, and widgets with their text, styles, and element IDs. Use this first to discover element IDs before editing.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Read Elementor Page',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem     = LuwiPress_Elementor::get_instance();
            $elements = $elem->get_widget_tree( intval( $args['post_id'] ) );
            if ( is_wp_error( $elements ) ) {
                return array( 'error' => $elements->get_error_message() );
            }
            $post = get_post( intval( $args['post_id'] ) );
            return array(
                'post_id'       => intval( $args['post_id'] ),
                'title'         => $post ? $post->post_title : '',
                'element_count' => count( $elements ),
                'elements'      => $elements,
            );
        } );

        $this->register_tool( 'elementor_page_outline', array(
            'description' => 'Get a compact outline of an Elementor page — lightweight hierarchy with element IDs, types, and text previews. Use this for a quick overview before detailed reads.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Elementor Page Outline',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $outline = $elem->get_page_outline( intval( $args['post_id'] ) );
            if ( is_wp_error( $outline ) ) {
                return array( 'error' => $outline->get_error_message() );
            }
            $post = get_post( intval( $args['post_id'] ) );
            return array(
                'post_id'   => intval( $args['post_id'] ),
                'title'     => $post ? $post->post_title : '',
                'structure' => $outline,
            );
        } );

        $this->register_tool( 'elementor_translate_page', array(
            'description' => 'AI-translate all text in an Elementor page and create a WPML translation copy. Translates headings, text editors, buttons, tabs, accordions, etc.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'         => array( 'type' => 'integer', 'description' => 'Source post/page ID (required)' ),
                    'target_language' => array( 'type' => 'string', 'description' => 'Target language code, e.g. fr, de, ar (required)' ),
                ),
                'required'   => array( 'post_id', 'target_language' ),
            ),
            'annotations' => array(
                'title'           => 'Translate Elementor Page (AI)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => true,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->translate_page( intval( $args['post_id'] ), sanitize_text_field( $args['target_language'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_retranslate_from_source', array(
            'description' => 'Retranslate an Elementor page into one or more languages FROM THE SOURCE (default) language. Re-syncs the page STRUCTURE from the source — so diverged/stale translations (missing or extra sections) are rebuilt to match — AND AI-translates every text field (headings, buttons, repeaters: tabs/cards/stats/items) in safe chunks. Clears any stale "no translatable text" guard, then queues one background job per language via wp-cron (avoids long-request timeouts). Omit "languages" to target every active language except the source. Poll the page render or system_logs for completion.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'   => array( 'type' => 'integer', 'description' => 'Source post/page ID in the DEFAULT language (required)' ),
                    'languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Target language codes, e.g. ["fr","it","es"]. Omit to retranslate into all active languages except the source.' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Retranslate Elementor Page From Source (structure + text)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => true,
            ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] ?? 0 );
            if ( ! $post_id ) {
                return array( 'error' => 'post_id required' );
            }
            if ( ! class_exists( 'LuwiPress_Translation' ) || ! class_exists( 'LuwiPress_Elementor' ) ) {
                return array( 'error' => 'Translation/Elementor module not available' );
            }
            $default = LuwiPress_Translation::get_default_language();

            $requested = $args['languages'] ?? array();
            if ( is_string( $requested ) ) {
                $requested = array_filter( array_map( 'trim', explode( ',', $requested ) ) );
            }
            if ( ! empty( $requested ) ) {
                $targets = array_map( 'sanitize_text_field', (array) $requested );
            } else {
                $targets = array_diff( LuwiPress_Translation::get_active_languages(), array( $default ) );
            }
            $targets = array_values( array_unique( array_filter( (array) $targets, function ( $l ) use ( $default ) {
                return $l && $l !== $default;
            } ) ) );
            if ( empty( $targets ) ) {
                return array( 'error' => 'No target languages resolved (only the default language is active, or none passed).' );
            }

            // Clear the stale guard so a wrongly-flagged page is not skipped by cron.
            delete_post_meta( $post_id, '_luwipress_no_translatable_text' );

            $offset = 0;
            $queued = array();
            foreach ( $targets as $lang ) {
                // Dedupe any already-pending event for this (post, lang) pair.
                wp_clear_scheduled_hook( 'luwipress_elementor_translate_single', array( $post_id, $lang ) );
                // An explicit operator request gets a fresh attempt budget, otherwise a
                // page that previously exhausted its retries could never be requeued.
                delete_post_meta( $post_id, LuwiPress_Elementor::translation_attempt_meta_key( $lang ) );
                wp_schedule_single_event( time() + $offset, 'luwipress_elementor_translate_single', array( $post_id, $lang ) );
                $queued[] = $lang;
                $offset  += 8; // stagger so cron does not collapse them into one process
            }
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }

            return array(
                'status'           => 'queued',
                'post_id'          => $post_id,
                'source_language'  => $default,
                'queued_languages' => $queued,
                'note'             => 'Each language runs in the background: structure is re-synced from the source page and all text is AI-translated in safe chunks. Poll the page render or system_logs for completion.',
            );
        } );

        $this->register_tool( 'elementor_translation_queue', array(
            'description' => 'List PENDING background Elementor translation jobs (wp-cron queue). This is the queue that translation_status cannot see — translation_status only counts the standard non-Elementor path. Each entry returns post_id, title, language, when it is due, how many attempts it has already burned (attempts/max_attempts), and the last recorded phase (queued|translating|completed|failed|failed_max_attempts|cancelled). Filter with post_id and/or language. Use this BEFORE requeueing anything, to check whether a job is already waiting.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Only show entries for this source post' ),
                    'language' => array( 'type' => 'string', 'description' => 'Only show entries for this target language code' ),
                ),
                'required'   => array(),
            ),
            'annotations' => array(
                'title'          => 'List Elementor Translation Queue',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
                return array( 'error' => 'Elementor module not available' );
            }
            return LuwiPress_Elementor::get_instance()->list_translation_queue(
                intval( $args['post_id'] ?? 0 ),
                sanitize_text_field( (string) ( $args['language'] ?? '' ) )
            );
        } );

        $this->register_tool( 'elementor_translation_queue_cancel', array(
            'description' => 'Cancel the PENDING background translation job(s) for ONE (post_id, language) pair, leaving every other queued job alone. Also stamps the post\'s translation status as cancelled. Use elementor_translation_queue first to see what is waiting. To wipe the entire queue for all posts and languages instead, POST /elementor/translate-queue with action=cancel.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Source post/page ID (required)' ),
                    'language' => array( 'type' => 'string', 'description' => 'Target language code, e.g. de (required)' ),
                ),
                'required'   => array( 'post_id', 'language' ),
            ),
            'annotations' => array(
                'title'           => 'Cancel One Elementor Translation Job',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
                return array( 'error' => 'Elementor module not available' );
            }
            $post_id  = intval( $args['post_id'] ?? 0 );
            $language = sanitize_text_field( (string) ( $args['language'] ?? '' ) );
            if ( ! $post_id || '' === $language ) {
                return array( 'error' => 'post_id and language are required' );
            }
            return LuwiPress_Elementor::get_instance()->cancel_translation_queue_entry( $post_id, $language );
        } );

        $this->register_tool( 'elementor_get_widget', array(
            'description' => 'Get a specific Elementor element (widget, section, or column) by its ID — returns full settings, text content, and style info',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID — get this from elementor_read_page or elementor_page_outline (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'          => 'Get Elementor Element',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem     = LuwiPress_Elementor::get_instance();
            $elements = $elem->get_widget_tree( intval( $args['post_id'] ) );
            if ( is_wp_error( $elements ) ) {
                return array( 'error' => $elements->get_error_message() );
            }
            $target_id = sanitize_text_field( $args['element_id'] );
            foreach ( $elements as $el ) {
                if ( $el['id'] === $target_id ) {
                    return $el;
                }
            }
            return array( 'error' => 'Element not found: ' . $target_id );
        } );

        $this->register_tool( 'elementor_set_widget_text', array(
            'description' => 'Update text content of an Elementor widget. Values may be strings OR structured values (a whole repeater array, or a link-control object like {"url":"/x/","is_external":""}). Repeater rows can also be addressed one field at a time with an indexed path: "tabs:0:tab_title", "items:2:title". The write is ATOMIC per widget: if any field is rejected, nothing is written and the response names the offending field. Passing a bare string to a link control fills its url key instead of replacing the object; passing a bare string to any other structured control is refused rather than silently breaking the render. Read the widget first (elementor_get_widget) to see the real field shapes.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID (required)' ),
                    'texts'      => array(
                        'type'        => 'object',
                        'description' => 'Fields to update. Examples: {"title": "New Title"}, {"editor": "<p>New content</p>"}, {"tabs:0:tab_title": "Erste"}, {"tabs": [{"tab_title": "Eins"}, {"tab_title": "Zwei"}]}, {"cta_url": {"url": "/de/meister/", "is_external": ""}}',
                    ),
                ),
                'required'   => array( 'post_id', 'element_id', 'texts' ),
            ),
            'annotations' => array(
                'title'           => 'Set Element Text',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_widget_text(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                $args['texts']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'post_id' => intval( $args['post_id'] ), 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_set_style', array(
            'description' => 'Update CSS styles on any Elementor element (widget, section, or column). Accepts standard CSS property names which are auto-converted to Elementor format. Examples: {"color": "#ff0000", "font-size": "18px", "background-color": "#000", "padding": "10px 20px", "font-weight": "700", "text-transform": "uppercase", "border-radius": "8px", "margin": "0 0 20px 0"}',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID — widget, section, or column (required)' ),
                    'styles'     => array(
                        'type'        => 'object',
                        'description' => 'CSS or Elementor style properties. CSS names auto-convert: "color" → title_color, "font-size" → typography_font_size, "padding" → _padding, etc.',
                    ),
                ),
                'required'   => array( 'post_id', 'element_id', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Set Element Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_widget_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'post_id' => intval( $args['post_id'] ), 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_bulk_update', array(
            'description' => 'Batch update multiple Elementor elements in one save. Each change can modify text and/or styles. texts accepts the same values as elementor_set_widget_text (strings, whole repeater arrays, link objects, and indexed "tabs:0:tab_title" paths) and is applied atomically per widget. Styles accept CSS names. The result maps each widget_id to "updated", "not_found", or {status:"rejected", errors:[...]}. Example: [{"widget_id": "abc", "texts": {"title": "New"}, "styles": {"color": "#fff", "font-size": "24px"}}]',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'changes' => array(
                        'type'        => 'array',
                        'description' => 'Array of element changes with CSS-friendly styles',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'widget_id' => array( 'type' => 'string', 'description' => 'Element ID' ),
                                'texts'     => array( 'type' => 'object', 'description' => 'Text fields to update' ),
                                'styles'    => array( 'type' => 'object', 'description' => 'CSS style properties' ),
                            ),
                            'required'   => array( 'widget_id' ),
                        ),
                    ),
                ),
                'required'   => array( 'post_id', 'changes' ),
            ),
            'annotations' => array(
                'title'           => 'Bulk Update Elements',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->bulk_update( intval( $args['post_id'] ), $args['changes'] );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'completed', 'post_id' => intval( $args['post_id'] ), 'results' => $result );
        } );

        // ── Structural tools ──

        $this->register_tool( 'elementor_add_widget', array(
            'description' => 'Add a new widget inside an Elementor container (section/column). Widget types: heading, text-editor, button, image, image-box, icon-box, video, spacer, divider, google_maps, icon, counter, progress, testimonial, tabs, accordion, toggle, alert, html, shortcode, menu-anchor, sidebar, call-to-action, price-table.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'      => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'container_id' => array( 'type' => 'string', 'description' => 'Parent element ID (section/column/container) — get from elementor_page_outline (required)' ),
                    'widget_type'  => array( 'type' => 'string', 'description' => 'Widget type, e.g. "heading", "button", "text-editor" (required)' ),
                    'settings'     => array( 'type' => 'object', 'description' => 'Widget settings: {"title": "Hello World", "title_color": "#000"}' ),
                    'position'     => array( 'type' => 'integer', 'description' => 'Insert position (0-based, -1 = append at end, default: -1)' ),
                ),
                'required'   => array( 'post_id', 'container_id', 'widget_type' ),
            ),
            'annotations' => array(
                'title'           => 'Add Widget',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->add_widget(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['container_id'] ),
                sanitize_text_field( $args['widget_type'] ),
                $args['settings'] ?? array(),
                intval( $args['position'] ?? -1 )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_add_section', array(
            'description' => 'Add a new section (with one column) to an Elementor page. Returns section_id and column_id for adding widgets into.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'position' => array( 'type' => 'integer', 'description' => 'Insert position among root sections (0-based, -1 = end, default: -1)' ),
                    'settings' => array( 'type' => 'object', 'description' => 'Section settings (background, padding, etc.)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Add Section',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->add_section(
                intval( $args['post_id'] ),
                intval( $args['position'] ?? -1 ),
                $args['settings'] ?? array()
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_delete_element', array(
            'description' => 'Delete an Elementor element (widget, section, or column) by its ID. Warning: deleting a section removes all its columns and widgets.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID to delete (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Delete Element',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->delete_element( intval( $args['post_id'] ), sanitize_text_field( $args['element_id'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'deleted', 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_move_element', array(
            'description' => 'Move an Elementor element to a new position. Can move within the same parent (reorder) or to a different container.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'       => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id'    => array( 'type' => 'string', 'description' => 'Element ID to move (required)' ),
                    'target_parent' => array( 'type' => 'string', 'description' => 'New parent container ID (empty = root level for sections)' ),
                    'position'      => array( 'type' => 'integer', 'description' => 'New position index (0-based, -1 = end)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Move Element',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->move_element(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                sanitize_text_field( $args['target_parent'] ?? '' ),
                intval( $args['position'] ?? -1 )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'moved', 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_clone_element', array(
            'description' => 'Duplicate an Elementor element (widget, section, column) with all its children. The clone is inserted right after the original with new unique IDs.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID to clone (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Clone Element',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->clone_element( intval( $args['post_id'] ), sanitize_text_field( $args['element_id'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        // ─────────────────── Theme Builder Templates (3.1.42-hotfix4) ───────────────────
        // Manage Elementor Pro Theme Builder templates: header, footer, single-post,
        // archive, single-product, 404, search-results, cart, checkout, etc. Lets the
        // AI scaffold full template hierarchies on a fresh site or backfill missing
        // templates on an existing one (e.g. Tapadum's missing Single Post template).

        $this->register_tool( 'elementor_templates_list', array(
            'description' => 'List Elementor Pro Theme Builder templates (header, footer, single-post, archive, etc). Filter by type to find what is missing or needs replacement.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'type'   => array( 'type' => 'string', 'description' => 'Filter by template_type (header/footer/single-post/single-product/archive/single-404/search-results/cart/checkout/my-account/popup/page/section/kit). Omit for all.' ),
                    'status' => array( 'type' => 'string', 'description' => 'Post status (any/publish/draft). Default: any' ),
                    'limit'  => array( 'type' => 'integer', 'description' => 'Max templates to return (default 100, max 500)', 'minimum' => 1, 'maximum' => 500 ),
                ),
            ),
            'annotations' => array( 'title' => 'List Theme Builder Templates', 'readOnlyHint' => true ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/elementor/templates' );
            if ( isset( $args['type'] ) )   { $request->set_param( 'type',   sanitize_text_field( $args['type'] ) ); }
            if ( isset( $args['status'] ) ) { $request->set_param( 'status', sanitize_text_field( $args['status'] ) ); }
            if ( isset( $args['limit'] ) )  { $request->set_param( 'limit',  intval( $args['limit'] ) ); }
            $data = $elem->rest_templates_list( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_template_create', array(
            'description' => 'Create a new Elementor Pro Theme Builder template (header, footer, single-post, archive, single-404, search-results, cart, checkout, my-account, popup, etc). Optionally copy structure from an existing template (copy_from) or pass an Elementor data tree (data). Returns the new template ID + edit URL. Conditions can be set in the same call (e.g. ["include/general"] for sitewide, ["include/post"] for all posts, ["include/product_archive"] for product category pages).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'         => array( 'type' => 'string', 'description' => 'Template title shown in admin (required)' ),
                    'template_type' => array( 'type' => 'string', 'description' => 'Type slug — header, footer, single-post, single-product, archive, product-archive, single-404, search-results, cart, checkout, my-account, popup, page, section (required)' ),
                    'status'        => array( 'type' => 'string', 'description' => 'Initial post status — draft (default) or publish' ),
                    'conditions'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Display conditions (e.g. ["include/general"], ["include/post"], ["include/product"], ["include/in_singular_singular_post_id_42"])' ),
                    'copy_from'     => array( 'type' => 'integer', 'description' => 'Optional: source template ID to copy structure from (creates a duplicate with new title)' ),
                ),
                'required'   => array( 'title', 'template_type' ),
            ),
            'annotations' => array( 'title' => 'Create Theme Builder Template', 'readOnlyHint' => false, 'destructiveHint' => false ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/template/create' );
            foreach ( array( 'title', 'template_type', 'status' ) as $k ) {
                if ( isset( $args[ $k ] ) ) { $request->set_param( $k, sanitize_text_field( $args[ $k ] ) ); }
            }
            if ( isset( $args['conditions'] ) ) { $request->set_param( 'conditions', (array) $args['conditions'] ); }
            if ( isset( $args['copy_from'] ) )  { $request->set_param( 'copy_from', intval( $args['copy_from'] ) ); }
            $data = $elem->rest_template_create( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_template_clone', array(
            'description' => 'Clone an existing Elementor Pro template under a new title (draft status). Useful for forking a working template (e.g. clone Single Product template as a starting point for Single Post). Returns the new template ID.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'source_id' => array( 'type' => 'integer', 'description' => 'Source template ID to clone (required)' ),
                    'new_title' => array( 'type' => 'string', 'description' => 'Title for the cloned template (required)' ),
                ),
                'required'   => array( 'source_id', 'new_title' ),
            ),
            'annotations' => array( 'title' => 'Clone Theme Builder Template', 'readOnlyHint' => false ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/template/clone' );
            $request->set_param( 'source_id', intval( $args['source_id'] ) );
            $request->set_param( 'new_title', sanitize_text_field( $args['new_title'] ) );
            $data = $elem->rest_template_clone( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_template_conditions_get', array(
            'description' => 'Read display conditions for a Theme Builder template (which posts/pages/types it applies to).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'template_id' => array( 'type' => 'integer', 'description' => 'Template ID (required)' ),
                ),
                'required'   => array( 'template_id' ),
            ),
            'annotations' => array( 'title' => 'Get Template Conditions', 'readOnlyHint' => true ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/elementor/template/conditions' );
            $request->set_param( 'template_id', intval( $args['template_id'] ) );
            $data = $elem->rest_template_conditions_get( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_template_conditions_set', array(
            'description' => 'Set display conditions for a Theme Builder template — controls where it applies. Common patterns: ["include/general"] for sitewide header/footer, ["include/post"] for all posts (Single Post), ["include/page"] for all pages, ["include/product"] for all WooCommerce products, ["include/product_archive"] for shop + category archives, ["include/in_singular_singular_post_id_42"] to target a specific post. Multiple conditions = OR. Use ["exclude/..."] prefix to exclude.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'template_id' => array( 'type' => 'integer', 'description' => 'Template ID (required)' ),
                    'conditions'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of condition strings (required, can be empty array to clear)' ),
                ),
                'required'   => array( 'template_id', 'conditions' ),
            ),
            'annotations' => array( 'title' => 'Set Template Conditions', 'readOnlyHint' => false ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/template/conditions' );
            $request->set_param( 'template_id', intval( $args['template_id'] ) );
            $request->set_param( 'conditions', (array) $args['conditions'] );
            $data = $elem->rest_template_conditions_set( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_template_delete', array(
            'description' => 'Delete a Theme Builder template (skips trash, permanent). Refuses to delete an active header/footer/kit unless force=true. Requires confirm_token="I_KNOW_WHAT_IM_DOING".',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'template_id'   => array( 'type' => 'integer', 'description' => 'Template ID (required)' ),
                    'confirm_token' => array( 'type' => 'string', 'description' => 'Must equal "I_KNOW_WHAT_IM_DOING" (required)' ),
                    'force'         => array( 'type' => 'boolean', 'description' => 'Set true to delete an active header/footer/kit despite the safety guard (default false)' ),
                ),
                'required'   => array( 'template_id', 'confirm_token' ),
            ),
            'annotations' => array( 'title' => 'Delete Template', 'readOnlyHint' => false, 'destructiveHint' => true ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/template/delete' );
            $request->set_param( 'template_id', intval( $args['template_id'] ) );
            $request->set_param( 'confirm_token', sanitize_text_field( $args['confirm_token'] ?? '' ) );
            $request->set_param( 'force', ! empty( $args['force'] ) );
            $data = $elem->rest_template_delete( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        // ── CSS & Responsive tools ──

        $this->register_tool( 'elementor_custom_css', array(
            'description' => 'Inject custom CSS on a specific element or the entire page. Use "selector" as the CSS selector for the element\'s wrapper. Example: "selector .elementor-heading-title { text-shadow: 2px 2px #000; }" — omit element_id to apply page-level CSS.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID (optional — omit for page-level CSS)' ),
                    'css'        => array( 'type' => 'string', 'description' => 'CSS code. Use "selector" for element wrapper. Example: "selector { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }" (required)' ),
                ),
                'required'   => array( 'post_id', 'css' ),
            ),
            'annotations' => array(
                'title'           => 'Custom CSS',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem       = LuwiPress_Elementor::get_instance();
            $element_id = sanitize_text_field( $args['element_id'] ?? '' );
            $css        = $args['css'];

            if ( empty( $element_id ) ) {
                $result = $elem->set_page_css( intval( $args['post_id'] ), $css );
            } else {
                $result = $elem->set_custom_css( intval( $args['post_id'] ), $element_id, $css );
            }

            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'target' => $element_id ?: 'page' );
        } );

        $this->register_tool( 'elementor_responsive_style', array(
            'description' => 'Set device-specific style overrides (mobile or tablet). CSS property names accepted. Example: set font-size to 14px on mobile only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID (required)' ),
                    'device'     => array( 'type' => 'string', 'description' => '"mobile" or "tablet" (required)', 'enum' => array( 'mobile', 'tablet' ) ),
                    'styles'     => array( 'type' => 'object', 'description' => 'CSS styles for that device. Example: {"font-size": "14px", "padding": "5px 10px"}' ),
                ),
                'required'   => array( 'post_id', 'element_id', 'device', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Responsive Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_responsive_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                sanitize_text_field( $args['device'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'element_id' => $args['element_id'], 'device' => $args['device'] );
        } );

        $this->register_tool( 'elementor_global_style', array(
            'description' => 'Apply a style to ALL widgets of a given type on a page. Example: make all headings blue, all buttons larger, etc. CSS property names accepted.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'widget_type' => array( 'type' => 'string', 'description' => 'Widget type: heading, button, text-editor, image, etc. (required)' ),
                    'styles'      => array( 'type' => 'object', 'description' => 'CSS styles to apply. Example: {"color": "#0000ff", "font-weight": "700"}' ),
                ),
                'required'   => array( 'post_id', 'widget_type', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Global Widget Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->apply_global_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['widget_type'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        // ── Sync, Audit, Revision tools ──

        $this->register_tool( 'elementor_sync_styles', array(
            'description' => 'Copy styles from a source Elementor page to its translation pages (WPML auto-detected). Only syncs visual styles — text content is untouched. Creates a backup snapshot before syncing. Omit target_ids to auto-sync to all WPML translations.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'source_id'  => array( 'type' => 'integer', 'description' => 'Source page ID with the correct styles (required)' ),
                    'target_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Target page IDs to sync to (optional — auto-discovers WPML translations if omitted)' ),
                    'include'    => array( 'type' => 'string', 'description' => 'What to sync: "all", "colors", "typography", "spacing", or comma-separated combo (default: "all")' ),
                ),
                'required'   => array( 'source_id' ),
            ),
            'annotations' => array(
                'title'           => 'Sync Styles to Translations',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->sync_styles(
                intval( $args['source_id'] ),
                $args['target_ids'] ?? array(),
                array( 'include' => $args['include'] ?? 'all' )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_audit_spacing', array(
            'description' => 'Audit an Elementor page for spacing/padding issues — detects excessive values, asymmetric padding, inconsistent patterns, and missing mobile overrides. Returns actionable issue list.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to audit (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Audit Spacing',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->audit_spacing( intval( $args['post_id'] ) );
        } );

        $this->register_tool( 'elementor_audit_responsive', array(
            'description' => 'Audit an Elementor page for responsive design issues — large fonts without mobile overrides, oversized padding, narrow columns, fixed line-heights. Returns issues with fix suggestions.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to audit (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Audit Responsive',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->audit_responsive( intval( $args['post_id'] ) );
        } );

        $this->register_tool( 'elementor_auto_fix', array(
            'description' => 'Auto-fix common spacing/responsive issues on an Elementor page. Creates a backup snapshot before fixing. Caps excessive padding/margin, adds mobile overrides for large values. Safe — always backs up first, and you can rollback.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to fix (required)' ),
                    'options' => array(
                        'type'       => 'object',
                        'description' => 'Fix options: fix_excessive (bool), fix_mobile (bool), max_padding (int, default 80), mobile_ratio (float, default 0.5)',
                    ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Auto-Fix Spacing',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->auto_fix_spacing( intval( $args['post_id'] ), $args['options'] ?? array() );
        } );

        $this->register_tool( 'elementor_snapshot', array(
            'description' => 'Create a snapshot/backup of an Elementor page before editing. Max 10 snapshots per page. Use this before any risky changes — you can rollback anytime.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'label'   => array( 'type' => 'string', 'description' => 'Snapshot label/description (optional)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Create Snapshot',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->create_snapshot( intval( $args['post_id'] ), sanitize_text_field( $args['label'] ?? '' ) );
        } );

        $this->register_tool( 'elementor_rollback', array(
            'description' => 'Rollback an Elementor page to a previous snapshot. Creates a backup of current state before rolling back. Omit snapshot_id to rollback to the most recent snapshot.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'snapshot_id' => array( 'type' => 'string', 'description' => 'Snapshot ID to restore (optional — omit for most recent)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Rollback to Snapshot',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->rollback_snapshot( intval( $args['post_id'] ), sanitize_text_field( $args['snapshot_id'] ?? '' ) );
        } );

        $this->register_tool( 'elementor_snapshots', array(
            'description' => 'List all available snapshots for an Elementor page — shows IDs, labels, timestamps.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'List Snapshots',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->list_snapshots( intval( $args['post_id'] ) );
        } );

        $this->register_tool( 'elementor_sync_structure', array(
            'description' => 'Sync the structural layout of an Elementor source page to its WPML/Polylang translation copies. Preserves translated text by default. Auto-snapshots each target before overwriting. If target_ids omitted, syncs to all detected translations.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'source_id'     => array( 'type' => 'integer', 'description' => 'Source (canonical language) page/post ID (required)' ),
                    'target_ids'    => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'integer' ),
                        'description' => 'Specific target translation post IDs. Omit to auto-detect all translations.',
                    ),
                    'preserve_text' => array( 'type' => 'boolean', 'description' => 'Keep translated text intact; only propagate structural changes (default true)' ),
                ),
                'required'   => array( 'source_id' ),
            ),
            'annotations' => array(
                'title'           => 'Sync Structure to Translations',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/sync-structure' );
            $request->set_param( 'source_id', intval( $args['source_id'] ) );
            if ( isset( $args['target_ids'] ) && is_array( $args['target_ids'] ) ) {
                $request->set_param( 'target_ids', array_map( 'intval', $args['target_ids'] ) );
            }
            if ( isset( $args['preserve_text'] ) ) {
                $request->set_param( 'preserve_text', (bool) $args['preserve_text'] );
            }
            $data = $elem->rest_sync_structure( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_kit_info', array(
            'description' => 'Get Elementor Kit metadata — kit post ID, active breakpoints, and whether custom CSS is present. Use before reading/writing Kit CSS.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'          => 'Elementor Kit Info',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function () {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/elementor/kit' );
            $data    = $elem->rest_get_kit_info( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_kit_css_get', array(
            'description' => 'Read the current Kit (global) CSS stored in the luwipress_kit_css option — used for theme-wide styling layers.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'          => 'Read Kit CSS',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function () {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/elementor/global-css' );
            $data    = $elem->rest_get_global_css( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_kit_css_set', array(
            'description' => 'Replace the Kit (global) CSS with a full CSS payload. Always performs a full replace (append:false) to avoid stale-cache cascade regressions — build the complete CSS locally before calling. Automatically flushes Elementor CSS cache. The MCP wrapper forbids append:true by design.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'css' => array( 'type' => 'string', 'description' => 'Full Kit CSS payload (required). Will replace existing Kit CSS entirely.' ),
                ),
                'required'   => array( 'css' ),
            ),
            'annotations' => array(
                'title'           => 'Write Kit CSS (Full Replace)',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/global-css' );
            $request->set_param( 'css', (string) $args['css'] );
            $request->set_param( 'append', false );
            $data = $elem->rest_set_global_css( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        /* ── Inspect tools (1.0.6 — paired with core 3.1.40) ────────────── */

        $this->register_tool( 'elementor_outline_deep', array(
            'description' => 'Deep page outline: walks every section, container, column, and widget and returns a tree with element IDs, types, widget types, text previews, and (optionally) background color/image info. Use this when the lighter elementor_page_outline does not include the element you need to find.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'          => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'include_bg_info'  => array( 'type' => 'boolean', 'description' => 'Include section/container background color + image (default true)' ),
                    'include_settings' => array( 'type' => 'boolean', 'description' => 'Include full Elementor settings on every node (heavy; default false)' ),
                    'preview_chars'    => array( 'type' => 'integer', 'description' => 'Max chars per text preview (20-200, default 80)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Elementor Deep Outline',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '' );
            $request->set_param( 'post_id', intval( $args['post_id'] ) );
            if ( isset( $args['include_bg_info'] ) ) {
                $request->set_param( 'include_bg_info', (bool) $args['include_bg_info'] );
            }
            if ( isset( $args['include_settings'] ) ) {
                $request->set_param( 'include_settings', (bool) $args['include_settings'] );
            }
            if ( isset( $args['preview_chars'] ) ) {
                $request->set_param( 'preview_chars', intval( $args['preview_chars'] ) );
            }
            $data = $elem->rest_outline_deep( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_find_by_id', array(
            'description' => 'Locate an Elementor element by its ID and return its full ancestor chain (root → element), type, widget type, text content, and style summary. Useful when you have an element ID from rendered HTML and need to learn what it actually is in the page tree.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'element_id' => array( 'type' => 'string',  'description' => 'Elementor element ID, hex string (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'          => 'Find Elementor Element by ID',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'GET', '' );
            $request->set_param( 'post_id', intval( $args['post_id'] ) );
            $request->set_param( 'element_id', sanitize_text_field( $args['element_id'] ) );
            $data = $elem->rest_find_by_id( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_find_by_text', array(
            'description' => 'Search every translatable widget text in a page and return matching elements with their ancestor chain. Use this to locate which widget owns a piece of rendered text without DOM scraping. Match modes: contains, exact, starts, ends.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'        => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'text'           => array( 'type' => 'string',  'description' => 'Text to search for (required)' ),
                    'match'          => array( 'type' => 'string',  'description' => 'Match mode: contains | exact | starts | ends (default contains)' ),
                    'case_sensitive' => array( 'type' => 'boolean', 'description' => 'Case-sensitive comparison (default false)' ),
                    'limit'          => array( 'type' => 'integer', 'description' => 'Max matches to return (1-50, default 20)' ),
                ),
                'required'   => array( 'post_id', 'text' ),
            ),
            'annotations' => array(
                'title'          => 'Find Elementor Element by Text',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '' );
            $request->set_param( 'post_id', intval( $args['post_id'] ) );
            $request->set_param( 'text', (string) $args['text'] );
            if ( isset( $args['match'] ) ) {
                $request->set_param( 'match', sanitize_text_field( $args['match'] ) );
            }
            if ( isset( $args['case_sensitive'] ) ) {
                $request->set_param( 'case_sensitive', (bool) $args['case_sensitive'] );
            }
            if ( isset( $args['limit'] ) ) {
                $request->set_param( 'limit', intval( $args['limit'] ) );
            }
            $data = $elem->rest_find_by_text( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'elementor_kit_css_preflight', array(
            'description' => 'Check whether a candidate Kit CSS payload would fit under the option size limit before pushing. Returns size, headroom, paired angle-bracket warning (wp_strip_all_tags risk), and savings candidates (existing layer markers that could be stripped). Always run before elementor_kit_css_set when adding a new layer.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'candidate_css' => array( 'type' => 'string', 'description' => 'Candidate full Kit CSS payload (required)' ),
                ),
                'required'   => array( 'candidate_css' ),
            ),
            'annotations' => array(
                'title'          => 'Kit CSS Preflight Size Check',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $request = new WP_REST_Request( 'POST', '' );
            $request->set_param( 'candidate_css', (string) $args['candidate_css'] );
            $data = $elem->rest_kit_css_preflight( $request );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message() );
            }
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        /* ───── Raw post_meta access (recovery surface, whitelist-enforced) ───── */

        $this->register_tool( 'post_meta_raw_get', array(
            'description' => 'Read a single post_meta value as base64-encoded raw bytes plus diagnostic info (length, sha, slash counts, json_decode probe). Recovery-only. Whitelist: _elementor_data, _elementor_page_settings, _elementor_css, _luwipress_elementor_snapshots. Use when /elementor/* endpoints return parse_error and you need to inspect actual stored bytes.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'meta_key' => array( 'type' => 'string', 'description' => 'Whitelisted meta key (required)' ),
                ),
                'required'   => array( 'post_id', 'meta_key' ),
            ),
            'annotations' => array(
                'title'          => 'Raw post_meta read (recovery)',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $data = $elem->read_post_meta_raw( intval( $args['post_id'] ), (string) $args['meta_key'] );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message(), 'code' => $data->get_error_code() );
            }
            return $data;
        } );

        $this->register_tool( 'post_meta_raw_set', array(
            'description' => 'Write a post_meta value from base64-encoded raw bytes. RECOVERY-ONLY DESTRUCTIVE TOOL — caller MUST pass confirm_token = "I_KNOW_WHAT_IM_DOING". Whitelist enforced (same as post_meta_raw_get). Always backs up the prior value into a parallel meta key before overwriting. For _elementor_data writes, regenerates Elementor CSS + purges page cache automatically.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'       => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'meta_key'      => array( 'type' => 'string', 'description' => 'Whitelisted meta key (required)' ),
                    'value_b64'     => array( 'type' => 'string', 'description' => 'Base64-encoded new bytes (required)' ),
                    'confirm_token' => array( 'type' => 'string', 'description' => 'Must equal "I_KNOW_WHAT_IM_DOING" (required)' ),
                ),
                'required'   => array( 'post_id', 'meta_key', 'value_b64', 'confirm_token' ),
            ),
            'annotations' => array(
                'title'           => 'Raw post_meta write (recovery)',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $data = $elem->write_post_meta_raw(
                intval( $args['post_id'] ),
                (string) $args['meta_key'],
                (string) $args['value_b64'],
                (string) ( $args['confirm_token'] ?? '' )
            );
            if ( is_wp_error( $data ) ) {
                return array( 'error' => $data->get_error_message(), 'code' => $data->get_error_code() );
            }
            return $data;
        } );

        /* ════════════════════════════════════════════════════════════════
         *  FR SUITE (3.4.2): bulk read / css read / image / advanced / schema / diff / find-replace
         * ════════════════════════════════════════════════════════════════ */

        // FR 1 — bulk widget read
        $this->register_tool( 'elementor_get_widgets_bulk', array(
            'description' => 'Read multiple Elementor widgets in a single round-trip. Returns full widget tree entries (settings, text, style summary) for each requested element_id. Use this instead of N separate elementor_get_widget calls when auditing or syncing many widgets on one page.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'     => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of Elementor element IDs to fetch (required)' ),
                ),
                'required'   => array( 'post_id', 'element_ids' ),
            ),
            'annotations' => array(
                'title'          => 'Bulk Read Elementor Widgets',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $ids  = is_array( $args['element_ids'] ?? null ) ? $args['element_ids'] : array();
            $res  = $elem->get_widgets_bulk( intval( $args['post_id'] ), $ids );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );

        // FR 2 — read custom CSS (element or page level)
        $this->register_tool( 'elementor_get_custom_css', array(
            'description' => 'Read element-level OR page-level Elementor custom CSS. Pass element_id for element-level (custom_css setting), omit for page-level (read from _elementor_page_settings.custom_css meta). Use BEFORE elementor_custom_css set to avoid blind overwrite.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID for element-level CSS, OR omit for page-level CSS' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Read Elementor Custom CSS',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $res  = $elem->get_custom_css( intval( $args['post_id'] ), sanitize_text_field( $args['element_id'] ?? '' ) );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );

        // FR 3 — widget schema introspection
        $this->register_tool( 'elementor_widget_schema', array(
            'description' => 'Get the settings schema for an Elementor widget type via reflection. Returns the full control list: field key, type (TEXT, TEXTAREA, MEDIA, REPEATER, SELECT, etc.), label, default value, options (for SELECT), and tab (content/style/advanced). Use BEFORE elementor_add_widget to construct a fully-configured settings object in one call.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'widget_type' => array( 'type' => 'string', 'description' => 'Widget type slug (e.g. "heading", "text-editor", "lwp-section-head", "lwp-timeline")' ),
                ),
                'required'   => array( 'widget_type' ),
            ),
            'annotations' => array(
                'title'          => 'Inspect Widget Schema',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $res  = $elem->get_widget_schema( sanitize_text_field( $args['widget_type'] ) );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );

        // FR 4 — find-replace MCP wrapper (REST already exists)
        $this->register_tool( 'elementor_replace_text', array(
            'description' => 'Bulk text find-replace across one or many Elementor pages. Supports dry_run mode (preview which widgets would change), regex mode, scope=text|styles|both. Mirrors POST /elementor/find-replace REST endpoint. Snapshots are NOT taken automatically — caller should run elementor_snapshot first if rollback may be needed.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Array of post IDs to scan' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type filter (alternative to post_ids — scans all posts of this type)' ),
                    'find'      => array( 'type' => 'string', 'description' => 'Text or regex pattern to find (required)' ),
                    'replace'   => array( 'type' => 'string', 'description' => 'Replacement text (required, can be empty string)' ),
                    'scope'     => array( 'type' => 'string', 'enum' => array( 'text', 'styles', 'both' ), 'description' => 'Where to search — default "text"' ),
                    'is_regex'  => array( 'type' => 'boolean', 'description' => 'If true, find is interpreted as regex pattern' ),
                    'dry_run'   => array( 'type' => 'boolean', 'description' => 'If true, preview without saving' ),
                    'style_key' => array( 'type' => 'string', 'description' => 'When scope=styles, limit to a specific style key' ),
                ),
                'required'   => array( 'find', 'replace' ),
            ),
            'annotations' => array(
                'title'           => 'Find and Replace Text Across Pages',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            if ( ! class_exists( 'WP_REST_Request' ) ) {
                return array( 'error' => 'REST API not loaded' );
            }
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/elementor/find-replace' );
            foreach ( array( 'post_ids', 'post_type', 'find', 'replace', 'scope', 'is_regex', 'dry_run', 'style_key' ) as $k ) {
                if ( array_key_exists( $k, $args ) ) {
                    $request->set_param( $k, $args[ $k ] );
                }
            }
            $elem = LuwiPress_Elementor::get_instance();
            $res  = $elem->rest_find_replace( $request );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res instanceof WP_REST_Response ? $res->get_data() : $res;
        } );

        // FR 5 — set widget image
        $this->register_tool( 'elementor_set_widget_image', array(
            'description' => 'Set image on a MEDIA control of an Elementor widget. Pass attachment_id (preferred — resolves URL automatically) OR raw url. Supports alt, size, link override. image_field defaults to "image" (the standard MEDIA setting key); use "background_image" for section/column backgrounds.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'       => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id'    => array( 'type' => 'string', 'description' => 'Elementor element ID (required)' ),
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'WP attachment ID — recommended (resolves URL via wp_get_attachment_url)' ),
                    'url'           => array( 'type' => 'string', 'description' => 'Raw image URL (alternative to attachment_id)' ),
                    'alt'           => array( 'type' => 'string', 'description' => 'Alt text — also persists to attachment _wp_attachment_image_alt meta' ),
                    'size'          => array( 'type' => 'string', 'description' => 'Image size: thumbnail|medium|large|full|custom' ),
                    'image_field'   => array( 'type' => 'string', 'description' => 'Settings key for the MEDIA control — default "image"' ),
                    'link'          => array( 'type' => 'object', 'description' => '{url, is_external?, nofollow?} — sets widget link override' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Set Widget Image',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $res  = $elem->set_widget_image(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                array(
                    'attachment_id' => intval( $args['attachment_id'] ?? 0 ),
                    'url'           => $args['url'] ?? '',
                    'alt'           => $args['alt'] ?? null,
                    'size'          => $args['size'] ?? '',
                    'image_field'   => $args['image_field'] ?? 'image',
                    'link'          => $args['link'] ?? null,
                )
            );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );

        // FR 6 — set advanced tab fields
        $this->register_tool( 'elementor_set_advanced', array(
            'description' => 'Write Elementor "Advanced" tab fields: css_classes, css_id (HTML id), animation, animation_delay, z_index, attributes (key|value comma-sep), hide_desktop/tablet/mobile. Whitelist-enforced. Friendly aliases: "class"|"classes" -> css_classes, "id" -> _element_id. Use this to attach theme-CSS-hook classes (e.g. lwp-pcard) to existing widgets without rebuilding them.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID (required)' ),
                    'advanced'   => array(
                        'type'        => 'object',
                        'description' => 'Advanced-tab fields: {"css_classes": "lwp-pcard lwp-pcard--featured", "css_id": "my-section", "z_index": 5, "_animation": "fadeIn", "_animation_delay": 200, "hide_mobile": "hidden-mobile"}',
                    ),
                ),
                'required'   => array( 'post_id', 'element_id', 'advanced' ),
            ),
            'annotations' => array(
                'title'           => 'Set Element Advanced Settings',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $advanced = is_array( $args['advanced'] ?? null ) ? $args['advanced'] : array();
            $res = $elem->set_widget_advanced(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                $advanced
            );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );

        // FR 7 — snapshot diff
        $this->register_tool( 'elementor_snapshot_diff', array(
            'description' => 'Compute the diff between two snapshots, OR between one snapshot and the current state (omit snapshot_b). Returns {added, removed, modified} with per-element widget_type + per-field before/after values. Use BEFORE elementor_rollback to preview what reverting would change.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'snapshot_a' => array( 'type' => 'string', 'description' => 'Snapshot ID for the "baseline" / before state (required)' ),
                    'snapshot_b' => array( 'type' => 'string', 'description' => 'Optional snapshot ID for "after" — omit to compare against current state' ),
                ),
                'required'   => array( 'post_id', 'snapshot_a' ),
            ),
            'annotations' => array(
                'title'          => 'Diff Elementor Snapshots',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            $res  = $elem->snapshot_diff(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['snapshot_a'] ),
                sanitize_text_field( $args['snapshot_b'] ?? '' )
            );
            if ( is_wp_error( $res ) ) {
                return array( 'error' => $res->get_error_message() );
            }
            return $res;
        } );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  INTERNAL HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Proxy a POST request to an existing singleton's REST handler.
     *
     * @param string $class_name  Singleton class name.
     * @param string $method      Method to call on the instance.
     * @param array  $body_params POST body parameters.
     * @return mixed
     */
    private function proxy_rest_post( $class_name, $method, $body_params ) {
        if ( ! class_exists( $class_name ) ) {
            throw new Exception( "{$class_name} not loaded" );
        }
        $instance = $class_name::get_instance();
        $request  = new WP_REST_Request( 'POST', '' );
        // Set params on every channel so handlers using get_param(), get_body_params(),
        // or get_json_params() all receive the data. Internal callbacks bypass the
        // HTTP body parser so we cannot rely on Content-Type negotiation.
        $request->set_body_params( $body_params );
        $request->set_query_params( $body_params );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body_params ) );
        foreach ( $body_params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $data = $instance->$method( $request );
        return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
    }

    /**
     * Schedule the generic edited_term cascade to run off the current request.
     *
     * taxonomy_update_term's description-only path used to fire
     * do_action('edited_term') synchronously so cache + sync listeners run. On a
     * multilingual catalog a 3rd-party listener (WPML term-sync, Rank Math
     * sitemap regeneration) can run for minutes — long enough to blow the MCP
     * client's ~4-minute response timeout even though the DB write already
     * succeeded. We defer the cascade to WP-Cron so the tool returns
     * immediately; the listeners still run, just out-of-band. If cron can't be
     * scheduled we fire inline as a last resort (preserves correctness).
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     * @return bool  True if deferred to cron, false if fired inline.
     */
    private function defer_term_edit_cascade( $term_id, $tt_id, $taxonomy ) {
        $args = array( (int) $term_id, (int) $tt_id, (string) $taxonomy );
        if ( wp_next_scheduled( 'luwipress_webmcp_term_edit_cascade', $args ) ) {
            return true; // already queued for this exact term.
        }
        if ( false !== wp_schedule_single_event( time(), 'luwipress_webmcp_term_edit_cascade', $args ) ) {
            return true;
        }
        // Cron unavailable — fire inline so listeners are not silently skipped.
        $this->run_term_edit_cascade( $term_id, $tt_id, $taxonomy );
        return false;
    }

    /**
     * Cron handler: fire the generic edited_term cascade for a deferred
     * description write. This is where any 3rd-party edited_term listener
     * (WPML / Rank Math / …) runs, off the MCP request.
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public function run_term_edit_cascade( $term_id, $tt_id, $taxonomy ) {
        $term_id  = (int) $term_id;
        $tt_id    = (int) $tt_id;
        $taxonomy = (string) $taxonomy;
        if ( $term_id <= 0 || '' === $taxonomy ) {
            return;
        }
        do_action( 'edited_term', $term_id, $tt_id, $taxonomy );
        do_action( "edited_{$taxonomy}", $term_id, $tt_id );
    }

    /**
     * Internal post query (bypasses webhook auth for MCP context).
     */
    private function query_posts( $args ) {
        $query_args = array(
            'post_type'      => sanitize_text_field( $args['post_type'] ?? 'post' ),
            'post_status'    => sanitize_text_field( $args['status'] ?? 'any' ),
            'posts_per_page' => min( intval( $args['per_page'] ?? 10 ), 100 ),
            'paged'          => intval( $args['page'] ?? 1 ),
        );

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        $query = new WP_Query( $query_args );
        $posts = array();

        foreach ( $query->posts as $post ) {
            $posts[] = array(
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => $post->post_status,
                'date'    => $post->post_date,
                'url'     => get_permalink( $post->ID ),
                'excerpt' => wp_trim_words( $post->post_content, 30 ),
            );
        }

        return array(
            'posts'      => $posts,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $query_args['paged'],
        );
    }

    /**
     * Internal post creation (MCP context — already authenticated).
     */
    private function create_post_internal( $args ) {
        if ( empty( $args['title'] ) ) {
            throw new Exception( 'Title is required' );
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['content'] ?? '' ),
            'post_excerpt' => sanitize_text_field( $args['excerpt'] ?? '' ),
            'post_status'  => sanitize_text_field( $args['status'] ?? 'draft' ),
            'post_type'    => sanitize_text_field( $args['post_type'] ?? 'post' ),
            'post_author'  => get_current_user_id() ?: 1,
            'meta_input'   => array(
                '_luwipress_created_via' => 'webmcp',
            ),
        );

        if ( ! empty( $args['categories'] ) ) {
            $post_data['post_category'] = array_map( 'intval', (array) $args['categories'] );
        }

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            throw new Exception( 'Failed to create post: ' . $post_id->get_error_message() );
        }

        if ( ! empty( $args['tags'] ) ) {
            wp_set_post_tags( $post_id, (array) $args['tags'] );
        }

        return array(
            'id'     => $post_id,
            'title'  => get_the_title( $post_id ),
            'url'    => get_permalink( $post_id ),
            'status' => get_post_status( $post_id ),
        );
    }

    /**
     * Internal post update.
     */
    private function update_post_internal( $args ) {
        $post_id = intval( $args['id'] ?? 0 );
        do_action( 'wpml_switch_language', 'all' );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
            throw new Exception( 'Post not found' );
        }

        $update = array( 'ID' => $post_id );
        if ( isset( $args['title'] ) ) {
            $update['post_title'] = sanitize_text_field( $args['title'] );
        }
        if ( isset( $args['content'] ) ) {
            $update['post_content'] = wp_kses_post( $args['content'] );
        }
        if ( isset( $args['excerpt'] ) ) {
            $update['post_excerpt'] = wp_kses_post( $args['excerpt'] );
        }
        if ( isset( $args['status'] ) ) {
            $update['post_status'] = sanitize_text_field( $args['status'] );
        }
        if ( isset( $args['slug'] ) ) {
            $update['post_name'] = sanitize_title( $args['slug'] );
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
            throw new Exception( 'Failed to update: ' . $result->get_error_message() );
        }

        $terms_updated = array();
        if ( array_key_exists( 'tags', $args ) && is_array( $args['tags'] ) ) {
            $tags = array_map( 'sanitize_text_field', $args['tags'] );
            wp_set_post_tags( $post_id, $tags, false );
            $terms_updated['tags'] = $tags;
        }
        if ( array_key_exists( 'categories', $args ) && is_array( $args['categories'] ) ) {
            $cats = array_map( 'intval', $args['categories'] );
            wp_set_post_categories( $post_id, $cats, false );
            $terms_updated['categories'] = $cats;
        }

        update_post_meta( $post_id, '_luwipress_last_updated_via', 'webmcp' );
        do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );

        $response = array(
            'id'      => $post_id,
            'updated' => true,
            'url'     => get_permalink( $post_id ),
        );
        if ( ! empty( $terms_updated ) ) {
            $response['terms_updated'] = $terms_updated;
        }
        return $response;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  JSON-RPC HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    private function jsonrpc_result( $id, $result ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    private function jsonrpc_error( $id, $code, $message, $data = null ) {
        $error = array(
            'code'    => $code,
            'message' => $message,
        );
        if ( null !== $data ) {
            $error['data'] = $data;
        }
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        );
    }

    /* ───────────────────── Admin / User Tools ──────────────────────── */

    private function register_admin_tools() {

        $this->register_tool( 'admin_list_users', array(
            'description' => 'List WordPress users with filtering by role, search, and pagination',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'role'     => array( 'type' => 'string', 'description' => 'Filter by role (administrator, editor, author, subscriber, customer, shop_manager)' ),
                    'search'   => array( 'type' => 'string', 'description' => 'Search by name or email' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 100, default 20)' ),
                    'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Users', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query_args = array(
                'number' => min( absint( $args['per_page'] ?? 20 ), 100 ),
                'paged'  => max( 1, absint( $args['page'] ?? 1 ) ),
            );
            if ( ! empty( $args['role'] ) ) {
                $query_args['role'] = sanitize_text_field( $args['role'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query_args['search'] = '*' . sanitize_text_field( $args['search'] ) . '*';
            }
            $query = new WP_User_Query( $query_args );
            $users = array();
            foreach ( $query->get_results() as $user ) {
                $users[] = array(
                    'id'           => $user->ID,
                    'username'     => $user->user_login,
                    'email'        => $user->user_email,
                    'display_name' => $user->display_name,
                    'roles'        => $user->roles,
                    'registered'   => $user->user_registered,
                );
            }
            return array( 'users' => $users, 'total' => $query->get_total(), 'page' => $query_args['paged'] );
        } );

        $this->register_tool( 'admin_get_user', array(
            'description' => 'Get a single user profile with full details',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id' => array( 'type' => 'integer', 'description' => 'WordPress user ID (required)' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Get User', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user = get_userdata( intval( $args['user_id'] ) );
            if ( ! $user ) {
                throw new Exception( 'User not found' );
            }
            $data = array(
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'first_name'   => $user->first_name,
                'last_name'    => $user->last_name,
                'roles'        => $user->roles,
                'registered'   => $user->user_registered,
                'url'          => $user->user_url,
            );
            if ( function_exists( 'wc_get_customer_order_count' ) ) {
                $data['order_count'] = wc_get_customer_order_count( $user->ID );
                $data['total_spent'] = wc_get_customer_total_spent( $user->ID );
            }
            return $data;
        } );

        $this->register_tool( 'admin_create_user', array(
            'description' => 'Create a new WordPress user with role assignment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'username'     => array( 'type' => 'string', 'description' => 'Username (required)' ),
                    'email'        => array( 'type' => 'string', 'description' => 'Email address (required)' ),
                    'password'     => array( 'type' => 'string', 'description' => 'Password (auto-generated if omitted)' ),
                    'display_name' => array( 'type' => 'string', 'description' => 'Display name' ),
                    'first_name'   => array( 'type' => 'string', 'description' => 'First name' ),
                    'last_name'    => array( 'type' => 'string', 'description' => 'Last name' ),
                    'role'         => array( 'type' => 'string', 'description' => 'Role (default: subscriber)' ),
                ),
                'required' => array( 'username', 'email' ),
            ),
            'annotations' => array( 'title' => 'Create User', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user_data = array(
                'user_login'   => sanitize_user( $args['username'] ),
                'user_email'   => sanitize_email( $args['email'] ),
                'user_pass'    => $args['password'] ?? wp_generate_password( 16 ),
                'display_name' => sanitize_text_field( $args['display_name'] ?? $args['username'] ),
                'first_name'   => sanitize_text_field( $args['first_name'] ?? '' ),
                'last_name'    => sanitize_text_field( $args['last_name'] ?? '' ),
                'role'         => sanitize_text_field( $args['role'] ?? 'subscriber' ),
            );
            $user_id = wp_insert_user( $user_data );
            if ( is_wp_error( $user_id ) ) {
                throw new Exception( $user_id->get_error_message() );
            }
            return array( 'id' => $user_id, 'username' => $user_data['user_login'], 'email' => $user_data['user_email'], 'role' => $user_data['role'] );
        } );

        $this->register_tool( 'admin_update_user', array(
            'description' => 'Update user profile fields (display name, email, role, meta)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id'      => array( 'type' => 'integer', 'description' => 'User ID (required)' ),
                    'email'        => array( 'type' => 'string', 'description' => 'New email' ),
                    'display_name' => array( 'type' => 'string', 'description' => 'New display name' ),
                    'first_name'   => array( 'type' => 'string', 'description' => 'New first name' ),
                    'last_name'    => array( 'type' => 'string', 'description' => 'New last name' ),
                    'role'         => array( 'type' => 'string', 'description' => 'New role' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Update User', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user_id = intval( $args['user_id'] );
            if ( ! get_userdata( $user_id ) ) {
                throw new Exception( 'User not found' );
            }
            $user_data = array( 'ID' => $user_id );
            if ( isset( $args['email'] ) ) $user_data['user_email'] = sanitize_email( $args['email'] );
            if ( isset( $args['display_name'] ) ) $user_data['display_name'] = sanitize_text_field( $args['display_name'] );
            if ( isset( $args['first_name'] ) ) $user_data['first_name'] = sanitize_text_field( $args['first_name'] );
            if ( isset( $args['last_name'] ) ) $user_data['last_name'] = sanitize_text_field( $args['last_name'] );
            if ( isset( $args['role'] ) ) $user_data['role'] = sanitize_text_field( $args['role'] );
            $result = wp_update_user( $user_data );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'id' => $user_id, 'updated' => true );
        } );

        $this->register_tool( 'admin_delete_user', array(
            'description' => 'Delete a WordPress user with optional content reassignment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id'     => array( 'type' => 'integer', 'description' => 'User ID to delete (required)' ),
                    'reassign_to' => array( 'type' => 'integer', 'description' => 'Reassign content to this user ID (recommended)' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Delete User', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $user_id = intval( $args['user_id'] );
            if ( ! get_userdata( $user_id ) ) {
                throw new Exception( 'User not found' );
            }
            $reassign = isset( $args['reassign_to'] ) ? intval( $args['reassign_to'] ) : null;
            $result = wp_delete_user( $user_id, $reassign );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete user' );
            }
            return array( 'id' => $user_id, 'deleted' => true, 'reassigned_to' => $reassign );
        } );

        $this->register_tool( 'admin_list_roles', array(
            'description' => 'List all registered WordPress roles with their capabilities',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Roles', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $wp_roles = wp_roles();
            $roles = array();
            foreach ( $wp_roles->roles as $slug => $role ) {
                $count = count_users();
                $roles[] = array(
                    'slug'         => $slug,
                    'name'         => $role['name'],
                    'capabilities' => array_keys( array_filter( $role['capabilities'] ) ),
                    'user_count'   => $count['avail_roles'][ $slug ] ?? 0,
                );
            }
            return array( 'roles' => $roles );
        } );
    }

    /**
     * KSES sanitizer for taxonomy term descriptions. Default behaviour matches
     * wp_kses_post (prose HTML). When $allow_iframe is true, extends the
     * post-content allowlist with <iframe> for embeds (YouTube / Maps): on*
     * event handlers are always stripped by kses and the default allowed-
     * protocols list blocks javascript:/data: src, so this cannot introduce
     * script execution. Backs taxonomy_update_term's allow_iframe flag.
     *
     * @param string $html
     * @param bool   $allow_iframe
     * @return string
     */
    private function kses_term_description( $html, $allow_iframe ) {
        if ( ! $allow_iframe ) {
            return wp_kses_post( (string) $html );
        }
        $allowed           = wp_kses_allowed_html( 'post' );
        $allowed['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'title'           => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'loading'         => true,
            'referrerpolicy'  => true,
            'sandbox'         => true,
            'style'           => true,
            'class'           => true,
        );
        return wp_kses( (string) $html, $allowed );
    }

    /**
     * `pre_term_description` filter callback swapped in (instead of
     * wp_filter_post_kses) when taxonomy_update_term runs with allow_iframe, so
     * the <iframe> survives wp_update_term's re-sanitize pass. Must be public —
     * WP core invokes it as a filter from outside the class scope.
     *
     * @param string $data
     * @return string
     */
    public function filter_term_description_iframe( $data ) {
        return $this->kses_term_description( $data, true );
    }

    /* ───────────────────── Taxonomy Tools ──────────────────────────── */

    private function register_taxonomy_tools() {

        $this->register_tool( 'taxonomy_list_taxonomies', array(
            'description' => 'List all registered taxonomies (built-in and custom) with their configuration',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Taxonomies', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
            $list = array();
            foreach ( $taxonomies as $tax ) {
                $count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
                $list[] = array(
                    'name'         => $tax->name,
                    'label'        => $tax->label,
                    'hierarchical' => $tax->hierarchical,
                    'post_types'   => (array) $tax->object_type,
                    'term_count'   => is_wp_error( $count ) ? 0 : absint( $count ),
                );
            }
            return array( 'taxonomies' => $list );
        } );

        $this->register_tool( 'taxonomy_list_terms', array(
            'description' => 'List terms for any taxonomy (category, post_tag, product_cat, product_tag, pa_*) with hierarchy',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomy slug (required, e.g. category, product_cat, pa_color)' ),
                    'parent'    => array( 'type' => 'integer', 'description' => 'Filter by parent term ID (0 for top-level)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search term name' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 200, default 50)' ),
                    'hide_empty' => array( 'type' => 'boolean', 'description' => 'Hide terms with no posts (default false)' ),
                ),
                'required' => array( 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'List Terms', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $query = array(
                'taxonomy'   => $taxonomy,
                'number'     => min( absint( $args['per_page'] ?? 50 ), 200 ),
                'hide_empty' => ! empty( $args['hide_empty'] ),
            );
            if ( isset( $args['parent'] ) ) {
                $query['parent'] = absint( $args['parent'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query['search'] = sanitize_text_field( $args['search'] );
            }
            $terms = get_terms( $query );
            if ( is_wp_error( $terms ) ) {
                throw new Exception( $terms->get_error_message() );
            }
            $list = array();
            foreach ( $terms as $term ) {
                $list[] = array(
                    'id'       => $term->term_id,
                    'name'     => $term->name,
                    'slug'     => $term->slug,
                    'parent'   => $term->parent,
                    'count'    => $term->count,
                    'description' => $term->description,
                );
            }
            return array( 'taxonomy' => $taxonomy, 'terms' => $list, 'total' => count( $list ) );
        } );

        $this->register_tool( 'taxonomy_assign_terms', array(
            'description' => 'Assign terms from a taxonomy to a post. Works for any taxonomy (post_tag, category, product_tag, product_cat, pa_*). Terms can be IDs, slugs, or names — non-existent term names are auto-created for non-hierarchical taxonomies. Default mode replaces existing terms; pass append=true to add without removing.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (required). Examples: post_tag, category, product_tag, product_cat, pa_color' ),
                    'terms'    => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => array( 'string', 'integer' ) ),
                        'description' => 'Term IDs (integers) or term names/slugs (strings). Pass [] to remove all terms in this taxonomy.',
                    ),
                    'append'   => array( 'type' => 'boolean', 'description' => 'If true, add terms without removing existing ones. Default false (replace).' ),
                ),
                'required' => array( 'post_id', 'taxonomy', 'terms' ),
            ),
            'annotations' => array( 'title' => 'Assign Terms', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id  = intval( $args['post_id'] ?? 0 );
            $taxonomy = sanitize_text_field( $args['taxonomy'] ?? '' );
            $append   = ! empty( $args['append'] );

            if ( ! $post_id || ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            if ( ! isset( $args['terms'] ) || ! is_array( $args['terms'] ) ) {
                throw new Exception( 'terms array is required (use [] to clear)' );
            }

            $terms_input = $args['terms'];
            $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

            // Hierarchical taxonomies (category, product_cat, pa_*): require IDs.
            // Non-hierarchical (post_tag, product_tag): names are fine and auto-created.
            if ( $is_hierarchical ) {
                $terms_input = array_map( 'intval', $terms_input );
                $terms_input = array_filter( $terms_input );
            } else {
                $terms_input = array_map( function ( $t ) {
                    return is_numeric( $t ) ? intval( $t ) : sanitize_text_field( $t );
                }, $terms_input );
            }

            $result = wp_set_object_terms( $post_id, $terms_input, $taxonomy, $append );
            if ( is_wp_error( $result ) ) {
                throw new Exception( 'Failed to assign terms: ' . $result->get_error_message() );
            }

            // Return the resolved term IDs for confirmation.
            $assigned = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
            $out = array();
            if ( ! is_wp_error( $assigned ) ) {
                foreach ( $assigned as $term ) {
                    $out[] = array(
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }

            update_post_meta( $post_id, '_luwipress_last_updated_via', 'webmcp' );

            return array(
                'post_id'  => $post_id,
                'taxonomy' => $taxonomy,
                'mode'     => $append ? 'append' : 'replace',
                'terms'    => $out,
                'count'    => count( $out ),
            );
        } );

        $this->register_tool( 'taxonomy_create_term', array(
            'description' => 'Create a new term in any taxonomy with parent, description, and slug',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'name'        => array( 'type' => 'string', 'description' => 'Term name (required)' ),
                    'slug'        => array( 'type' => 'string', 'description' => 'Term slug (auto-generated if omitted)' ),
                    'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID (for hierarchical taxonomies)' ),
                    'description' => array( 'type' => 'string', 'description' => 'Term description' ),
                ),
                'required' => array( 'taxonomy', 'name' ),
            ),
            'annotations' => array( 'title' => 'Create Term', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $term_args = array();
            if ( isset( $args['slug'] ) ) $term_args['slug'] = sanitize_title( $args['slug'] );
            if ( isset( $args['parent'] ) ) $term_args['parent'] = absint( $args['parent'] );
            if ( isset( $args['description'] ) ) $term_args['description'] = sanitize_textarea_field( $args['description'] );

            $result = wp_insert_term( sanitize_text_field( $args['name'] ), $taxonomy, $term_args );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'term_id' => $result['term_id'], 'term_taxonomy_id' => $result['term_taxonomy_id'], 'taxonomy' => $taxonomy, 'name' => $args['name'] );
        } );

        $this->register_tool( 'taxonomy_update_term', array(
            'description' => 'Update an existing term name, slug, description, or parent. Description preserves prose HTML (a / h2-h6 / ul / li / em); set allow_iframe=true to also permit <iframe> embeds (YouTube / Maps).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'      => array( 'type' => 'integer', 'description' => 'Term ID (required)' ),
                    'taxonomy'     => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'name'         => array( 'type' => 'string', 'description' => 'New name' ),
                    'slug'         => array( 'type' => 'string', 'description' => 'New slug' ),
                    'parent'       => array( 'type' => 'integer', 'description' => 'New parent term ID' ),
                    'description'  => array( 'type' => 'string', 'description' => 'New description (prose HTML preserved)' ),
                    'allow_iframe' => array( 'type' => 'boolean', 'description' => 'When true, permit <iframe> embeds (e.g. YouTube / Maps) in description. javascript:/data: src + on* event handlers are always stripped. Admin-gated via the MCP token. Default false.' ),
                ),
                'required' => array( 'term_id', 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'Update Term', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            global $wpdb;
            $term_id  = absint( $args['term_id'] );
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                throw new Exception( "Term {$term_id} not found in taxonomy '{$taxonomy}'" );
            }

            $has_name   = isset( $args['name'] );
            $has_slug   = isset( $args['slug'] );
            $has_parent = isset( $args['parent'] );
            $has_desc   = isset( $args['description'] );
            // Opt-in <iframe> embeds in the description. Admin-gated by the MCP
            // token (the token path skips wp_set_current_user, so manage_options
            // can't be checked here — the token IS the admin gate).
            $allow_iframe = ! empty( $args['allow_iframe'] );

            // wp_update_term() always re-runs wp_unique_term_slug() — which is not
            // WPML-language-aware. On translation terms this falsely flags the
            // sibling-language slug as a collision. For description-only updates
            // we bypass the whole pipeline with a direct term_taxonomy write.
            //
            // Description sanitizer is `wp_kses_post` — symmetric with the
            // luwipress-gold render side (1.7.34+) which echoes the description
            // through `wp_kses_post()`. Allows prose HTML (anchors, headings,
            // lists, emphasis) so operators can author internal-link / AEO
            // structure into category descriptions; still blocks script /
            // iframe / inline event handlers. The previous
            // `sanitize_textarea_field()` was a plain-text helper that
            // stripped every tag, then `wpautop` on the render side
            // re-wrapped paragraphs but anchors / h2 / h3 / ul / li never
            // came back. Matches the WP-core "post content" sanitizer that
            // the WP admin term-edit screen applies for users with
            // `unfiltered_html`.
            if ( $has_desc && ! $has_name && ! $has_slug && ! $has_parent ) {
                $updated = $wpdb->update(
                    $wpdb->term_taxonomy,
                    array( 'description' => $this->kses_term_description( $args['description'], $allow_iframe ) ),
                    array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                if ( false === $updated ) {
                    throw new Exception( 'Direct description update failed: ' . $wpdb->last_error );
                }
                clean_term_cache( $term_id, $taxonomy );
                // Defer the generic edited_term cascade off this request. A
                // description-only write changes no slug/structure, but some
                // 3rd-party listeners (WPML term-sync, Rank Math sitemap regen)
                // hook edited_term and can run for MINUTES on a multilingual
                // catalog — long enough to blow the MCP client's ~4-minute
                // response timeout even though the DB write already succeeded.
                // Scheduling the cascade on cron returns the tool immediately;
                // the listeners still run, just out-of-band.
                $deferred = $this->defer_term_edit_cascade( $term_id, (int) $term->term_taxonomy_id, $taxonomy );
                return array(
                    'term_id'  => $term_id,
                    'taxonomy' => $taxonomy,
                    'updated'  => true,
                    'method'   => 'direct_description_write',
                    'sync'     => $deferred ? 'deferred' : 'inline',
                );
            }

            // Full update path: name/slug/parent involved. Set WPML language
            // context to the term's own language so wp_unique_term_slug scopes
            // its uniqueness check to siblings in the same language.
            $restore_lang = null;
            global $sitepress;
            if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
                $term_lang = apply_filters( 'wpml_element_language_code', null, array(
                    'element_id'   => $term_id,
                    'element_type' => $taxonomy,
                ) );
                if ( ! empty( $term_lang ) ) {
                    $restore_lang = apply_filters( 'wpml_current_language', null );
                    $sitepress->switch_lang( $term_lang );
                }
            }

            $term_args = array();
            if ( $has_name )   $term_args['name']        = sanitize_text_field( $args['name'] );
            if ( $has_slug )   $term_args['slug']        = sanitize_title( $args['slug'] );
            if ( $has_parent ) $term_args['parent']      = absint( $args['parent'] );
            // wp_kses_post matches the render-side filter the luwipress-gold
            // theme uses (1.7.34+). See the direct path above for the full
            // rationale.
            if ( $has_desc )   $term_args['description'] = $this->kses_term_description( $args['description'], $allow_iframe );

            // wp_update_term → sanitize_term → sanitize_term_field('description', …, 'db')
            // applies the `pre_term_description` filter. WP core attaches
            // `wp_filter_kses` (restrictive: allows <a>, blocks <h2>/<h3>/<ul>/<li>)
            // unless the current user has `unfiltered_html`. Our token-auth path
            // intentionally does NOT call wp_set_current_user (see
            // LuwiPress_Permission::is_token_authenticated docblock) so that
            // capability is false in this context. Swap to a permissive kses
            // filter for the duration of this call so the input we pre-sanitized
            // survives wp_update_term's re-sanitize intact, then restore. When
            // allow_iframe is set we swap in our iframe-aware filter (otherwise
            // wp_filter_post_kses would re-strip the <iframe> we just kept).
            $kses_swap = $has_desc;
            $kses_cb   = $allow_iframe ? array( $this, 'filter_term_description_iframe' ) : 'wp_filter_post_kses';
            if ( $kses_swap ) {
                remove_filter( 'pre_term_description', 'wp_filter_kses' );
                add_filter( 'pre_term_description', $kses_cb );
            }

            $result = wp_update_term( $term_id, $taxonomy, $term_args );

            if ( $kses_swap ) {
                remove_filter( 'pre_term_description', $kses_cb );
                add_filter( 'pre_term_description', 'wp_filter_kses' );
            }

            if ( $restore_lang !== null && is_object( $sitepress ) ) {
                $sitepress->switch_lang( $restore_lang );
            }

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array(
                'term_id'  => $term_id,
                'taxonomy' => $taxonomy,
                'updated'  => true,
                'method'   => 'wp_update_term',
            );
        } );

        $this->register_tool( 'taxonomy_delete_term', array(
            'description' => 'Delete a term from any taxonomy',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID to delete (required)' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                ),
                'required' => array( 'term_id', 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'Delete Term', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $term_id  = absint( $args['term_id'] );
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $result = wp_delete_term( $term_id, $taxonomy );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            if ( $result === false ) {
                throw new Exception( 'Term not found or is default term' );
            }
            return array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'deleted' => true );
        } );
    }

    /* ───────────────────── WooCommerce Tools ───────────────────────── */

    private function register_woo_tools() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // --- Product write (WC CRUD): price / stock / featured image / status. ---
        // content_create_post can make a product post but cannot set price meta
        // (protected). This closes that gap through the proper WC CRUD so _price,
        // _regular_price and the product lookup table stay consistent.
        $this->register_tool( 'product_update', array(
            'description' => 'Update a WooCommerce product through the WC CRUD (recalculates _price + the product lookup table — the correct, safe way, unlike raw meta writes). Send id + any of: regular_price, sale_price (empty string clears it), sku, stock_status (instock|outofstock|onbackorder), manage_stock, stock_quantity, featured (bool), featured_image_id (WP attachment ID → product thumbnail), gallery_image_ids (array of attachment IDs), status (publish|draft|pending|private). Setting regular_price is what makes a product purchasable so Add to cart works. Returns the resulting price/stock/image_id + is_purchasable.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'                => array( 'type' => 'integer', 'description' => 'Product ID (required).' ),
                    'regular_price'     => array( 'type' => 'string', 'description' => 'Regular price, e.g. "120" or "120.00". Required for the product to be purchasable.' ),
                    'sale_price'        => array( 'type' => 'string', 'description' => 'Sale price; pass "" to clear it.' ),
                    'sku'               => array( 'type' => 'string' ),
                    'stock_status'      => array( 'type' => 'string', 'description' => 'instock | outofstock | onbackorder' ),
                    'manage_stock'      => array( 'type' => 'boolean' ),
                    'stock_quantity'    => array( 'type' => 'integer' ),
                    'featured'          => array( 'type' => 'boolean', 'description' => 'Mark as a WooCommerce featured product.' ),
                    'featured_image_id' => array( 'type' => 'integer', 'description' => 'WP media attachment ID to use as the product image (thumbnail).' ),
                    'gallery_image_ids' => array( 'type' => 'array', 'description' => 'Array of media attachment IDs for the product gallery.' ),
                    'status'            => array( 'type' => 'string', 'description' => 'publish | draft | pending | private' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Update Product', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! function_exists( 'wc_get_product' ) ) {
                return array( 'error' => 'WooCommerce not active' );
            }
            $id      = absint( $args['id'] ?? 0 );
            $product = $id ? wc_get_product( $id ) : null;
            if ( ! $product ) {
                return array( 'error' => 'Product not found', 'id' => $id );
            }
            if ( array_key_exists( 'regular_price', $args ) ) {
                $product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
            }
            if ( array_key_exists( 'sale_price', $args ) ) {
                $product->set_sale_price( '' === $args['sale_price'] ? '' : wc_format_decimal( $args['sale_price'] ) );
            }
            if ( isset( $args['sku'] ) ) {
                try {
                    $product->set_sku( sanitize_text_field( $args['sku'] ) );
                } catch ( \Exception $e ) {
                    return array( 'error' => 'SKU: ' . $e->getMessage() );
                }
            }
            if ( isset( $args['stock_status'] ) ) {
                $product->set_stock_status( sanitize_text_field( $args['stock_status'] ) );
            }
            if ( isset( $args['manage_stock'] ) ) {
                $product->set_manage_stock( (bool) $args['manage_stock'] );
            }
            if ( isset( $args['stock_quantity'] ) ) {
                $product->set_stock_quantity( intval( $args['stock_quantity'] ) );
            }
            if ( isset( $args['featured'] ) ) {
                $product->set_featured( (bool) $args['featured'] );
            }
            if ( isset( $args['featured_image_id'] ) ) {
                $product->set_image_id( absint( $args['featured_image_id'] ) );
            }
            if ( isset( $args['gallery_image_ids'] ) && is_array( $args['gallery_image_ids'] ) ) {
                $product->set_gallery_image_ids( array_map( 'absint', $args['gallery_image_ids'] ) );
            }
            if ( isset( $args['status'] ) ) {
                $product->set_status( sanitize_text_field( $args['status'] ) );
            }
            $product->save();
            $fresh = wc_get_product( $id );
            return array(
                'id'            => $id,
                'updated'       => true,
                'name'          => $fresh->get_name(),
                'regular_price' => $fresh->get_regular_price(),
                'sale_price'    => $fresh->get_sale_price(),
                'price'         => $fresh->get_price(),
                'price_html'    => wp_strip_all_tags( $fresh->get_price_html() ),
                'sku'           => $fresh->get_sku(),
                'stock_status'  => $fresh->get_stock_status(),
                'image_id'      => $fresh->get_image_id(),
                'status'        => $fresh->get_status(),
                'purchasable'   => $fresh->is_purchasable(),
                'permalink'     => get_permalink( $id ),
            );
        } );

        // --- Store visibility: WooCommerce "Coming soon" vs Live (WC 9.1+). ---
        $this->register_tool( 'store_visibility_set', array(
            'description' => "Toggle the WooCommerce site-visibility 'Coming soon' feature. mode 'live' makes the store public (customers can browse + buy); mode 'coming_soon' shows the coming-soon page to logged-out visitors. Optional store_pages_only restricts coming-soon to shop/product/cart/checkout only (rest of the site stays public). Returns the resulting option values.",
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'mode'             => array( 'type' => 'string', 'description' => "'live' | 'coming_soon' (required)." ),
                    'store_pages_only' => array( 'type' => 'boolean', 'description' => 'When coming_soon: restrict it to store pages only (default false = whole site).' ),
                ),
                'required'   => array( 'mode' ),
            ),
            'annotations' => array( 'title' => 'Set Store Visibility', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $mode = sanitize_text_field( $args['mode'] ?? '' );
            if ( ! in_array( $mode, array( 'live', 'coming_soon' ), true ) ) {
                return array( 'error' => "mode must be 'live' or 'coming_soon'" );
            }
            update_option( 'woocommerce_coming_soon', 'coming_soon' === $mode ? 'yes' : 'no' );
            if ( isset( $args['store_pages_only'] ) ) {
                update_option( 'woocommerce_store_pages_only', $args['store_pages_only'] ? 'yes' : 'no' );
            }
            return array(
                'mode'             => $mode,
                'coming_soon'      => get_option( 'woocommerce_coming_soon' ),
                'store_pages_only' => get_option( 'woocommerce_store_pages_only' ),
            );
        } );

        // --- Manual / offline payment (Mobile Money, bank transfer). ---
        $this->register_tool( 'wc_manual_payment_set', array(
            'description' => "Enable a manual/offline payment method at checkout — for Mobile Money or bank transfer where the customer pays off-site and the store confirms manually. Uses WooCommerce's built-in Direct bank transfer (BACS) gateway, retitled. Provide title (e.g. 'Mobile Money'), instructions (shown on the order + thank-you page, e.g. 'Pay via Mobile Money to +250 785 156 670 and use your order number as reference'), optional description. enabled=false turns it off. Orders placed with it stay On-hold until you confirm payment.",
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'        => array( 'type' => 'string', 'description' => "Method title at checkout, e.g. 'Mobile Money'." ),
                    'instructions' => array( 'type' => 'string', 'description' => 'Payment instructions shown to the customer on the order + thank-you page.' ),
                    'description'  => array( 'type' => 'string', 'description' => 'Short description under the title at checkout (optional).' ),
                    'enabled'      => array( 'type' => 'boolean', 'description' => 'Enable (default true) or disable the method.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Set Manual Payment', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return array( 'error' => 'WooCommerce not active' );
            }
            $enabled  = array_key_exists( 'enabled', $args ) ? (bool) $args['enabled'] : true;
            $settings = get_option( 'woocommerce_bacs_settings', array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }
            $settings['enabled'] = $enabled ? 'yes' : 'no';
            if ( isset( $args['title'] ) ) {
                $settings['title'] = sanitize_text_field( $args['title'] );
            }
            if ( isset( $args['description'] ) ) {
                $settings['description'] = wp_kses_post( $args['description'] );
            }
            if ( isset( $args['instructions'] ) ) {
                $settings['instructions'] = wp_kses_post( $args['instructions'] );
            }
            update_option( 'woocommerce_bacs_settings', $settings );
            return array(
                'gateway'      => 'bacs',
                'enabled'      => $enabled,
                'title'        => $settings['title'] ?? '',
                'instructions' => $settings['instructions'] ?? '',
            );
        } );

        $this->register_tool( 'woo_list_orders', array(
            'description' => 'List WooCommerce orders with filtering by status, date, customer; supports orderby/order for sorting and date_after+date_before for bounded ranges.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'status'      => array( 'type' => 'string', 'description' => 'Order status: processing, completed, on-hold, cancelled, refunded, pending, failed, any (default: any)' ),
                    'customer_id' => array( 'type' => 'integer', 'description' => 'Filter by customer user ID' ),
                    'per_page'    => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                    'page'        => array( 'type' => 'integer', 'description' => 'Page number' ),
                    'date_after'  => array( 'type' => 'string', 'description' => 'Orders on or after this date (YYYY-MM-DD)' ),
                    'date_before' => array( 'type' => 'string', 'description' => 'Orders on or before this date (YYYY-MM-DD)' ),
                    'orderby'     => array( 'type' => 'string', 'description' => 'Sort field: date (default), modified, id, title, menu_order, rand' ),
                    'order'       => array( 'type' => 'string', 'description' => 'Sort direction: ASC or DESC (default DESC)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Orders', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = array(
                'limit'  => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'page'   => max( 1, absint( $args['page'] ?? 1 ) ),
                'return' => 'objects',
            );
            $status = $args['status'] ?? 'any';
            if ( $status !== 'any' ) {
                $query['status'] = sanitize_text_field( $status );
            }
            if ( ! empty( $args['customer_id'] ) ) {
                $query['customer_id'] = absint( $args['customer_id'] );
            }
            // Date filter — combine after + before into wc_get_orders range syntax.
            $after  = ! empty( $args['date_after'] )  ? sanitize_text_field( $args['date_after'] )  : '';
            $before = ! empty( $args['date_before'] ) ? sanitize_text_field( $args['date_before'] ) : '';
            if ( $after && $before ) {
                $query['date_created'] = $after . '...' . $before;
            } elseif ( $after ) {
                $query['date_created'] = '>=' . $after;
            } elseif ( $before ) {
                $query['date_created'] = '<=' . $before;
            }
            // Sorting — whitelist orderby field, ASC/DESC default DESC.
            $allowed_orderby = array( 'date', 'modified', 'id', 'title', 'menu_order', 'rand' );
            $orderby = isset( $args['orderby'] ) ? sanitize_text_field( $args['orderby'] ) : 'date';
            $query['orderby'] = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date';
            $order = isset( $args['order'] ) ? strtoupper( sanitize_text_field( $args['order'] ) ) : 'DESC';
            $query['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
            $orders = wc_get_orders( $query );
            $list = array();
            foreach ( $orders as $order ) {
                $list[] = array(
                    'id'              => $order->get_id(),
                    'number'          => $order->get_order_number(),
                    'status'          => $order->get_status(),
                    'total'           => $order->get_total(),
                    'currency'        => $order->get_currency(),
                    'customer_id'     => $order->get_customer_id(),
                    'billing_email'   => $order->get_billing_email(),
                    'billing_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'item_count'      => $order->get_item_count(),
                    'payment_method'  => $order->get_payment_method_title(),
                    'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                );
            }
            return array( 'orders' => $list, 'count' => count( $list ) );
        } );

        $this->register_tool( 'woo_get_order', array(
            'description' => 'Get full order details: items, billing, shipping, notes, totals',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'order_id' => array( 'type' => 'integer', 'description' => 'Order ID (required)' ),
                ),
                'required' => array( 'order_id' ),
            ),
            'annotations' => array( 'title' => 'Get Order', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $order = wc_get_order( intval( $args['order_id'] ) );
            if ( ! $order ) {
                throw new Exception( 'Order not found' );
            }
            $items = array();
            foreach ( $order->get_items() as $item ) {
                $items[] = array(
                    'name'       => $item->get_name(),
                    'product_id' => $item->get_product_id(),
                    'quantity'   => $item->get_quantity(),
                    'subtotal'   => $item->get_subtotal(),
                    'total'      => $item->get_total(),
                    'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
                );
            }
            $notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 10 ) );
            $note_list = array();
            foreach ( $notes as $note ) {
                $note_list[] = array( 'content' => $note->content, 'date' => $note->date_created->format( 'Y-m-d H:i:s' ), 'author' => $note->added_by );
            }
            return array(
                'id'              => $order->get_id(),
                'number'          => $order->get_order_number(),
                'status'          => $order->get_status(),
                'total'           => $order->get_total(),
                'subtotal'        => $order->get_subtotal(),
                'tax_total'       => $order->get_total_tax(),
                'shipping_total'  => $order->get_shipping_total(),
                'discount_total'  => $order->get_discount_total(),
                'currency'        => $order->get_currency(),
                'payment_method'  => $order->get_payment_method_title(),
                'customer_id'     => $order->get_customer_id(),
                'billing'         => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'address_1'  => $order->get_billing_address_1(),
                    'city'       => $order->get_billing_city(),
                    'country'    => $order->get_billing_country(),
                ),
                'shipping'        => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'address_1'  => $order->get_shipping_address_1(),
                    'city'       => $order->get_shipping_city(),
                    'country'    => $order->get_shipping_country(),
                ),
                'items'           => $items,
                'notes'           => $note_list,
                'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                'date_modified'   => $order->get_date_modified() ? $order->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
            );
        } );

        $this->register_tool( 'woo_update_order_status', array(
            'description' => 'Change order status (processing, completed, on-hold, cancelled)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'order_id' => array( 'type' => 'integer', 'description' => 'Order ID (required)' ),
                    'status'   => array( 'type' => 'string', 'description' => 'New status: processing, completed, on-hold, cancelled, refunded (required)' ),
                    'note'     => array( 'type' => 'string', 'description' => 'Optional order note explaining the change' ),
                ),
                'required' => array( 'order_id', 'status' ),
            ),
            'annotations' => array( 'title' => 'Update Order Status', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $order = wc_get_order( intval( $args['order_id'] ) );
            if ( ! $order ) {
                throw new Exception( 'Order not found' );
            }
            $old_status = $order->get_status();
            $new_status = sanitize_text_field( $args['status'] );
            $note       = sanitize_text_field( $args['note'] ?? 'Status updated via LuwiPress MCP' );
            $order->update_status( $new_status, $note );
            return array( 'order_id' => $order->get_id(), 'old_status' => $old_status, 'new_status' => $new_status );
        } );

        $this->register_tool( 'woo_list_coupons', array(
            'description' => 'List WooCommerce coupons with filtering',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'   => array( 'type' => 'string', 'description' => 'Search coupon code' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Coupons', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = new WP_Query( array(
                'post_type'      => 'shop_coupon',
                'posts_per_page' => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'post_status'    => 'publish',
                's'              => sanitize_text_field( $args['search'] ?? '' ),
            ) );
            $coupons = array();
            foreach ( $query->posts as $post ) {
                $coupon = new WC_Coupon( $post->ID );
                $coupons[] = array(
                    'id'               => $coupon->get_id(),
                    'code'             => $coupon->get_code(),
                    'discount_type'    => $coupon->get_discount_type(),
                    'amount'           => $coupon->get_amount(),
                    'usage_count'      => $coupon->get_usage_count(),
                    'usage_limit'      => $coupon->get_usage_limit(),
                    'expiry_date'      => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d' ) : null,
                    'minimum_amount'   => $coupon->get_minimum_amount(),
                    'free_shipping'    => $coupon->get_free_shipping(),
                );
            }
            return array( 'coupons' => $coupons, 'total' => $query->found_posts );
        } );

        $this->register_tool( 'woo_create_coupon', array(
            'description' => 'Create a WooCommerce coupon (percentage, fixed, free shipping)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'code'           => array( 'type' => 'string', 'description' => 'Coupon code (required)' ),
                    'discount_type'  => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ), 'description' => 'Discount type (default: percent)' ),
                    'amount'         => array( 'type' => 'number', 'description' => 'Discount amount (required)' ),
                    'usage_limit'    => array( 'type' => 'integer', 'description' => 'Total usage limit' ),
                    'expiry_date'    => array( 'type' => 'string', 'description' => 'Expiry date YYYY-MM-DD' ),
                    'minimum_amount' => array( 'type' => 'number', 'description' => 'Minimum order amount' ),
                    'free_shipping'  => array( 'type' => 'boolean', 'description' => 'Enable free shipping' ),
                    'individual_use' => array( 'type' => 'boolean', 'description' => 'Cannot be combined with other coupons' ),
                ),
                'required' => array( 'code', 'amount' ),
            ),
            'annotations' => array( 'title' => 'Create Coupon', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $coupon = new WC_Coupon();
            $coupon->set_code( sanitize_text_field( $args['code'] ) );
            $coupon->set_discount_type( sanitize_text_field( $args['discount_type'] ?? 'percent' ) );
            $coupon->set_amount( floatval( $args['amount'] ) );
            if ( isset( $args['usage_limit'] ) ) $coupon->set_usage_limit( absint( $args['usage_limit'] ) );
            if ( isset( $args['expiry_date'] ) ) $coupon->set_date_expires( sanitize_text_field( $args['expiry_date'] ) );
            if ( isset( $args['minimum_amount'] ) ) $coupon->set_minimum_amount( floatval( $args['minimum_amount'] ) );
            if ( isset( $args['free_shipping'] ) ) $coupon->set_free_shipping( (bool) $args['free_shipping'] );
            if ( isset( $args['individual_use'] ) ) $coupon->set_individual_use( (bool) $args['individual_use'] );
            $coupon->save();
            return array( 'id' => $coupon->get_id(), 'code' => $coupon->get_code(), 'discount_type' => $coupon->get_discount_type(), 'amount' => $coupon->get_amount() );
        } );

        $this->register_tool( 'woo_get_shipping_zones', array(
            'description' => 'List shipping zones with their methods and rates',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Shipping Zones', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $zones_raw = WC_Shipping_Zones::get_zones();
            $zones = array();
            foreach ( $zones_raw as $zone_data ) {
                $zone = new WC_Shipping_Zone( $zone_data['id'] );
                $methods = array();
                foreach ( $zone->get_shipping_methods() as $method ) {
                    $methods[] = array(
                        'id'      => $method->id,
                        'title'   => $method->get_title(),
                        'enabled' => $method->is_enabled(),
                        'cost'    => $method->get_option( 'cost', '' ),
                    );
                }
                $zones[] = array(
                    'id'        => $zone->get_id(),
                    'name'      => $zone->get_zone_name(),
                    'locations' => count( $zone_data['zone_locations'] ?? array() ),
                    'methods'   => $methods,
                );
            }
            return array( 'zones' => $zones );
        } );

        $this->register_tool( 'woo_get_tax_rates', array(
            'description' => 'List tax rates by class',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'class' => array( 'type' => 'string', 'description' => 'Tax class slug (default: standard)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Tax Rates', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            global $wpdb;
            $class = sanitize_text_field( $args['class'] ?? '' );
            $rates = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_order",
                $class
            ) );
            $list = array();
            foreach ( $rates as $rate ) {
                $list[] = array(
                    'id'       => absint( $rate->tax_rate_id ),
                    'country'  => $rate->tax_rate_country,
                    'state'    => $rate->tax_rate_state,
                    'rate'     => $rate->tax_rate,
                    'name'     => $rate->tax_rate_name,
                    'priority' => $rate->tax_rate_priority,
                    'shipping' => $rate->tax_rate_shipping,
                );
            }
            return array( 'tax_class' => $class ?: 'standard', 'rates' => $list );
        } );
    }

    /* ───────────────────── Media Library Tools ─────────────────────── */

    private function register_media_tools() {

        // Upload a file to the Media Library from base64 data — for local files
        // that are not reachable by a public URL (media_upload_from_url can't
        // reach them). Lets an AI push images/video directly into WP media.
        $this->register_tool( 'media_upload_base64', array(
            'description' => 'Upload an image or MP4 to the Media Library from base64-encoded data — use this when the file lives on the operator machine and is NOT reachable by a public URL (otherwise prefer media_upload_from_url). Provide filename (with extension) + data_base64 (raw base64, no "data:...;base64," prefix). Max 12 MB. Returns the new attachment id + url; wire it into a product via product_update featured_image_id or an Elementor widget.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'filename'    => array( 'type' => 'string', 'description' => 'File name with extension, e.g. "no6-green.jpg" (required).' ),
                    'data_base64' => array( 'type' => 'string', 'description' => 'Base64-encoded file contents, with NO "data:...;base64," prefix (required).' ),
                    'title'       => array( 'type' => 'string', 'description' => 'Attachment title (optional; defaults to the filename).' ),
                    'alt_text'    => array( 'type' => 'string', 'description' => 'Image alt text (optional).' ),
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Attach to this post ID (optional).' ),
                ),
                'required'   => array( 'filename', 'data_base64' ),
            ),
            'annotations' => array( 'title' => 'Upload Media (base64)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $filename = sanitize_file_name( (string) ( $args['filename'] ?? '' ) );
            $b64      = (string) ( $args['data_base64'] ?? '' );
            if ( '' === $filename || '' === $b64 ) {
                return array( 'error' => 'filename and data_base64 are required' );
            }
            // Tolerate an accidental data-URI prefix.
            if ( 0 === strpos( $b64, 'data:' ) && false !== strpos( $b64, ',' ) ) {
                $b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
            }
            $data = base64_decode( $b64, true );
            if ( false === $data || '' === $data ) {
                return array( 'error' => 'invalid base64 data' );
            }
            if ( strlen( $data ) > 12 * 1024 * 1024 ) {
                return array( 'error' => 'file too large (>12 MB)' );
            }
            $ft = wp_check_filetype( $filename );
            if ( empty( $ft['type'] ) || ( 0 !== strpos( $ft['type'], 'image/' ) && 'video/mp4' !== $ft['type'] ) ) {
                return array( 'error' => 'unsupported file type: ' . ( $ft['type'] ?: '(unknown)' ) . ' — allowed: images, video/mp4' );
            }
            if ( ! function_exists( 'wp_upload_bits' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $up = wp_upload_bits( $filename, null, $data );
            if ( ! empty( $up['error'] ) ) {
                return array( 'error' => $up['error'] );
            }
            $file = $up['file'];
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment = array(
                'post_mime_type' => $ft['type'],
                'post_title'     => sanitize_text_field( $args['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            );
            $parent    = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
            $attach_id = wp_insert_attachment( $attachment, $file, $parent );
            if ( is_wp_error( $attach_id ) ) {
                return array( 'error' => $attach_id->get_error_message() );
            }
            $meta = wp_generate_attachment_metadata( $attach_id, $file );
            wp_update_attachment_metadata( $attach_id, $meta );
            if ( ! empty( $args['alt_text'] ) ) {
                update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
            }
            return array(
                'id'       => $attach_id,
                'url'      => wp_get_attachment_url( $attach_id ),
                'mime'     => $ft['type'],
                'filename' => basename( $file ),
                'bytes'    => strlen( $data ),
            );
        } );

        $this->register_tool( 'media_list', array(
            'description' => 'List media items with filtering by type, date, search',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'mime_type' => array( 'type' => 'string', 'description' => 'Filter by MIME type (image, video, application/pdf)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search by title' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                    'page'      => array( 'type' => 'integer', 'description' => 'Page number' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Media', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query_args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'paged'          => max( 1, absint( $args['page'] ?? 1 ) ),
            );
            if ( ! empty( $args['mime_type'] ) ) {
                $query_args['post_mime_type'] = sanitize_text_field( $args['mime_type'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query_args['s'] = sanitize_text_field( $args['search'] );
            }
            $query = new WP_Query( $query_args );
            $items = array();
            foreach ( $query->posts as $post ) {
                $meta = wp_get_attachment_metadata( $post->ID );
                $items[] = array(
                    'id'         => $post->ID,
                    'title'      => $post->post_title,
                    'url'        => wp_get_attachment_url( $post->ID ),
                    'mime_type'  => $post->post_mime_type,
                    'alt_text'   => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                    'width'      => $meta['width'] ?? null,
                    'height'     => $meta['height'] ?? null,
                    'filesize'   => $meta['filesize'] ?? null,
                    'date'       => $post->post_date,
                );
            }
            return array( 'items' => $items, 'total' => $query->found_posts, 'page' => $query_args['paged'] );
        } );

        $this->register_tool( 'media_get', array(
            'description' => 'Get media item details (URL, dimensions, alt text, file size, usage)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Get Media', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id   = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $meta = wp_get_attachment_metadata( $id );
            $sizes = array();
            if ( ! empty( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size => $data ) {
                    $sizes[ $size ] = array( 'width' => $data['width'], 'height' => $data['height'], 'file' => $data['file'] );
                }
            }
            return array(
                'id'        => $id,
                'title'     => $post->post_title,
                'caption'   => $post->post_excerpt,
                'description' => $post->post_content,
                'alt_text'  => get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'url'       => wp_get_attachment_url( $id ),
                'mime_type' => $post->post_mime_type,
                'width'     => $meta['width'] ?? null,
                'height'    => $meta['height'] ?? null,
                'filesize'  => $meta['filesize'] ?? null,
                'file'      => $meta['file'] ?? null,
                'sizes'     => $sizes,
                'parent_id' => $post->post_parent,
                'date'      => $post->post_date,
            );
        } );

        $this->register_tool( 'media_upload_from_url', array(
            'description' => 'Download and import a media file from an external URL into the WordPress media library',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'url'      => array( 'type' => 'string', 'description' => 'External file URL (required)' ),
                    'title'    => array( 'type' => 'string', 'description' => 'Attachment title' ),
                    'alt_text' => array( 'type' => 'string', 'description' => 'Image alt text' ),
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Attach to this post ID' ),
                ),
                'required' => array( 'url' ),
            ),
            'annotations' => array( 'title' => 'Upload from URL', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $url     = esc_url_raw( $args['url'] );
            $post_id = absint( $args['post_id'] ?? 0 );

            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                throw new Exception( 'Download failed: ' . $tmp->get_error_message() );
            }
            $file_array = array(
                'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
                'tmp_name' => $tmp,
            );
            $attachment_id = media_handle_sideload( $file_array, $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                @unlink( $tmp );
                throw new Exception( 'Upload failed: ' . $attachment_id->get_error_message() );
            }
            if ( ! empty( $args['title'] ) ) {
                wp_update_post( array( 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $args['title'] ) ) );
            }
            if ( ! empty( $args['alt_text'] ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
            }
            return array( 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ) );
        } );

        $this->register_tool( 'media_update', array(
            'description' => 'Update media alt text, title, caption, or description',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                    'title'         => array( 'type' => 'string', 'description' => 'New title' ),
                    'alt_text'      => array( 'type' => 'string', 'description' => 'New alt text' ),
                    'caption'       => array( 'type' => 'string', 'description' => 'New caption' ),
                    'description'   => array( 'type' => 'string', 'description' => 'New description' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Update Media', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $update = array( 'ID' => $id );
            if ( isset( $args['title'] ) ) $update['post_title'] = sanitize_text_field( $args['title'] );
            if ( isset( $args['caption'] ) ) $update['post_excerpt'] = sanitize_text_field( $args['caption'] );
            if ( isset( $args['description'] ) ) $update['post_content'] = wp_kses_post( $args['description'] );
            wp_update_post( $update );
            if ( isset( $args['alt_text'] ) ) {
                update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
            }
            return array( 'id' => $id, 'updated' => true );
        } );

        $this->register_tool( 'media_delete', array(
            'description' => 'Delete a media item (with option to force-delete the file)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                    'force'         => array( 'type' => 'boolean', 'description' => 'Permanently delete file (default: true)' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Delete Media', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $force = $args['force'] ?? true;
            $result = wp_delete_attachment( $id, $force );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete attachment' );
            }
            return array( 'id' => $id, 'deleted' => true );
        } );
    }

    /* ───────────────────── Comment Tools ───────────────────────────── */

    private function register_comment_tools() {

        $this->register_tool( 'comment_list', array(
            'description' => 'List comments with filtering by post, status, type',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Filter by post ID' ),
                    'status'   => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ), 'description' => 'Comment status (default: all)' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 100, default 20)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Comments', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = array(
                'number' => min( absint( $args['per_page'] ?? 20 ), 100 ),
                'status' => sanitize_text_field( $args['status'] ?? 'all' ),
            );
            if ( ! empty( $args['post_id'] ) ) {
                $query['post_id'] = absint( $args['post_id'] );
            }
            $comments = get_comments( $query );
            $list = array();
            foreach ( $comments as $c ) {
                $list[] = array(
                    'id'           => $c->comment_ID,
                    'post_id'      => $c->comment_post_ID,
                    'author'       => $c->comment_author,
                    'author_email' => $c->comment_author_email,
                    'content'      => wp_strip_all_tags( $c->comment_content ),
                    'status'       => $c->comment_approved,
                    'date'         => $c->comment_date,
                    'parent'       => $c->comment_parent,
                    'type'         => $c->comment_type ?: 'comment',
                );
            }
            return array( 'comments' => $list, 'count' => count( $list ) );
        } );

        $this->register_tool( 'comment_moderate', array(
            'description' => 'Approve, unapprove, spam, or trash a comment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Comment ID (required)' ),
                    'status'     => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash' ), 'description' => 'New status (required)' ),
                ),
                'required' => array( 'comment_id', 'status' ),
            ),
            'annotations' => array( 'title' => 'Moderate Comment', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $comment_id = absint( $args['comment_id'] );
            if ( ! get_comment( $comment_id ) ) {
                throw new Exception( 'Comment not found' );
            }
            $result = wp_set_comment_status( $comment_id, sanitize_text_field( $args['status'] ) );
            if ( ! $result ) {
                throw new Exception( 'Failed to update comment status' );
            }
            return array( 'comment_id' => $comment_id, 'status' => $args['status'] );
        } );

        $this->register_tool( 'comment_reply', array(
            'description' => 'Reply to a comment (creates a child comment from admin)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Parent comment ID (required)' ),
                    'content'    => array( 'type' => 'string', 'description' => 'Reply content (required)' ),
                ),
                'required' => array( 'comment_id', 'content' ),
            ),
            'annotations' => array( 'title' => 'Reply to Comment', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $parent = get_comment( absint( $args['comment_id'] ) );
            if ( ! $parent ) {
                throw new Exception( 'Parent comment not found' );
            }
            $current_user = wp_get_current_user();
            $reply_id = wp_insert_comment( array(
                'comment_post_ID'  => $parent->comment_post_ID,
                'comment_parent'   => $parent->comment_ID,
                'comment_content'  => wp_kses_post( $args['content'] ),
                'comment_author'   => $current_user->display_name ?: 'Admin',
                'comment_author_email' => $current_user->user_email ?: get_option( 'admin_email' ),
                'user_id'          => $current_user->ID ?: 0,
                'comment_approved' => 1,
            ) );
            if ( ! $reply_id ) {
                throw new Exception( 'Failed to create reply' );
            }
            return array( 'reply_id' => $reply_id, 'parent_id' => $parent->comment_ID, 'post_id' => $parent->comment_post_ID );
        } );

        $this->register_tool( 'comment_delete', array(
            'description' => 'Permanently delete a comment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Comment ID (required)' ),
                ),
                'required' => array( 'comment_id' ),
            ),
            'annotations' => array( 'title' => 'Delete Comment', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $comment_id = absint( $args['comment_id'] );
            if ( ! get_comment( $comment_id ) ) {
                throw new Exception( 'Comment not found' );
            }
            $result = wp_delete_comment( $comment_id, true );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete comment' );
            }
            return array( 'comment_id' => $comment_id, 'deleted' => true );
        } );
    }

    /* ───────────────────── Settings Tools ──────────────────────────── */

    private function register_settings_tools() {

        // Whitelist of safe option keys
        $safe_wp_keys = array(
            'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format',
            'posts_per_page', 'permalink_structure', 'default_comment_status',
            'default_ping_status', 'show_on_front', 'page_on_front', 'page_for_posts',
            'blog_public', 'WPLANG',
        );

        $this->register_tool( 'settings_get', array(
            'description' => 'Read WordPress settings (general, reading, writing, discussion, permalinks)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Specific option keys to read (omit for all safe settings)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Get Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_wp_keys ) {
            $keys = ! empty( $args['keys'] ) ? array_intersect( $args['keys'], $safe_wp_keys ) : $safe_wp_keys;
            $settings = array();
            foreach ( $keys as $key ) {
                $settings[ $key ] = get_option( $key );
            }
            return array( 'settings' => $settings );
        } );

        $this->register_tool( 'settings_update', array(
            'description' => 'Update a whitelisted WordPress setting (cannot change siteurl, home, or admin_email for security)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'   => array( 'type' => 'string', 'description' => 'Option key (required)' ),
                    'value' => array( 'type' => 'string', 'description' => 'New value (required)' ),
                ),
                'required' => array( 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Update Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_wp_keys ) {
            $key = sanitize_text_field( $args['key'] );
            if ( ! in_array( $key, $safe_wp_keys, true ) ) {
                throw new Exception( "Setting '{$key}' is not in the allowed whitelist" );
            }
            $old = get_option( $key );
            update_option( $key, sanitize_text_field( $args['value'] ) );
            return array( 'key' => $key, 'old_value' => $old, 'new_value' => $args['value'], 'updated' => true );
        } );

        $this->register_tool( 'settings_get_woo', array(
            'description' => 'Read WooCommerce settings (store address, currency, tax, shipping defaults)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Get WooCommerce Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return array( 'error' => 'WooCommerce not active' );
            }
            return array(
                'currency'          => get_woocommerce_currency(),
                'currency_symbol'   => get_woocommerce_currency_symbol(),
                'store_address'     => get_option( 'woocommerce_store_address' ),
                'store_city'        => get_option( 'woocommerce_store_city' ),
                'store_country'     => get_option( 'woocommerce_default_country' ),
                'store_postcode'    => get_option( 'woocommerce_store_postcode' ),
                'calc_taxes'        => get_option( 'woocommerce_calc_taxes' ),
                'prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
                'tax_display_shop'  => get_option( 'woocommerce_tax_display_shop' ),
                'weight_unit'       => get_option( 'woocommerce_weight_unit' ),
                'dimension_unit'    => get_option( 'woocommerce_dimension_unit' ),
                'enable_reviews'    => get_option( 'woocommerce_enable_reviews' ),
                'manage_stock'      => get_option( 'woocommerce_manage_stock' ),
                'registration'      => get_option( 'woocommerce_enable_myaccount_registration' ),
            );
        } );

        $safe_woo_keys = array(
            'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode',
            'woocommerce_default_country', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
            'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_enable_reviews',
        );

        $this->register_tool( 'settings_update_woo', array(
            'description' => 'Update a whitelisted WooCommerce setting',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'   => array( 'type' => 'string', 'description' => 'WooCommerce option key (required)' ),
                    'value' => array( 'type' => 'string', 'description' => 'New value (required)' ),
                ),
                'required' => array( 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Update WooCommerce Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_woo_keys ) {
            $key = sanitize_text_field( $args['key'] );
            if ( ! in_array( $key, $safe_woo_keys, true ) ) {
                throw new Exception( "WooCommerce setting '{$key}' is not in the allowed whitelist" );
            }
            $old = get_option( $key );
            update_option( $key, sanitize_text_field( $args['value'] ) );
            return array( 'key' => $key, 'old_value' => $old, 'new_value' => $args['value'], 'updated' => true );
        } );

        /* LuwiPress module settings — thin proxies to /<module>/settings REST endpoints */

        $module_settings = array(
            'enrich'      => array( 'class' => 'LuwiPress_AI_Content',         'route' => '/enrich/settings',      'title' => 'Enrichment Settings' ),
            'translation' => array( 'class' => 'LuwiPress_Translation',        'route' => '/translation/settings', 'title' => 'Translation Settings' ),
            'chat'        => array( 'class' => 'LuwiPress_Customer_Chat',      'route' => '/chat/settings',        'title' => 'Customer Chat Settings' ),
            'schedule'    => array( 'class' => 'LuwiPress_Content_Scheduler',  'route' => '/schedule/settings',    'title' => 'Content Scheduler Settings' ),
        );

        foreach ( $module_settings as $module => $cfg ) {
            $class = $cfg['class'];
            $route = $cfg['route'];
            $title = $cfg['title'];

            $this->register_tool( "{$module}_settings_get", array(
                'description' => "Read the current {$module} module settings. Returns the same keys the POST endpoint accepts.",
                'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
                'annotations' => array( 'title' => "Get {$title}", 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function () use ( $class, $route ) {
                if ( ! class_exists( $class ) ) {
                    return array( 'error' => "{$class} not active" );
                }
                $instance = call_user_func( array( $class, 'get_instance' ) );
                $request  = new WP_REST_Request( 'GET', "/luwipress/v1{$route}" );
                $data     = $instance->handle_get_settings( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            } );

            $this->register_tool( "{$module}_settings_set", array(
                'description' => "Update the {$module} module settings. Partial update — only keys present in the request body are written; other keys stay unchanged.",
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'settings' => array( 'type' => 'object', 'description' => 'Settings object; keys match the module shape.' ),
                    ),
                    'required' => array( 'settings' ),
                ),
                'annotations' => array( 'title' => "Update {$title}", 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) use ( $class, $route ) {
                if ( ! class_exists( $class ) ) {
                    return array( 'error' => "{$class} not active" );
                }
                $instance = call_user_func( array( $class, 'get_instance' ) );
                $request  = new WP_REST_Request( 'POST', "/luwipress/v1{$route}" );
                $request->set_body_params( (array) ( $args['settings'] ?? array() ) );
                $data = $instance->handle_set_settings( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            } );
        }

        // ─── WPML SEO Settings (Vendor-MCP-3, 3.4.0+) ──────────────────────
        //
        // WPML stores everything in one giant `icl_sitepress_settings` array
        // with cross-linked sub-options (`icl_lang_neg_url_type`, etc.) that
        // are SET via icl_sitepress_settings but READ via separate option
        // keys. Migration / config-audit scripts need both read AND write,
        // which neither core settings_get/set nor WPML's admin UI exposes
        // via API. These two tools surface the SEO-relevant subset.

        $wpml_safe_keys = array(
            'default_language'      => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'default_language' ),                              'type' => 'string' ),
            'language_url_type'     => array( 'option' => 'icl_lang_neg_url_type',                                                                       'type' => 'integer' ),
            'use_directory'         => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'language_negotiation_type' ),                      'type' => 'integer' ),
            'hide_default_lang'     => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'urls', 'hide_language_switchers_for_default_lang' ), 'type' => 'integer' ),
            'taxonomies_sync'       => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'taxonomies_sync_option' ),                         'type' => 'array' ),
            'sync_post_taxonomies'  => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'sync_taxonomies' ),                                'type' => 'integer' ),
            'sync_post_date'        => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'sync_post_date' ),                                 'type' => 'integer' ),
            'sync_page_template'    => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'sync_page_template' ),                             'type' => 'integer' ),
            'wpml_seo_settings'     => array( 'option' => 'wpml_seo_settings',                                                                            'type' => 'array' ),
            'hreflang_show'         => array( 'parent' => 'icl_sitepress_settings', 'path' => array( 'seo', 'head_langs' ),                              'type' => 'integer' ),
        );

        $this->register_tool( 'wpml_seo_settings_get', array(
            'description' => 'Read WPML language + SEO settings — default_language, URL pattern (1=subdir/2=subdomain/3=different domain), hreflang head emission, taxonomy sync flags, language negotiation type. Vendor-MCP-3.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array( 'title' => 'WPML SEO Settings (Read)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () use ( $wpml_safe_keys ) {
            if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
                return array( 'error' => 'WPML not active' );
            }
            $sitepress_opt = get_option( 'icl_sitepress_settings', array() );
            $out = array();
            foreach ( $wpml_safe_keys as $alias => $cfg ) {
                if ( isset( $cfg['option'] ) ) {
                    $out[ $alias ] = get_option( $cfg['option'] );
                    continue;
                }
                // walk path inside sitepress array
                $cursor = $sitepress_opt;
                foreach ( $cfg['path'] as $segment ) {
                    if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
                        $cursor = $cursor[ $segment ];
                    } else {
                        $cursor = null;
                        break;
                    }
                }
                $out[ $alias ] = $cursor;
            }
            // Active languages — separate API.
            if ( function_exists( 'icl_get_languages' ) ) {
                $langs = icl_get_languages( 'skip_missing=N' );
                $out['active_languages'] = is_array( $langs ) ? array_keys( $langs ) : array();
            }
            return array( 'wpml_active' => true, 'settings' => $out );
        } );

        $this->register_tool( 'wpml_seo_settings_set', array(
            'description' => 'Update WPML language + SEO settings (partial update). Pass `settings` object with any subset of: default_language, language_url_type, use_directory, hide_default_lang, sync_post_taxonomies, sync_post_date, sync_page_template, wpml_seo_settings, hreflang_show. Vendor-MCP-3.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'settings' => array( 'type' => 'object', 'description' => 'Partial update — only keys present are written.' ),
                ),
                'required' => array( 'settings' ),
            ),
            'annotations' => array( 'title' => 'WPML SEO Settings (Write)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $wpml_safe_keys ) {
            if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
                return array( 'error' => 'WPML not active' );
            }
            $settings = isset( $args['settings'] ) ? (array) $args['settings'] : array();
            if ( empty( $settings ) ) {
                return array( 'error' => 'No settings provided' );
            }
            $sitepress_opt = get_option( 'icl_sitepress_settings', array() );
            $changed       = array();
            foreach ( $settings as $alias => $value ) {
                if ( ! isset( $wpml_safe_keys[ $alias ] ) ) {
                    continue; // silently skip unknown aliases
                }
                $cfg = $wpml_safe_keys[ $alias ];

                // Type coercion based on declared type.
                if ( 'integer' === $cfg['type'] ) {
                    $value = intval( $value );
                } elseif ( 'string' === $cfg['type'] ) {
                    $value = sanitize_text_field( $value );
                } elseif ( 'array' === $cfg['type'] && ! is_array( $value ) ) {
                    continue;
                }

                if ( isset( $cfg['option'] ) ) {
                    update_option( $cfg['option'], $value );
                    $changed[ $alias ] = $value;
                    continue;
                }
                // walk path and set; create intermediate arrays as needed
                $ref =& $sitepress_opt;
                foreach ( $cfg['path'] as $i => $segment ) {
                    if ( $i === count( $cfg['path'] ) - 1 ) {
                        $ref[ $segment ] = $value;
                    } else {
                        if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
                            $ref[ $segment ] = array();
                        }
                        $ref =& $ref[ $segment ];
                    }
                }
                unset( $ref );
                $changed[ $alias ] = $value;
            }
            // Persist sitepress block (only if any path-based change touched it).
            update_option( 'icl_sitepress_settings', $sitepress_opt );
            return array( 'updated' => true, 'changed' => $changed );
        } );
    }

    /* ───────────────────── Plugin & Theme Tools ────────────────────── */

    /**
     * Plugins whose deactivation/deletion would kill this MCP endpoint itself —
     * the agent would lose all control with no way to re-enable over MCP. Hard-
     * blocked in plugins_deactivate / plugins_delete; the operator can still do it
     * from WP Admin → Plugins if they truly intend to.
     */
    private function is_mcp_lifeline_plugin( $plugin ) {
        $lifeline = array(
            'luwipress/luwipress.php',                // core: the auth + permission layer the MCP rides on
            'luwipress-webmcp/luwipress-webmcp.php',  // the MCP server itself
        );
        return in_array( $plugin, $lifeline, true );
    }

    private function register_plugin_theme_tools() {

        $this->register_tool( 'plugins_list', array(
            'description' => 'List all installed plugins with status, version, update availability',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Plugins', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all     = get_plugins();
            $updates = get_site_transient( 'update_plugins' );
            $list = array();
            foreach ( $all as $file => $data ) {
                $list[] = array(
                    'file'           => $file,
                    'name'           => $data['Name'],
                    'version'        => $data['Version'],
                    'active'         => is_plugin_active( $file ),
                    'update_available' => isset( $updates->response[ $file ] ),
                    'new_version'    => $updates->response[ $file ]->new_version ?? null,
                    'author'         => $data['AuthorName'] ?? '',
                );
            }
            return array( 'plugins' => $list, 'total' => count( $list ), 'active' => count( array_filter( $list, function( $p ) { return $p['active']; } ) ) );
        } );

        $this->register_tool( 'plugins_activate', array(
            'description' => 'Activate an installed plugin by file path',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Activate Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $plugin = sanitize_text_field( $args['plugin'] );
            $result = activate_plugin( $plugin );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'plugin' => $plugin, 'activated' => true );
        } );

        $this->register_tool( 'plugins_deactivate', array(
            'description' => 'Deactivate an active plugin by file path',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Deactivate Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $plugin = sanitize_text_field( $args['plugin'] );
            if ( $this->is_mcp_lifeline_plugin( $plugin ) ) {
                throw new Exception( 'Refusing to deactivate "' . $plugin . '" — it powers this MCP endpoint; deactivating it would lock you out with no way to re-enable over MCP. Do it from WP Admin → Plugins if you truly intend to.' );
            }
            deactivate_plugins( $plugin );
            return array( 'plugin' => $plugin, 'deactivated' => true );
        } );

        // ── Plugin Search (WordPress.org repository) ──

        $this->register_tool( 'plugins_search', array(
            'description' => 'Search WordPress.org plugin repository by keyword',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'   => array( 'type' => 'string', 'description' => 'Search keyword (required)' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (default 10, max 30)' ),
                ),
                'required' => array( 'search' ),
            ),
            'annotations' => array( 'title' => 'Search Plugins (WP.org)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            $per_page = min( absint( $args['per_page'] ?? 10 ), 30 );
            $response = plugins_api( 'query_plugins', array(
                'search'   => sanitize_text_field( $args['search'] ),
                'per_page' => $per_page,
                'fields'   => array(
                    'short_description' => true,
                    'icons'             => false,
                    'banners'           => false,
                    'sections'          => false,
                    'tested'            => true,
                    'active_installs'   => true,
                    'rating'            => true,
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                throw new Exception( $response->get_error_message() );
            }
            $results = array();
            foreach ( $response->plugins as $p ) {
                $results[] = array(
                    'name'              => $p->name,
                    'slug'              => $p->slug,
                    'version'           => $p->version,
                    'author'            => wp_strip_all_tags( $p->author ),
                    'rating'            => $p->rating,
                    'active_installs'   => $p->active_installs,
                    'tested'            => $p->tested ?? null,
                    'short_description' => $p->short_description,
                );
            }
            return array( 'query' => $args['search'], 'results' => $results, 'total' => $response->info['results'] ?? count( $results ) );
        } );

        // ── Plugin Install (from WordPress.org by slug) ──

        $this->register_tool( 'plugins_install', array(
            'description' => 'Install a plugin from WordPress.org by slug (does NOT activate — use plugins_activate after)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'Plugin slug from WordPress.org e.g. "google-listings-and-ads" (required)' ),
                ),
                'required' => array( 'slug' ),
            ),
            'annotations' => array( 'title' => 'Install Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $slug = sanitize_text_field( $args['slug'] );

            // Check if already installed
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed = get_plugins();
            foreach ( $installed as $file => $data ) {
                if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
                    return array( 'slug' => $slug, 'installed' => true, 'already_installed' => true, 'plugin_file' => $file );
                }
            }

            // Fetch plugin info from WP.org
            $api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
            if ( is_wp_error( $api ) ) {
                throw new Exception( 'Plugin not found on WordPress.org: ' . $api->get_error_message() );
            }

            // Install
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $api->download_link );

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            if ( ! $result ) {
                $errors = $skin->get_errors();
                $msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Installation failed — check filesystem permissions';
                throw new Exception( $msg );
            }

            // Find the installed plugin file
            $plugin_file = $upgrader->plugin_info();

            LuwiPress_Logger::log( "Plugin installed via WebMCP: {$slug}", 'info', 'webmcp' );

            return array( 'slug' => $slug, 'installed' => true, 'plugin_file' => $plugin_file, 'name' => $api->name, 'version' => $api->version );
        } );

        // ── Plugin Delete (uninstall) ──

        $this->register_tool( 'plugins_delete', array(
            'description' => 'Delete (uninstall) an inactive plugin. Plugin must be deactivated first.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Delete Plugin', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $plugin = sanitize_text_field( $args['plugin'] );

            if ( $this->is_mcp_lifeline_plugin( $plugin ) ) {
                throw new Exception( 'Refusing to delete "' . $plugin . '" — it powers this MCP endpoint. Do it from WP Admin → Plugins if you truly intend to.' );
            }

            if ( is_plugin_active( $plugin ) ) {
                throw new Exception( 'Cannot delete an active plugin — deactivate it first' );
            }

            $all = get_plugins();
            if ( ! isset( $all[ $plugin ] ) ) {
                throw new Exception( "Plugin '{$plugin}' not found" );
            }

            $result = delete_plugins( array( $plugin ) );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            LuwiPress_Logger::log( "Plugin deleted via WebMCP: {$plugin}", 'warning', 'webmcp' );

            return array( 'plugin' => $plugin, 'deleted' => true );
        } );

        // ── Plugin Update ──

        $this->register_tool( 'plugins_update', array(
            'description' => 'Update an installed plugin to its latest version from WordPress.org',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Update Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin = sanitize_text_field( $args['plugin'] );

            $all = get_plugins();
            if ( ! isset( $all[ $plugin ] ) ) {
                throw new Exception( "Plugin '{$plugin}' not found" );
            }

            $old_version = $all[ $plugin ]['Version'];

            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->upgrade( $plugin );

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            // Re-read to get new version
            $updated  = get_plugins();
            $new_ver  = isset( $updated[ $plugin ] ) ? $updated[ $plugin ]['Version'] : $old_version;

            LuwiPress_Logger::log( "Plugin updated via WebMCP: {$plugin} ({$old_version} → {$new_ver})", 'info', 'webmcp' );

            return array( 'plugin' => $plugin, 'old_version' => $old_version, 'new_version' => $new_ver, 'updated' => $old_version !== $new_ver );
        } );

        $this->register_tool( 'themes_list', array(
            'description' => 'List installed themes with active status',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Themes', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $themes = wp_get_themes();
            $active = get_stylesheet();
            $list = array();
            foreach ( $themes as $slug => $theme ) {
                $list[] = array(
                    'slug'      => $slug,
                    'name'      => $theme->get( 'Name' ),
                    'version'   => $theme->get( 'Version' ),
                    'active'    => $slug === $active,
                    'parent'    => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                    'author'    => $theme->get( 'Author' ),
                );
            }
            return array( 'themes' => $list, 'active_theme' => $active );
        } );

        $this->register_tool( 'themes_activate', array(
            'description' => 'Switch the active theme',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'theme' => array( 'type' => 'string', 'description' => 'Theme slug (required)' ),
                ),
                'required' => array( 'theme' ),
            ),
            'annotations' => array( 'title' => 'Activate Theme', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $theme_slug = sanitize_text_field( $args['theme'] );
            $theme = wp_get_theme( $theme_slug );
            if ( ! $theme->exists() ) {
                throw new Exception( "Theme '{$theme_slug}' not found" );
            }
            switch_theme( $theme_slug );
            return array( 'theme' => $theme_slug, 'activated' => true );
        } );

        // ─────────────────── Theme inspection & Customizer (1.0.11) ───────────────────
        // Lets an AI orchestrator drive every theme-side process end-to-end:
        // diagnose what's live, dump current Customizer state, tune individual
        // settings, and confirm the change took effect — without leaving the
        // MCP transport. Pairs with the LuwiPress Gold theme 1.3.0 which
        // exposes its surfaces (AI search, chat, KG-related rail) under a
        // consistent `luwipress_gold_*` theme_mod namespace.

        $this->register_tool( 'theme_status', array(
            'description' => 'Detailed status of the active theme: version, parent, ecosystem capabilities (LuwiPress AI surface live? customer chat enabled? required friendly plugins detected?), and which Customizer-driven features are configured. Pair with theme_customizer_dump for the full picture.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Theme Status', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $stylesheet = get_stylesheet();
            $template   = get_template();
            $theme      = wp_get_theme();

            $is_gold = ( $stylesheet === 'luwipress-gold' ) || ( $template === 'luwipress-gold' );

            $detector_data = array();
            if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
                $det = LuwiPress_Plugin_Detector::get_instance();
                $detector_data = array(
                    'seo'         => $det->detect_seo()['plugin']         ?? 'none',
                    'translation' => $det->detect_translation()['plugin'] ?? 'none',
                    'page_builder'=> $det->detect_page_builder()['plugin']?? 'none',
                    'cache'       => $det->detect_cache()['plugin']       ?? 'none',
                    'crm'         => $det->detect_crm()['plugin']         ?? 'none',
                );
            }

            // Theme-specific capability inventory (works for any theme; the
            // luwipress_gold_* keys simply come back empty when a different
            // theme is active — so the tool stays useful in mixed setups).
            $caps = array(
                'requires_plugins' => $theme->get( 'RequiresPlugins' ),
                'wc_active'        => class_exists( 'WooCommerce' ),
                'elementor_active' => did_action( 'elementor/loaded' ) > 0,
                'luwipress_active' => class_exists( 'LuwiPress' ),
                'chat_enabled'     => (bool) get_option( 'luwipress_chat_enabled', 0 ),
                'gold_topbar_promo'=> (string) get_theme_mod( 'luwipress_gold_topbar_promo', '' ),
                'gold_logo_accent' => (string) get_theme_mod( 'luwipress_gold_logo_accent_letter', '' ),
            );

            return array(
                'active_theme' => array(
                    'slug'    => $stylesheet,
                    'name'    => $theme->get( 'Name' ),
                    'version' => $theme->get( 'Version' ),
                    'author'  => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
                    'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                    'is_luwipress_gold' => $is_gold,
                ),
                'ecosystem'    => $detector_data,
                'capabilities' => $caps,
            );
        } );

        $this->register_tool( 'theme_customizer_dump', array(
            'description' => 'Dump every theme_mod for the active theme as a flat key-value map. Optionally filter by prefix (e.g. "luwipress_gold_") so an AI can read only the theme-owned settings without WordPress core noise (nav_menu_locations, custom_css_post_id, etc).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'prefix' => array( 'type' => 'string', 'description' => 'Return only mods whose key starts with this prefix. Common: "luwipress_gold_". Omit for all.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Dump Theme Customizer', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $prefix = isset( $args['prefix'] ) ? sanitize_text_field( $args['prefix'] ) : '';
            $mods   = get_theme_mods();
            if ( ! is_array( $mods ) ) {
                $mods = array();
            }
            if ( $prefix !== '' ) {
                $mods = array_filter( $mods, function ( $k ) use ( $prefix ) {
                    return is_string( $k ) && strpos( $k, $prefix ) === 0;
                }, ARRAY_FILTER_USE_KEY );
            }
            return array(
                'theme'   => get_stylesheet(),
                'prefix'  => $prefix,
                'count'   => count( $mods ),
                'mods'    => $mods,
            );
        } );

        $this->register_tool( 'theme_customizer_get', array(
            'description' => 'Read a single theme_mod by key. Returns the value plus the resolved type so an AI can confirm what it is reading before it writes back.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'     => array( 'type' => 'string', 'description' => 'theme_mod key e.g. "luwipress_gold_topbar_promo" (required)' ),
                    'default' => array( 'description' => 'Optional default to return when the mod is unset. Any JSON-serializable value.' ),
                ),
                'required' => array( 'key' ),
            ),
            'annotations' => array( 'title' => 'Get Theme Customizer Setting', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $key     = sanitize_key( $args['key'] );
            $default = $args['default'] ?? null;
            $value   = get_theme_mod( $key, $default );
            return array(
                'key'      => $key,
                'value'    => $value,
                'type'     => gettype( $value ),
                'is_default' => ( $value === $default ),
            );
        } );

        $this->register_tool( 'theme_customizer_set', array(
            'description' => 'Write a single theme_mod. Use this to remote-tune the active theme (topbar promo, logo accent letter, journal subtitle, footer blurb, social URLs, etc). Mod keys for LuwiPress Gold all start with "luwipress_gold_". Reads back the saved value so the caller can verify.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'   => array( 'type' => 'string', 'description' => 'theme_mod key (required). Common LuwiPress Gold keys: luwipress_gold_topbar_location, luwipress_gold_topbar_phone, luwipress_gold_topbar_email, luwipress_gold_topbar_promo, luwipress_gold_topbar_track_url, luwipress_gold_topbar_track_label, luwipress_gold_logo_accent_letter, luwipress_gold_footer_blurb, luwipress_gold_footer_legal, luwipress_gold_footer_byline, luwipress_gold_social_instagram, luwipress_gold_social_youtube, luwipress_gold_social_facebook, luwipress_gold_social_whatsapp, luwipress_gold_journal_subtitle.' ),
                    'value' => array( 'description' => 'Value to store (required). Strings are sanitized with sanitize_text_field; URLs (keys ending in _url) with esc_url_raw; booleans/integers passed through.' ),
                ),
                'required' => array( 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Set Theme Customizer Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $key = sanitize_key( $args['key'] );
            if ( $key === '' ) {
                throw new Exception( 'Invalid theme_mod key' );
            }
            $raw = $args['value'];

            // Sanitize by key shape — URL-ish keys get esc_url_raw, scalars
            // get sanitize_text_field, others (arrays/objects) pass through.
            if ( is_string( $raw ) ) {
                if ( preg_match( '/_(url|link|href)$/', $key ) ) {
                    $value = esc_url_raw( $raw );
                } else {
                    $value = sanitize_text_field( $raw );
                }
            } else {
                $value = $raw;
            }

            set_theme_mod( $key, $value );

            return array(
                'key'        => $key,
                'value'      => get_theme_mod( $key ),
                'wrote'      => true,
                'sanitized_from_string' => is_string( $raw ),
            );
        } );

        $this->register_tool( 'theme_ecosystem_status', array(
            'description' => 'Single-call ecosystem snapshot from the active theme\'s perspective: which storefront AI surfaces are live (search suggestions, customer chat, KG-related rail), which friendly plugins are detected and what feature each gains, plus token-tracker daily spend. Mirrors the LuwiPress Gold "Appearance -> LuwiPress Gold" admin dashboard so an AI orchestrator sees the same story the operator sees.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Theme Ecosystem Status', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $stylesheet = get_stylesheet();
            $is_gold    = ( 'luwipress-gold' === $stylesheet ) || ( 'luwipress-gold' === get_template() );

            // Friendly-plugin layer — same source the theme dashboard consumes.
            $detector_data = array();
            if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
                $det = LuwiPress_Plugin_Detector::get_instance();
                $detector_data = array(
                    'seo'          => $det->detect_seo(),
                    'translation'  => $det->detect_translation(),
                    'page_builder' => $det->detect_page_builder(),
                    'cache'        => $det->detect_cache(),
                    'crm'          => $det->detect_crm(),
                );
            }

            // Storefront surface state — what's actually rendered for visitors.
            $surfaces = array(
                'ai_search_suggestions' => array(
                    'live'   => class_exists( 'LuwiPress_AI_Engine' ),
                    'reason' => class_exists( 'LuwiPress_AI_Engine' ) ? 'LuwiPress AI Engine available' : 'LuwiPress missing',
                ),
                'customer_chat' => array(
                    'live'   => (bool) get_option( 'luwipress_chat_enabled', 0 ),
                    'reason' => get_option( 'luwipress_chat_enabled', 0 ) ? 'Chat module enabled' : 'Toggle chat in LuwiPress -> Customer Chat',
                ),
                'kg_related_rail' => array(
                    'live'   => $is_gold && class_exists( 'WooCommerce' ),
                    'reason' => ! $is_gold
                        ? 'Theme is not LuwiPress Gold'
                        : ( class_exists( 'WooCommerce' ) ? 'WooCommerce active' : 'WooCommerce missing' ),
                ),
            );

            // Today's AI spend — small, cheap query against the token tracker.
            // The real method on LuwiPress_Token_Tracker is `get_today_cost`
            // (not `get_today_total`); the method_exists check is kept so this
            // tool stays graceful if the core plugin renames it.
            $today_spend = null;
            if ( class_exists( 'LuwiPress_Token_Tracker' ) && method_exists( 'LuwiPress_Token_Tracker', 'get_today_cost' ) ) {
                try {
                    $today_spend = LuwiPress_Token_Tracker::get_today_cost();
                } catch ( Throwable $e ) {
                    $today_spend = null;
                }
            }

            return array(
                'theme'         => array(
                    'slug'             => $stylesheet,
                    'is_luwipress_gold'=> $is_gold,
                    'wc_active'        => class_exists( 'WooCommerce' ),
                    'elementor_active' => did_action( 'elementor/loaded' ) > 0,
                    'luwipress_active' => class_exists( 'LuwiPress' ),
                ),
                'surfaces'      => $surfaces,
                'friendly'      => $detector_data,
                'token_tracker' => array(
                    'today_total' => $today_spend,
                ),
            );
        } );

        // ────────── Theme Tools framework (1.0.12 — paired with core 3.1.48) ──────────
        // Bridge contract: themes register maintenance tools via the
        // `luwipress_theme_tools` filter. These four MCP tools surface that
        // registry over MCP so AI agents can drive the same scan/execute/
        // restore flow operators see in the LuwiPress -> Theme admin tab.

        $this->register_tool( 'theme_tools_list', array(
            'description' => 'List every maintenance tool the active theme has registered with the LuwiPress Theme Bridge. Each tool exposes scan / execute / restore primitives. Tools are filtered by active theme — switching themes changes the registry.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Theme Tools', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $bridge = LuwiPress_Theme_Bridge::get_instance();
            $tools  = array();
            foreach ( $bridge->get_tools() as $t ) {
                $tools[] = array(
                    'id'          => $t['id'],
                    'label'       => $t['label'],
                    'description' => $t['description'],
                    'category'    => $t['category'],
                    'capability'  => $t['capability'],
                    'wpml_aware'  => (bool) $t['wpml_aware'],
                    'destructive' => (bool) $t['destructive'],
                    'actions'     => array_keys( $t['callbacks'] ),
                );
            }
            return array(
                'theme' => $bridge->active_theme_slug(),
                'tools' => $tools,
                'count' => count( $tools ),
            );
        } );

        $this->register_tool( 'theme_tool_run', array(
            'description' => 'Run a registered theme tool. Use action="scan" to discover candidates (read-only); action="execute" to mutate (only for tools whose `destructive` flag is true). Execute auto-expands WPML/Polylang siblings when the tool is `wpml_aware:true`. Returns the tool\'s native shape: candidates list (scan) or mutated count + backup_id (execute).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'tool_id'  => array( 'type' => 'string', 'description' => 'Tool id from theme_tools_list (e.g. "elementor_shell_cleanup", "kit_css_health", "slug_conflict_audit").' ),
                    'action'   => array( 'type' => 'string', 'enum' => array( 'scan', 'execute' ), 'description' => 'scan = read-only discovery; execute = mutate (must be supported by the tool).' ),
                    'post_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Required for execute. Source post IDs; siblings auto-expand for WPML-aware tools.' ),
                    'args'     => array( 'type' => 'object', 'description' => 'Tool-specific arguments (limit, post_types, etc).' ),
                ),
                'required' => array( 'tool_id', 'action' ),
            ),
            'annotations' => array( 'title' => 'Run Theme Tool', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $tool_id = sanitize_key( $args['tool_id'] ?? '' );
            $action  = sanitize_key( $args['action'] ?? 'scan' );
            $payload = isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : array();
            if ( ! empty( $args['post_ids'] ) && is_array( $args['post_ids'] ) ) {
                $payload['post_ids'] = array_map( 'intval', $args['post_ids'] );
            }
            $bridge = LuwiPress_Theme_Bridge::get_instance();
            $res    = $bridge->run_tool( $tool_id, $action, $payload );
            if ( is_wp_error( $res ) ) {
                throw new Exception( $res->get_error_message() );
            }
            return $res;
        } );

        $this->register_tool( 'theme_tool_restore', array(
            'description' => 'Restore from a backup taken by a previous theme_tool_run execute. Replays the captured pre-mutation payload. Backups are pruned to the last 20 per site; older entries are not retrievable.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'tool_id'   => array( 'type' => 'string', 'description' => 'Tool id (must match the tool that originally produced the backup).' ),
                    'backup_id' => array( 'type' => 'string', 'description' => 'UUID returned by theme_tool_run (or by theme_tool_backups).' ),
                ),
                'required' => array( 'tool_id', 'backup_id' ),
            ),
            'annotations' => array( 'title' => 'Restore Theme Tool Backup', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $tool_id   = sanitize_key( $args['tool_id'] ?? '' );
            $backup_id = sanitize_text_field( $args['backup_id'] ?? '' );
            $bridge    = LuwiPress_Theme_Bridge::get_instance();
            $res       = $bridge->run_tool( $tool_id, 'restore', array( 'backup_id' => $backup_id ) );
            if ( is_wp_error( $res ) ) {
                throw new Exception( $res->get_error_message() );
            }
            return $res;
        } );

        $this->register_tool( 'theme_tool_backups', array(
            'description' => 'List backups for a theme tool (or all tools when tool_id is omitted). Each entry includes the backup id, timestamp, and the post IDs that were affected. Use the id with theme_tool_restore.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'tool_id' => array( 'type' => 'string', 'description' => 'Optional — filter to a single tool.' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Theme Tool Backups', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $tool_id = isset( $args['tool_id'] ) && $args['tool_id'] !== '' ? sanitize_key( $args['tool_id'] ) : null;
            $bridge  = LuwiPress_Theme_Bridge::get_instance();
            return array(
                'theme'   => $bridge->active_theme_slug(),
                'tool_id' => $tool_id,
                'backups' => $bridge->get_backups( $tool_id ),
            );
        } );

        $this->register_tool( 'theme_settings_get', array(
            'description' => 'Read every theme_mod proxy registered via the LuwiPress Theme Bridge. Returns id, theme_mod key, label, type, default, current value, and the group it belongs to.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Get Bridged Theme Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $bridge = LuwiPress_Theme_Bridge::get_instance();
            $out    = array();
            foreach ( $bridge->get_settings() as $s ) {
                $out[] = array(
                    'id'        => $s['id'],
                    'theme_mod' => $s['theme_mod'],
                    'label'     => $s['label'],
                    'type'      => $s['type'],
                    'group'     => $s['group'],
                    'default'   => $s['default'],
                    'value'     => get_theme_mod( $s['theme_mod'], $s['default'] ),
                );
            }
            return array( 'theme' => $bridge->active_theme_slug(), 'settings' => $out, 'count' => count( $out ) );
        } );

        $this->register_tool( 'theme_settings_set', array(
            'description' => 'Update one bridged theme setting by id (NOT the raw theme_mod key — use theme_customizer_set for that). The bridge validates type, clamps numbers to min/max, and rejects unknown ids. Returns the saved value so the caller can verify.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'    => array( 'type' => 'string', 'description' => 'Setting id from theme_settings_get (e.g. "loader_enabled", "mega_columns").' ),
                    'value' => array( 'description' => 'New value. Type-coerced per the setting definition.' ),
                ),
                'required' => array( 'id', 'value' ),
            ),
            'annotations' => array( 'title' => 'Set Bridged Theme Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! class_exists( 'LuwiPress_Theme_Bridge' ) ) {
                throw new Exception( 'Theme Bridge unavailable — requires LuwiPress 3.1.48+.' );
            }
            $bridge = LuwiPress_Theme_Bridge::get_instance();
            $res    = $bridge->save_setting( sanitize_key( $args['id'] ?? '' ), $args['value'] ?? '' );
            if ( is_wp_error( $res ) ) {
                throw new Exception( $res->get_error_message() );
            }
            return $res;
        } );
    }

    /* ───────────────────── Menu Tools ──────────────────────────────── */

    private function register_menu_tools() {

        $this->register_tool( 'menu_list', array(
            'description' => 'List all navigation menus with their item count and assigned locations',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Menus', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $menus     = wp_get_nav_menus();
            $locations = get_nav_menu_locations();
            $loc_names = get_registered_nav_menus();
            $loc_map = array();
            foreach ( $locations as $loc => $menu_id ) {
                $loc_map[ $menu_id ][] = $loc_names[ $loc ] ?? $loc;
            }
            $list = array();
            foreach ( $menus as $menu ) {
                $list[] = array(
                    'id'         => $menu->term_id,
                    'name'       => $menu->name,
                    'slug'       => $menu->slug,
                    'item_count' => $menu->count,
                    'locations'  => $loc_map[ $menu->term_id ] ?? array(),
                );
            }
            return array( 'menus' => $list, 'registered_locations' => array_values( $loc_names ) );
        } );

        $this->register_tool( 'menu_get_items', array(
            'description' => 'Get all items in a menu (hierarchical, with URLs, types)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'menu_id' => array( 'type' => 'integer', 'description' => 'Menu ID or term_id (required)' ),
                ),
                'required' => array( 'menu_id' ),
            ),
            'annotations' => array( 'title' => 'Get Menu Items', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $items = wp_get_nav_menu_items( intval( $args['menu_id'] ) );
            if ( ! $items ) {
                throw new Exception( 'Menu not found or empty' );
            }
            $list = array();
            foreach ( $items as $item ) {
                $list[] = array(
                    'id'        => absint( $item->ID ),
                    'title'     => $item->title,
                    'url'       => $item->url,
                    'type'      => $item->type,
                    'object'    => $item->object,
                    'object_id' => absint( $item->object_id ),
                    'parent'    => absint( $item->menu_item_parent ),
                    'position'  => absint( $item->menu_order ),
                    'classes'   => array_filter( $item->classes ),
                );
            }
            return array( 'menu_id' => intval( $args['menu_id'] ), 'items' => $list );
        } );

        $this->register_tool( 'menu_create', array(
            'description' => 'Create a new navigation menu',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'name' => array( 'type' => 'string', 'description' => 'Menu name (required)' ),
                ),
                'required' => array( 'name' ),
            ),
            'annotations' => array( 'title' => 'Create Menu', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $menu_id = wp_create_nav_menu( sanitize_text_field( $args['name'] ) );
            if ( is_wp_error( $menu_id ) ) {
                throw new Exception( $menu_id->get_error_message() );
            }
            return array( 'menu_id' => $menu_id, 'name' => $args['name'] );
        } );

        $this->register_tool( 'menu_add_item', array(
            'description' => 'Add an item to a navigation menu (page, post, custom URL, category). On WPML sites pass `lang` so the newly created nav_menu_item post is attached to the right language context — without it, WPML strips the term-attachment and the item appears as an orphan in the wp_posts table without being visible in the target menu.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'menu_id'   => array( 'type' => 'integer', 'description' => 'Menu ID (required)' ),
                    'title'     => array( 'type' => 'string', 'description' => 'Menu item title (required)' ),
                    'url'       => array( 'type' => 'string', 'description' => 'URL for custom link items' ),
                    'object_id' => array( 'type' => 'integer', 'description' => 'Post/page/category ID for content items' ),
                    'object'    => array( 'type' => 'string', 'description' => 'Object type: page, post, category, custom' ),
                    'parent'    => array( 'type' => 'integer', 'description' => 'Parent menu item ID for submenus' ),
                    'lang'      => array( 'type' => 'string', 'description' => 'WPML/Polylang language code (en/fr/it/es/...). Required on multilingual sites — sets the global language context before insertion so WPML attaches the new nav_menu_item to the correct language.' ),
                ),
                'required' => array( 'menu_id', 'title' ),
            ),
            'annotations' => array( 'title' => 'Add Menu Item', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $menu_id = intval( $args['menu_id'] );
            $lang    = isset( $args['lang'] ) ? sanitize_key( (string) $args['lang'] ) : '';

            // Set WPML/Polylang language context BEFORE the menu item is
            // created, otherwise the nav_menu_item post lands in the
            // default language and is silently disowned by the term-
            // attachment hooks WPML runs at wp_update_nav_menu_item.
            // Documented in feedback_menu_add_item_wpml_orphan.md after a
            // bare insertion in the previous session created 5 orphans.
            $restore_lang = null;
            if ( $lang !== '' ) {
                if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
                    $restore_lang = ICL_LANGUAGE_CODE;
                }
                if ( function_exists( 'do_action' ) ) {
                    do_action( 'wpml_switch_language', $lang );
                }
            }

            try {
                $item_data = array(
                    'menu-item-title'  => sanitize_text_field( $args['title'] ),
                    'menu-item-status' => 'publish',
                );
                if ( ! empty( $args['url'] ) ) {
                    $item_data['menu-item-url']  = esc_url_raw( $args['url'] );
                    $item_data['menu-item-type'] = 'custom';
                } elseif ( ! empty( $args['object_id'] ) ) {
                    $item_data['menu-item-object-id'] = absint( $args['object_id'] );
                    $obj = sanitize_text_field( $args['object'] ?? 'page' );
                    $item_data['menu-item-object'] = $obj;
                    $item_data['menu-item-type'] = in_array( $obj, array( 'category', 'post_tag', 'product_cat' ), true ) ? 'taxonomy' : 'post_type';
                }
                if ( ! empty( $args['parent'] ) ) {
                    $item_data['menu-item-parent-id'] = absint( $args['parent'] );
                }
                $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );
                if ( is_wp_error( $item_id ) ) {
                    throw new Exception( $item_id->get_error_message() );
                }

                // Force WPML to record the language assignment for the new
                // nav_menu_item post (in case the language switch above
                // didn't propagate to wpml-translations on insert).
                if ( $lang !== '' && function_exists( 'do_action' ) ) {
                    do_action( 'wpml_set_element_language_details', array(
                        'element_id'           => (int) $item_id,
                        'element_type'         => 'post_nav_menu_item',
                        'language_code'        => $lang,
                        'source_language_code' => null,
                    ) );
                }

                // Verify the item is actually attached to the target menu
                // term (the bug pattern we're guarding against).
                $attached = false;
                $items = wp_get_nav_menu_items( $menu_id );
                if ( is_array( $items ) ) {
                    foreach ( $items as $it ) {
                        if ( (int) $it->ID === (int) $item_id ) { $attached = true; break; }
                    }
                }
                $result = array(
                    'item_id'  => (int) $item_id,
                    'menu_id'  => $menu_id,
                    'title'    => $args['title'],
                    'lang'     => $lang ?: null,
                    'attached' => $attached,
                );
                if ( ! $attached ) {
                    $result['warning'] = 'item_inserted_but_not_attached_to_menu';
                    $result['hint']    = 'On WPML sites pass `lang` matching the target menu\'s language, or add manually via wp-admin where the language context is set correctly.';
                }
                return $result;
            } finally {
                if ( $restore_lang !== null && function_exists( 'do_action' ) ) {
                    do_action( 'wpml_switch_language', $restore_lang );
                }
            }
        } );

        $this->register_tool( 'menu_remove_item', array(
            'description' => 'Remove an item from a navigation menu',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'item_id' => array( 'type' => 'integer', 'description' => 'Menu item post ID (required)' ),
                ),
                'required' => array( 'item_id' ),
            ),
            'annotations' => array( 'title' => 'Remove Menu Item', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $item_id = absint( $args['item_id'] );
            $result = wp_delete_post( $item_id, true );
            if ( ! $result ) {
                throw new Exception( 'Failed to remove menu item' );
            }
            return array( 'item_id' => $item_id, 'removed' => true );
        } );

        // ── WPML menu structure sync (mega-menu translation, step 1) ──
        // Copies the default-language menu STRUCTURE to every translated menu so
        // each language has the same items to translate. Run BEFORE
        // menu_translate_wpml. No-op (and reports so) on non-WPML sites.
        $this->register_tool( 'menu_sync_wpml', array(
            'description' => 'WPML only — sync navigation menu STRUCTURE from the default language to every translated menu. Run this BEFORE menu_translate_wpml so the translated menu items exist. Fixes mega menus that show default-language labels in other languages because the translated menu was never created. Returns the number of menus synced.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Sync WPML Menus', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Translation' ) ) {
                throw new Exception( 'LuwiPress Translation module not available' );
            }
            $result = LuwiPress_Translation::get_instance()->sync_wpml_menus();
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return $result;
        } );

        // ── WPML menu label translation (mega-menu translation, step 2) ──
        // Walks every translated menu and sets each item's label: the exact
        // translated object title for plain post/term items, AI translation for
        // custom links and label overrides. Run menu_sync_wpml first.
        $this->register_tool( 'menu_translate_wpml', array(
            'description' => 'WPML only — AI-translate navigation menu item LABELS into every language. Post/term items reuse their exact translated object title; custom links and label overrides are AI-translated. Run menu_sync_wpml FIRST so the translated items exist (the response reports needs_sync when items are still missing). Returns the count of labels updated.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Translate WPML Menus', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Translation' ) ) {
                throw new Exception( 'LuwiPress Translation module not available' );
            }
            $result = LuwiPress_Translation::get_instance()->translate_wpml_menus();
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return $result;
        } );
    }

    /* ───────────────────── Custom Field / Meta Tools ──────────────── */

    private function register_meta_tools() {

        $this->register_tool( 'meta_get', array(
            'description' => 'Get all or specific custom field values for a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Specific meta key (omit for all public meta)' ),
                ),
                'required' => array( 'post_id' ),
            ),
            'annotations' => array( 'title' => 'Get Meta', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            if ( ! empty( $args['key'] ) ) {
                $value = get_post_meta( $post_id, sanitize_text_field( $args['key'] ), true );
                return array( 'post_id' => $post_id, 'key' => $args['key'], 'value' => $value );
            }
            $all_meta = get_post_meta( $post_id );
            $filtered = array();
            foreach ( $all_meta as $key => $values ) {
                if ( substr( $key, 0, 1 ) !== '_' ) {
                    $filtered[ $key ] = count( $values ) === 1 ? $values[0] : $values;
                }
            }
            return array( 'post_id' => $post_id, 'meta' => $filtered );
        } );

        $this->register_tool( 'meta_set', array(
            'description' => 'Set a custom field value for a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Meta key (required)' ),
                    'value'   => array( 'type' => 'string', 'description' => 'Meta value (required)' ),
                ),
                'required' => array( 'post_id', 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Set Meta', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            $key = sanitize_text_field( $args['key'] );
            update_post_meta( $post_id, $key, sanitize_text_field( $args['value'] ) );
            return array( 'post_id' => $post_id, 'key' => $key, 'updated' => true );
        } );

        $this->register_tool( 'meta_set_bulk', array(
            'description' => 'Set MANY custom fields on ONE post/product in a single call — the bulk form of meta_set. Pass { "post_id": N, "meta": { "key1": "v1", "key2": "v2", … } }. Ideal for filling a CPT field schema (10-16 fields) in one round-trip instead of N sequential meta_set calls. Values are stored as strings. Structured-array keys (_luwipress_faq / _luwipress_howto / _luwipress_speakable) are skipped — use aeo_save_faq / aeo_save_schema for those.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'meta'    => array( 'type' => 'object', 'description' => '{ meta_key: value, … } — at least one pair (required).' ),
                ),
                'required' => array( 'post_id', 'meta' ),
            ),
            'annotations' => array( 'title' => 'Set Meta (bulk)', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            $meta = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
            if ( empty( $meta ) ) {
                throw new Exception( 'meta must be a non-empty object of { key: value } pairs.' );
            }
            $structured_keys = array( '_luwipress_faq', '_luwipress_howto', '_luwipress_speakable' );
            $set     = array();
            $skipped = array();
            foreach ( $meta as $k => $v ) {
                $key = sanitize_text_field( (string) $k );
                if ( '' === $key ) {
                    continue;
                }
                if ( in_array( $key, $structured_keys, true ) ) {
                    $skipped[] = $key; // structured array — use aeo_save_faq / aeo_save_schema.
                    continue;
                }
                update_post_meta( $post_id, $key, sanitize_text_field( (string) $v ) );
                $set[] = $key;
            }
            return array( 'post_id' => $post_id, 'set' => $set, 'count' => count( $set ), 'skipped' => $skipped );
        } );

        $this->register_tool( 'meta_delete', array(
            'description' => 'Delete a custom field from a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Meta key to delete (required)' ),
                ),
                'required' => array( 'post_id', 'key' ),
            ),
            'annotations' => array( 'title' => 'Delete Meta', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            $key = sanitize_text_field( $args['key'] );
            $result = delete_post_meta( $post_id, $key );
            return array( 'post_id' => $post_id, 'key' => $key, 'deleted' => $result );
        } );

        // ─── TAXONOMY TERM META ─────────────────────────────────────────
        // Parallel to meta_get / meta_set / meta_delete but target term meta
        // via update_term_meta(). Use case: Rank Math title/description on
        // product_cat / post_tag / pa_* attribute taxonomies. WPML term
        // translations are separate term_id rows — caller passes the term_id
        // for the language they want (use taxonomy_get_terms with lang= to
        // enumerate the per-language IDs).

        $this->register_tool( 'taxonomy_meta_get', array(
            'description' => 'Get term meta for a taxonomy term (e.g. Rank Math meta on a product_cat). WPML: pass the term_id of the specific language variant.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (e.g. product_cat, post_tag, pa_color) — required for validation' ),
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID (required)' ),
                    'key'      => array( 'type' => 'string', 'description' => 'Specific meta key (omit for all public term meta)' ),
                ),
                'required' => array( 'taxonomy', 'term_id' ),
            ),
            'annotations' => array( 'title' => 'Get Term Meta', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_key( $args['taxonomy'] );
            $term_id  = intval( $args['term_id'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( 'Taxonomy not registered: ' . $taxonomy );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                throw new Exception( 'Term not found in taxonomy ' . $taxonomy );
            }
            if ( ! empty( $args['key'] ) ) {
                $key   = sanitize_text_field( $args['key'] );
                $value = get_term_meta( $term_id, $key, true );
                return array(
                    'taxonomy' => $taxonomy,
                    'term_id'  => $term_id,
                    'slug'     => $term->slug,
                    'key'      => $key,
                    'value'    => $value,
                );
            }
            $all_meta = get_term_meta( $term_id );
            $filtered = array();
            foreach ( $all_meta as $key => $values ) {
                if ( substr( $key, 0, 1 ) !== '_' ) {
                    $filtered[ $key ] = count( $values ) === 1 ? $values[0] : $values;
                }
            }
            return array(
                'taxonomy' => $taxonomy,
                'term_id'  => $term_id,
                'slug'     => $term->slug,
                'meta'     => $filtered,
            );
        } );

        $this->register_tool( 'taxonomy_meta_set', array(
            'description' => 'Set term meta for a taxonomy term. Use case: Rank Math title/description/focus_keyword on product_cat. WPML: each language variant is a separate term_id — call once per language.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID (required)' ),
                    'key'      => array( 'type' => 'string', 'description' => 'Meta key (required)' ),
                    'value'    => array( 'type' => 'string', 'description' => 'Meta value (required)' ),
                ),
                'required' => array( 'taxonomy', 'term_id', 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Set Term Meta', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_key( $args['taxonomy'] );
            $term_id  = intval( $args['term_id'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( 'Taxonomy not registered: ' . $taxonomy );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                throw new Exception( 'Term not found in taxonomy ' . $taxonomy );
            }
            $key   = sanitize_text_field( $args['key'] );
            // FR-023: these keys store a structured ARRAY consumed by the Schema
            // Registry (FAQPage / HowTo / Speakable). taxonomy_meta_set writes a
            // plain STRING, which silently corrupts them (the value lands as a
            // literal JSON string, not an array, and the schema never renders).
            // Refuse + route the caller to the dedicated, validating pipeline.
            $structured_keys = array( '_luwipress_faq', '_luwipress_howto', '_luwipress_speakable' );
            if ( in_array( $key, $structured_keys, true ) ) {
                throw new Exception( sprintf(
                    'Refused: "%s" stores a structured array, not a string — writing it here corrupts the schema. Use aeo_save_faq (term_id + taxonomy) or the generic aeo_save_schema so the value is validated and stored in the canonical shape.',
                    $key
                ) );
            }
            // Rank Math keys accept HTML in description; use wp_kses_post for description-shaped keys,
            // sanitize_text_field for title/keyword.
            $is_desc = ( false !== strpos( $key, 'description' ) || false !== strpos( $key, '_desc' ) );
            $value   = $is_desc ? wp_kses_post( $args['value'] ) : sanitize_text_field( $args['value'] );
            $updated = update_term_meta( $term_id, $key, $value );
            return array(
                'taxonomy' => $taxonomy,
                'term_id'  => $term_id,
                'slug'     => $term->slug,
                'key'      => $key,
                'updated'  => (bool) $updated,
            );
        } );

        $this->register_tool( 'taxonomy_meta_delete', array(
            'description' => 'Delete a term meta key from a taxonomy term. WPML: each language variant is a separate term_id.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID (required)' ),
                    'key'      => array( 'type' => 'string', 'description' => 'Meta key to delete (required)' ),
                ),
                'required' => array( 'taxonomy', 'term_id', 'key' ),
            ),
            'annotations' => array( 'title' => 'Delete Term Meta', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_key( $args['taxonomy'] );
            $term_id  = intval( $args['term_id'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( 'Taxonomy not registered: ' . $taxonomy );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                throw new Exception( 'Term not found in taxonomy ' . $taxonomy );
            }
            $key    = sanitize_text_field( $args['key'] );
            $result = delete_term_meta( $term_id, $key );
            return array(
                'taxonomy' => $taxonomy,
                'term_id'  => $term_id,
                'slug'     => $term->slug,
                'key'      => $key,
                'deleted'  => (bool) $result,
            );
        } );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  PUBLIC API — Introspection
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Get the count of registered tools.
     */
    public function get_tool_count() {
        return count( $this->tools );
    }

    /**
     * Get all tool names grouped by category.
     */
    public function get_tool_catalog() {
        $catalog = array();
        foreach ( $this->tools as $name => $schema ) {
            $parts    = explode( '_', $name, 2 );
            $category = $parts[0];
            if ( ! isset( $catalog[ $category ] ) ) {
                $catalog[ $category ] = array();
            }
            $catalog[ $category ][] = array(
                'name'        => $name,
                'description' => $schema['description'] ?? '',
            );
        }
        return $catalog;
    }

    /* ───────────────────── Search Tools ──────────────────────────── */

    private function register_search_tools() {
        if ( ! class_exists( 'LuwiPress_Search_Index' ) ) {
            return;
        }

        $this->register_tool( 'search_products', array(
            'description' => 'Search the BM25 index using title, description, categories, tags, attributes, SKU, and FAQ content with relevance scoring. Default scope is products; operators can extend the index to include posts and pages by setting the option `luwipress_search_index_post_types` (or the `luwipress_search_index_post_types` filter) and running search_reindex. Each result row includes `post_type` so chat callers can distinguish a product hit (with price + stock + SKU) from a blog or page hit (price/stock/sku empty, description = trimmed body).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'description' => 'Search query (required)' ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Max results (default 10)' ),
                ),
                'required' => array( 'query' ),
            ),
            'annotations' => array( 'title' => 'BM25 Search', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $index = LuwiPress_Search_Index::get_instance();
            if ( ! $index->is_indexed() ) {
                return array( 'error' => 'Search index not built yet. Run reindex first.', 'indexed' => false );
            }
            $results = $index->search( sanitize_text_field( $args['query'] ), absint( $args['limit'] ?? 10 ) );
            return array( 'query' => $args['query'], 'results' => $results, 'count' => count( $results ) );
        } );

        $this->register_tool( 'search_reindex', array(
            'description' => 'Rebuild the BM25 search index. Pass `post_types` to override the indexable set for this rebuild AND persist it as the new default (writes the `luwipress_search_index_post_types` option). Omit `post_types` to reindex the current configured set (default: ["product"]; operators opt post/page in here to enable chat RAG over blog/page content — IMP-002). Indexes title, description, categories, tags, attributes, SKU, and FAQ content; product-specific fields (SKU, attributes) are only collected for `product` rows.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_types' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Override the indexable post-type set for this rebuild and persist it as the new default. E.g. ["product","post","page"] to enable cross-content RAG. Omit to reuse the current option value.',
                    ),
                ),
            ),
            'annotations' => array( 'title' => 'Rebuild Search Index', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( isset( $args['post_types'] ) && is_array( $args['post_types'] ) && ! empty( $args['post_types'] ) ) {
                $clean = array_values( array_unique( array_map( 'sanitize_key', $args['post_types'] ) ) );
                if ( ! empty( $clean ) ) {
                    update_option( 'luwipress_search_index_post_types', $clean );
                }
            }
            $index = LuwiPress_Search_Index::get_instance();
            $count = $index->reindex_all();
            $stats = $index->get_stats();
            return array(
                'reindexed'  => $count,
                'post_types' => $index->get_indexable_post_types(),
                'stats'      => $stats,
            );
        } );

        $this->register_tool( 'search_stats', array(
            'description' => 'Get BM25 search index statistics — total documents, unique tokens, average document length',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Search Index Stats', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $index = LuwiPress_Search_Index::get_instance();
            return $index->get_stats();
        } );
    }

    /* ───────────────────── ACP Attribution Tools ────────────────────── */

    private function register_attribution_tools() {
        if ( ! class_exists( 'LuwiPress_ACP_Attribution' ) ) {
            return;
        }

        $this->register_tool( 'attribution_settings_get', array(
            'description' => 'Read ACP attribution bridge settings (GA4 Measurement Protocol, Meta CAPI, Google Ads) — secrets are masked, only presence + last-4 returned',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Attribution Settings',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/attribution/settings' );
            $response = LuwiPress_ACP_Attribution::get_instance()->rest_get_settings( $request );
            return $response instanceof WP_REST_Response ? $response->get_data() : $response;
        } );

        $this->register_tool( 'attribution_settings_set', array(
            'description' => 'Update ACP attribution bridge settings. Partial-update — only present keys are touched. Set debug_mode=false to start firing real events.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled'                        => array( 'type' => 'boolean', 'description' => 'Master on/off switch' ),
                    'debug_mode'                     => array( 'type' => 'boolean', 'description' => 'When true, log payloads instead of dispatching' ),
                    'ga4_measurement_id'             => array( 'type' => 'string', 'description' => 'GA4 Measurement ID (G-XXXXXXXXXX)' ),
                    'ga4_api_secret'                 => array( 'type' => 'string', 'description' => 'GA4 Measurement Protocol API secret' ),
                    'meta_pixel_id'                  => array( 'type' => 'string', 'description' => 'Meta Pixel ID (15+ digit number)' ),
                    'meta_capi_token'                => array( 'type' => 'string', 'description' => 'Meta Conversions API access token' ),
                    'meta_test_event_code'           => array( 'type' => 'string', 'description' => 'Meta Events Manager test code (omit for production)' ),
                    'google_ads_customer_id'         => array( 'type' => 'string', 'description' => 'Google Ads customer ID (no dashes)' ),
                    'google_ads_conversion_action'   => array( 'type' => 'string', 'description' => 'Google Ads conversion action resource name (customers/X/conversionActions/Y)' ),
                    'google_ads_developer_token'     => array( 'type' => 'string', 'description' => 'Google Ads API developer token' ),
                    'google_ads_login_customer_id'   => array( 'type' => 'string', 'description' => 'Manager (MCC) account ID — required only when using a manager OAuth flow' ),
                    'google_ads_oauth_client_id'     => array( 'type' => 'string', 'description' => 'OAuth 2.0 client_id from Google Cloud Console' ),
                    'google_ads_oauth_client_secret' => array( 'type' => 'string', 'description' => 'OAuth 2.0 client_secret' ),
                    'google_ads_oauth_refresh_token' => array( 'type' => 'string', 'description' => 'OAuth refresh_token (long-lived, scope https://www.googleapis.com/auth/adwords)' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Update Attribution Settings',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/attribution/settings' );
            foreach ( (array) $args as $k => $v ) {
                $request->set_param( $k, $v );
            }
            $response = LuwiPress_ACP_Attribution::get_instance()->rest_save_settings( $request );
            return $response instanceof WP_REST_Response ? $response->get_data() : $response;
        } );

        $this->register_tool( 'attribution_log_recent', array(
            'description' => 'List the most recent ACP attribution dispatches with channel results (GA4, Meta CAPI). Use to verify events are firing after enabling.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max entries (default 50, max 200)' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Attribution Audit Log',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $limit = absint( $args['limit'] ?? 50 );
            return array(
                'entries' => LuwiPress_ACP_Attribution::get_instance()->get_audit_log( $limit ),
            );
        } );

        $this->register_tool( 'attribution_test_send', array(
            'description' => 'Fire a synthetic test event to all configured channels (GA4, Meta CAPI). Use Meta test_event_code to verify in Events Manager without polluting production.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Send Attribution Test Event',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => true,
            ),
        ), function () {
            return LuwiPress_ACP_Attribution::get_instance()->test_dispatch();
        } );
    }

    /* ───── UCP Tools — Google Universal Commerce Protocol (3.5.9+) ────── */

    private function register_ucp_tools() {
        if ( ! class_exists( 'LuwiPress_UCP' ) ) {
            return;
        }

        $this->register_tool( 'ucp_settings_get', array(
            'description' => 'Read Google UCP (Universal Commerce Protocol) store settings — enabled/sandbox flags, return policy, customer-support info, feed format. No secrets.',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'annotations' => array(
                'title'          => 'UCP Settings',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function () {
            return LuwiPress_UCP::get_instance()->get_settings();
        } );

        $this->register_tool( 'ucp_settings_set', array(
            'description' => 'Update UCP store settings (partial — only present keys touched). Keep sandbox=true until Google validates. Configure return policy + support before flagging products.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled'                 => array( 'type' => 'boolean', 'description' => 'Master on/off' ),
                    'sandbox'                 => array( 'type' => 'boolean', 'description' => 'Validate against Google sandbox (no live checkout)' ),
                    'merchant_of_record'      => array( 'type' => 'boolean', 'description' => 'Merchant stays Merchant of Record (UCP default true)' ),
                    'default_native_commerce' => array( 'type' => 'boolean', 'description' => 'New products eligible by default' ),
                    'return_cost'             => array( 'type' => 'string', 'description' => 'Return cost label, e.g. "Free" or "9.90 USD"' ),
                    'return_window_days'      => array( 'type' => 'integer', 'description' => 'Return window in days' ),
                    'return_policy_url'       => array( 'type' => 'string', 'description' => 'Full return policy URL' ),
                    'support_email'           => array( 'type' => 'string', 'description' => 'Customer support email' ),
                    'support_phone'           => array( 'type' => 'string', 'description' => 'Customer support phone' ),
                    'support_url'             => array( 'type' => 'string', 'description' => 'Customer support URL' ),
                    'support_hours'           => array( 'type' => 'string', 'description' => 'Support hours text' ),
                    'feed_format'             => array( 'type' => 'string', 'description' => 'Default supplemental feed format: json | csv | xml' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Update UCP Settings',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            return LuwiPress_UCP::get_instance()->save_settings( (array) $args );
        } );

        $this->register_tool( 'ucp_eligibility_report', array(
            'description' => 'UCP eligibility coverage: total products, native_commerce-flagged count, sampled eligibility + missing-attribute breakdown, return/support readiness, detected feed plugin.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'sample' => array( 'type' => 'integer', 'description' => 'Products to deep-validate for the breakdown (default 100, max 500)' ),
                ),
            ),
            'annotations' => array(
                'title'          => 'UCP Eligibility Report',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $sample = absint( $args['sample'] ?? 100 );
            return LuwiPress_UCP::get_instance()->get_eligibility_report( $sample ?: 100 );
        } );

        $this->register_tool( 'ucp_product_profile', array(
            'description' => 'Read the UCP profile for one product: native_commerce flag + source, mapped merchant_item_id, consumer_notice, resolved commerce attributes, validation warnings, eligibility verdict.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'WooCommerce product ID' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array(
                'title'          => 'UCP Product Profile',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $profile = LuwiPress_UCP::get_instance()->get_product_profile( absint( $args['product_id'] ?? 0 ) );
            if ( is_wp_error( $profile ) ) {
                return array( 'error' => $profile->get_error_message() );
            }
            return $profile;
        } );

        $this->register_tool( 'ucp_product_set', array(
            'description' => 'Set UCP product meta (partial). Flag native_commerce to make a product eligible for the UCP Buy button, map a merchant_item_id, or attach a consumer_notice (regulatory warning). Returns the fresh profile.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'       => array( 'type' => 'integer', 'description' => 'WooCommerce product ID' ),
                    'native_commerce'  => array( 'type' => 'boolean', 'description' => 'Eligible for UCP checkout' ),
                    'merchant_item_id' => array( 'type' => 'string', 'description' => 'Checkout-API id mapping (blank = use product ID)' ),
                    'consumer_notice'  => array( 'type' => 'string', 'description' => 'Regulatory warning text (blank = none)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array(
                'title'           => 'Set UCP Product Meta',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $pid     = absint( $args['product_id'] ?? 0 );
            $profile = LuwiPress_UCP::get_instance()->set_product_meta( $pid, (array) $args );
            if ( is_wp_error( $profile ) ) {
                return array( 'error' => $profile->get_error_message() );
            }
            return $profile;
        } );

        $this->register_tool( 'ucp_feed_preview', array(
            'description' => 'Preview the UCP supplemental feed rows (id + native_commerce + consumer_notice) that overlay the primary Merchant Center feed. include=eligible (default) or all.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'include' => array( 'type' => 'string', 'description' => 'eligible (default) | all' ),
                    'limit'   => array( 'type' => 'integer', 'description' => 'Max rows (default 1000, max 5000)' ),
                ),
            ),
            'annotations' => array(
                'title'          => 'UCP Feed Preview',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $rows = LuwiPress_UCP::get_instance()->build_feed_rows(
                (string) ( $args['include'] ?? 'eligible' ),
                absint( $args['limit'] ?? 1000 ) ?: 1000
            );
            return array( 'count' => count( $rows ), 'products' => $rows );
        } );

        // ── UCP Native Checkout (phase 2) ── these are WRITE tools, so the
        // autonomous agentic loop (readOnly-only) never auto-invokes them;
        // they are operator / explicit-agent surfaces. `complete` creates a
        // real pending order in live mode (sandbox simulates).
        if ( class_exists( 'LuwiPress_UCP_Checkout' ) ) {

            $this->register_tool( 'ucp_checkout_session_create', array(
                'description' => 'Create a UCP checkout session from items. Backed by a WooCommerce draft order so totals/tax are authoritative. items: [{product_id|merchant_item_id, quantity}]. Honours sandbox (default from settings).',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'items'           => array( 'type' => 'array', 'description' => 'Line items: [{product_id|merchant_item_id, quantity}]' ),
                        'sandbox'         => array( 'type' => 'boolean', 'description' => 'Override sandbox flag for this session' ),
                        'idempotency_key' => array( 'type' => 'string', 'description' => 'Reuse an open session on retry' ),
                    ),
                    'required'   => array( 'items' ),
                ),
                'annotations' => array(
                    'title'           => 'UCP Create Checkout Session',
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => false,
                    'openWorldHint'   => false,
                ),
            ), function ( $args ) {
                $res = LuwiPress_UCP_Checkout::get_instance()->create_session( (array) $args );
                return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res;
            } );

            $this->register_tool( 'ucp_checkout_session_get', array(
                'description' => 'Read a UCP checkout session: line items, authoritative totals, shipping options, status.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array( 'session_id' => array( 'type' => 'string' ) ),
                    'required'   => array( 'session_id' ),
                ),
                'annotations' => array(
                    'title'          => 'UCP Get Checkout Session',
                    'readOnlyHint'   => true,
                    'idempotentHint' => true,
                    'openWorldHint'  => false,
                ),
            ), function ( $args ) {
                $res = LuwiPress_UCP_Checkout::get_instance()->get_session( (string) ( $args['session_id'] ?? '' ) );
                return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res;
            } );

            $this->register_tool( 'ucp_checkout_session_update', array(
                'description' => 'Update a UCP checkout session: set shipping/billing address (drives tax + shipping rates), select a shipping rate, or change items. Returns recomputed totals + shipping_options.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'        => array( 'type' => 'string' ),
                        'shipping_address'  => array( 'type' => 'object', 'description' => 'Flat address: first_name,last_name,address_1,city,state,postcode,country,…' ),
                        'billing_address'   => array( 'type' => 'object', 'description' => 'Optional separate billing address' ),
                        'selected_shipping' => array( 'type' => 'string', 'description' => 'Chosen shipping rate id from shipping_options' ),
                        'items'             => array( 'type' => 'array', 'description' => 'Optional replacement line items' ),
                    ),
                    'required'   => array( 'session_id' ),
                ),
                'annotations' => array(
                    'title'           => 'UCP Update Checkout Session',
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => false,
                ),
            ), function ( $args ) {
                $sid = (string) ( $args['session_id'] ?? '' );
                $res = LuwiPress_UCP_Checkout::get_instance()->update_session( $sid, (array) $args );
                return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res;
            } );

            $this->register_tool( 'ucp_checkout_session_complete', array(
                'description' => 'Complete a UCP checkout session. Sandbox: simulated success, no payable order. Live: transitions the draft into a pending WooCommerce order (payment captured by the processor / AP2 mandate). Idempotent — replays return the existing order. Optionally accepts ap2_cart_mandate.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'       => array( 'type' => 'string' ),
                        'buyer'            => array( 'type' => 'object', 'description' => '{email, phone, name}' ),
                        'billing_address'  => array( 'type' => 'object' ),
                        'shipping_address' => array( 'type' => 'object' ),
                        'ap2_cart_mandate' => array( 'type' => 'object', 'description' => 'Optional AP2 Cart Mandate to verify + attach (phase 3)' ),
                    ),
                    'required'   => array( 'session_id' ),
                ),
                'annotations' => array(
                    'title'           => 'UCP Complete Checkout',
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => false,
                ),
            ), function ( $args ) {
                $sid = (string) ( $args['session_id'] ?? '' );
                $res = LuwiPress_UCP_Checkout::get_instance()->complete_session( $sid, (array) $args );
                return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res;
            } );
        }

        // ── AP2 — Agent Payments Protocol mandate audit trail (phase 3) ──
        if ( class_exists( 'LuwiPress_AP2' ) ) {

            $this->register_tool( 'ap2_settings_get', array(
                'description' => 'Read AP2 (Agent Payments Protocol) settings — enabled, require_verification (strict mode), amount_match, issuer allowlist.',
                'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
                'annotations' => array( 'title' => 'AP2 Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function () {
                return LuwiPress_AP2::get_instance()->get_settings();
            } );

            $this->register_tool( 'ap2_settings_set', array(
                'description' => 'Update AP2 settings (partial). require_verification=true makes an unverified mandate or amount mismatch abort checkout completion (strict mode).',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'enabled'              => array( 'type' => 'boolean' ),
                        'require_verification' => array( 'type' => 'boolean', 'description' => 'Strict: abort on unverified / amount mismatch' ),
                        'amount_match'         => array( 'type' => 'boolean', 'description' => 'Enforce Cart Mandate total == order total' ),
                        'issuer_jwks_url'      => array( 'type' => 'string', 'description' => 'Optional JWKS URL for a future signature verifier' ),
                        'allowed_issuers'      => array( 'type' => 'string', 'description' => 'Comma-separated issuer allowlist' ),
                    ),
                ),
                'annotations' => array( 'title' => 'Update AP2 Settings', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) {
                return LuwiPress_AP2::get_instance()->save_settings( (array) $args );
            } );

            $this->register_tool( 'ap2_mandate_verify', array(
                'description' => 'Diagnostic: verify a mandate object (structure, expiry, issuer allowlist, pluggable signature check) and extract its committed amount + currency. Does not mutate anything.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'mandate' => array( 'type' => 'object', 'description' => 'The mandate (Cart or Intent) to inspect' ),
                        'context' => array( 'type' => 'object', 'description' => 'Optional {kind, order_id}' ),
                    ),
                    'required'   => array( 'mandate' ),
                ),
                'annotations' => array( 'title' => 'AP2 Verify Mandate', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) {
                $mandate = $args['mandate'] ?? null;
                if ( empty( $mandate ) ) {
                    return array( 'error' => 'mandate is required' );
                }
                $ctx     = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array();
                $verdict = LuwiPress_AP2::get_instance()->verify_mandate( $mandate, $ctx );
                list( $amount, $currency ) = LuwiPress_AP2::get_instance()->extract_mandate_amount( $mandate );
                $verdict['extracted_amount']   = $amount;
                $verdict['extracted_currency'] = $currency;
                return $verdict;
            } );

            $this->register_tool( 'ap2_transaction_get', array(
                'description' => 'Read the AP2 mandate chain (Intent → Cart), verification verdict, and amount-match for a WooCommerce order.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array( 'order_id' => array( 'type' => 'integer' ) ),
                    'required'   => array( 'order_id' ),
                ),
                'annotations' => array( 'title' => 'AP2 Transaction', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) {
                $res = LuwiPress_AP2::get_instance()->get_transaction( absint( $args['order_id'] ?? 0 ) );
                return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res;
            } );

            $this->register_tool( 'ap2_log_recent', array(
                'description' => 'Recent AP2 mandate verification verdicts (order_id, status, issuer, amount_match) for monitoring.',
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array( 'limit' => array( 'type' => 'integer', 'description' => 'Max entries (default 50, max 100)' ) ),
                ),
                'annotations' => array( 'title' => 'AP2 Audit Log', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) {
                return array( 'entries' => LuwiPress_AP2::get_instance()->get_log( absint( $args['limit'] ?? 50 ) ?: 50 ) );
            } );
        }
    }

    /* ───────────────────── Vendor Tools (3.5.2+) ─────────────────────── */

    private function register_vendors_tools() {
        if ( ! class_exists( 'LuwiPress_Vendors' ) ) {
            return;
        }

        $this->register_tool( 'vendor_settings_get', array(
            'description' => 'Read the Vendors (CPT) module configuration — archive slug, singular/plural labels, with_front toggle, profile field visibility, social-link field toggles, and legacy redirect pairs. Generic across verticals: rename to "Luthier"/"Chef"/"Artist"/"Team"/etc. per site.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Vendor Settings Read', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            return array(
                'settings'  => LuwiPress_Vendors::get_all_settings(),
                'post_type' => LuwiPress_Vendors::POST_TYPE,
                'count'     => (int) ( wp_count_posts( LuwiPress_Vendors::POST_TYPE )->publish ?? 0 ),
            );
        } );

        $this->register_tool( 'vendor_settings_set', array(
            'description' => 'Update Vendors module settings (partial — only keys present in payload are written). Changing archive_slug or with_front automatically re-registers the CPT and flushes rewrite rules. legacy_redirects accepts an array of {from,to} pairs (e.g. [{"from":"/masters/","to":"/luthiers/"}]) — supports exact + prefix-with-tail matching at template_redirect.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'enabled'             => array( 'type' => 'integer' ),
                    'archive_slug'        => array( 'type' => 'string', 'description' => 'URL slug for the CPT archive — luthiers / chefs / artists / etc.' ),
                    'singular_label'      => array( 'type' => 'string' ),
                    'plural_label'        => array( 'type' => 'string' ),
                    'menu_icon'           => array( 'type' => 'string', 'description' => 'Dashicons class, e.g. dashicons-store' ),
                    'entity_type'         => array( 'type' => 'string', 'description' => 'Schema.org entity: organization | person | localbusiness' ),
                    'default_occupation'  => array( 'type' => 'string' ),
                    'with_front'          => array( 'type' => 'integer' ),
                    'archive_enabled'     => array( 'type' => 'integer' ),
                    'show_location'       => array( 'type' => 'integer' ),
                    'show_specialty'      => array( 'type' => 'integer' ),
                    'show_years'          => array( 'type' => 'integer' ),
                    'show_quote'          => array( 'type' => 'integer' ),
                    'social_facebook'     => array( 'type' => 'integer' ),
                    'social_instagram'    => array( 'type' => 'integer' ),
                    'social_youtube'      => array( 'type' => 'integer' ),
                    'social_soundcloud'   => array( 'type' => 'integer' ),
                    'social_linkedin'     => array( 'type' => 'integer' ),
                    'social_x'            => array( 'type' => 'integer' ),
                    'social_behance'      => array( 'type' => 'integer' ),
                    'social_website'      => array( 'type' => 'integer' ),
                    'legacy_redirects'    => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'from' => array( 'type' => 'string', 'description' => 'Old path, e.g. /masters/' ),
                                'to'   => array( 'type' => 'string', 'description' => 'New URL or path, e.g. /luthiers/' ),
                            ),
                        ),
                        'description' => 'Legacy URL redirect pairs (301)',
                    ),
                ),
            ),
            'annotations' => array( 'title' => 'Vendor Settings Write', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/vendors/settings' );
            $request->set_body_params( $args );
            $resp = LuwiPress_Vendors::get_instance()->rest_update_settings( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'vendor_list', array(
            'description' => 'List all Vendor (CPT) entries with their profile meta (location, specialty, years, social links) and any attached vendor_group terms. Useful for confirming what is published before generating an /<archive>/ index page or wiring the vendor-grid widget. Filter by group with the `group` arg to scope the list to one vertical (e.g. "team" vs "luthiers" on the same site).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit'   => array( 'type' => 'integer', 'description' => 'Max entries (default 50, max 200)' ),
                    'orderby' => array( 'type' => 'string',  'description' => 'menu_order | date | title (default menu_order)' ),
                    'order'   => array( 'type' => 'string',  'description' => 'ASC | DESC (default ASC)' ),
                    'group'   => array( 'type' => 'string',  'description' => 'Vendor group slug, term ID, or comma-separated list (e.g. "team" or "luthiers,team"). Omit to return every vendor regardless of group.' ),
                ),
            ),
            'annotations' => array( 'title' => 'Vendor List', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/vendors' );
            $request->set_query_params( $args );
            $resp = LuwiPress_Vendors::get_instance()->rest_list( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'vendor_get', array(
            'description' => 'Read one Vendor entry — full profile, all meta, and the auto-built Schema.org Person / Organization / LocalBusiness JSON-LD preview (with sameAs verified social URLs).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id' => array( 'type' => 'integer', 'description' => 'Vendor post ID' ),
                ),
                'required' => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Vendor Get', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/vendors/' . absint( $args['id'] ) );
            $request->set_url_params( array( 'id' => absint( $args['id'] ) ) );
            $resp = LuwiPress_Vendors::get_instance()->rest_get_one( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'vendor_meta_set', array(
            'description' => 'Update profile meta on a single Vendor — location, specialty, years_active, social URLs (facebook, instagram, youtube, soundcloud, linkedin, x, behance, website), quote, occupation. Partial: only keys present in payload are written. URLs sanitized via esc_url_raw; quote runs through wp_kses_post.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array( 'type' => 'integer' ),
                    'location'   => array( 'type' => 'string' ),
                    'occupation' => array( 'type' => 'string' ),
                    'specialty'  => array( 'type' => 'string' ),
                    'years'      => array( 'type' => 'integer' ),
                    'quote'      => array( 'type' => 'string' ),
                    'facebook'   => array( 'type' => 'string' ),
                    'instagram'  => array( 'type' => 'string' ),
                    'youtube'    => array( 'type' => 'string' ),
                    'soundcloud' => array( 'type' => 'string' ),
                    'linkedin'   => array( 'type' => 'string' ),
                    'x'          => array( 'type' => 'string' ),
                    'behance'    => array( 'type' => 'string' ),
                    'website'    => array( 'type' => 'string' ),
                ),
                'required' => array( 'id' ),
            ),
            'annotations' => array( 'title' => 'Vendor Meta Write', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = absint( $args['id'] );
            unset( $args['id'] );
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/vendors/' . $id . '/meta' );
            $request->set_url_params( array( 'id' => $id ) );
            $request->set_body_params( $args );
            $resp = LuwiPress_Vendors::get_instance()->rest_set_meta( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'vendor_flush_rewrite', array(
            'description' => 'Manually flush WP rewrite rules after changing the Vendors archive slug. Normally handled automatically on settings change — use this only when a URL is stuck on the old slug.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Vendor Rewrite Flush', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $resp = LuwiPress_Vendors::get_instance()->rest_flush_rewrite();
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );
    }

    /**
     * Tour booking tools (3.15.0+). Flag and configure WooCommerce products as
     * bookable tours remotely — pax range (guests, not quantity), duration,
     * time slots, add-ons, pickup, cancellation. Proxies to LuwiPress_Booking
     * so validation lives in one place. WC-gated (the core class itself returns
     * 503 when WooCommerce is inactive).
     */
    private function register_booking_tools() {
        if ( ! class_exists( 'LuwiPress_Booking' ) ) {
            return;
        }

        $this->register_tool( 'booking_list_tours', array(
            'description' => 'List bookable tours — WooCommerce products flagged as tours — with their normalized booking config (per-person price, duration, pax min/max/default, time slots, add-ons, pickup, cancellation). Pass all=true to include every product (discovery: find products that should be flagged). Use before configuring to see current state.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'all'   => array( 'type' => 'boolean', 'description' => 'Include non-tour products too (default false)' ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Max products (default 100, max 500)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Booking List Tours', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/booking/tours' );
            $request->set_query_params( is_array( $args ) ? $args : array() );
            $resp = LuwiPress_Booking::get_instance()->rest_list_tours( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'booking_get_tour', array(
            'description' => 'Read one product\'s tour booking config plus a TouristTrip JSON-LD preview. Returns is_tour flag, per-person price, duration + bucket, pax range, time slots, add-ons, pickup, deposit, cancellation.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'WooCommerce product ID' ),
                ),
                'required' => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Booking Get Tour', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = absint( $args['product_id'] );
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/booking/tour/' . $id );
            $request->set_url_params( array( 'id' => $id ) );
            $resp = LuwiPress_Booking::get_instance()->rest_get_tour( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'booking_configure_tour', array(
            'description' => 'Flag a WooCommerce product as a bookable tour and/or set its booking fields. Partial — only keys present are written. Pax is GUESTS (person count), not WooCommerce quantity: the per-person price (the product price) is multiplied by pax at checkout while WC quantity stays 1. time_slots is a list of strings ("Morning","Sunset"); addons is a list of {label, price} objects (price is per person). duration_bucket must be short|half|full|multi (or empty for auto).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'      => array( 'type' => 'integer', 'description' => 'WooCommerce product ID' ),
                    'is_tour'         => array( 'type' => 'boolean', 'description' => 'Mark/unmark as a bookable tour' ),
                    'duration'        => array( 'type' => 'string',  'description' => 'Human label, e.g. "6 hours" / "3 days"' ),
                    'duration_bucket' => array( 'type' => 'string',  'description' => 'short (<=3h) | half (3-6h) | full (1 day) | multi (2+ days) | "" (auto)' ),
                    'pax_min'         => array( 'type' => 'integer', 'description' => 'Minimum guests (>=1)' ),
                    'pax_max'         => array( 'type' => 'integer', 'description' => 'Maximum guests' ),
                    'pax_default'     => array( 'type' => 'integer', 'description' => 'Default guests (clamped into min..max)' ),
                    'pickup_included' => array( 'type' => 'boolean', 'description' => 'Hotel pickup included' ),
                    'deposit_pct'     => array( 'type' => 'integer', 'description' => 'Deposit percentage 0..100' ),
                    'cancellation'    => array( 'type' => 'string',  'description' => 'Cancellation policy note' ),
                    'time_slots'      => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Selectable time slots, e.g. ["Morning","Afternoon","Sunset"]',
                    ),
                    'addons'          => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'label' => array( 'type' => 'string' ),
                                'price' => array( 'type' => 'number', 'description' => 'Per-person price' ),
                            ),
                        ),
                        'description' => 'Optional extras, e.g. [{"label":"Private guide","price":120}]',
                    ),
                ),
                'required' => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Booking Configure Tour', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = absint( $args['product_id'] );
            unset( $args['product_id'] );
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/booking/tour/' . $id );
            $request->set_url_params( array( 'id' => $id ) );
            $request->set_body_params( is_array( $args ) ? $args : array() );
            $resp = LuwiPress_Booking::get_instance()->rest_set_tour( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'booking_settings_get', array(
            'description' => 'Read the Booking module defaults (default pax range, default pickup, default cancellation note, default time slots and add-ons) applied as a baseline when flagging a tour.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Booking Settings Read', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/booking/settings' );
            $resp = LuwiPress_Booking::get_instance()->rest_get_settings( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );

        $this->register_tool( 'booking_settings_set', array(
            'description' => 'Update Booking module defaults (partial — only keys present are written).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'default_pax_min'      => array( 'type' => 'integer' ),
                    'default_pax_max'      => array( 'type' => 'integer' ),
                    'default_pax_default'  => array( 'type' => 'integer' ),
                    'default_pickup'       => array( 'type' => 'boolean' ),
                    'default_cancellation' => array( 'type' => 'string' ),
                    'default_time_slots'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'default_addons'       => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'label' => array( 'type' => 'string' ),
                                'price' => array( 'type' => 'number' ),
                            ),
                        ),
                    ),
                ),
            ),
            'annotations' => array( 'title' => 'Booking Settings Write', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/booking/settings' );
            $request->set_body_params( is_array( $args ) ? $args : array() );
            $resp = LuwiPress_Booking::get_instance()->rest_set_settings( $request );
            return $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
        } );
    }

    /**
     * Check if WebMCP is enabled via settings.
     */
    public static function is_enabled() {
        return (bool) get_option( 'luwipress_webmcp_enabled', true );
    }
}
