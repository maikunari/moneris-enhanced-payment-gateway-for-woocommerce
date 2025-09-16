<?php
/**
 * Payment form template
 *
 * @package MonerisEnhancedGateway
 * @var WC_Payment_Gateway $gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="moneris-payment-form" class="moneris-payment-form">
    <?php if ( $gateway->get_description() ) : ?>
        <div class="moneris-payment-description">
            <?php echo wpautop( wptexturize( $gateway->get_description() ) ); ?>
        </div>
    <?php endif; ?>

    <?php if ( $gateway->supports( 'tokenization' ) && is_checkout() ) : ?>
        <div class="moneris-saved-payment-methods">
            <?php $gateway->saved_payment_methods(); ?>
        </div>
    <?php endif; ?>

    <fieldset id="wc-<?php echo esc_attr( $gateway->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
        <?php do_action( 'woocommerce_credit_card_form_start', $gateway->id ); ?>

        <div class="form-row form-row-wide">
            <label for="moneris-card-number">
                <?php esc_html_e( 'Card Number', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                <span class="required">*</span>
            </label>
            <div class="moneris-card-number-wrapper">
                <input 
                    id="moneris-card-number" 
                    class="input-text wc-credit-card-form-card-number" 
                    inputmode="numeric" 
                    autocomplete="cc-number" 
                    autocorrect="no" 
                    autocapitalize="no" 
                    spellcheck="no" 
                    type="tel" 
                    placeholder="•••• •••• •••• ••••" 
                    name="moneris_card_number" 
                />
                <span class="moneris-card-type"></span>
            </div>
        </div>

        <div class="form-row form-row-first">
            <label for="moneris-card-expiry">
                <?php esc_html_e( 'Expiry (MM/YY)', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                <span class="required">*</span>
            </label>
            <input 
                id="moneris-card-expiry" 
                class="input-text wc-credit-card-form-card-expiry" 
                inputmode="numeric" 
                autocomplete="cc-exp" 
                autocorrect="no" 
                autocapitalize="no" 
                spellcheck="no" 
                type="tel" 
                placeholder="<?php esc_attr_e( 'MM / YY', 'moneris-enhanced-gateway-for-woocommerce' ); ?>" 
                name="moneris_card_expiry" 
            />
        </div>

        <div class="form-row form-row-last">
            <label for="moneris-card-cvc">
                <?php esc_html_e( 'Card Code', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                <span class="required">*</span>
            </label>
            <input 
                id="moneris-card-cvc" 
                class="input-text wc-credit-card-form-card-cvc" 
                inputmode="numeric" 
                autocomplete="off" 
                autocorrect="no" 
                autocapitalize="no" 
                spellcheck="no" 
                type="tel" 
                maxlength="4" 
                placeholder="<?php esc_attr_e( 'CVC', 'moneris-enhanced-gateway-for-woocommerce' ); ?>" 
                name="moneris_card_cvc" 
            />
        </div>

        <?php if ( $gateway->supports( 'tokenization' ) && is_user_logged_in() && $gateway->get_option( 'save_cards' ) === 'yes' ) : ?>
            <div class="form-row form-row-wide">
                <p class="form-row woocommerce-SavedPaymentMethods-saveNew">
                    <input 
                        id="wc-<?php echo esc_attr( $gateway->id ); ?>-new-payment-method" 
                        name="wc-<?php echo esc_attr( $gateway->id ); ?>-new-payment-method" 
                        type="checkbox" 
                        value="true" 
                    />
                    <label for="wc-<?php echo esc_attr( $gateway->id ); ?>-new-payment-method">
                        <?php esc_html_e( 'Save to account', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                    </label>
                </p>
            </div>
        <?php endif; ?>

        <?php do_action( 'woocommerce_credit_card_form_end', $gateway->id ); ?>

        <div class="clear"></div>
    </fieldset>
</div>