<?php
/**
 * Plugin Name: Naturasoft Sync A (Incremental, HPOS-safe)
 * Description: WooCommerce → Naturasoft XML export. Incrementális batch/single: csak a még nem exportált rendelések. HPOS-safe meta mentés (CRUD API). Tartalmaz: /pull-batch-xml, /pull-next-xml, /order-xml, /debug-export-flags
 * Version: 0.7.4
 * Author: Emőd Tibor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('NSA_OPT_KEY', 'naturasoft_sync_a_options');
define('NSA_UPLOAD_DIR', 'naturasoft-xml');
define('NSA_EXPORTED_META', '_nsa_exported'); // ISO időbélyeg vagy "1"

require_once __DIR__ . '/includes/XmlBuilder.php';
add_action('plugins_loaded', function () {
    if ( class_exists('WooCommerce') ) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-naturas-products-import.php';
        if ( class_exists('Naturasoft_Products_Import') ) {
            Naturasoft_Products_Import::init();
        }
    }
}, 20);
final class Naturasoft_Sync_A {

  public function __construct() {
    add_action('admin_menu', [$this,'admin_menu']);
    add_action('woocommerce_order_status_changed', [$this,'on_status_change'], 10, 4);
    add_action('rest_api_init', [$this,'register_rest']);
    add_action('init', [$this,'ensure_upload_dir']);
  }

  private function get_opt(){
    $opt = get_option(NSA_OPT_KEY, []);
    $opt += [
      'export_on_status' => [],
      'company_vat_field'=> '_billing_vat',
      'api_token'        => '',
      'rolling_statuses' => [],
      'batch_limit'      => 50,
    ];
    return $opt;
  }

  public function admin_menu() {
    add_menu_page('Naturasoft Sync A','Naturasoft Sync A','manage_options','naturasoft-sync-a',[$this,'settings_page'],'dashicons-randomize',56);
    add_submenu_page('naturasoft-sync-a','Manuális export','Manuális export','manage_options','naturasoft-sync-a-export',[$this,'export_page']);
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) return;
    $opt = $this->get_opt();

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['nsa_save']) && check_admin_referer('nsa_save')) {
      $opt['export_on_status'] = array_map('sanitize_text_field', $_POST['export_on_status'] ?? []);
      $opt['company_vat_field']= sanitize_text_field($_POST['company_vat_field'] ?? '_billing_vat');
      $opt['api_token']        = sanitize_text_field($_POST['api_token'] ?? '');
      $opt['rolling_statuses'] = array_map('sanitize_text_field', $_POST['rolling_statuses'] ?? []);
      $opt['batch_limit']      = max(1, intval($_POST['batch_limit'] ?? $opt['batch_limit']));
      update_option(NSA_OPT_KEY, $opt);
      echo '<div class="updated"><p>Mentve.</p></div>';
    }

    if (isset($_POST['nsa_reset_exported']) && check_admin_referer('nsa_reset_exported')) {
      $this->reset_exported_flags();
      echo '<div class="updated"><p>Exportált jelzők törölve.</p></div>';
    }

    $statuses = wc_get_order_statuses();
    ?>
    <div class="wrap">
      <h1>Naturasoft Sync A — Beállítások</h1>
      <form method="post">
        <?php wp_nonce_field('nsa_save'); ?>
        <table class="form-table">
          <tr>
            <th scope="row">Export státuszok (automatikus XML generálás)</th>
            <td>
              <?php foreach ($statuses as $key=>$label): ?>
                <label style="display:inline-block;margin:6px 12px 6px 0;">
                  <input type="checkbox" name="export_on_status[]" value="<?php echo esc_attr($key); ?>"
                    <?php echo in_array($key, $opt['export_on_status']) ? 'checked':''; ?>>
                  <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="company_vat_field">Vevő adószám meta mező</label></th>
            <td>
              <input id="company_vat_field" type="text" name="company_vat_field" value="<?php echo esc_attr($opt['company_vat_field']); ?>" class="regular-text">
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="api_token">API token (REST)</label></th>
            <td>
              <input id="api_token" type="text" name="api_token" value="<?php echo esc_attr($opt['api_token']); ?>" class="regular-text" placeholder="pl. 9b2f0e6c...">
              <p class="description">REST hívások: <code>?token=&lt;TOKEN&gt;</code> vagy <code>Authorization: Bearer &lt;TOKEN&gt;</code>.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Rolling / Batch — figyelt státuszok</th>
            <td>
              <?php foreach ($statuses as $key=>$label): ?>
                <label style="display:inline-block;margin:6px 12px 6px 0;">
                  <input type="checkbox" name="rolling_statuses[]" value="<?php echo esc_attr($key); ?>"
                    <?php echo in_array($key, $opt['rolling_statuses']) ? 'checked':''; ?>>
                  <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
              <p class="description">Ha üres, minden státuszt figyel.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="batch_limit">Batch limit</label></th>
            <td>
              <input id="batch_limit" type="number" min="1" step="1" name="batch_limit" value="<?php echo intval($opt['batch_limit']); ?>" class="small-text">
              <p class="description">Ennyi rendelést küldünk vissza egy XML-ben (alap: 50).</p>
            </td>
          </tr>
        </table>
        <p><button class="button button-primary" name="nsa_save" value="1">Mentés</button></p>
      </form>

      <h2>Export-jelzők kezelése</h2>
      <form method="post" onsubmit="return confirm('Biztosan törlöd az exportált jelzőket? Újra le fognak jönni a rendelések.');">
        <?php wp_nonce_field('nsa_reset_exported'); ?>
        <button class="button">Exportált jelzők törlése</button>
      </form>

      <h2>Hasznos végpontok</h2>
      <ul>
        <li><code>GET /wp-json/nsync-a/v1/pull-batch-xml</code> — több rendelés egy XML-ben (application/xml)</li>
        <li><code>GET /wp-json/nsync-a/v1/pull-next-xml</code> — következő rendelés (application/xml)</li>
        <li><code>GET /wp-json/nsync-a/v1/order-xml?order_id=123</code> — konkrét rendelés (JSON URL)</li>
        <li><code>GET /wp-json/nsync-a/v1/debug-export-flags?limit=10</code> — ellenőrzés: id, order_number, exported_flag</li>
      </ul>
    </div>
    <?php
  }

  public function export_page() {
    if (!current_user_can('manage_options')) return;

    $exported = [];

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['nsa_export']) && check_admin_referer('nsa_export')) {
      $date_from = sanitize_text_field($_POST['date_from'] ?? '');
      $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
      $statuses  = array_map('sanitize_text_field', $_POST['statuses'] ?? []);

      $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'status' => $statuses ?: array_keys(wc_get_order_statuses()),
        'meta_query' => [
          'relation' => 'OR',
          [ 'key' => NSA_EXPORTED_META, 'compare' => 'NOT EXISTS' ],
          [ 'key' => NSA_EXPORTED_META, 'value' => '', 'compare' => '=' ],
        ],
      ];
      if ($date_from) $args['date_created'] = '>=' . $date_from . ' 00:00:00';
      if ($date_to)   $args['date_modified'] = '<=' . $date_to   . ' 23:59:59';

      $orders = wc_get_orders($args);
      foreach ($orders as $order) {
        $path = $this->export_order_xml($order);
        if ($path) {
          $exported[] = $path;
          $this->mark_exported($order);
        }
      }
    }

    $statuses = wc_get_order_statuses();
    ?>
    <div class="wrap">
      <h1>Manuális export (Naturasoft XML)</h1>
      <form method="post">
        <?php wp_nonce_field('nsa_export'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="date_from">Dátum -tól</label></th>
            <td><input type="date" id="date_from" name="date_from"></td>
          </tr>
          <tr>
            <th scope="row"><label for="date_to">Dátum -ig</label></th>
            <td><input type="date" id="date_to" name="date_to"></td>
          </tr>
          <tr>
            <th scope="row">Státuszok</th>
            <td>
              <?php foreach ($statuses as $key=>$label): ?>
                <label style="display:inline-block;margin:6px 12px 6px 0;">
                  <input type="checkbox" name="statuses[]" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
            </td>
          </tr>
        </table>
        <p><button class="button button-primary" name="nsa_export" value="1">XML-ek generálása</button></p>
      </form>

      <?php if (!empty($exported)): ?>
        <h2>Elkészült fájlok</h2>
        <ul>
          <?php foreach ($exported as $file): 
            $url = $this->path_to_url($file);
          ?>
            <li><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(basename($file)); ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
  }

  public function register_rest() {
    $auth_cb = function( WP_REST_Request $r ){
      if ( current_user_can('manage_woocommerce') ) return true;
      $opt = $this->get_opt();
      $token = $opt['api_token'] ?? '';
      if ( $token ) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ( $hdr === 'Bearer ' . $token ) return true;
        $q = $r->get_param('token');
        if ( is_string($q) && hash_equals($token, $q) ) return true;
      }
      return new WP_Error('rest_forbidden', __('Elnézést, nincs megfelelő jogosultság a kívánt művelethez.'), ['status'=>401]);
    };

    register_rest_route('nsync-a/v1','/order-xml', [
      'methods' => 'GET',
      'permission_callback' => $auth_cb,
      'callback' => function(WP_REST_Request $r){
        $order_id = absint($r->get_param('order_id'));
        if (!$order_id) return new WP_Error('bad_request','order_id kötelező', ['status'=>400]);
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('not_found','Rendelés nem található', ['status'=>404]);

        $file = $this->export_order_xml($order);
        if (!$file) return new WP_Error('export_failed','XML export sikertelen', ['status'=>500]);

        return new WP_REST_Response([ 'ok'=>true, 'file'=> $file, 'url'=> $this->path_to_url($file) ]);
      }
    ]);

    register_rest_route('nsync-a/v1','/pull-next-xml', [
      'methods' => 'GET',
      'permission_callback' => $auth_cb,
      'callback' => function(WP_REST_Request $r){
        $orders = $this->get_unexported_orders(1);
        if (empty($orders)) return new WP_REST_Response(null, 204);
        $order = $orders[0];
        $xml = $this->build_order_xml_string($order);
        $this->mark_exported($order);

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="order-'.$order->get_order_number().'.xml"');
        echo $xml;
        exit;
      }
    ]);

    register_rest_route('nsync-a/v1','/pull-batch-xml', [
      'methods' => 'GET',
      'permission_callback' => $auth_cb,
      'callback' => function(WP_REST_Request $r){
        $limit = max(1, intval($r->get_param('limit') ?: $this->get_opt()['batch_limit']));
        $orders = $this->get_unexported_orders($limit);
        if (empty($orders)) return new WP_REST_Response(null, 204);

        $xml = $this->build_orders_xml_string($orders);
        foreach ($orders as $o){ $this->mark_exported($o); }

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="orders-batch.xml"');
        echo $xml;
        exit;
      }
    ]);

    // Debug endpoint to quickly verify flags
    register_rest_route('nsync-a/v1','/debug-export-flags', [
      'methods' => 'GET',
      'permission_callback' => function( WP_REST_Request $r ){ return current_user_can('manage_woocommerce'); },
      'callback' => function(WP_REST_Request $r){
        $limit = max(1, intval($r->get_param('limit') ?: 10));
        $orders = wc_get_orders([
          'limit' => $limit,
          'type'  => 'shop_order',
          'orderby' => 'ID',
          'order'   => 'DESC',
          'return'  => 'objects',
        ]);
        $out = [];
        foreach ($orders as $o){
          $out[] = [
            'id' => $o->get_id(),
            'order_number' => $o->get_order_number(),
            'exported_meta' => $o->get_meta(NSA_EXPORTED_META, true),
          ];
        }
        return new WP_REST_Response($out);
      }
    ]);
  }

  private function get_unexported_orders($limit){
    $opt = $this->get_opt();
    $statuses = $opt['rolling_statuses'];
    $args = [
      'limit' => $limit,
      'type'  => 'shop_order',
      'orderby' => 'ID',
      'order'   => 'ASC',
      'status'  => !empty($statuses) ? $statuses : array_keys( wc_get_order_statuses() ),
      'return'  => 'objects',
      'meta_query' => [
        'relation' => 'OR',
        [ 'key' => NSA_EXPORTED_META, 'compare' => 'NOT EXISTS' ],
        [ 'key' => NSA_EXPORTED_META, 'value' => '', 'compare' => '=' ],
      ],
    ];
    return wc_get_orders($args);
  }

  private function mark_exported(WC_Order $order){
    $order->update_meta_data(NSA_EXPORTED_META, current_time('mysql'));
    $order->save(); // HPOS-safe
  }

  private function reset_exported_flags(){
    $orders = wc_get_orders([
      'limit' => -1,
      'return'=> 'objects',
      'meta_query' => [
        [ 'key' => NSA_EXPORTED_META, 'compare' => 'EXISTS' ]
      ],
    ]);
    foreach ($orders as $o){
      $o->delete_meta_data(NSA_EXPORTED_META);
      $o->save();
    }
  }

  private function build_order_xml_string(WC_Order $order){
    $opt = $this->get_opt();
    $vat_key = $opt['company_vat_field'] ?? '_billing_vat';
    $vat_num = $order->get_meta($vat_key, true);

    $builder = new Naturasoft_Xml_Builder();
    return $builder->build_order_xml([
      'order_number' => $order->get_order_number(),
      'order_id' => $order->get_id(),
      'date' => $order->get_date_created() ? $order->get_date_created()->date('c') : '',
      'currency' => $order->get_currency(),
      'status' => $order->get_status(),
      'total' => (float)$order->get_total(),
      'tax_total' => (float)$order->get_total_tax(),
      'shipping_total' => (float)$order->get_shipping_total(),
      'discount_total' => (float)$order->get_discount_total(),
      'billing' => $order->get_address('billing'),
      'shipping'=> $order->get_address('shipping'),
      'vat_number'=> $vat_num ?: '',
      'items' => array_map(function($item) use ($order){
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : '';
        $price_ex = $product ? wc_get_price_excluding_tax($product, ['qty'=>1]) : 0;
        $taxes = $order->get_taxes();
        $tax_rate = 0.0;
        if (count($taxes)) {
          $first = array_values($taxes)[0];
          $tax_rate = (float)($first->get_rate_percent() ?? 0);
        }
        return [
          'sku' => (string)$sku,
          'name'=> (string)$item->get_name(),
          'qty' => (float)$item->get_quantity(),
          'price_ex_vat'=> (float)$price_ex,
          'tax_rate'=> (float)$tax_rate,
        ];
      }, $order->get_items()),
      'shipping_lines' => array_map(function($sl){
        return [
          'method_id' => $sl->get_method_id(),
          'total' => (float)$sl->get_total()
        ];
      }, $order->get_shipping_methods())
    ]);
  }

  private function build_orders_xml_string(array $orders){
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement('Orders');
    $doc->appendChild($root);

    foreach ($orders as $o){
      $x = $this->build_order_xml_string($o);
      $tmp = new DOMDocument();
      $tmp->loadXML($x);
      $node = $doc->importNode($tmp->documentElement, true);
      $root->appendChild($node);
    }
    return $doc->saveXML();
  }

  public function ensure_upload_dir() {
    $this->get_export_dir();
  }

  public function on_status_change($order_id, $old_status, $new_status, $order) {
    $opt = $this->get_opt();
    $watched = $opt['export_on_status'] ?? [];
    if (!in_array('wc-'.$new_status, $watched)) return;

    if (!$order instanceof WC_Order) $order = wc_get_order($order_id);
    $path = $this->export_order_xml($order);
    if ($path) $this->mark_exported($order);
  }

  private function export_order_xml(WC_Order $order) {
    $upload_dir = $this->get_export_dir();
    if (!$upload_dir) return false;

    $xml = $this->build_order_xml_string($order);
    $filename = sprintf('order-%s.xml', $order->get_order_number());
    $path = trailingslashit($upload_dir) . $filename;
    file_put_contents($path, $xml);
    return $path;
  }

  private function get_export_dir() {
    $upload = wp_get_upload_dir();
    if (!empty($upload['error'])) return false;
    $dir = trailingslashit($upload['basedir']) . NSA_UPLOAD_DIR;
    if (!file_exists($dir)) {
      wp_mkdir_p($dir);
    }
    return $dir;
  }

  private function path_to_url($path) {
    $upload = wp_get_upload_dir();
    $basedir = trailingslashit($upload['basedir']);
    $baseurl = trailingslashit($upload['baseurl']) . NSA_UPLOAD_DIR . '/';
    if (strpos($path, $basedir) === 0) {
      return $baseurl . basename($path);
    }
    return '';
  }
}

add_action('plugins_loaded', function(){ new Naturasoft_Sync_A(); });
// Egység kiírása a termékoldalon a név alatt
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }

    $unit = get_post_meta($product->get_id(), '_nsa_unit', true);
    if ($unit) {
        echo '<p class="nsa-unit" style="margin-bottom:0.5em;">';
        echo esc_html__('Egység: ', 'naturasoft-sync-a') . esc_html($unit);
        echo '</p>';
    }
}, 6); // 5 = title, 10 = rating, szóval 6-tal kb. a cím után jön

// Ár után egység kiírása: " / m"
add_filter('woocommerce_get_price_suffix', function ($suffix, $product) {
    if (!$product instanceof WC_Product) {
        return $suffix;
    }

    $unit = get_post_meta($product->get_id(), '_nsa_unit', true);
    if ($unit) {
        // Ha van már suffix (pl. adó szöveg), ahhoz fűzzük
        $suffix .= ' / ' . esc_html($unit);
    }

    return $suffix;
}, 10, 2);

add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    if (!isset($cart_item['product_id'])) {
        return $name;
    }
    $unit = get_post_meta($cart_item['product_id'], '_nsa_unit', true);
    if ($unit) {
        $name .= ' <small>(' . esc_html($unit) . ')</small>';
    }
    return $name;
}, 10, 3);