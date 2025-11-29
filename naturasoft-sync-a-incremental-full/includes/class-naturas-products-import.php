<?php
if (!defined('ABSPATH')) exit;

// SimpleXLSX – egyfájlos XLSX olvasó, Composer nélkül
require_once __DIR__ . '/lib/SimpleXLSX.php';
require_once __DIR__ . '/lib/SimpleXLS.php';

// Ha a library namespaced (Shuchkin\SimpleXLSX), aliasoljuk globális SimpleXLSX-re
if (class_exists('\Shuchkin\SimpleXLSX') && !class_exists('\SimpleXLSX')) {
    class_alias('\Shuchkin\SimpleXLSX', 'SimpleXLSX');
}

// Ha namespaced (Shuchkin\SimpleXLS), aliasoljuk globális SimpleXLS-re
if (class_exists('\Shuchkin\SimpleXLS') && !class_exists('\SimpleXLS')) {
    class_alias('\Shuchkin\SimpleXLS', 'SimpleXLS');
}

/**
 * Naturasoft XLSX termékimport WooCommerce-be.
 */
class Naturasoft_Products_Import {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'handle_post']);
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Naturasoft Termékimport (XLSX)',
            'Naturasoft Termékimport',
            'manage_woocommerce',
            'nsa-product-import-xlsx',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nincs jogosultság.');
        }
        ?>
        <div class="wrap">
            <h1>Naturasoft Termékimport (XLSX)</h1>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('nsa_import_xlsx', 'nsa_import_xlsx_nonce'); ?>

                <p>
                    <input type="file" name="nsa_file" accept=".xlsx,.xls" required>
                </p>

                <p>
                    <label>Hiányzó ár esetén:
                        <select name="nsa_price_fallback">
                            <option value="0">0 Ft</option>
                            <option value="skip">Sor kihagyása</option>
                        </select>
                    </label>
                </p>

                <p>
                    <label>Kategória elválasztó:
                        <input type="text" name="nsa_cat_sep" value=">" size="2">
                    </label>

                    <label style="margin-left:1em">Kép URL-ek elválasztó:
                        <input type="text" name="nsa_img_sep" value=";" size="2">
                    </label>
                </p>

                <p>
                    <button class="button" name="nsa_action" value="preview">Előnézet</button>
                    <button class="button button-primary" name="nsa_action" value="import">Importálás</button>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_post() {
        if (!isset($_POST['nsa_action'])) return;
        if (!current_user_can('manage_woocommerce')) return;
        if (!wp_verify_nonce($_POST['nsa_import_xlsx_nonce'] ?? '', 'nsa_import_xlsx')) return;
        if (empty($_FILES['nsa_file']['tmp_name'])) return;

        $tmp = $_FILES['nsa_file']['tmp_name'];
        $price_fallback = $_POST['nsa_price_fallback'] ?? '0';
        $cat_sep = $_POST['nsa_cat_sep'] ?? '>';
        $img_sep = $_POST['nsa_img_sep'] ?? ';';

        try {
            $rows = nsa_xlsx_parse($tmp);
        } catch (\Throwable $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>Hiba az XLSX olvasásakor: ' .
                    esc_html($e->getMessage()) . '</p></div>';
            });
            return;
        }

        if ($_POST['nsa_action'] === 'preview') {
            add_action('admin_notices', function() use ($rows) {
                if (!$rows) {
                    echo '<div class="notice notice-warning"><p>Nincs adat az XLSX-ben.</p></div>';
                    return;
                }
                echo '<div class="notice notice-info"><p><strong>Előnézet (első 10 sor):</strong></p>';
                echo '<table class="widefat"><thead><tr>';
                foreach (array_keys($rows[0]) as $h) {
                    echo '<th>' . esc_html($h) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach (array_slice($rows, 0, 10) as $r) {
                    echo '<tr>';
                    foreach ($r as $v) {
                        echo '<td>' . esc_html((string)$v) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            });
            return;
        }

        $report = nsa_xlsx_import_products($rows, [
            'price_fallback' => $price_fallback,
            'cat_sep'        => $cat_sep,
            'img_sep'        => $img_sep,
        ]);

        add_action('admin_notices', function() use ($report) {
            echo '<div class="notice notice-success"><p><strong>Import kész.</strong> ' .
                sprintf(
                    'Létrejött: %d, frissült: %d, kihagyva: %d',
                    $report['created'],
                    $report['updated'],
                    $report['skipped']
                ) . '</p></div>';
        });
    }
}

/**
 * XLSX beolvasása SimpleXLSX-szel
 */
function nsa_xlsx_parse($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // XLSX – SimpleXLSX
    if ($ext === 'xlsx') {
        if (!class_exists('SimpleXLSX') && !class_exists('\Shuchkin\SimpleXLSX')) {
            throw new \RuntimeException('SimpleXLSX osztály nem elérhető XLSX fájlhoz.');
        }

        $xlsx = \SimpleXLSX::parse($path);
        if (!$xlsx) {
            $err = method_exists('\SimpleXLSX', 'parseError')
                ? \SimpleXLSX::parseError()
                : 'ismeretlen hiba';
            throw new \RuntimeException('Nem sikerült beolvasni az XLSX fájlt: ' . $err);
        }

        $rows = $xlsx->rows(); // [ [cell1, cell2,...], ... ]
    }
    // XLS – SimpleXLS
    elseif ($ext === 'xls') {
        if (!class_exists('SimpleXLS') && !class_exists('\Shuchkin\SimpleXLS')) {
            throw new \RuntimeException('SimpleXLS osztály nem elérhető XLS fájlhoz.');
        }

        $xls = \SimpleXLS::parseFile($path);
        if (!$xls) {
            $err = method_exists('\SimpleXLS', 'parseError')
                ? \SimpleXLS::parseError()
                : 'ismeretlen hiba';
            throw new \RuntimeException('Nem sikerült beolvasni az XLS fájlt: ' . $err);
        }

        $rows = $xls->rows();
    }
    else {
        throw new \RuntimeException('Ismeretlen kiterjesztés: ' . $ext . ' (csak .xls vagy .xlsx támogatott).');
    }

    if (empty($rows) || empty($rows[0])) {
        throw new \RuntimeException('Üres Excel fájl vagy hiányzó fejléc.');
    }

    // 1. sor: fejlécek
    $headersRow = $rows[0];
    $headers = [];
    foreach ($headersRow as $idx => $header) {
        $header = trim((string)$header);
        if ($header !== '') {
            $headers[$idx] = $header;
        }
    }

    if (!$headers) {
        throw new \RuntimeException('Hiányoznak a fejléc mezők az első sorban.');
    }

    $out = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $assoc = [];
        foreach ($headers as $colIndex => $header) {
            $assoc[$header] = isset($row[$colIndex]) ? trim((string)$row[$colIndex]) : '';
        }
        $out[] = nsa_normalize_row($assoc);
    }

    // üres sorok kiszűrése
    return array_values(array_filter($out, function($r) {
        return !empty($r['name']) || !empty($r['sku']);
    }));
}


