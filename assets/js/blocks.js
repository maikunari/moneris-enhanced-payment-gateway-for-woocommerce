/**
 * Moneris Enhanced Gateway - WooCommerce Blocks Integration
 *
 * @package MonerisEnhancedGateway
 */

( function( wp, wc ) {
    'use strict';

    const { __ } = wp.i18n;
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { createElement, Fragment } = wp.element;
    const { useState, useEffect } = wp.element;
    const { TextControl, CheckboxControl } = wp.components;

    // Get settings from backend
    const settings = getSetting( 'moneris_enhanced_data', {} );

    /**
     * Label component
     */
    const Label = () => {
        const { PaymentMethodLabel } = wc.components;

        return createElement(
            PaymentMethodLabel,
            {
                text: settings.title || __( 'Credit Card (Moneris)', 'moneris-enhanced-gateway-for-woocommerce' )
            }
        );
    };

    /**
     * Content component - Payment form
     */
    const Content = ( props ) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentProcessing } = eventRegistration;

        const [ cardNumber, setCardNumber ] = useState( '' );
        const [ cardExpiry, setCardExpiry ] = useState( '' );
        const [ cardCvc, setCardCvc ] = useState( '' );
        const [ saveCard, setSaveCard ] = useState( false );
        const [ errors, setErrors ] = useState( {} );

        // Validate card number
        const validateCardNumber = ( value ) => {
            const cleaned = value.replace( /\s+/g, '' );
            if ( cleaned.length < 13 || cleaned.length > 19 ) {
                return false;
            }
            return true;
        };

        // Format card number
        const formatCardNumber = ( value ) => {
            const cleaned = value.replace( /\s+/g, '' );
            const formatted = cleaned.match( /.{1,4}/g );
            return formatted ? formatted.join( ' ' ) : cleaned;
        };

        // Format expiry date
        const formatExpiry = ( value ) => {
            const cleaned = value.replace( /\D/g, '' );
            if ( cleaned.length >= 2 ) {
                return cleaned.substring( 0, 2 ) + ' / ' + cleaned.substring( 2, 4 );
            }
            return cleaned;
        };

        // Handle payment processing
        useEffect( () => {
            const unsubscribe = onPaymentProcessing( async () => {
                const validationErrors = {};

                if ( ! validateCardNumber( cardNumber ) ) {
                    validationErrors.cardNumber = settings.i18n?.card_number_invalid || 'Invalid card number';
                }

                if ( cardExpiry.length < 7 ) {
                    validationErrors.cardExpiry = settings.i18n?.expiry_invalid || 'Invalid expiry date';
                }

                if ( cardCvc.length < 3 ) {
                    validationErrors.cardCvc = settings.i18n?.cvc_invalid || 'Invalid CVC';
                }

                if ( Object.keys( validationErrors ).length > 0 ) {
                    setErrors( validationErrors );
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.i18n?.generic_error || 'Please check your card details',
                    };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            moneris_card_number: cardNumber.replace( /\s+/g, '' ),
                            moneris_card_expiry: cardExpiry.replace( /\s+/g, '' ),
                            moneris_card_cvc: cardCvc,
                            moneris_save_card: saveCard,
                        },
                    },
                };
            } );

            return () => unsubscribe();
        }, [
            onPaymentProcessing,
            cardNumber,
            cardExpiry,
            cardCvc,
            saveCard,
            emitResponse.responseTypes.ERROR,
            emitResponse.responseTypes.SUCCESS
        ] );

        // If Hosted Payment Page is enabled, show iframe placeholder
        if ( settings.hpp_enabled ) {
            return createElement(
                'div',
                { className: 'moneris-hpp-container' },
                createElement( 'p', null,
                    __( 'You will be redirected to Moneris secure payment page to complete your purchase.', 'moneris-enhanced-gateway-for-woocommerce' )
                )
            );
        }

        // Standard card form
        return createElement(
            Fragment,
            null,
            createElement(
                'div',
                { className: 'moneris-payment-fields' },

                // Card number field
                createElement(
                    TextControl,
                    {
                        label: settings.i18n?.card_number || __( 'Card Number', 'moneris-enhanced-gateway-for-woocommerce' ),
                        value: cardNumber,
                        onChange: ( value ) => {
                            setCardNumber( formatCardNumber( value ) );
                            if ( errors.cardNumber ) {
                                setErrors( { ...errors, cardNumber: null } );
                            }
                        },
                        placeholder: '•••• •••• •••• ••••',
                        required: true,
                        className: errors.cardNumber ? 'has-error' : '',
                    }
                ),
                errors.cardNumber && createElement( 'span', { className: 'error' }, errors.cardNumber ),

                // Expiry date field
                createElement(
                    TextControl,
                    {
                        label: settings.i18n?.expiry_date || __( 'Expiry Date', 'moneris-enhanced-gateway-for-woocommerce' ),
                        value: cardExpiry,
                        onChange: ( value ) => {
                            setCardExpiry( formatExpiry( value ) );
                            if ( errors.cardExpiry ) {
                                setErrors( { ...errors, cardExpiry: null } );
                            }
                        },
                        placeholder: 'MM / YY',
                        required: true,
                        className: errors.cardExpiry ? 'has-error' : '',
                        maxLength: 7,
                    }
                ),
                errors.cardExpiry && createElement( 'span', { className: 'error' }, errors.cardExpiry ),

                // CVC field
                createElement(
                    TextControl,
                    {
                        label: settings.i18n?.cvc || __( 'CVC', 'moneris-enhanced-gateway-for-woocommerce' ),
                        value: cardCvc,
                        onChange: ( value ) => {
                            setCardCvc( value.replace( /\D/g, '' ) );
                            if ( errors.cardCvc ) {
                                setErrors( { ...errors, cardCvc: null } );
                            }
                        },
                        placeholder: 'CVC',
                        required: true,
                        className: errors.cardCvc ? 'has-error' : '',
                        maxLength: 4,
                    }
                ),
                errors.cardCvc && createElement( 'span', { className: 'error' }, errors.cardCvc ),

                // Save card checkbox
                settings.show_saved_cards && createElement(
                    CheckboxControl,
                    {
                        label: settings.i18n?.save_card || __( 'Save payment method to my account', 'moneris-enhanced-gateway-for-woocommerce' ),
                        checked: saveCard,
                        onChange: setSaveCard,
                    }
                )
            )
        );
    };

    /**
     * Moneris payment method configuration
     */
    const monerisPaymentMethod = {
        name: 'moneris_enhanced',
        label: createElement( Label ),
        content: createElement( Content ),
        edit: createElement( Content ),
        canMakePayment: () => true,
        ariaLabel: settings.title || __( 'Credit Card (Moneris)', 'moneris-enhanced-gateway-for-woocommerce' ),
        supports: {
            features: settings.supports || [],
        },
    };

    // Register the payment method
    registerPaymentMethod( monerisPaymentMethod );

} )( window.wp, window.wc );