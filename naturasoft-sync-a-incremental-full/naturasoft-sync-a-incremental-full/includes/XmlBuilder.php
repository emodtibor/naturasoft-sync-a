<?php
if (!defined('ABSPATH')) exit;

/**
 * Naturasoft_Xml_Builder
 * Naturasoft-kompatibilis rendelés XML építése.
 */
class Naturasoft_Xml_Builder {

  public function build_order_xml(array $o) {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $order = $doc->createElement('Order');
    $doc->appendChild($order);

    $this->el($doc, $order, 'OrderNumber', $o['order_number'] ?? '');
    $this->el($doc, $order, 'OrderId', (string)($o['order_id'] ?? ''));
    $this->el($doc, $order, 'Created', $o['date'] ?? '');
    $this->el($doc, $order, 'Currency', $o['currency'] ?? 'HUF');
    $this->el($doc, $order, 'Status', $o['status'] ?? '');
    $this->el($doc, $order, 'Total', number_format((float)($o['total'] ?? 0), 2, '.', ''));
    $this->el($doc, $order, 'TaxTotal', number_format((float)($o['tax_total'] ?? 0), 2, '.', ''));
    $this->el($doc, $order, 'ShippingTotal', number_format((float)($o['shipping_total'] ?? 0), 2, '.', ''));
    $this->el($doc, $order, 'DiscountTotal', number_format((float)($o['discount_total'] ?? 0), 2, '.', ''));
    $this->el($doc, $order, 'VATNumber', $o['vat_number'] ?? '');

    if (!empty($o['billing']) && is_array($o['billing'])) {
      $billing = $doc->createElement('Billing');
      $order->appendChild($billing);
      $this->el($doc, $billing, 'FirstName', $o['billing']['first_name'] ?? '');
      $this->el($doc, $billing, 'LastName', $o['billing']['last_name'] ?? '');
      $this->el($doc, $billing, 'Company', $o['billing']['company'] ?? '');
      $this->el($doc, $billing, 'Address1', $o['billing']['address_1'] ?? '');
      $this->el($doc, $billing, 'Address2', $o['billing']['address_2'] ?? '');
      $this->el($doc, $billing, 'City', $o['billing']['city'] ?? '');
      $this->el($doc, $billing, 'Postcode', $o['billing']['postcode'] ?? '');
      $this->el($doc, $billing, 'Country', $o['billing']['country'] ?? '');
      $this->el($doc, $billing, 'Email', $o['billing']['email'] ?? '');
      $this->el($doc, $billing, 'Phone', $o['billing']['phone'] ?? '');
    }

    if (!empty($o['shipping']) && is_array($o['shipping'])) {
      $shipping = $doc->createElement('Shipping');
      $order->appendChild($shipping);
      $this->el($doc, $shipping, 'FirstName', $o['shipping']['first_name'] ?? '');
      $this->el($doc, $shipping, 'LastName', $o['shipping']['last_name'] ?? '');
      $this->el($doc, $shipping, 'Company', $o['shipping']['company'] ?? '');
      $this->el($doc, $shipping, 'Address1', $o['shipping']['address_1'] ?? '');
      $this->el($doc, $shipping, 'Address2', $o['shipping']['address_2'] ?? '');
      $this->el($doc, $shipping, 'City', $o['shipping']['city'] ?? '');
      $this->el($doc, $shipping, 'Postcode', $o['shipping']['postcode'] ?? '');
      $this->el($doc, $shipping, 'Country', $o['shipping']['country'] ?? '');
    }

    $items = $doc->createElement('Items');
    $order->appendChild($items);
    foreach (($o['items'] ?? []) as $it) {
      $item = $doc->createElement('Item');
      $items->appendChild($item);
      $this->el($doc, $item, 'SKU', $it['sku'] ?? '');
      $this->el($doc, $item, 'Name', $it['name'] ?? '');
      $this->el($doc, $item, 'Qty', (string)($it['qty'] ?? '0'));
      $this->el($doc, $item, 'PriceExVAT', number_format((float)($it['price_ex_vat'] ?? 0), 2, '.', ''));
      $this->el($doc, $item, 'TaxRate', number_format((float)($it['tax_rate'] ?? 0), 2, '.', ''));
    }

    if (!empty($o['shipping_lines']) && is_array($o['shipping_lines'])) {
      $slines = $doc->createElement('ShippingLines');
      $order->appendChild($slines);
      foreach ($o['shipping_lines'] as $sl) {
        $line = $doc->createElement('Line');
        $slines->appendChild($line);
        $this->el($doc, $line, 'MethodId', $sl['method_id'] ?? '');
        $this->el($doc, $line, 'Total', number_format((float)($sl['total'] ?? 0), 2, '.', ''));
      }
    }

    return $doc->saveXML();
  }

  private function el(DOMDocument $doc, DOMNode $parent, string $name, string $value) {
    $el = $doc->createElement($name);
    $el->appendChild($doc->createTextNode($value));
    $parent->appendChild($el);
  }
}