/**
 * Fejlécek normalizálása: Naturasoft / magyar oszlopnevekből belső kulcsok.
 */
function nsa_normalize_row(array $r) {
    $map = [
        'sku'               => ['Cikkszám','SKU'],
        'name'              => ['Megnevezés','Név','Termék megnevezés'],
        'net_price'         => ['Nettó ár'],
        'gross_price'       => ['Bruttó ár'],
        'vat'               => ['ÁFA','ÁFA%'],
        'stock'             => ['Készlet','Mennyiség'],
        'unit'              => ['Mértékegység','Egység'],
        'short_description' => ['Rövid leírás'],
        'description'       => ['Leírás'],
        'category'          => ['Kategória','Kategóriák'],
        'images'            => ['Kép URL','Kép URL-ek'],
    ];

    $norm = [
        'sku'               => '',
        'name'              => '',
        'net_price'         => '',
        'gross_price'       => '',
        'vat'               => '',
        'stock'             => '',
        'unit'              => '',
        'short_description' => '',
        'description'       => '',
        'category'          => '',
        'images'            => '',
    ];

    foreach ($map as $k => $aliases) {
        foreach ($aliases as $a) {
            if (array_key_exists($a, $r) && $r[$a] !== '') {
                $norm[$k] = $r[$a];
                break;
            }
        }
    }

    return $norm;
}

