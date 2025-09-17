<?php
/*
Plugin Name: Sregle WhatsApp Bot
Description: WhatsApp bot for vtupress website connect through vprest API — Login/Register/Logout, Balance, Airtime & Data, cached services via Admin API.
Plugin URI: https://github.com/Sregle/sregle-whatsapp-bot
Version: 4.4.0
Author: Sregle Dev Team
Author URI: https://github.com/Sregle
*/

if (! defined('ABSPATH')) exit;

global $wpdb;

/* ---------------------------
  Constants / option names
----------------------------*/
define('SREGLE_BOT_OPTION_PREFIX','sregle_bot_');
define('SREGLE_BOT_OPTION_SERVICES','sregle_bot_services_cache');
define('SREGLE_BOT_OPTION_ADMIN_ID','sregle_bot_admin_id');
define('SREGLE_BOT_OPTION_ADMIN_APIKEY','sregle_bot_admin_apikey');
define('SREGLE_BOT_OPTION_WEBHOOK_KEY','sregle_bot_webhook_key');
define('SREGLE_BOT_OPTION_CMD_PREFIX','sregle_bot_cmd_prefix');
define('SREGLE_GITHUB_REPO', 'sregle/sregle-whatsapp-bot'); // change to your repo
define('SREGLE_PLUGIN_FILE', plugin_basename(__FILE__));


// GitHub Plugin Updater for Sregle WhatsApp Bot
add_filter('pre_set_site_transient_update_plugins', 'sregle_check_for_update');
function sregle_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = SREGLE_PLUGIN_FILE;
    $current_version = get_plugin_data(__FILE__)['Version'];

    // Fetch latest release from GitHub API
    $request = wp_remote_get("https://api.github.com/repos/" . SREGLE_GITHUB_REPO . "/releases/latest");

    if (is_wp_error($request)) {
        return $transient;
    }

    $release = json_decode(wp_remote_retrieve_body($request));
    if (empty($release->tag_name)) {
        return $transient;
    }

    $remote_version = ltrim($release->tag_name, 'v'); // tag v4.3.1 → 4.3.1
    $zip_url = $release->assets[0]->browser_download_url ?? $release->zipball_url;

    if (version_compare($current_version, $remote_version, '<')) {
        $obj = new stdClass();
        $obj->slug = dirname($plugin_file);
        $obj->plugin = $plugin_file;
        $obj->new_version = $remote_version;
        $obj->url = $release->html_url; // GitHub release page
        $obj->package = $zip_url;       // Download link
        $transient->response[$plugin_file] = $obj;
    }

    return $transient;
}

// Add "View details" popup with changelog
add_filter('plugins_api', 'sregle_plugin_info', 10, 3);
function sregle_plugin_info($res, $action, $args) {
    if ($action !== 'plugin_information') return $res;
    if ($args->slug !== dirname(SREGLE_PLUGIN_FILE)) return $res;

    $request = wp_remote_get("https://api.github.com/repos/" . SREGLE_GITHUB_REPO . "/releases/latest");
    if (is_wp_error($request)) return $res;

    $release = json_decode(wp_remote_retrieve_body($request));
    if (empty($release->tag_name)) return $res;

    $remote_version = ltrim($release->tag_name, 'v');
    $zip_url = $release->assets[0]->browser_download_url ?? $release->zipball_url;

    return (object) [
        'name'        => 'Sregle WhatsApp Bot',
        'slug'        => dirname(SREGLE_PLUGIN_FILE),
        'version'     => $remote_version,
        'author'      => '<a href="https://sregle.com">Sregle Dev Team</a>',
        'homepage'    => $release->html_url,
        'download_link' => $zip_url,
        'sections'    => [
            'description' => 'WhatsApp bot for VtuPress websites using vprest API.',
            'changelog'   => $release->body ?? 'No changelog provided.',
        ],
    ];
}


/* =========================================================
   ADMIN MENU (top-level: Sregle Bot)
   Settings + Fetch Services button
=========================================================*/
add_action('admin_menu', function() {
    add_menu_page(
        'Sregle Bot',
        'Sregle Bot',
        'manage_options',
        'sregle-bot',
        'sregle_bot_admin_page',
        'dashicons-whatsapp',
        60
    );
    // Submenu: Manual Plans (add/edit/delete data & cable plans)
    add_submenu_page(
        'sregle-bot',
        'Manual Plans',
        'Manual Plans',
        'manage_options',
        'sregle-bot-manual-plans',
        'sregle_bot_manual_plans_page'
    );

});

