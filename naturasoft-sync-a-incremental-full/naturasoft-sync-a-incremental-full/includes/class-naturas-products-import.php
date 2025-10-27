<?php
/**
 * Drop-in: Naturasoft CSV Product Import (compat)
 * Adds POST /wp-json/naturasoft/v1/products endpoint to import products from Naturasoft pipe-delimited CSV.
 * Compatibility: avoids arrow functions; has mbstring fallbacks.
 *
 * Usage in your main plugin file (ensure WooCommerce is loaded first):
 *
 *   add_action('plugins_loaded', function() {
 *       if ( class_exists('WooCommerce') ) {
 *           require_once plugin_dir_path(__FILE__) . 'includes/class-naturas-products-import.php';
 *           if ( class_exists('Naturasoft_Products_Import') ) {
 *               Naturasoft_Products_Import::init();
 *           }
 *       }
 *   }, 20);
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('Naturasoft_Products_Import') ) {
class Naturasoft_Products_Import {
    const OPTION_API_KEY = 'naturasoft_api_key'; // shared option

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('naturasoft/v1', '/products', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'handle_import'),
            'permission_callback' => array(__CLASS__, 'auth_check'),
            'args' => array(
                'csv' => array('type'=>'string','required'=>false),
                'dry_run' => array('type'=>'boolean','required'=>false,'default'=>false),
                'publish' => array('type'=>'boolean','required'=>false,'default'=>true),
            ),
        ));
    }

    public static function auth_check( WP_REST_Request $request ) {
        // 1️⃣ Token források: header / bearer / ?key=
        $provided = $request->get_header('X-Naturasoft-Key');
        if ( empty($provided) ) {
            $auth = $request->get_header('Authorization');
            if ( is_string($auth) && stripos($auth, 'Bearer ') === 0 ) {
                $provided = trim(substr($auth, 7));
            }
        }
        if ( empty($provided) ) {
            $provided = $request->get_param('key');
        }

        // 2️⃣ Megpróbáljuk a fő plugin opciójából (Naturasoft Sync A) is kiolvasni
        $expected = get_option(self::OPTION_API_KEY, '');
        if ( empty($expected) ) {
            $sync_opts = get_option('naturasoft_sync_a_options', array());
            if ( is_array($sync_opts) && ! empty($sync_opts['api_token']) ) {
                $expected = $sync_opts['api_token'];
            }
        }

        // 3️⃣ Ha nincs kulcs beállítva, csak admin hívhatja
        if ( empty($expected) ) {
            return current_user_can('manage_options');
        }

        // 4️⃣ Összehasonlítás (biztonságos)
        return is_string($provided) && hash_equals((string)$expected, (string)$provided);
    }


    public static function handle_import( WP_REST_Request $request ) {
        $dry_run = (bool) $request->get_param('dry_run');
        $publish = (bool) $request->get_param('publish');
        $csv     = $request->get_param('csv');

        if ( empty($csv) && ! empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) ) {
            $csv = file_get_contents($_FILES['file']['tmp_name']);
        }
        if ( empty($csv) ) {
            return new WP_REST_Response(array('ok'=>false,'error'=>'Hiányzik a CSV (csv param vagy file).'), 400);
        }

        $csv = self::to_utf8($csv);
        $rows = self::parse_pipe_csv($csv);
        if ( empty($rows) ) return new WP_REST_Response(array('ok'=>false,'error'=>'Üres vagy hibás CSV.'), 400);

        $required_headers = array('NATURASOFTID','MEGNEVEZES','TERMEKKOD','CIKKSZAM','VTSZ','MEE','SZABADKESZLET');
        $missing = array_diff($required_headers, array_keys($rows[0]));
        if ( ! empty($missing) ) {
            return new WP_REST_Response(array('ok'=>false,'error'=>'Hiányzó fejléc(ek): '.implode(', ',$missing)), 400);
        }

        $result = array('ok'=>true,'dry_run'=>$dry_run,'created'=>0,'updated'=>0,'skipped'=>0,'items'=>array());

        foreach ($rows as $i=>$row) {
            $line = $i+2;
            $naturas_id = trim((string)(isset($row['NATURASOFTID']) ? $row['NATURASOFTID'] : ''));
            $name       = trim((string)(isset($row['MEGNEVEZES']) ? $row['MEGNEVEZES'] : ''));
            $termekkod  = trim((string)(isset($row['TERMEKKOD']) ? $row['TERMEKKOD'] : ''));
            $cikkszam   = trim((string)(isset($row['CIKKSZAM']) ? $row['CIKKSZAM'] : ''));
            $vtsz       = trim((string)(isset($row['VTSZ']) ? $row['VTSZ'] : ''));
            $mee        = trim((string)(isset($row['MEE']) ? $row['MEE'] : ''));
            $kstr       = trim((string)(isset($row['SZABADKESZLET']) ? $row['SZABADKESZLET'] : '0'));

            if ($naturas_id === '' || $name === '') {
                $result['skipped']++;
                $result['items'][] = array('line'=>$line,'status'=>'skipped','reason'=>'NATURASOFTID és MEGNEVEZES kötelező.');
                continue;
            }

            $norm = str_replace(array(','), array('.'), $kstr);
            $stock_qty = is_numeric($norm) ? (float)$norm : 0.0;
            $sku = $termekkod !== '' ? $termekkod : $cikkszam;

            $existing_id = self::find_product_by_naturas_id($naturas_id);
            if (!$existing_id && $sku!=='') {
                $maybe = function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
                if ($maybe) {
                    $existing_id = (int)$maybe;
                    if (!$dry_run) update_post_meta($existing_id,'_naturas_id',$naturas_id);
                }
            }

            $summary = array('line'=>$line,'naturas_id'=>$naturas_id,'name'=>$name,'sku'=>$sku,'stock_quantity'=>$stock_qty,'mee'=>$mee,'vtsz'=>$vtsz);
            if ($dry_run) {
                $summary['status'] = $existing_id ? 'would_update' : 'would_create';
                $result['items'][] = $summary;
                continue;
            }

            if ($existing_id) {
                $pid = $existing_id;
                wp_update_post(array('ID'=>$pid,'post_title'=>$name));
                if ($sku!=='') update_post_meta($pid,'_sku', function_exists('wc_clean')? wc_clean($sku): $sku);
                update_post_meta($pid,'_manage_stock','yes');
                update_post_meta($pid,'_stock', function_exists('wc_stock_amount')? wc_stock_amount($stock_qty): $stock_qty);
                update_post_meta($pid,'_stock_status',$stock_qty>0?'instock':'outofstock');
                update_post_meta($pid,'_naturas_id',$naturas_id);
                if ($vtsz!=='') update_post_meta($pid,'_vtsz',$vtsz);
                if ($mee!=='')  update_post_meta($pid,'_mee',$mee);
                self::upsert_custom_attributes($pid,array('MEE'=>$mee,'VTSZ'=>$vtsz));
                $result['updated']++; $summary['status']='updated'; $summary['product_id']=$pid; $result['items'][]=$summary;
            } else {
                $pid = wp_insert_post(array('post_type'=>'product','post_title'=>$name,'post_status'=>$publish?'publish':'draft'));
                if (is_wp_error($pid)) { $result['skipped']++; $summary['status']='error'; $summary['error']=$pid->get_error_message(); $result['items'][]=$summary; continue; }
                update_post_meta($pid,'_visibility','visible');
                update_post_meta($pid,'_naturas_id',$naturas_id);
                if ($sku!=='') update_post_meta($pid,'_sku', function_exists('wc_clean')? wc_clean($sku): $sku);
                if (function_exists('wp_set_object_terms')) wp_set_object_terms($pid,'simple','product_type');
                update_post_meta($pid,'_manage_stock','yes');
                update_post_meta($pid,'_stock', function_exists('wc_stock_amount')? wc_stock_amount($stock_qty): $stock_qty);
                update_post_meta($pid,'_stock_status',$stock_qty>0?'instock':'outofstock');
                if ($vtsz!=='') update_post_meta($pid,'_vtsz',$vtsz);
                if ($mee!=='')  update_post_meta($pid,'_mee',$mee);
                self::upsert_custom_attributes($pid,array('MEE'=>$mee,'VTSZ'=>$vtsz));
                $result['created']++; $summary['status']='created'; $summary['product_id']=$pid; $result['items'][]=$summary;
            }
        }

        return new WP_REST_Response($result,200);
    }

    private static function to_utf8( $s ) {
        // Strip UTF-8 BOM
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        // mbstring fallback
        if (function_exists('mb_check_encoding') && ! mb_check_encoding($s, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1250, ISO-8859-2, ISO-8859-1, UTF-8');
            } elseif (function_exists('iconv')) {
                $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $s);
                if ($converted !== false) $s = $converted;
            }
        }
        return $s;
    }

    private static function parse_pipe_csv( $csv ) {
        $lines = preg_split("/\r\n|\n|\r/", $csv);
        // remove empty/whitespace-only lines
        $tmp = array();
        foreach ($lines as $l) { if (trim($l) !== '') $tmp[] = $l; }
        $lines = array_values($tmp);
        if (count($lines) < 2) return array();

        $header = array_map('trim', explode('|', $lines[0]));
        $rows = array();
        for ($i=1; $i<count($lines); $i++) {
            $cols = explode('|', $lines[$i]);
            if (count($cols) < count($header)) {
                $cols = array_pad($cols, count($header), '');
            }
            $assoc = array();
            foreach ($header as $idx => $key) {
                $assoc[$key] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    private static function find_product_by_naturas_id( $naturas_id ) {
        global $wpdb;
        $pid = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
            '_naturas_id', $naturas_id
        ));
        return $pid ? (int)$pid : null;
    }

    private static function upsert_custom_attributes( $product_id, $attrs ) {
        $existing = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($existing)) $existing = array();
        foreach ($attrs as $name => $value){
            if ($value===null || $value==='') continue;
            $key = sanitize_title($name);
            $existing[$key] = array(
                'name'=> function_exists('wc_clean')? wc_clean($name): $name,
                'value'=> function_exists('wc_clean')? wc_clean($value): $value,
                'is_visible'=>1,
                'is_variation'=>0,
                'is_taxonomy'=>0,
            );
        }
        update_post_meta($product_id, '_product_attributes', $existing);
    }
}}