/**
 * Termékek importálása / frissítése
 */
function nsa_xlsx_import_products(array $rows, array $opts = []) {
    $created = $updated = $skipped = 0;
    $cat_sep = $opts['cat_sep'] ?? '>';
    $img_sep = $opts['img_sep'] ?? ';';
    $price_fallback = $opts['price_fallback'] ?? '0';

    foreach ($rows as $r) {
        $name = trim((string)$r['name']);
        $sku  = trim((string)$r['sku']);
        if (!$name || !$sku) { $skipped++; continue; }

        // Ár számítás
        $gross = null;
        if ($r['gross_price'] !== '' && is_numeric($r['gross_price'])) {
            $gross = (float)$r['gross_price'];
        } elseif ($r['net_price'] !== '' && is_numeric($r['net_price']) && is_numeric($r['vat'] ?? null)) {
            $gross = (float)$r['net_price'] * (1 + ((float)$r['vat']) / 100.0);
        } else {
            if ($price_fallback === 'skip') {
                $skipped++;
                continue;
            }
            $gross = 0.0;
        }

        $stock = ($r['stock'] !== '' && is_numeric($r['stock'])) ? (int)$r['stock'] : null;

        // SKU alapú CRUD
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $product = wc_get_product($product_id);
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku($sku);
        }

        $product->set_name($name);
        $product->set_regular_price(wc_format_decimal($gross));
        $product->set_manage_stock(true);
        if ($stock !== null) {
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }

        if (!empty($r['short_description'])) {
            $product->set_short_description(wp_kses_post($r['short_description']));
        }
        if (!empty($r['description'])) {
            $product->set_description(wp_kses_post($r['description']));
        }

        // Kategória
        if (!empty($r['category'])) {
            $cat_ids = nsa_ensure_categories($r['category'], $cat_sep);
            if ($cat_ids) {
                $product->set_category_ids($cat_ids);
            }
        }

        $is_new = !$product_id;
        $product_id = $product->save();

        // Képek (első: kiemelt, többi: galéria)
        if (!empty($r['images'])) {
            $urls = array_filter(array_map('trim', explode($img_sep, $r['images'])));
            nsa_attach_images($product_id, $urls);
        }

        update_post_meta($product_id, '_nsa_source', 'xlsx');
        $row_hash = md5(json_encode([$name, $sku, $gross, $stock, $r['category'] ?? '', $r['images'] ?? '']));
        update_post_meta($product_id, '_nsa_row_hash', $row_hash);

        if ($is_new) $created++; else $updated++;
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
    ];
}

/**
 * Kategória-hierarchia biztosítása (pl. "Fő>Al")
 */
function nsa_ensure_categories($path, $sep = '>') {
    $parts = array_map('trim', explode($sep, $path));
    $parent = 0;
    $ids = [];

    foreach ($parts as $name) {
        if ($name === '') continue;
        $term = term_exists($name, 'product_cat', $parent);
        if (!$term) {
            $term = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
        }
        if (!is_wp_error($term)) {
            $term_id = is_array($term) ? (int)$term['term_id'] : (int)$term;
            $ids[] = $term_id;
            $parent = $term_id;
        }
    }

    return $ids;
}

/**
 * Képek letöltése és hozzárendelése termékhez
 */
function nsa_attach_images($product_id, array $urls) {
    if (empty($urls)) return;
    $media_ids = [];

    foreach ($urls as $i => $url) {
        $attachment_id = media_sideload_image($url, $product_id, null, 'id');
        if (!is_wp_error($attachment_id)) {
            $media_ids[] = (int)$attachment_id;
            if ($i === 0) {
                set_post_thumbnail($product_id, (int)$attachment_id);
            }
        }
    }

    if (count($media_ids) > 1) {
        update_post_meta(
            $product_id,
            '_product_image_gallery',
            implode(',', array_slice($media_ids, 1))
        );
    }
}