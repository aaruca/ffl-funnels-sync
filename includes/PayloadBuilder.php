<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

final class PayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(\WC_Order $order, string $event_name, string $event_key, int $refund_id = 0): array
    {
        $created = $order->get_date_created();
        $paid    = $order->get_date_paid();
        $refund  = $this->get_refund($refund_id);

        $payload = [
            'event'          => $event_name,
            'event_id'       => sprintf('wc_order_%d_%s', $order->get_id(), $event_key),
            'order_id'       => $order->get_id(),
            'order_key'      => $order->get_order_key(),
            'status'         => $order->get_status(),
            'currency'       => $order->get_currency(),
            'totals'         => [
                'subtotal'  => (float) $order->get_subtotal(),
                'shipping'  => (float) $order->get_shipping_total(),
                'tax'       => (float) $order->get_total_tax(),
                'discount'  => (float) $order->get_total_discount(),
                'total'     => (float) $order->get_total(),
                'refunded'  => abs((float) $order->get_total_refunded()),
                'remaining' => max(0.0, (float) $order->get_total() - abs((float) $order->get_total_refunded())),
            ],
            'customer'       => [
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'country'    => $order->get_billing_country(),
                'zip'        => $order->get_billing_postcode(),
            ],
            'attribution'    => [
                'fbp'          => (string) $order->get_meta(Plugin::META_FBP),
                'fbc'          => (string) $order->get_meta(Plugin::META_FBC),
                'client_ip'    => $order->get_customer_ip_address(),
                'user_agent'   => $order->get_customer_user_agent(),
                'source_url'   => (string) $order->get_meta('_wc_order_attribution_source_url'),
                'utm_source'   => (string) $order->get_meta('_wc_order_attribution_utm_source'),
                'utm_medium'   => (string) $order->get_meta('_wc_order_attribution_utm_medium'),
                'utm_campaign' => (string) $order->get_meta('_wc_order_attribution_utm_campaign'),
            ],
            'line_items'     => $this->build_line_items($order->get_items(), false),
            'refund'         => $this->build_refund_payload($order, $refund),
            'created_at'     => $created ? $created->date(\DATE_ATOM) : null,
            'paid_at'        => $paid ? $paid->date(\DATE_ATOM) : null,
            'site'           => home_url(),
            'plugin_version' => FFL_FS_VERSION,
        ];

        return (array) apply_filters('ffl_fs_payload', $payload, $order, $event_name, $refund_id);
    }

    private function get_refund(int $refund_id): ?\WC_Order_Refund
    {
        if ($refund_id <= 0) {
            return null;
        }

        $refund = wc_get_order($refund_id);

        return $refund instanceof \WC_Order_Refund ? $refund : null;
    }

    /**
     * @param iterable<int|string, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function build_line_items(iterable $items, bool $absolute_totals): array
    {
        $line_items = [];

        foreach ($items as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product  = $item->get_product();
            $subtotal = (float) $item->get_subtotal();
            $total    = (float) $item->get_total();
            $quantity = (int) $item->get_quantity();

            if ($absolute_totals) {
                $subtotal = abs($subtotal);
                $total    = abs($total);
                $quantity = abs($quantity);
            }

            $line_items[] = [
                'product_id'   => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'sku'          => $product ? (string) $product->get_sku() : '',
                'name'         => (string) $item->get_name(),
                'quantity'     => $quantity,
                'subtotal'     => $subtotal,
                'total'        => $total,
            ];
        }

        return $line_items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_refund_payload(\WC_Order $order, ?\WC_Order_Refund $refund): ?array
    {
        if (!$refund instanceof \WC_Order_Refund) {
            return null;
        }

        $refunded_at = $refund->get_date_created();
        $refunded    = abs((float) $order->get_total_refunded());
        $order_total = (float) $order->get_total();
        $is_partial  = $refunded + 0.00001 < $order_total;

        return [
            'refund_id'       => $refund->get_id(),
            'parent_order_id' => $refund->get_parent_id(),
            'amount'          => abs((float) $refund->get_amount()),
            'reason'          => $refund->get_reason(),
            'shipping'        => abs((float) $refund->get_shipping_total()),
            'tax'             => abs((float) $refund->get_total_tax()),
            'is_partial'      => $is_partial,
            'line_items'      => $this->build_line_items($refund->get_items(), true),
            'created_at'      => $refunded_at ? $refunded_at->date(\DATE_ATOM) : null,
        ];
    }
}
