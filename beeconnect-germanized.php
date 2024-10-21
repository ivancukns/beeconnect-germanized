<?php
/*
Plugin Name: Beeconnect Billbee - Germanized
Description: Billbee integration for the Germanized plugin
Version: 1.0
Author: Beeconnect GbR
Author URI: https://beeconnect.io/
*/

use Vendidero\Germanized\Shipments\Shipment;

if ( !defined('ABSPATH') ) {
    exit;
}

class Beeconnect_Billbee_Shipping_Data_Update {

    public function __construct() {
        add_filter( 'woocommerce_rest_pre_insert_shop_order_object', [$this, 'update_germanized_shipment_status'], 10, 3 );
    }

    /**
     * Hook into the order update event, triggered via API order-updates.
     * If ShipperName and TrackingNumber are available in the meta_data of the incoming request,
     * update the germanized-shipment status to 'gzd-shipped' and send the corresponding email.
     *
     * @param WC_Order $order
     * @param WP_REST_Request $request
     * @param bool $creating
     * @return WC_Order
     */
    public function update_germanized_shipment_status( $order, $request, $creating) {
        if ( !$creating ) {
            $logger = new WC_Logger();

            if ( !is_a( $order, 'WC_Order' ) ) {
                $logger->error('Passed $order parameter is not an instance of WC_Order class', ['source' => 'beeconnect-germanized']);
                return $order;
            }

            $request_body = $request->get_body();
            $order_id = $order->get_id();

            if ( empty( $request_body ) ) {
                return $order;
            }

            $request_body = json_decode($request_body);

            if ( empty($request_body->meta_data) ) {
                return $order;
            }

            $shipping_info = [];
            foreach ($request_body->meta_data as $meta_data) {
                if ($meta_data->key === '_shipping_info_1' &&
                    !empty( $meta_data->value->shipper_name ) &&
                    !empty( $meta_data->value->tracking_number )
                ) {
                    $shipping_info['shipper_name'] = $meta_data->value->shipper_name;
                    $shipping_info['tracking_number'] = $meta_data->value->tracking_number;
                }
            }

            if ( empty( $shipping_info ) ||
                empty($shipping_info['shipper_name']) ||
                empty($shipping_info['tracking_number'])
            ) {
                return $order;
            }

            $shipping_info['shipper_name'] = $this->map_shipping_provider($shipping_info['shipper_name']);

            $order_shipment = wc_gzd_get_shipment_order( $order );
            if ( false === $order_shipment) {
                $logger->error("Order ID: $order_id - Germanized shipment order couldnt be retrieved", ['source' => 'beeconnect-germanized']);
                return $order;
            }

            $shipments = $order_shipment->get_shipments();
            if ( empty( $shipments ) ) {
                $logger->error("Order ID: $order_id - No shipments found for this order", ['source' => 'beeconnect-germanized']);
                return $order;
            }
            $shipment = $shipments[0];

            $shipment = $this->update_shipment_data( $shipment, $shipping_info['shipper_name'], $shipping_info['tracking_number']);
            $this->send_germanized_shipped_email( $shipment );

        }
        return $order;
    }

    /**
     * Map the incoming shipping provider code
     * to the shipping provider code in the woo-shop.
     * Left are codes from Billbee, right are codes in the Woo-Shop
     *
     * @param string $shipping_provider
     * @return string
     */
    private function map_shipping_provider(string $shipping_provider): string
    {
        return match ($shipping_provider) {
            'other', 'dhl' => 'dhl',
            'ups' => 'ups',
            'gls' => 'gls',
            'dpd' => 'dpd',
            default => 'billbee',
        };
    }

    /**
     * Update shipping provider, tracking_id and shipment status
     *
     * @param Shipment $shipment
     * @param string $shipping_provider
     * @param mixed $tracking_id
     * @return Shipment
     */
    private function update_shipment_data (
        Shipment $shipment,
        string $shipping_provider,
        mixed $tracking_id
    ): Shipment
    {
        $shipment->set_shipping_provider( strtolower( $shipping_provider ) );
        $shipment->set_tracking_id( $tracking_id );
        $shipment->update_status( 'gzd-shipped' );
        $shipment->save();

        return $shipment;
    }

    /**
     * Send the germanized shipment email
     *
     * @param Shipment $shipment
     * @return void
     */
    private function send_germanized_shipped_email ( Shipment $shipment ): void
    {
        $shipment_email = new WC_GZD_Email_Customer_Shipment();
        $shipment_email->trigger( $shipment->get_id() );
    }
}

new Beeconnect_Billbee_Shipping_Data_Update();
