<?php

namespace Drupal\commerce_payfast\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class PaymentOffsiteForm extends BasePaymentOffsiteForm
{
    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm( array $form, FormStateInterface $form_state )
    {
        $form = parent::buildConfigurationForm( $form, $form_state );

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;

        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

        $mode = $payment_gateway_plugin->getConfiguration()['mode'] == 'test' ? 'sandbox' : 'www';
        $url = 'https://' . $mode . '.payfast.co.za/eng/process';

        $protocolString = strpos('https://', $_SERVER['HTTP_REFERER']) != false ? 'https' : 'http';
        $notifyUrl = $protocolString . '://' . $_SERVER['SERVER_NAME'] . '/payment/notify/payfast';

        $orderId = $payment->getOrderId();
        $payment->save();

        $merchant_id = $payment_gateway_plugin->getConfiguration()['merchant_id'];
        $merchant_key = $payment_gateway_plugin->getConfiguration()['merchant_key'];
        // Use default sandbox details if no sandbox account details are entered when in test mode.
        if (!isset($merchant_id, $merchant_key) && $payment_gateway_plugin->getConfiguration()['mode'] == 'test') {
          $merchant_id = '10000100';
          $merchant_key = '46f0cd694581a';
        }

        $order = \Drupal\commerce_order\Entity\Order::load( $orderId );

        $data = [
            'merchant_id' => $merchant_id,
            'merchant_key' => $merchant_key,
            'return_url' => $form['#return_url'],
            'cancel_url' => $form['#cancel_url'],
            'notify_url' => $notifyUrl,
            'm_payment_id' => $payment->getOrderId(),
            'amount' => number_format( sprintf( "%.2f", $payment->getAmount()->getNumber() ), 2, '.', '' ),
            'item_name' => 'Order ID: ' . $orderId,
            'custom_int1' => $orderId,
            'custom_str1' => 'PF_DRUPAL_9_COMMERCE_2_1.3.0',
        ];

        foreach ( $order->getItems() as $order_item )
        {
            $product = $order_item->getPurchasedEntity()->getProduct();

            if ( isset( $product->field_subscription_type->value ) && isset( $product->field_recurring_amount->value )
                && isset( $product->field_frequency->value ) && isset( $product->field_cycles->value ) )
            {
              $data['custom_str2'] = gmdate( 'Y-m-d' );
              $data['subscription_type'] = $product->field_subscription_type->value;
              $data['recurring_amount'] = number_format( sprintf( "%.2f", $product->field_recurring_amount->value ), 2, '.', '' );
              $data['frequency'] = $product->field_frequency->value;
              $data['cycles'] = $product->field_cycles->value;
            }
        }

        $pfOutput = '';
        // Create output string
        foreach ( $data as $key => $value )
        {
            $pfOutput .= $key . '=' . urlencode( trim( $value ) ) . '&';
        }
        $passPhrase = trim( $payment_gateway_plugin->getConfiguration()['passphrase'] );

        if ( empty( $passPhrase ) )
        {
            $pfOutput = substr( $pfOutput, 0, -1 );
        }
        else
        {
            $pfOutput = $pfOutput . "passphrase=" . urlencode( $passPhrase );
        }

        $data['signature'] = md5( $pfOutput );
        $data['user_agent'] = 'Drupal Commerce 2';

        return $this->buildRedirectForm( $form, $form_state, $url, $data, 'post' );
    }

}