function sregle_bot_admin_page() {
    if (!current_user_can('manage_options')) wp_die('No permission');

    // Handle save
    if (isset($_POST['sregle_bot_save_settings']) && check_admin_referer('sregle_bot_save','sregle_bot_nonce')) {
        update_option(SREGLE_BOT_OPTION_WEBHOOK_KEY, sanitize_text_field($_POST['webhook_key'] ?? ''));
        update_option(SREGLE_BOT_OPTION_ADMIN_ID, sanitize_text_field($_POST['admin_id'] ?? ''));
        update_option(SREGLE_BOT_OPTION_ADMIN_APIKEY, sanitize_text_field($_POST['admin_apikey'] ?? ''));
        update_option(SREGLE_BOT_OPTION_CMD_PREFIX, sanitize_text_field($_POST['cmd_prefix'] ?? ''));
        update_option('sregle_bot_vprest_base_url', esc_url_raw($_POST['vprest_base_url'] ?? ''));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Handle fetch services button
    if (isset($_POST['sregle_bot_fetch_services']) && check_admin_referer('sregle_bot_fetch','sregle_bot_fetch_nonce')) {
        $res = sregle_bot_admin_fetch_services();
        if (is_wp_error($res)) {
            echo '<div class="error"><p>Fetch failed: ' . esc_html($res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="updated"><p>Services fetched and cached successfully.</p></div>';
        }
    }

    $webhook_key   = esc_attr(get_option(SREGLE_BOT_OPTION_WEBHOOK_KEY,''));
    $admin_id      = esc_attr(get_option(SREGLE_BOT_OPTION_ADMIN_ID,''));
    $admin_apikey  = esc_attr(get_option(SREGLE_BOT_OPTION_ADMIN_APIKEY,''));
    $cmd_prefix    = esc_attr(get_option(SREGLE_BOT_OPTION_CMD_PREFIX,'sreg'));

    // Auto-detect base URL if empty
    $vprest_base   = esc_attr(get_option('sregle_bot_vprest_base_url',''));
    if (empty($vprest_base)) {
        $vprest_base = site_url('/wp-content/plugins/vprest/');
    }

    $cache = get_option(SREGLE_BOT_OPTION_SERVICES, false);
    $cache_time = $cache && isset($cache['fetched_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), intval($cache['fetched_at'])) : 'Never';

    ?>
    <div class="wrap">
        <h1>Sregle Bot Settings</h1>

        <form method="post">
            <?php wp_nonce_field('sregle_bot_save','sregle_bot_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Webhook Key (for your Auto Reply app)</th>
                    <td><input name="webhook_key" value="<?php echo $webhook_key; ?>" class="regular-text" />
                    <p class="description">Your webhook must send an HTTP header: <code>Authorization: &lt;Webhook Key&gt;</code></p>
                    <p>Webhook endpoint is fixed: <code><?php echo esc_html(rest_url('sregle-bot/v1/webhook')); ?></code></p>
                    </td>
                </tr>
                <tr>
                    <th>Admin ID</th>
                    <td><input name="admin_id" value="<?php echo $admin_id; ?>" class="regular-text" />
                    <p class="description">Used to fetch services.</p>
                    </td>
                </tr>
                <tr>
                    <th>Admin API Key</th>
                    <td><input name="admin_apikey" value="<?php echo $admin_apikey; ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Command Prefix (optional)</th>
                    <td><input name="cmd_prefix" value="<?php echo $cmd_prefix; ?>" class="regular-text" />
                    <p class="description">If users start messages with this prefix (e.g. "sreg airtime"), it will be stripped automatically.</p>
                    </td>
                </tr>
                <tr>
                    <th>API Base URL</th>
                    <td>
                        <input name="vprest_base_url" value="<?php echo $vprest_base; ?>" class="regular-text" />
                        <p class="description">Default (auto): <code><?php echo site_url('/wp-content/plugins/vprest/'); ?></code>. Only change if your domain or plugin folder is different.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings','primary','sregle_bot_save_settings'); ?>
        </form>

        <h2>Services cache</h2>
        <p>Last fetched: <strong><?php echo esc_html($cache_time); ?></strong></p>
        <form method="post">
            <?php wp_nonce_field('sregle_bot_fetch','sregle_bot_fetch_nonce'); ?>
            <?php submit_button('Fetch/Refresh Services from Admin API','secondary','sregle_bot_fetch_services'); ?>
        </form>

        <h3>Notes</h3>
        <ul>
            <li>The bot uses per-user vtupress API keys when available. If a user has no API key, the Admin ID & API key above will be used (fallback).</li>
            <li>If a user has no funding account details set in their profile, the bot will instruct them to log in to the website and generate funding account details.</li>
        </ul>
    </div>
    <?php
}

/* =========================================================
   REST endpoint (fixed)
=========================================================*/
add_action('rest_api_init', function() {
    register_rest_route('sregle-bot/v1','/webhook', array(
        'methods' => 'POST',
        'callback' => 'sregle_bot_handle_webhook',
        'permission_callback' => '__return_true'
    ));
});

/* =========================================================
   License Manager
=========================================================*/
class Sregle_License_Manager {

    private $option_key     = 'sregle_license_key';
    private $status_key     = 'sregle_license_status';
    private $last_check_key = 'sregle_license_last_check';
    private $expiry_key     = 'sregle_license_expiry';
    private $server_url     = 'https://sreg.sregle.com/wp-json/wplm/v1/check'; // CHANGE THIS IF NEEDED

    public function __construct() {
        // Admin
        add_action('admin_menu', [$this, 'add_license_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_validate_license']);
        add_action('update_option_' . $this->option_key, [$this, 'validate_license'], 10, 2);

        // Enforce restrictions when plugins are loaded
        add_action('plugins_loaded', [$this, 'enforce_license']);
    }

    public function add_license_page() {
        // Add submenu under sregle-bot
        add_submenu_page(
            'sregle-bot',
            'Plugin License',
            'Plugin License',
            'manage_options',
            'sregle-license',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        $status = get_option($this->status_key, 'unknown');
        $expiry = get_option($this->expiry_key, 'N/A');
        ?>
        <div class="wrap">
            <h1>Plugin License</h1>
            <form method="post" action="options.php">
                <?php settings_fields('sregle_license_group'); ?>
                <?php do_settings_sections('sregle_license_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">License Key</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_key); ?>"
                                   value="<?php echo esc_attr(get_option($this->option_key)); ?>"
                                   style="width:300px;">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save License'); ?>
            </form>
            <p><strong>Status:</strong> <?php echo esc_html(ucfirst($status)); ?></p>
            <p><strong>Expiry:</strong> <?php echo esc_html($expiry); ?></p>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('sregle_license_group', $this->option_key);
    }

    public function maybe_validate_license() {
        $last_check = get_option($this->last_check_key);
        if ($last_check && (time() - $last_check) < DAY_IN_SECONDS) return;
        $this->validate_license();
    }

    public function validate_license() {
        $key = get_option($this->option_key);
        if (!$key) {
            update_option($this->status_key, 'missing');
            return false;
        }

        $response = wp_remote_post($this->server_url, [
            'timeout' => 15,
            'body' => [
                'license_key' => $key,
                'site_url'    => home_url(),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]
        ]);

        if (is_wp_error($response)) {
            update_option($this->status_key, 'error');
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['success'])) {
            update_option($this->status_key, 'valid');
            update_option($this->expiry_key, $data['expiry'] ?? 'Unknown');
            update_option($this->last_check_key, time());
            return true;
        } else {
            update_option($this->status_key, 'invalid');
            update_option($this->expiry_key, 'Expired/Revoked');
            update_option($this->last_check_key, time());
            return false;
        }
    }

    public function enforce_license() {
        $status = get_option($this->status_key);
        if ($status !== 'valid') {
            add_action('admin_notices', function() {
                echo '<div class="error"><p><strong>'
                    . esc_html__('License inactive: Please activate a valid license to use Sregle WhatsApp Bot.', 'sregle')
                    . '</strong></p></div>';
            });

            // Hide all bot submenus except License
            add_action('admin_menu', function() {
                global $submenu;
                if (isset($submenu['sregle-bot'])) {
                    foreach ($submenu['sregle-bot'] as $index => $item) {
                        if (isset($item[2]) && strtolower($item[2]) !== 'sregle-license') {
                            unset($submenu['sregle-bot'][$index]);
                        }
                    }
                }
            }, 999);

            // Block bot features
            add_action('init', function() {
                remove_all_actions('init');
                if (!defined('SREGL_BOT_BLOCKED')) define('SREGL_BOT_BLOCKED', true);
            }, 0);

            // Block REST API webhook
            add_filter('rest_pre_dispatch', function($result, $server, $request) {
                $route = $request->get_route();
                if (strpos($route, '/sregle-bot/v1/webhook') !== false) {
                    return new WP_Error(
                        'sregle_license_invalid',
                        __('Webhook blocked: License is not active.', 'sregle'),
                        ['status' => 403]
                    );
                }
                return $result;
            }, 10, 3);
        }
    }
} // end class Sregle_License_Manager
// Boot license manager
new Sregle_License_Manager();
/* =========================================================
   Helpers: formatting, sessions, phone normalization
=========================================================*/
function sregle_bot_response($message) {
    return array('data' => array(array('message' => $message)));
}

function sregle_bot_normalize_phone($p) {
    $p = trim((string)$p);
    $p = preg_replace('/[^0-9+]/','',$p);
    return $p;
}

function sregle_bot_session_key($phone) {
    return SREGLE_BOT_OPTION_PREFIX . 'session_' . md5($phone);
}
function sregle_bot_set_session($phone, $session) {
    update_option(sregle_bot_session_key($phone), $session, false);
}
function sregle_bot_get_session($phone) {
    return get_option(sregle_bot_session_key($phone), false);
}
function sregle_bot_clear_session($phone) {
    delete_option(sregle_bot_session_key($phone));
}

/* strip optional prefix like "sreg airtime" */
function sregle_bot_strip_prefix($text) {
    $prefix = trim((string)get_option(SREGLE_BOT_OPTION_CMD_PREFIX,'sreg'));
    if ($prefix === '') return trim($text);
    $lc = ltrim(strtolower($text));
    $p = strtolower($prefix);
    $variants = array($p.' ', $p.':', $p.',' , '@'.$p.' ');
    foreach ($variants as $v) {
        if (strpos($lc, $v) === 0) {
            return trim(substr($text, strlen($v)));
        }
    }
    if ($lc === $p) return '';
    return trim($text);
}

/* =========================================================
   Load / normalize vp_user_data
   (handles JSON or serialized string)
=========================================================*/
function sregle_bot_load_vp($user_id) {
    $raw = get_user_meta($user_id, 'vp_user_data', true);
    $vp = null;

    if (!empty($raw)) {
        if (is_string($raw)) {
            $try = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($try)) $vp = $try;
            else {
                $try2 = maybe_unserialize($raw);
                if (is_array($try2)) $vp = $try2;
            }
        } elseif (is_array($raw)) $vp = $raw;
    }
    if (!is_array($vp)) $vp = array();

    // normalize keys
    $vp['vp_bal'] = isset($vp['vp_bal']) ? $vp['vp_bal'] : (isset($vp['balance']) ? $vp['balance'] : 0);
    $vp['vp_pin'] = isset($vp['vp_pin']) ? $vp['vp_pin'] : (isset($vp['pin']) ? $vp['pin'] : '');
    $vp['paymentpoint_accountnumber'] = $vp['paymentpoint_accountnumber'] ?? '';
    $vp['paymentpoint_accountname']   = $vp['paymentpoint_accountname'] ?? '';
    $vp['paymentpoint_bankname']      = $vp['paymentpoint_bankname'] ?? 'Palmpay';
    $vp['account_number2'] = $vp['account_number2'] ?? '';
    $vp['account_name2'] = $vp['account_name2'] ?? '';
    $vp['bank_name2'] = $vp['bank_name2'] ?? 'Palmpay';

    // save normalized back as json for consistency
    update_user_meta($user_id, 'vp_user_data', wp_json_encode($vp));
    return $vp;
}

function sregle_bot_save_vp_balance($user_id, $new_balance) {
    $vp = sregle_bot_load_vp($user_id);
    $vp['vp_bal'] = $new_balance;
    update_user_meta($user_id, 'vp_user_data', wp_json_encode($vp));
}

/**
 * Load vp_user_data JSON for a user and extract vr_id as API key.
 */
function sregle_bot_get_vtupress_row($user_id) {
    // Fetch vp_user_data from usermeta
    $vp_data = get_user_meta($user_id, 'vp_user_data', true);
    if (empty($vp_data)) {
        return false;
    }

    // vp_user_data might be JSON or serialized array
    if (is_string($vp_data)) {
        $decoded = json_decode($vp_data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $vp_data = $decoded;
        } else {
            // maybe serialized
            $maybe = @unserialize($vp_data);
            if ($maybe !== false && is_array($maybe)) {
                $vp_data = $maybe;
            }
        }
    }

    if (!is_array($vp_data)) {
        return false;
    }

    // vr_id will act as the API key
    if (!empty($vp_data['vr_id'])) {
        return array(
            'id'     => $user_id,
            'apikey' => sanitize_text_field($vp_data['vr_id']),
            'source' => 'usermeta'
        );
    }

    return false;
}

/**
 * Get credentials for API actor (user only).
 * - Returns error array if missing
 */
function sregle_bot_get_actor_credentials($user_id) {
    $row = sregle_bot_get_vtupress_row($user_id);

    if ($row && !empty($row['apikey'])) {
        return array(
            'id'     => intval($row['id']),
            'apikey' => $row['apikey'],
            'source' => 'user'
        );
    }

    // Friendly error for missing vr_id
    return array(
        'error' => true,
        'message' => "⚠️ You don’t have an API key linked. Please register or update your account before making a purchase."
    );
}


/* =========================================================
   vprest call utility (auto-detect base if empty)
=========================================================*/
function sregle_bot_vprest_call($args) {
    $base = get_option('sregle_bot_vprest_base_url','');
    if (empty($base)) {
        $base = site_url('/wp-content/plugins/vprest/');
    }
    $url  = add_query_arg($args, $base);

    $resp = wp_remote_get($url, array('timeout' => 120));
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    return array('code' => $code, 'raw' => $body, 'json' => $json);
}

/* =========================================================
   PIN check: vp_user_data.vp_pin (plain) OR transaction_pin (hashed)
=========================================================*/
function sregle_bot_check_pin($user_id, $pin) {
    $vp = sregle_bot_load_vp($user_id);
    $stored_plain = $vp['vp_pin'] ?? '';
    $stored_hashed = get_user_meta($user_id, 'transaction_pin', true);

    if (!empty($stored_plain) && hash_equals((string)$stored_plain, (string)$pin)) return true;
    if (!empty($stored_hashed) && wp_check_password($pin, $stored_hashed, $user_id)) return true;
    return false;
}

/* =========================================================
   UI helper texts
=========================================================*/
function sregle_bot_main_menu_text() {
    return "Main Menu\n"
         . "1) Airtime\n"
         . "2) Data\n"
         . "3) Cable TV\n"
         . "4) Electricity Bills\n"
         . "5) Check Balance\n"
         . "6) Funding\n"
         . "#) Logout\n\n"
         . "Reply with a number or type the command (airtime, data, cable, bill, balance, funding, logout).";
}

function sregle_bot_funding_text($vp) {
    $lines = array();
    $main_name = trim($vp['paymentpoint_accountname']);
    $main_no   = trim($vp['paymentpoint_accountnumber']);
    $main_bank = trim($vp['paymentpoint_bankname']);

    $alt_name  = trim($vp['account_name2']);
    $alt_no    = trim($vp['account_number2']);
    $alt_bank  = trim($vp['bank_name2']);

    if ($main_no !== '') $lines[] = "🏦 Main: {$main_name} | {$main_no} | " . ($main_bank ?: 'Palmpay');
    if ($alt_no !== '') $lines[]  = "🔁 Alt: {$alt_name} | {$alt_no} | " . ($alt_bank ?: 'Palmpay');
    if (empty($lines)) return "⚠️ Please login to the website to generate your funding account.";
    return implode("\n", $lines);
}

/* =========================================================
   Admin: Fetch services from vprest (Admin ID & API key)
   - Uses custom base URL from settings (fallback: current site)
   - Tries multiple query options for flexibility
   - Stores result in option SREGLE_BOT_OPTION_SERVICES
=========================================================*/
function sregle_bot_admin_fetch_services() {
    $admin_id     = get_option(SREGLE_BOT_OPTION_ADMIN_ID, '');
    $admin_apikey = get_option(SREGLE_BOT_OPTION_ADMIN_APIKEY, '');

    if (empty($admin_id) || empty($admin_apikey)) {
        return new WP_Error(
            'missing_admin',
            'Please set Admin ID and Admin API Key in Sregle Bot settings before fetching services.'
        );
    }

    // Get custom vprest base URL from settings (fallback to site)
    $custom_base = get_option('sregle_vprest_base_url', '');
    if (!empty($custom_base)) {
        $base = trailingslashit($custom_base);
    } else {
        $site_url = get_site_url();
        $base     = trailingslashit($site_url) . 'wp-content/plugins/vprest/';
    }

    $attempts = array(
        array('q' => 'services'),
        array('q' => 'list'),
        array('q' => 'all_services'),
        array('q' => 'products'),
        array('q' => 'pricing'),
    );

    $found    = false;
    $services = array();

    foreach ($attempts as $a) {
        $args = array_merge(array('id' => $admin_id, 'apikey' => $admin_apikey), $a);
        $url  = add_query_arg($args, $base);

        $resp = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($resp)) continue;

        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if (is_array($json) && !empty($json)) {
            // Heuristics: look for keys that look like services or plans
            if (isset($json['services']) || isset($json['data']) || isset($json['plans']) || isset($json['product'])) {
                $services = $json;
                $found    = 'api';
                break;
            }

            // Some API return associative array
            if (array_values($json) !== $json) {
                $services = $json;
                $found    = 'api';
                break;
            }

            // Some return numeric array with service details
            if (!empty($json[0]) && (isset($json[0]['name']) || isset($json[0]['id']) || isset($json[0]['price']))) {
                $services = $json;
                $found    = 'api';
                break;
            }
        }
    }

    if ($found) {
        update_option(SREGLE_BOT_OPTION_SERVICES, array(
            'fetched_at' => time(),
            'by'         => $found,
            'services'   => $services
        ));
    }

    return $found ? $services : new WP_Error('no_services', 'No services could be fetched from vprest.');


    // If none found from API, fallback to embedded service lists (derived from your docs)
    if (! $found) {
        $services = sregle_bot_embedded_services();
        $found = 'embedded';
    }

    $cache = array(
        'fetched_at' => time(),
        'by' => $found,
        'services' => $services
    );

    update_option(SREGLE_BOT_OPTION_SERVICES, $cache);
    return true;
 } 
/* Embedded fallback services (structured)
   This is used only if admin API doesn't return usable services list.
   The format: services => array('data'=>[plan objects], 'cable'=>[...], 'airtime_networks'=>[...] , 'bill_providers'=>[...] )
*/
function sregle_bot_embedded_services() {
    // Minimal structured representation (plan_id, name, amount, type/network)
    $data_plans = array(
        // SME sample from docs (id => [name, price])
        array('id'=>1,'type'=>'SME','network'=>'MTN','name'=>'MTN SME 500MB','amount'=>370),
        array('id'=>2,'type'=>'SME','network'=>'MTN','name'=>'MTN SME 1GB','amount'=>620),
        array('id'=>3,'type'=>'SME','network'=>'MTN','name'=>'MTN SME 2GB','amount'=>1240),
        // ... add other plans if you want; this is fallback only
    );

    $cable_plans = array(
        array('id'=>1,'provider'=>'gotv','name'=>'GOTV MAX','amount'=>8500),
        array('id'=>5,'provider'=>'dstv','name'=>'DSTV YANGA','amount'=>6000),
        array('id'=>8,'provider'=>'dstv','name'=>'DSTV PREMIUM','amount'=>44500),
    );

    $airtime_networks = array('mtn','airtel','glo','9mobile');

    $bill_providers = array(
        array('id'=>1,'name'=>'IKEJA'),
        array('id'=>2,'name'=>'EKO'),
        array('id'=>3,'name'=>'ABUJA'),
        array('id'=>4,'name'=>'KANO'),
    );

    return array(
        'data' => $data_plans,
        'cable'=> $cable_plans,
        'airtime_networks' => $airtime_networks,
        'bills' => $bill_providers
    );
}

/* Convenience: get cached services (or an empty structure) */
function sregle_bot_get_cached_services() {
    $cache = get_option(SREGLE_BOT_OPTION_SERVICES, false);
    if (!is_array($cache) || empty($cache['services'])) {
        // try to auto-fetch if admin key exists? We'll return fallback embedded structure
        return sregle_bot_embedded_services();
    }
    return $cache['services'];
}

/* =========================================================
   Main webhook handler (state-machine)
=========================================================*/
function sregle_bot_handle_webhook(WP_REST_Request $request) {
    // check Authorization header (webhook key)
    $auth_header = $request->get_header('authorization');
    $expected = get_option(SREGLE_BOT_OPTION_WEBHOOK_KEY,'');
    if (!empty($expected) && $auth_header !== $expected) {
        return sregle_bot_response('❌ Unauthorized — invalid webhook key.');
    }

    // read body parameters from many possible auto-reply apps
    $body = $request->get_json_params();
    if (!is_array($body)) $body = $request->get_params();

    $from = '';
    $from_keys = array('senderNumber','senderPhone','senderJid','from','contact','number','msisdn','whatsapp','wa_number');
    foreach ($from_keys as $k) { if (!empty($body[$k])) { $from = $body[$k]; break; } }
    if (empty($from) && !empty($body['senderName'])) $from = $body['senderName'];
    if (empty($from)) $from = 'unknown';
    $from = sregle_bot_normalize_phone($from);

    $message = '';
    $msg_keys = array('senderMessage','message','body','msg','text','message_text','data');
    foreach ($msg_keys as $k) { if (isset($body[$k]) && $body[$k] !== '') { $message = $body[$k]; break; } }
    $message = is_string($message) ? trim($message) : '';
    $message = sregle_bot_strip_prefix($message);
    $lc = strtolower($message);

    // session
    $session = sregle_bot_get_session($from);
    if (!$session || !is_array($session)) {
        $session = array('step'=>'welcome','data'=>array());
        sregle_bot_set_session($from,$session);
        $brand = get_bloginfo('name');
        $welcome = "👋 Welcome to {$brand}!\n
                Reply:\n
                1️⃣ Login (phone + PIN)\n                                             
                2️⃣ Register\n
                3️⃣ Services\n
        Tip: you can also type: login <phone> <pin>\nType 'logout' anytime to exit.";
        return sregle_bot_response($welcome);
    }
  
  // global main menu shortcut
if ($lc === 'menu' || $lc === '0') {
    if (!empty($session['user_id'])) {
        // logged in → show logged-in main menu (dashboard)
        $user = get_userdata(intval($session['user_id']));
        if ($user) {
            $vp = sregle_bot_load_vp($user->ID);
            $bal = number_format((float)$vp['vp_bal'], 2);
            $fund_text = sregle_bot_funding_text($vp);

            $session['step'] = 'logged_in';
            sregle_bot_set_session($from, $session);

            $reply = "✅ Welcome back {$user->display_name}!\n💰 
Balance: ₦{$bal}\n
{$fund_text}\n" . 
sregle_bot_main_menu_text();

            return sregle_bot_response($reply);
        }
    }

    // not logged in → show welcome menu
    $session = array('step'=>'welcome','data'=>array());
    sregle_bot_set_session($from,$session);
    $brand = get_bloginfo('name');
    return sregle_bot_response("👋 Welcome to {$brand}!\n
Reply:\n
1️⃣ Login (phone + PIN)\n
2️⃣ Register\n
3️⃣ Services\n
Tip: type 'menu' or 0 anytime to return here.");
}

    // global logout
    if ($lc === 'logout' || $lc === '#' || $lc === 'log out') {
        if (!empty($session['user_id'])) {
            delete_user_meta(intval($session['user_id']), 'sregle_bot_logged_in');
        }
        sregle_bot_clear_session($from);
        return sregle_bot_response("✅ You have been logged out.");
    }

    // inline quick login: "login 0803... 1234"
    if (stripos($lc,'login ') === 0 && $session['step'] === 'welcome') {
        $parts = preg_split('/s+/', $message);
        if (count($parts) >= 3) {
            $phone = sregle_bot_normalize_phone($parts[1]);
            $pin = $parts[2];
            $user = sregle_bot_find_user_by_identifier($phone);
            if (!$user) return sregle_bot_response("❌ No account found for {$phone}. Reply 2 to register.");
            if (!sregle_bot_check_pin($user->ID, $pin)) return sregle_bot_response("🔑 Incorrect PIN. Reply 1 to try again.");

            update_user_meta($user->ID, 'whatsapp_number', $from);
            update_user_meta($user->ID, 'sregle_bot_logged_in', $from);

            $vp = sregle_bot_load_vp($user->ID);
            $bal = number_format((float)$vp['vp_bal'],2);
            $fund_text = sregle_bot_funding_text($vp);

            sregle_bot_set_session($from, array('step'=>'logged_in','user_id'=>$user->ID,'data'=>array()));

            $reply = "✅ Welcome back {$user->display_name}!\n💰 
            Balance: ₦{$bal}\n         
            {$fund_text}\n" . 
            sregle_bot_main_menu_text();
            return sregle_bot_response($reply);
        } else {
            return sregle_bot_response("⚠️ Usage: login <phone> <pin>nExample: login 08136187098 0167");
        }
    }

    $step = $session['step'];
    $save = function($s) use ($from){ sregle_bot_set_session($from,$s); };

    /* ---------- WELCOME ---------- */
    if ($step === 'welcome') {
        if ($lc === '1' || $lc === 'login') {
            $session['step'] = 'login_phone'; $save($session);
            return sregle_bot_response("📲 Please send your whatsapp number that you used to register on our site :");
        }
        if ($lc === '2' || $lc === 'register') {
            $session['step'] = 'reg_first'; $save($session);
            return sregle_bot_response("📝 Registration - Step 1/7: Send your First name:");
        }
        if ($lc === '3' || $lc === 'services') {
            $session['step'] = 'services_menu'; $save($session);
            return sregle_bot_response("🛍 Services:\n
            1️⃣ Airtime\n
            2️⃣ Data\n
            3️⃣ Cable TV\n
            4️⃣ Electricity Bills\n
            5️⃣ Check Balance\n
            6️⃣ Funding\n
            #) Logout\n
            Reply with a number.");
        }
        return sregle_bot_response("❗ Reply 1 to Login, 2 to Register, 3 for Services, 4 for check balance, 5 for Funding, or # for log out.");
    }

    /* ---------- LOGIN FLOW ---------- */
    if ($step === 'login_phone') {
        $phone = sregle_bot_normalize_phone($message);
        if (empty($phone)) return sregle_bot_response("❗ Invalid phone. Send the phone number link to your account in our app or site :");
        $session['data']['login_phone'] = $phone;
        $session['step'] = 'login_pin'; $save($session);
        return sregle_bot_response("🔒 Send your 4-6 digit PIN:");
    }
    if ($step === 'login_pin') {
        $phone = $session['data']['login_phone'] ?? '';
        if (empty($phone)) { $session['step'] = 'welcome'; $save($session); return sregle_bot_response("⚠️ Session lost. Start again."); }
        $user = sregle_bot_find_user_by_identifier($phone);
        if (!$user) { $session['step']='welcome'; $save($session); return sregle_bot_response("❌ No account found for {$phone}. Reply 2 to register."); }
        if (!sregle_bot_check_pin($user->ID, trim($message))) { $session['step']='welcome'; $save($session); return sregle_bot_response("🔑 Incorrect PIN. Reply 1 to try again."); }

        update_user_meta($user->ID, 'whatsapp_number', $from);
        update_user_meta($user->ID, 'sregle_bot_logged_in', $from);

        $vp = sregle_bot_load_vp($user->ID);
        $bal = number_format((float)$vp['vp_bal'],2);
        $fund_text = sregle_bot_funding_text($vp);

        sregle_bot_set_session($from, array('step'=>'logged_in','user_id'=>$user->ID,'data'=>array()));

        $reply = "✅ Welcome back {$user->display_name}!\n💰 Balance: ₦{$bal}\n{$fund_text}\n" . sregle_bot_main_menu_text();
        return sregle_bot_response($reply);
    }

    /* ---------- REGISTRATION FLOW (keeps previous behavior) ---------- */
    if ($step === 'reg_first') {
        $first = sanitize_text_field($message);
        if ($first === '') return sregle_bot_response("❗ First name cannot be empty. Send your first name:");
        $session['data']['reg_first'] = $first;
        $session['step'] = 'reg_last'; $save($session);
        return sregle_bot_response("📝 Step 2/7 - Send your Last name:");
    }
    if ($step === 'reg_last') {
        $last = sanitize_text_field($message);
        if ($last === '') return sregle_bot_response("❗ Last name cannot be empty. Send your last name:");
        $session['data']['reg_last'] = $last;
        $session['step'] = 'reg_username'; $save($session);
        return sregle_bot_response("📝 Step 3/7 - Choose a username:");
    }
    if ($step === 'reg_username') {
        $username = sanitize_user($message, true);
        if ($username === '') return sregle_bot_response("❗ Invalid username. Enter a username:");
        if (username_exists($username)) return sregle_bot_response("❗ Username taken. Choose another:");
        $session['data']['reg_username'] = $username;
        $session['step'] = 'reg_email'; $save($session);
        return sregle_bot_response("📧 Step 4/7 - Send your email address:");
    }
    if ($step === 'reg_email') {
        $email = sanitize_email($message);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return sregle_bot_response("❗ Invalid email. Send a valid email address:");
        if (email_exists($email)) return sregle_bot_response("❗ Email already used. Use another:");
        $session['data']['reg_email'] = $email;
        $session['step'] = 'reg_phone'; $save($session);
        return sregle_bot_response("📱 Step 5/7 - Send your WhatsApp number :");
    }
    if ($step === 'reg_phone') {
        $phone = sregle_bot_normalize_phone($message);
        if (empty($phone)) return sregle_bot_response("❗ Invalid phone. Send me your WhatsApp number:");
        // uniqueness checks
        foreach (array('whatsapp_number','phone','mobile','msisdn') as $mk) {
            $users = get_users(array('meta_key'=>$mk,'meta_value'=>$phone,'number'=>1));
            if (!empty($users)) { $session['step']='welcome'; $save($session); return sregle_bot_response('❗ Phone already registered. Reply 1 to Login.'); }
        }
        // check vp_user_data
        global $wpdb;
        $like = '%' . $wpdb->esc_like($phone) . '%';
        $uid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='vp_user_data' AND meta_value LIKE %s LIMIT 1", $like));
        if ($uid) { $session['step']='welcome'; $save($session); return sregle_bot_response('❗ Phone already registered (vp_user_data). Reply 1 to Login.'); }

        $session['data']['reg_phone'] = $phone;
        $session['step'] = 'reg_password'; $save($session);
        return sregle_bot_response("🔒 Step 6/7 - Choose a password (min 6 chars):");
    }
    if ($step === 'reg_password') {
        if (strlen($message) < 6) return sregle_bot_response("❗ Password too short. Enter password (min 6 chars):");
        $session['data']['reg_password'] = $message;
        $session['step'] = 'reg_pin'; $save($session);
        return sregle_bot_response("🔐 Step 7/7 - Set a 4-6 digit PIN (numbers only):");
    }
    if ($step === 'reg_pin') {
        if (!preg_match('/^[0-9]{4,6}$/', $message)) return sregle_bot_response("❗ PIN must be 4-6 digits. Enter PIN:");
        $d = $session['data'];
        $first = $d['reg_first'] ?? '';
        $last = $d['reg_last'] ?? '';
        $username = $d['reg_username'] ?? '';
        $email = $d['reg_email'] ?? '';
        $phone = $d['reg_phone'] ?? '';
        $pass = $d['reg_password'] ?? '';

        if ($first === '' || $username === '' || $email === '' || $phone === '' || $pass === '') {
            $session['step'] = 'welcome'; $save($session);
            return sregle_bot_response("❗ Registration data missing. Start again.");
        }

        $display = trim($first . ' ' . $last);
        $uid = wp_insert_user(array('user_login'=>$username,'user_pass'=>$pass,'user_email'=>$email,'display_name'=>$display));
        if (is_wp_error($uid)) { $session['step']='welcome'; $save($session); return sregle_bot_response("❗ Registration failed: " . $uid->get_error_message()); }

        update_user_meta($uid,'whatsapp_number',$phone);
        update_user_meta($uid,'transaction_pin', wp_hash_password($message));

        // initialize vp_user_data
        $vp = array(
            'vp_bal' => 0,
            'vp_pin' => $message,
            'paymentpoint_accountnumber' => '',
            'paymentpoint_accountname' => '',
            'paymentpoint_bankname' => 'Palmpay',
            'account_number2' => '',
            'account_name2' => '',
            'bank_name2' => 'Palmpay'
        );
        update_user_meta($uid,'vp_user_data', wp_json_encode($vp));

        sregle_bot_set_session($from, array('step'=>'logged_in','user_id'=>$uid,'data'=>array()));
        update_user_meta($uid,'sregle_bot_logged_in',$from);

        return sregle_bot_response("✅ Registration complete! Welcome {$display}.\n💰 Balance: ₦0.00\n" . sregle_bot_main_menu_text());
    }

    /* ---------- SERVICES MENU (pre-login) ---------- */
    if ($step === 'services_menu') {
        if ($lc === '1' || $lc === 'airtime') {
            $session['step'] = 'airtime_network'; $save($session);
            // numbered network list
            $net_text = "📱 Choose Network:\n
            1️⃣ mtn\n
            2️⃣ airtel\n
            3️⃣ glo\n
            4️⃣ 9mobile\n
            Reply with number or network name.";
            return sregle_bot_response($net_text);
        }
        if ($lc === '2' || $lc === 'data') {
            $session['step'] = 'data_network'; $save($session);
            $net_text = "🌐 Choose Network for Data:\n
            1️⃣ mtn\n
            2️⃣ airtel\n
            3️⃣ glo\n
            4️⃣ 9mobile\n
            Reply with number or network name.";
            return sregle_bot_response($net_text);
        }
        if ($lc === '3' || $lc === 'cable') {
            $session['step'] = 'cable_provider'; $save($session);
            $text = "📺 Choose Cable Provider:\n
            1️⃣ gotv\n
            2️⃣ dstv\n
            3️⃣ startimes\n
            Reply with number or provider name.";
            return sregle_bot_response($text);
        }
        if ($lc === '4' || $lc === 'bill' || $lc === 'electricity') {
            $session['step'] = 'bill_provider'; $save($session);
            $text = "⚡ Choose Electricity Provider:\n
            1️⃣ ikeja\n
            2️⃣ eko\n
            3️⃣ abuja\n
            4️⃣ kano\n
            5️⃣ portharcourt\n
            6️⃣ ibadan\n
            7️⃣ kaduna\n
            8️⃣ jos\n
            Reply with number or provider name.";
            return sregle_bot_response($text);
        }
        if ($lc === '5' || $lc === 'check balance') {
            $vp = sregle_bot_load_vp($uid);
            $bal = number_format((float)$vp['vp_bal'],2);
            return sregle_bot_response("💰 Your balance is: ₦{$bal}");
        }
        if ($lc === '6' || $lc === 'funding' || $lc === 'funding accounts') {
            $vp = sregle_bot_load_vp($uid);
            return sregle_bot_response(sregle_bot_funding_text($vp));
        }
        if ($lc === '#' || $lc === 'logout') {
            delete_user_meta($uid, 'sregle_bot_logged_in');
            sregle_bot_clear_session($from);
            return sregle_bot_response("✅ You have been logged out.");
        }        

        return sregle_bot_response("❗ Reply 1 for Airtime, 2 for Data, 3 for Cable, 4 for Bills, 5 for check balance, 6 for finding, or # for log out.");
    }

    /* ---------- LOGGED-IN MENU ---------- */
    if ($step === 'logged_in') {
        $uid = intval($session['user_id'] ?? 0);
        if (!$uid) { sregle_bot_clear_session($from); return sregle_bot_response('⚠️ Session error. Please login again.'); }

        if ($lc === '1' || $lc === 'airtime') {
            $session['step'] = 'airtime_network'; $save($session);
            return sregle_bot_response("📱 Choose Network for Airtime:\n
            1️⃣ mtn\n
            2️⃣ airtel\n
            3️⃣ glo\n
            4️⃣ 9mobile\n
            (Reply with number or name)");
        }
        if ($lc === '2' || $lc === 'data') {
            $session['step'] = 'data_network'; $save($session);
            return sregle_bot_response("🌐 Choose Network for Data:\n
            1️⃣ mtn\n
            2️⃣ airtel\n
            3️⃣ glo\n
            4️⃣ 9mobile\n
            (Reply with number or name)");            
        }
        
        if ($lc === '3' || $lc === 'cable') {
            $session['step'] = 'cable_provider'; $save($session);
            return sregle_bot_response("🌐 Choose provider for Cable:\n
            1️⃣ gotv\n
            2️⃣ dstv\n
            3️⃣ startimes\n            
            (Reply with number or name)");
        }    
        
        if ($lc === '4' || $lc === 'bills') {
            $session['step'] = 'bill_provider'; $save($session);
            return sregle_bot_response("🌐 Choose Network for Data:\n
            1️⃣ ikeja\n
            2️⃣ eko\n
            3️⃣ abuja\n
            4️⃣ kano\n
            5️⃣ portharcourt\n
            6️⃣ ibadan\n
            7️⃣ kaduna\n
            8️⃣ jos\n
            (Reply with number or name)");
       } 
       
        if ($lc === '5' || $lc === 'check balance') {
            $vp = sregle_bot_load_vp($uid);
            $bal = number_format((float)$vp['vp_bal'],2);
            return sregle_bot_response("💰 Your balance is: ₦{$bal}");
        }
        if ($lc === '6' || $lc === 'funding' || $lc === 'funding accounts') {
            $vp = sregle_bot_load_vp($uid);
            return sregle_bot_response(sregle_bot_funding_text($vp));
        }
        if ($lc === '#' || $lc === 'logout') {
            delete_user_meta($uid, 'sregle_bot_logged_in');
            sregle_bot_clear_session($from);
            return sregle_bot_response("✅ You have been logged out.");
        }
        return sregle_bot_response(sregle_bot_main_menu_text());
    }

    /* ---------- AIRTIME FLOW ---------- */
    if ($step === 'airtime_network') {
        $num_map = array('1'=>'mtn','2'=>'airtel','3'=>'glo','4'=>'9mobile');
        $network = '';
        if (isset($num_map[$message])) $network = $num_map[$message];
        else $network = strtolower(trim($message));

        // normalize 9mobile variants
        if (strpos($network,'9') !== false) $network = '9mobile';
        if (!in_array($network, array('mtn','airtel','glo','9mobile'))) {
            return sregle_bot_response("❗ Invalid network. Use: mtn, airtel, glo, 9mobile");
        }
        $session['data']['airtime_network'] = $network;
        $session['step'] = 'airtime_amount'; $save($session);
        return sregle_bot_response("📱 Enter recipient phone number or type 'me' for your number:");
    }
    if ($step === 'airtime_amount') {
        $target = sregle_bot_normalize_phone($message);
        if ($message === 'me' || $message === 'Me') {
            // use logged-in user's whatsapp_number if available
            $uid = intval($session['user_id'] ?? 0);
            $target = get_user_meta($uid,'whatsapp_number',true) ?: $from;
        }
        if (empty($target)) return sregle_bot_response("❗ Invalid phone. Enter 11 digit phone number:");
        $session['data']['airtime_target'] = $target;
        $session['step'] = 'airtime_amount2'; $save($session);
        return sregle_bot_response("💵 Enter amount (numeric, e.g., 200):");
    }
    if ($step === 'airtime_amount2') {
        $amount = floatval(preg_replace('/[^0-9.]/','',$message));
        if ($amount <= 0) return sregle_bot_response("❗ Invalid amount. Enter a numeric amount:");
        $session['data']['airtime_amount'] = $amount;
        $session['step'] = 'airtime_type'; $save($session);
        return sregle_bot_response("🔤 Enter airtime type (vtu, share, awuf) or press enter for default 'vtu':");
    }
    if ($step === 'airtime_type') {
        $type = strtolower(trim($message));
        if ($type === '') $type = 'vtu';
        if (!in_array($type,array('vtu','share','awuf'))) $type = 'vtu';
        $session['data']['airtime_type'] = $type;
        $session['step'] = 'airtime_confirm_pin'; $save($session);

        $t = $session['data'];
        $preview = "🔒 Confirm purchase:\n📱 {$t['airtime_target']}\n📡 {$t['airtime_network']}\n💰 ₦" . number_format($t['airtime_amount'],2) . "nType your PIN to confirm.";
        return sregle_bot_response($preview);
    }
    if ($step === 'airtime_confirm_pin') {
        $uid = intval($session['user_id'] ?? 0);
        if (!$uid) return sregle_bot_response("⚠️ You must be logged in to purchase. Reply 1 to Login.");
        $pin = trim($message);
        if (!sregle_bot_check_pin($uid,$pin)) {
            $session['step'] = 'logged_in'; $save($session);
            return sregle_bot_response("❌ Invalid PIN. Transaction cancelled.");
        }

        $cred = sregle_bot_get_actor_credentials($uid);
        if (!$cred) {
            $session['step']='logged_in'; $save($session);
            return sregle_bot_response("⚠️ Missing API key. Please generate an API key on your website profile or contact admin.");
        }

        $args = array(
            'q'=>'airtime',
            'id'=>intval($cred['id']),
            'apikey'=>$cred['apikey'],
            'phone'=>$session['data']['airtime_target'],
            'amount'=>$session['data']['airtime_amount'],
            'network'=>$session['data']['airtime_network'],
            'type'=>$session['data']['airtime_type']
        );

        $res = sregle_bot_vprest_call($args);
        if (is_wp_error($res)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ API error: " . $res->get_error_message()); }
        $json = $res['json'] ?? null;
        if (!is_array($json)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Invalid API response."); }

        $succ = false;
        if ((isset($json['Status']) && (string)$json['Status']==='100') || (isset($json['Successful']) && ($json['Successful'] === true || $json['Successful'] === 'true'))) $succ = true;

        $prev = $json['Previous_Balance'] ?? $json['PreviousBalance'] ?? null;
        $curr = $json['Current_Balance'] ?? $json['CurrentBalance'] ?? null;

        if ($succ) {
            if ($curr !== null && is_numeric($curr)) sregle_bot_save_vp_balance($uid, floatval($curr));
            $session['step']='logged_in'; $save($session);

            $msg = "✅ Airtime Purchase Successful!\n
            📱 Receiver: {$session['data']['airtime_target']}\n
            📡 Network: {$session['data']['airtime_network']}\n
            💰 Amount: ₦" . number_format($session['data']['airtime_amount'],2) . "\n";
            if ($prev !== null) $msg .= "📉 Previous Balance: ₦" . number_format((float)$prev,2) . "\n";
            if ($curr !== null) $msg .= "📈 New Balance: ₦" . number_format((float)$curr,2) . "\n";
            $msg .= "\n" . sregle_bot_main_menu_text();
            return sregle_bot_response($msg);
        } else {
            $session['step']='logged_in'; $save($session);
            $err = $json['Message'] ?? $json['message'] ?? $json['Response'] ?? 'Unknown error';
            return sregle_bot_response("❌ Airtime failed: " . $err);
        }
    }

    /* ---------- DATA FLOW ---------- */
    if ($step === 'data_network') {
        $num_map = array('1'=>'mtn','2'=>'airtel','3'=>'glo','4'=>'9mobile');
        $network = isset($num_map[$message]) ? $num_map[$message] : strtolower(trim($message));
        if (strpos($network,'9')!==false) $network = '9mobile';
        if (!in_array($network,array('mtn','airtel','glo','9mobile'))) return sregle_bot_response("❗ Invalid network. Use: mtn, airtel, glo, 9mobile");
        $session['data']['data_network'] = $network;

        // fetch cached services plans (data)
        $services = sregle_bot_get_cached_services();
        $plans_raw = array();
        // try to locate plans by network in cached data
        if (isset($services['data']) && is_array($services['data'])) {
            foreach ($services['data'] as $p) {
                // Some APIs return plan entries with 'network' or 'Network' key
                $pn = strtolower($p['network'] ?? $p['Network'] ?? ($p['type'] ?? ''));
                if ($pn === strtolower($network) || (isset($p['Network']) && strtolower($p['Network'])===strtolower($network))) {
                    $plans_raw[] = $p;
                } elseif (!isset($p['network']) && isset($p['name']) && stripos($p['name'],$network)!==false) {
                    $plans_raw[] = $p;
                }
            }
        }

        // If none found, attempt to fetch dynamically using admin creds (best-effort)
        if (empty($plans_raw)) {
            // We'll try to call Admin API q=data_list or q=data_plans (not guaranteed)
            $admin_id = get_option(SREGLE_BOT_OPTION_ADMIN_ID,'');
            $admin_apikey = get_option(SREGLE_BOT_OPTION_ADMIN_APIKEY,'');
            if ($admin_id && $admin_apikey) {
                $try_args = array('q'=>'data_plans','id'=>$admin_id,'apikey'=>$admin_apikey,'network'=>$network);
                $resp = sregle_bot_vprest_call($try_args);
                if (!is_wp_error($resp) && is_array($resp['json'])) {
                    // if the API returned list-like structure, accept it
                    $j = $resp['json'];
                    if (isset($j['plans']) && is_array($j['plans'])) $plans_raw = $j['plans'];
                    elseif (is_array($j) && !empty($j)) $plans_raw = $j;
                }
            }
        }

        // Build numbered menu of plans
        if (empty($plans_raw)) {
            $session['step'] = 'data_ask_plan_manual'; $save($session);
            return sregle_bot_response("📋 I couldn't fetch plans for {$network}. Please send plan_id (numeric) or type 'list' to show sample plans.");
        }

        
        // Merge admin-defined manual data plans (if any)
        $manual = sregle_bot_get_manual_plans('data', $network);
        if (!empty($manual)) {
            // prepend manual plans so they appear first
            $plans_raw = array_merge($manual, (array)$plans_raw);
        }
$session['data']['data_plans'] = $plans_raw;
        $session['step'] = 'data_choose_plan';
        $save($session);

        $out = "📶 *" . strtoupper($network) . " Data Plans*\n";
        $i = 1;
        foreach ($plans_raw as $p) {
            $id = $p['id'] ?? ($p['Plan_Code'] ?? ($p['plan_id'] ?? ($p['product_id'] ?? ($p['code'] ?? ''))));
            $name = $p['name'] ?? $p['Data_Plan'] ?? $p['DataPlan'] ?? $p['Plan'] ?? ($p['product'] ?? '');
            $amount = $p['amount'] ?? $p['Amount'] ?? $p['Price'] ?? $p['value'] ?? 0;
            $amount_display = is_numeric($amount) ? '₦' . number_format(floatval($amount),2) : (string)$amount;
            $out .= "{$i}️⃣ {$id} — {$name} — {$amount_display}\n";
            $i++;
        }
        $out .= "\nReply with plan number (e.g. 1) or plan id.";
        return sregle_bot_response($out);
    }

    if ($step === 'data_choose_plan') {
        $plans = $session['data']['data_plans'] ?? array();
        if (empty($plans)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Plans not available. Try again."); }

        // allow selecting by list number or by plan id
        $selected = null;
        if (preg_match('/^d+$/', trim($message))) {
            $idx = intval(trim($message)) - 1;
            if (isset($plans[$idx])) $selected = $plans[$idx];
        }
        if (!$selected) {
            // try match by plan id or plan code
            foreach ($plans as $p) {
                $id = (string)($p['id'] ?? $p['Plan_Code'] ?? $p['plan_id'] ?? $p['product_id'] ?? '');
                if ($id !== '' && $id === trim($message)) { $selected = $p; break; }
                if (isset($p['name']) && strcasecmp($p['name'], $message)===0) { $selected = $p; break; }
            }
        }

        if (!$selected) return sregle_bot_response("❗ Invalid selection. Reply with plan number or plan id:");

        $plan_id = $selected['id'] ?? $selected['Plan_Code'] ?? $selected['plan_id'] ?? $selected['product_id'] ?? '';
        $session['data']['data_selected_plan'] = $selected;
        $session['step'] = 'data_ask_phone'; $save($session);
        return sregle_bot_response("📱 Enter recipient phone number or type 'me' for your number:");
    }

    if ($step === 'data_ask_phone') {
        $target = sregle_bot_normalize_phone($message);
        if ($message === 'me') {
            $uid = intval($session['user_id'] ?? 0);
            $target = get_user_meta($uid,'whatsapp_number',true) ?: $from;
        }
        if (empty($target)) return sregle_bot_response("❗ Invalid phone. Enter 11 digit phone number :");
        $session['data']['data_target'] = $target;
        $session['step'] = 'data_confirm_pin'; $save($session);
        $plan = $session['data']['data_selected_plan'];
        $plan_name = $plan['name'] ?? ($plan['Data_Plan'] ?? 'plan');
        $plan_id = $plan['id'] ?? ($plan['Plan_Code'] ?? $plan['plan_id'] ?? '');
        return sregle_bot_response("🔒 Confirm purchase: {$plan_name} (ID: {$plan_id}) to {$target}. Enter your PIN to proceed:");
    }

    if ($step === 'data_confirm_pin') {
        $uid = intval($session['user_id'] ?? 0);
        if (!$uid) return sregle_bot_response("⚠️ You must be logged in to purchase data. Reply 1 to Login.");
        $pin = trim($message);
        if (!sregle_bot_check_pin($uid,$pin)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("❌ Invalid PIN. Transaction cancelled."); }

        $cred = sregle_bot_get_actor_credentials($uid);
        if (!$cred) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Missing API key. Please generate one on the website or contact admin."); }

        $plan = $session['data']['data_selected_plan'];
        $plan_id = $plan['id'] ?? $plan['Plan_Code'] ?? $plan['plan_id'] ?? '';
        $args = array(
            'q'=>'data',
            'id'=>intval($cred['id']),
            'apikey'=>$cred['apikey'],
            'phone'=>$session['data']['data_target'],
            'network'=>$session['data']['data_network'],
            'dataplan'=>intval($plan_id),
            'type'=> (isset($plan['type']) ? $plan['type'] : 'sme')
        );

        $res = sregle_bot_vprest_call($args);
        if (is_wp_error($res)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ API error: " . $res->get_error_message()); }
        $json = $res['json'] ?? null;
        if (!is_array($json)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Invalid API response."); }

        $succ = false;
        if ((isset($json['Status']) && (string)$json['Status']==='100') || (isset($json['Successful']) && ($json['Successful'] === true || $json['Successful'] === 'true'))) $succ = true;

        $prev = $json['Previous_Balance'] ?? $json['PreviousBalance'] ?? null;
        $curr = $json['Current_Balance'] ?? $json['CurrentBalance'] ?? null;

        if ($succ) {
            if ($curr !== null && is_numeric($curr)) sregle_bot_save_vp_balance($uid, floatval($curr));
            $session['step']='logged_in'; $save($session);
            $msg = "✅ Data Purchase Successful!\n
            📱 Receiver: {$session['data']['data_target']}\n
            📡 Network: {$session['data']['data_network']}\n
            📦 Plan: " . ($plan['name'] ?? '') . "\n";
            if ($prev !== null) $msg .= "📉 Previous Balance: ₦" . number_format((float)$prev,2) . "\n";
            if ($curr !== null) $msg .= "📈 New Balance: ₦" . number_format((float)$curr,2) . "\n";
            $msg .= "\n" . sregle_bot_main_menu_text();
            return sregle_bot_response($msg);
        } else {
            $session['step']='logged_in'; $save($session);
            $err = $json['Message'] ?? $json['message'] ?? $json['Response'] ?? 'Unknown error';
            return sregle_bot_response("❌ Data purchase failed: " . $err);
        }
    }

    /* ---------- CABLE FLOW (similar pattern) ---------- */
    if ($step === 'cable_provider') {
        $map = array('1'=>'gotv','2'=>'dstv','3'=>'startimes');
        $provider = isset($map[$message]) ? $map[$message] : strtolower(trim($message));
        $session['data']['cable_provider'] = $provider;
        // attempt to get cable plans from cache
        $services = sregle_bot_get_cached_services();
        $plans = $services['cable'] ?? array();
        $filtered = array();
        foreach ($plans as $p) {
            $prov = strtolower($p['provider'] ?? ($p['type'] ?? ''));
            if ($prov === $provider || stripos($p['name'],$provider)!==false) $filtered[] = $p;
        }
        
        // Merge manual cable plans (admin-defined)
        $manual = sregle_bot_get_manual_plans('cable', $provider);
        if (!empty($manual)) {
            foreach ($manual as $mp) { $filtered[] = $mp; }
        }
if (empty($filtered)) {
            // fallback: ask admin to set plans or instruct manual plan id entry
            $session['step'] = 'cable_ask_plan_manual'; $save($session);
            return sregle_bot_response("📋 I couldn't fetch cable plans for {$provider}. Please send plan id and IUC (e.g. <plan_id> <iuc>) or type 'list' for sample.");
        }
        $session['data']['cable_plans'] = $filtered;
        $session['step'] = 'cable_choose_plan'; $save($session);

        $out = "📺 *" . strtoupper($provider) . " Plans\n";
        $i=1;
        foreach ($filtered as $p) {
            $id = $p['id'] ?? $p['plan'] ?? '';
            $name = $p['name'] ?? '';
            $amt = $p['amount'] ?? $p['Amount'] ?? 0;
            $out .= "{$i}️⃣ {$id} — {$name} — ₦" . number_format((float)$amt,2) . "\n";
            $i++;
        }
        $out .= "\nReply with plan number or plan id.";
        return sregle_bot_response($out);
    }

    if ($step === 'cable_choose_plan') {
        $plans = $session['data']['cable_plans'] ?? array();
        if (empty($plans)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Cable plans not available. Try again."); }

        $selected = null;
        if (preg_match('/^d+$/', trim($message))) {
            $idx = intval(trim($message)) - 1;
            if (isset($plans[$idx])) $selected = $plans[$idx];
        }
        if (!$selected) {
            foreach ($plans as $p) {
                $id = (string)($p['id'] ?? $p['plan'] ?? '');
                if ($id !== '' && $id === trim($message)) { $selected = $p; break; }
            }
        }
        if (!$selected) return sregle_bot_response("❗ Invalid selection. Reply with plan number or plan id:");

        $session['data']['cable_selected_plan'] = $selected;
        $session['step'] = 'cable_ask_iuc'; $save($session);
        return sregle_bot_response("🔢 Enter recipient IUC / Smart Card Number:");
    }

    if ($step === 'cable_ask_iuc') {
        $iuc = trim($message);
        if ($iuc === '') return sregle_bot_response("❗ IUC cannot be empty. Enter IUC / Smart Card Number:");
        $session['data']['cable_iuc'] = $iuc;
        $session['step'] = 'cable_confirm_pin'; $save($session);

        $plan = $session['data']['cable_selected_plan'];
        $plan_name = $plan['name'] ?? '';
        return sregle_bot_response("🔒 Confirm purchase: {$plan_name} to IUC {$iuc}. Enter your PIN to proceed:");
    }

    if ($step === 'cable_confirm_pin') {
        $uid = intval($session['user_id'] ?? 0);
        if (!$uid) return sregle_bot_response("⚠️ You must be logged in to purchase cable. Reply 1 to Login.");
        $pin = trim($message);
        if (!sregle_bot_check_pin($uid,$pin)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("❌ Invalid PIN. Transaction cancelled."); }

        $cred = sregle_bot_get_actor_credentials($uid);
        if (!$cred) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Missing API key. Please generate one on the website or contact admin."); }

        $plan = $session['data']['cable_selected_plan'];
        $plan_id = $plan['id'] ?? $plan['plan'] ?? '';

        $args = array(
            'q'=>'cable',
            'id'=>intval($cred['id']),
            'apikey'=>$cred['apikey'],
            'type'=>$session['data']['cable_provider'],
            'iuc'=>$session['data']['cable_iuc'],
            'plan'=>$plan_id
        );

        $res = sregle_bot_vprest_call($args);
        if (is_wp_error($res)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ API error: " . $res->get_error_message()); }
        $json = $res['json'] ?? null;
        if (!is_array($json)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Invalid API response."); }

        $succ = false;
        if ((isset($json['Status']) && (string)$json['Status']==='100') || (isset($json['Successful']) && ($json['Successful']===true || $json['Successful']==='true'))) $succ = true;

        $prev = $json['Previous_Balance'] ?? $json['PreviousBalance'] ?? null;
        $curr = $json['Current_Balance'] ?? $json['CurrentBalance'] ?? null;

        if ($succ) {
            if ($curr !== null && is_numeric($curr)) sregle_bot_save_vp_balance($uid, floatval($curr));
            $session['step']='logged_in'; $save($session);
            $msg = "✅ Cable Purchase Successful!\n
            📺 Provider: {$session['data']['cable_provider']}\n
            🔢 IUC: {$session['data']['cable_iuc']}\n
            📦 Plan: " . ($plan['name'] ?? '') . "\n";
            if ($prev !== null) $msg .= "📉 Previous Balance: ₦" . number_format((float)$prev,2) . "\n";
            if ($curr !== null) $msg .= "📈 New Balance: ₦" . number_format((float)$curr,2) . "\n";
            $msg .= "\n" . sregle_bot_main_menu_text();
            return sregle_bot_response($msg);
        } else {
            $session['step']='logged_in'; $save($session);
            $err = $json['Message'] ?? $json['message'] ?? $json['Response'] ?? 'Unknown error';
            return sregle_bot_response("❌ Cable purchase failed: " . $err);
        }
    }

    /* ---------- BILLS FLOW ---------- */
    if ($step === 'bill_provider') {
        $map = array('1'=>'ikeja','2'=>'eko','3'=>'abuja','4'=>'kano','5'=>'portharcourt','6'=>'ibadan','7'=>'kaduna','8'=>'jos');
        $provider = isset($map[$message]) ? $map[$message] : strtolower(trim($message));
        $session['data']['bill_provider'] = $provider;
        $session['step'] = 'bill_ask_meter'; $save($session);
        return sregle_bot_response("🔢 Enter meter number:");
    }
    if ($step === 'bill_ask_meter') {
        $meter = trim($message);
        if ($meter === '') return sregle_bot_response("❗ Meter number cannot be empty. Enter meter number:");
        $session['data']['bill_meter'] = $meter;
        $session['step'] = 'bill_ask_amount'; $save($session);
        return sregle_bot_response("💵 Enter amount to pay (e.g., 1000):");
    }
    if ($step === 'bill_ask_amount') {
        $amount = floatval(preg_replace('/[^0-9.]/','',$message));
        if ($amount <= 0) return sregle_bot_response("❗ Invalid amount. Enter numeric amount:");
        $session['data']['bill_amount'] = $amount;
        $session['step'] = 'bill_confirm_pin'; $save($session);
        return sregle_bot_response("🔒 Confirm bill payment of ₦" . number_format($amount,2) . " to meter " . $session['data']['bill_meter'] . ". Enter your PIN to proceed:");
    }
    if ($step === 'bill_confirm_pin') {
        $uid = intval($session['user_id'] ?? 0);
        if (!$uid) return sregle_bot_response("⚠️ You must be logged in to pay bills. Reply 1 to Login.");
        $pin = trim($message);
        if (!sregle_bot_check_pin($uid,$pin)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("❌ Invalid PIN. Transaction cancelled."); }

        $cred = sregle_bot_get_actor_credentials($uid);
        if (!$cred) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Missing API key. Please generate one on the website or contact admin."); }

        $args = array(
            'q'=>'bill',
            'id'=>intval($cred['id']),
            'apikey'=>$cred['apikey'],
            'type'=>'prepaid',
            'meter_number'=> $session['data']['bill_meter'],
            'plan'=>  intval($session['data']['bill_provider']), // plan id representing provider in docs
            'amount'=> intval($session['data']['bill_amount'])
        );

        $res = sregle_bot_vprest_call($args);
        if (is_wp_error($res)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ API error: " . $res->get_error_message()); }
        $json = $res['json'] ?? null;
        if (!is_array($json)) { $session['step']='logged_in'; $save($session); return sregle_bot_response("⚠️ Invalid API response."); }

        $succ = false;
        if ((isset($json['Status']) && (string)$json['Status']==='100') || (isset($json['Successful']) && ($json['Successful']===true || $json['Successful']==='true'))) $succ = true;

        $prev = $json['Previous_Balance'] ?? $json['PreviousBalance'] ?? null;
        $curr = $json['Current_Balance'] ?? $json['CurrentBalance'] ?? null;

        if ($succ) {
            if ($curr !== null && is_numeric($curr)) sregle_bot_save_vp_balance($uid, floatval($curr));
            $session['step']='logged_in'; $save($session);
            $msg = "✅ Bill Payment Successful!\n
            🔌 Provider: {$session['data']['bill_provider']}\n
            🔢 Meter: {$session['data']['bill_meter']}\n
            💰 Amount: ₦" . number_format($session['data']['bill_amount'],2) . "\n";
            if ($prev !== null) $msg .= "📉 Previous Balance: ₦" . number_format((float)$prev,2) . "\n";
            if ($curr !== null) $msg .= "📈 New Balance: ₦" . number_format((float)$curr,2) . "\n";
            $msg .= "\n" . sregle_bot_main_menu_text();
            return sregle_bot_response($msg);
        } else {
            $session['step']='logged_in'; $save($session);
            $err = $json['Message'] ?? $json['message'] ?? $json['Response'] ?? 'Unknown error';
            return sregle_bot_response("❌ Bill payment failed: " . $err);
        }
    }
}
    /* ---------- FALLBACK / RESET ---------- */
    sregle_bot_clear_session($from);
    return sregle_bot_response("⚠️ Restarting session. Type 'login' or 'register' to begin."); 

/* =========================================================
   Utility: find user by login/email/phone/vp_user_data
=========================================================*/
function sregle_bot_find_user_by_identifier($identifier) {
    global $wpdb;
    $identifier = trim($identifier);
    if ($identifier === '') return false;

    if ($u = get_user_by('login',$identifier)) return $u;
    if ($u = get_user_by('email',$identifier)) return $u;

    $meta_keys = array('whatsapp_number','phone','mobile','msisdn');
    foreach ($meta_keys as $mk) {
        $users = get_users(array('meta_key'=>$mk,'meta_value'=>$identifier,'number'=>1));
        if (!empty($users)) return $users[0];
    }

    // vtupress table attempt
    $vt_table = $wpdb->prefix . 'vtupress_users';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$vt_table}'") == $vt_table) {
        if (is_numeric($identifier)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$vt_table} WHERE user_wp_id = %d LIMIT 1", intval($identifier)), ARRAY_A);
            if ($row && !empty($row['user_wp_id'])) {
                $u = get_userdata(intval($row['user_wp_id']));
                if ($u) return $u;
            }
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$vt_table} WHERE phone = %s LIMIT 1", $identifier), ARRAY_A);
        if ($row && !empty($row['user_wp_id'])) {
            $u = get_userdata(intval($row['user_wp_id']));
            if ($u) return $u;
        }
    }

    // search vp_user_data meta
    $like = '%' . $wpdb->esc_like($identifier) . '%';
    $uid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'vp_user_data' AND meta_value LIKE %s LIMIT 1", $like));
    if ($uid) return get_userdata($uid);

    return false;
}
    
/* =========================================================
   Manual Plans: DB table, admin UI and helpers
=========================================================*/

function sregle_bot_manual_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'sregle_manual_plans';
}

function sregle_bot_create_manual_table() {
    global $wpdb;
    $table = sregle_bot_manual_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        plan_code VARCHAR(191) NOT NULL DEFAULT '',
        type VARCHAR(50) NOT NULL DEFAULT '',
        provider VARCHAR(100) NOT NULL DEFAULT '',
        name VARCHAR(255) NOT NULL DEFAULT '',
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        meta TEXT NULL,
        created DATETIME DEFAULT CURRENT_TIMESTAMP,
        modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('admin_init', function() {
    // ensure table exists (safe to call repeatedly)
    sregle_bot_create_manual_table();
});

// CRUD helpers
function sregle_bot_get_manual_plans($type = null, $provider = null) {
    global $wpdb;
    $table = sregle_bot_manual_table_name();
    $where = array();
    $params = array();
    if ($type !== null) { $where[] = 'type=%s'; $params[] = $type; }
    if ($provider !== null) { $where[] = 'provider=%s'; $params[] = $provider; }
    $sql = "SELECT * FROM {$table}";
    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    $out = array();
    if ($rows) {
        foreach ($rows as $r) {
            // Normalize to same shape as remote plans: use 'id' or 'plan' keys
            $out[] = array(
                'id' => $r['plan_code'],
                'plan' => $r['plan_code'],
                'name' => $r['name'],
                'amount' => (float)$r['amount'],
                'provider' => $r['provider'],
                'meta' => $r['meta']
            );
        }
    }
    return $out;
}

// Admin page: Manual Plans
function sregle_bot_manual_plans_page() {
    if (!current_user_can('manage_options')) wp_die('No permission');
    global $wpdb;

    $table = sregle_bot_manual_table_name();

    // handle add/edit/delete
    if (isset($_POST['sregle_manual_action']) && check_admin_referer('sregle_manual_action','sregle_manual_nonce')) {
        $action = sanitize_text_field($_POST['sregle_manual_action']);
        $plan_code = sanitize_text_field($_POST['plan_code'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $meta = sanitize_textarea_field($_POST['meta'] ?? '');

        if ($action === 'add') {
            $wpdb->insert($table, array(
                'plan_code'=>$plan_code, 'type'=>$type, 'provider'=>$provider, 'name'=>$name, 'amount'=>$amount, 'meta'=>$meta
            ), array('%s','%s','%s','%s','%f','%s'));
            echo '<div class="updated"><p>Plan added.</p></div>';
        } elseif ($action === 'edit' && !empty($_POST['id'])) {
            $id = intval($_POST['id']);
            $wpdb->update($table, array('plan_code'=>$plan_code, 'type'=>$type, 'provider'=>$provider, 'name'=>$name, 'amount'=>$amount, 'meta'=>$meta), array('id'=>$id));
            echo '<div class="updated"><p>Plan updated.</p></div>';
        } elseif ($action === 'delete' && !empty($_POST['id'])) {
            $id = intval($_POST['id']);
            $wpdb->delete($table, array('id'=>$id));
            echo '<div class="updated"><p>Plan deleted.</p></div>';
        }
    }

    // Edit form prefill
    $editing = null;
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", intval($_GET['edit'])), ARRAY_A);
    }

    // Fetch all plans
    $plans = $wpdb->get_results("SELECT * FROM {$table} ORDER BY type, provider, name", ARRAY_A);

    // Render admin UI
    echo '<div class="wrap"><h1>Sregle Bot — Manual Plans</h1>';

    // Form (add/edit)
    echo '<h2>' . ($editing ? 'Edit Plan' : 'Add Plan') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('sregle_manual_action','sregle_manual_nonce');
    echo '<input type="hidden" name="sregle_manual_action" value="' . ($editing ? 'edit' : 'add') . '">';
    if ($editing) echo '<input type="hidden" name="id" value="' . intval($editing['id']) . '">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Plan Code</th><td><input name="plan_code" value="' . esc_attr($editing['plan_code'] ?? '') . '" class="regular-text" required></td></tr>';
    echo '<tr><th>Type</th><td><select name="type"><option value="data"' . ((($editing['type'] ?? '')==='data')?' selected':'') . '>data</option><option value="cable"' . ((($editing['type'] ?? '')==='cable')?' selected':'') . '>cable</option></select></td></tr>';
    echo '<tr><th>Provider/Network</th><td><input name="provider" value="' . esc_attr($editing['provider'] ?? '') . '" class="regular-text" required></td></tr>';
    echo '<tr><th>Name</th><td><input name="name" value="' . esc_attr($editing['name'] ?? '') . '" class="regular-text" required></td></tr>';
    echo '<tr><th>Amount</th><td><input name="amount" value="' . esc_attr($editing['amount'] ?? '') . '" class="regular-text" required></td></tr>';
    echo '<tr><th>Meta (JSON)</th><td><textarea name="meta" class="large-text" rows="4">' . esc_textarea($editing['meta'] ?? '') . '</textarea></td></tr>';
    echo '</tbody></table>';
    submit_button($editing ? 'Save Plan' : 'Add Plan');
    echo '</form>';

    // List of plans
    echo '<h2>Existing Plans</h2>';
    echo '<table class="widefat fixed"><thead><tr><th>ID</th><th>Plan Code</th><th>Type</th><th>Provider</th><th>Name</th><th>Amount</th><th>Actions</th></tr></thead><tbody>';
    if ($plans) {
        foreach ($plans as $p) {
            echo '<tr>';
            echo '<td>' . intval($p['id']) . '</td>';
            echo '<td>' . esc_html($p['plan_code']) . '</td>';
            echo '<td>' . esc_html($p['type']) . '</td>';
            echo '<td>' . esc_html($p['provider']) . '</td>';
            echo '<td>' . esc_html($p['name']) . '</td>';
            echo '<td>₦' . number_format((float)$p['amount'],2) . '</td>';
            echo '<td><a href="' . esc_url(add_query_arg('edit', $p['id'])) . '">Edit</a> | ';
            echo '<form method="post" style="display:inline">' . wp_nonce_field('sregle_manual_action','sregle_manual_nonce',true,false);
            echo '<input type="hidden" name="sregle_manual_action" value="delete">';
            echo '<input type="hidden" name="id" value="' . intval($p['id']) . '">';
            echo '<button class="button-link" onclick="return confirm(\'Delete this plan?\')">Delete</button>';

        }
    } else {
        echo '<tr><td colspan="7">No manual plans added yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
     }

/* END Manual Plans */

/* =========================================================
   Activation hook: ensure default options
=========================================================*/
register_activation_hook(__FILE__, function() {
    if (get_option(SREGLE_BOT_OPTION_CMD_PREFIX) === false) update_option(SREGLE_BOT_OPTION_CMD_PREFIX, 'sreg');
});

// Load universal updater
require_once __DIR__ . '/update-checker.php';

// Register updater
sregle_register_updater(
    'https://github.com/Sregle/sregle-whatsapp-bot', // 🔹 Repo URL
    __FILE__,                                  // 🔹 Path to main plugin file
    'sregle-whatsapp-bot'                             // 🔹 Plugin slug (folder name)
    // , 'your-github-token'                   // 🔹 Optional for private repos
);

/* end of plugin */
