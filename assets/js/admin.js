/**
 * WooCommerce Bol.com Sync Pro — Admin JavaScript
 *
 * Handles all interactive UI actions:
 *   - Test Connection button
 *   - Sync Products / Sync Orders buttons
 *   - Clear All Logs button (with confirm dialog)
 *   - Validate License / Deactivate License buttons
 *   - Log data expand/collapse toggle
 *
 * Depends on: jQuery, wbsAdmin (localised via wp_localize_script)
 */

/* global wbsAdmin */

( function ( $ ) {
    'use strict';

    // ── Woosa-style collapsible sections ──────────────────────────────────────
    //
    // Every `.wbs-section` card has a `<button class="wbs-section__toggle">` in
    // its green header. Clicking it collapses the section body and swaps the
    // toggle label between "Collapse" and "Minimize" to match the Woosa
    // reference UI. Open/closed state is persisted per-section to localStorage
    // so the merchant's preference survives page reloads.

    var WBS_SECTION_STORAGE_PREFIX = 'wbs:section-state:';

    /**
     * Read the stored state for a section ID.
     *
     * @param {string} sectionId
     * @returns {('open'|'closed'|null)}
     */
    function wbsReadSectionState( sectionId ) {
        if ( ! sectionId || typeof window.localStorage === 'undefined' ) {
            return null;
        }
        try {
            var raw = window.localStorage.getItem( WBS_SECTION_STORAGE_PREFIX + sectionId );
            return raw === 'open' || raw === 'closed' ? raw : null;
        } catch ( err ) {
            return null;
        }
    }

    /**
     * Persist open/closed state for a section.
     *
     * @param {string}  sectionId
     * @param {boolean} isCollapsed
     */
    function wbsWriteSectionState( sectionId, isCollapsed ) {
        if ( ! sectionId || typeof window.localStorage === 'undefined' ) {
            return;
        }
        try {
            window.localStorage.setItem(
                WBS_SECTION_STORAGE_PREFIX + sectionId,
                isCollapsed ? 'closed' : 'open'
            );
        } catch ( err ) {
            /* storage full / blocked – ignore */
        }
    }

    /**
     * Sync the "Collapse" / "Minimize" toggle label and aria-expanded state
     * to the current collapsed state of the parent section.
     *
     * @param {jQuery} $section
     */
    function wbsSyncSectionToggle( $section ) {
        var collapsed = $section.hasClass( 'is-collapsed' );
        var $toggle   = $section.find( '> .wbs-section__header > .wbs-section__toggle' ).first();
        if ( ! $toggle.length ) {
            return;
        }

        $toggle.attr( 'aria-expanded', collapsed ? 'false' : 'true' );

        var $label = $toggle.find( '.wbs-section__toggle-label' ).first();
        if ( ! $label.length ) {
            return;
        }

        var openLabel  = $label.data( 'label-open' );
        var closeLabel = $label.data( 'label-closed' );

        if ( ! openLabel ) {
            openLabel = $label.text();
            $label.data( 'label-open', openLabel );
        }
        if ( ! closeLabel ) {
            closeLabel = ( window.wbsAdmin && wbsAdmin.i18n && wbsAdmin.i18n.minimize )
                ? wbsAdmin.i18n.minimize
                : 'Minimize';
            $label.data( 'label-closed', closeLabel );
        }

        $label.text( collapsed ? closeLabel : openLabel );
    }

    $( function () {
        var $sections = $( '.wbs-wrap .wbs-section' );

        $sections.each( function () {
            var $section  = $( this );
            var sectionId = $section.attr( 'data-section' ) || '';
            var stored    = wbsReadSectionState( sectionId );

            if ( stored === 'closed' ) {
                $section.addClass( 'is-collapsed' );
            }

            wbsSyncSectionToggle( $section );
        } );

        $( document ).on( 'click', '.wbs-wrap .wbs-section__toggle', function ( event ) {
            event.preventDefault();
            var $section  = $( this ).closest( '.wbs-section' );
            if ( ! $section.length ) {
                return;
            }
            $section.toggleClass( 'is-collapsed' );
            wbsSyncSectionToggle( $section );
            wbsWriteSectionState( $section.attr( 'data-section' ) || '', $section.hasClass( 'is-collapsed' ) );
        } );
    } );

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Set a button to loading state.
     *
     * @param {jQuery} $btn         Button element.
     * @param {string} loadingText  Text to show while loading.
     */
    function setLoading( $btn, loadingText ) {
        if ( ! $btn.data( 'originalHtml' ) ) {
            $btn.data( 'originalHtml', $btn.html() );
        }

        $btn.prop( 'disabled', true );

        var spinHtml = '';
        var $spin = $btn.find( '.wbs-spin' ).first();
        if ( $spin.length ) {
            var $spinClone = $spin.clone();
            $spinClone.show();
            spinHtml = $( '<div>' ).append( $spinClone ).html();
        }

        $btn.html( spinHtml + ' ' + loadingText );
    }

    /**
     * Restore a button to its original idle state.
     *
     * @param {jQuery} $btn Button element.
     */
    function resetButton( $btn ) {
        $btn.prop( 'disabled', false );
        var originalHtml = $btn.data( 'originalHtml' );

        if ( originalHtml ) {
            $btn.html( originalHtml );
        }
    }

    /**
     * Show a styled inline message below a container element.
     *
     * @param {jQuery} $el    Message container.
     * @param {string} text   Message text.
     * @param {string} type   'success' or 'error'.
     */
    function showMessage( $el, text, type ) {
        $el
            .removeClass( 'is-success is-error' )
            .addClass( 'is-' + type )
            .html( text )
            .slideDown( 200 );
    }

    function debounce( fn, wait ) {
        var timeoutId = null;
        return function () {
            var context = this;
            var args = arguments;
            window.clearTimeout( timeoutId );
            timeoutId = window.setTimeout( function () {
                fn.apply( context, args );
            }, wait );
        };
    }

    function escapeHtml( text ) {
        return $( '<div>' ).text( text || '' ).html();
    }

    function escapeAttr( text ) {
        return escapeHtml( text ).replace( /`/g, '&#96;' );
    }

    function renderHealthReport( report ) {
        if ( ! report || ! report.checks ) {
            return '';
        }

        var html = '<div class="wbs-health-report">';
        $.each( report.checks, function ( key, check ) {
            html += '<div class="wbs-health-report__item ' + ( check.ok ? 'is-success' : 'is-error' ) + '">';
            html += '<strong>' + escapeHtml( key.replace( /_/g, ' ' ) ) + '</strong>';
            html += '<span>' + escapeHtml( check.message || '' ) + '</span>';
            html += '</div>';
        } );

        if ( report.warnings && report.warnings.length ) {
            html += '<div class="wbs-health-report__warnings"><strong>Warnings</strong><ul>';
            $.each( report.warnings, function ( _, warning ) {
                html += '<li>' + escapeHtml( warning ) + '</li>';
            } );
            html += '</ul></div>';
        }

        html += '</div>';
        return html;
    }

    function updateDashboardStats( stats ) {
        if ( ! stats || typeof stats !== 'object' ) {
            return;
        }

        if ( stats.products ) {
            if ( $( '#wbs-stat-products-synced' ).length ) {
                $( '#wbs-stat-products-synced' ).text( String( stats.products.synced || 0 ) );
            }
            if ( $( '#wbs-stat-products-failed' ).length ) {
                $( '#wbs-stat-products-failed' ).text( String( stats.products.failed || 0 ) );
            }
            if ( $( '#wbs-sync-products-meta' ).length ) {
                $( '#wbs-sync-products-meta' ).html(
                    'Synced: ' + ( stats.products.synced || 0 )
                    + ' | Failed: ' + ( stats.products.failed || 0 )
                    + ' | Pending: ' + ( stats.products.pending || 0 )
                );
            }
        }

        if ( stats.orders ) {
            if ( $( '#wbs-stat-orders-imported' ).length ) {
                $( '#wbs-stat-orders-imported' ).text( String( stats.orders.total || 0 ) );
            }
            if ( $( '#wbs-stat-orders-shipped' ).length ) {
                $( '#wbs-stat-orders-shipped' ).text( String( stats.orders.shipped || 0 ) );
            }
            if ( $( '#wbs-sync-orders-meta' ).length ) {
                $( '#wbs-sync-orders-meta' ).html(
                    'Imported: ' + ( stats.orders.total || 0 )
                    + ' | Shipped: ' + ( stats.orders.shipped || 0 )
                    + ' | Failed: ' + ( stats.orders.failed || 0 )
                );
            }
        }
    }

    function updateNetContentPreview() {
        if ( ! $( '#wbs-net-content-preview' ).length ) {
            return;
        }
        var value = String( $( '#_wbs_net_content_value' ).val() || '' ).trim();
        var unit = String( $( '#_wbs_net_content_unit' ).val() || '' ).trim();
        var pieces = String( $( '#_wbs_net_content_pieces' ).val() || '' ).trim();
        var preview = '';

        if ( value && unit ) {
            preview = value + ' ' + unit;
        } else if ( value ) {
            preview = value;
        }

        if ( pieces ) {
            preview = preview ? preview + ' (' + pieces + ' pieces)' : pieces + ' pieces';
        }

        if ( ! preview ) {
            preview = String( $( '#wbs-net-content-preview' ).data( 'fallback' ) || '' ).trim();
        }

        $( '#wbs-net-content-preview' ).text(
            preview ? ( 'Net content preview: ' + preview ) : 'Net content preview: not set'
        );
        $( '#_wbs_net_content' ).val( preview );
    }

    function updateProductSyncUi( payload ) {
        if ( ! payload || typeof payload !== 'object' ) {
            return;
        }

        if ( payload.result && $( '#wbs-sync-products-meta' ).length ) {
            var result = payload.result;
            $( '#wbs-sync-products-meta' ).html(
                'Synced: ' + ( result.synced || 0 )
                + ' | Created: ' + ( result.created || 0 )
                + ' | Updated: ' + ( result.updated || 0 )
                + ' | Pending async: ' + ( result.pending_async || 0 )
                + ' | Failed: ' + ( result.failed || 0 )
                + ' | Skipped: ' + ( result.skipped || 0 )
                + ' | Invalid: ' + ( result.invalid || 0 )
            );
        }

        if ( payload.stats ) {
            updateDashboardStats( payload.stats );
        }
    }

    function refreshDashboardStats() {
        if ( ! $( '#wbs-stat-products-synced' ).length ) {
            return;
        }
        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: { action: 'wbs_dashboard_stats', nonce: wbsAdmin.nonce },
            success: function ( response ) {
                if ( response && response.success && response.data && response.data.stats ) {
                    updateDashboardStats( response.data.stats );
                }
            }
        } );
    }

    /**
     * Execute a standard AJAX sync call.
     *
     * @param {jQuery} $btn     Trigger button.
     * @param {jQuery} $msg     Message container.
     * @param {string} action   WordPress AJAX action name.
     * @param {string} loadingText Text shown during the request.
     * @param {Object} extra    Additional POST data (optional).
     */
    function runAjax( $btn, $msg, action, loadingText, extra ) {
        setLoading( $btn, loadingText );
        $msg.slideUp( 80 );

        var data = $.extend( {
            action : action,
            nonce  : wbsAdmin.nonce,
        }, extra || {} );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : data,
            success: function ( response ) {
                resetButton( $btn );
                var type = response.success ? 'success' : 'error';
                showMessage( $msg, escapeHtml( response.data.message || wbsAdmin.i18n.error ), type );
                if ( response.success && response.data && ( action === 'wbs_sync_products' || action === 'wbs_retry_failed_products' || action === 'wbs_force_update_existing_products' || action === 'wbs_sync_selected_product' ) ) {
                    updateProductSyncUi( response.data );
                }
                if ( response.success ) {
                    refreshDashboardStats();
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    }

    // ── Connection status helpers ─────────────────────────────────────────────

    /**
     * Update the connection status badge on the dashboard / settings page.
     *
     * @param {boolean} success
     * @param {string}  message
     */
    function updateConnStatus( success, message ) {
        var $status = $( '#wbs-conn-status' );

        $status
            .removeClass( 'wbs-status--unknown wbs-status--success wbs-status--error' )
            .addClass( success ? 'wbs-status--success' : 'wbs-status--error' )
            .show()
            .find( '.wbs-status__label' )
            .text( message );
    }

    /**
     * Update Economic operator panel on Settings (and any future pages using #wbs-eo-panel).
     *
     * @param {Object|null} p Payload from wp_send_json_* economic_operator key.
     */
    function applyEconomicOperatorPayload( p ) {
        if ( ! p || typeof p !== 'object' ) {
            return;
        }

        var $panel = $( '#wbs-eo-panel' );
        if ( ! $panel.length ) {
            return;
        }

        $panel.find( '#wbs-eo-status' )
            .text( p.status_line || '' )
            .removeClass( 'wbs-eo-status--ok wbs-eo-status--none' )
            .addClass( p.connected ? 'wbs-eo-status--ok' : 'wbs-eo-status--none' );

        var $idWrap = $panel.find( '#wbs-eo-id-wrap' );
        var $idCode = $panel.find( '#wbs-eo-id-code' );
        if ( p.connected && p.operator_id ) {
            $idWrap.show();
            $idCode.text( p.operator_id );
        } else {
            $idWrap.hide();
            $idCode.text( '' );
        }

        var $syncWrap = $panel.find( '#wbs-eo-sync-wrap' );
        var $syncLabel = $panel.find( '#wbs-eo-sync-label' );
        if ( p.last_sync ) {
            $syncWrap.show();
            var t = wbsAdmin.i18n.lastFetched || 'Last fetched: %s';
            $syncLabel.text( t.replace( '%s', p.last_sync ) );
        } else {
            $syncWrap.hide();
            $syncLabel.text( '' );
        }
    }

    // ── Test Connection ───────────────────────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-test-connection', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-conn-message' );

        setLoading( $btn, wbsAdmin.i18n.testing );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_test_connection', nonce: wbsAdmin.nonce },
            success: function ( response ) {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( response.data.message ), response.success ? 'success' : 'error' );
                updateConnStatus( response.success, response.data.message );
                if ( response.data && response.data.economic_operator ) {
                    applyEconomicOperatorPayload( response.data.economic_operator );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
                updateConnStatus( false, wbsAdmin.i18n.error );
            },
        } );
    } );

    // ── Fetch economic operator (Settings) ────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-fetch-economic-operator', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-eo-message' );

        setLoading( $btn, wbsAdmin.i18n.fetchingEo );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_refresh_economic_operator', nonce: wbsAdmin.nonce },
            success: function ( response ) {
                resetButton( $btn );
                var text = response.data && response.data.message ? response.data.message : wbsAdmin.i18n.error;
                showMessage( $msg, escapeHtml( text ), response.success ? 'success' : 'error' );
                if ( response.data && response.data.economic_operator ) {
                    applyEconomicOperatorPayload( response.data.economic_operator );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    } );

    function economicOperatorFormPayload() {
        return {
            name: $( '#wbs-eo-name' ).val(),
            street: $( '#wbs-eo-street' ).val(),
            houseNumber: $( '#wbs-eo-houseNumber' ).val(),
            postalCode: $( '#wbs-eo-postalCode' ).val(),
            city: $( '#wbs-eo-city' ).val(),
            country: $( '#wbs-eo-country' ).val(),
            emailAddress: $( '#wbs-eo-emailAddress' ).val(),
            phoneNumber: $( '#wbs-eo-phoneNumber' ).val(),
            additionalAddressInfo: $( '#wbs-eo-additionalAddressInfo' ).val(),
            externalReference: $( '#wbs-eo-externalReference' ).val()
        };
    }

    $( document ).on( 'click', '#wbs-btn-save-economic-operator', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-eo-editor-message' );

        setLoading( $btn, wbsAdmin.i18n.fetchingEo );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : $.extend( {
                action: 'wbs_save_economic_operator',
                nonce: wbsAdmin.nonce
            }, economicOperatorFormPayload() ),
            success: function ( response ) {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( response.data.message || wbsAdmin.i18n.error ), response.success ? 'success' : 'error' );
                if ( response.data && response.data.economic_operator ) {
                    applyEconomicOperatorPayload( response.data.economic_operator );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    } );

    $( document ).on( 'click', '#wbs-btn-delete-economic-operator', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-eo-editor-message' );

        if ( ! window.confirm( 'Delete the current economic operator?' ) ) {
            return;
        }

        $btn.prop( 'disabled', true );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : {
                action: 'wbs_delete_economic_operator',
                nonce: wbsAdmin.nonce
            },
            success: function ( response ) {
                $btn.prop( 'disabled', false );
                showMessage( $msg, escapeHtml( response.data.message || wbsAdmin.i18n.error ), response.success ? 'success' : 'error' );
                if ( response.success && response.data && response.data.economic_operator ) {
                    applyEconomicOperatorPayload( response.data.economic_operator );
                }
            },
            error: function () {
                $btn.prop( 'disabled', false );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    } );

    // ── Sync Products ─────────────────────────────────────────────────────────

    function renderDirectSyncResults( items ) {
        var $results = $( '#wbs-direct-sync-results' );
        if ( ! $results.length ) {
            return;
        }

        if ( ! items || ! items.length ) {
            $results.html( '<div class="wbs-direct-sync-results__empty">' + escapeHtml( wbsAdmin.i18n.searchProductsEmpty || 'No matching products found.' ) + '</div>' ).prop( 'hidden', false );
            return;
        }

        var html = '';
        $.each( items, function ( _, item ) {
            html += '<button type="button" class="wbs-direct-sync-result" data-product-id="' + escapeAttr( String( item.id || '' ) ) + '" data-product-label="' + escapeAttr( String( item.label || '' ) ) + '">';
            html += '<span class="wbs-direct-sync-result__title">' + escapeHtml( item.label || '' ) + '</span>';
            html += '</button>';
        } );

        $results.html( html ).prop( 'hidden', false );
    }

    function setDirectSyncSelection( item ) {
        var label = item && item.label ? String( item.label ) : '';
        var id = item && item.id ? String( item.id ) : '';
        $( '#wbs-direct-sync-product' ).val( id );
        $( '#wbs-direct-sync-search' ).val( label );
        $( '#wbs-direct-sync-selected-label' ).text( label );
        $( '#wbs-direct-sync-selected' ).prop( 'hidden', ! id );
        $( '#wbs-direct-sync-results' ).prop( 'hidden', true ).empty();
    }

    function clearDirectSyncSelection() {
        $( '#wbs-direct-sync-product' ).val( '' );
        $( '#wbs-direct-sync-search' ).val( '' );
        $( '#wbs-direct-sync-selected-label' ).text( '' );
        $( '#wbs-direct-sync-selected' ).prop( 'hidden', true );
        $( '#wbs-direct-sync-results' ).prop( 'hidden', true ).empty();
    }

    var runDirectSyncSearch = debounce( function () {
        var $input = $( '#wbs-direct-sync-search' );
        var $results = $( '#wbs-direct-sync-results' );
        if ( ! $input.length || ! $results.length ) {
            return;
        }

        var term = String( $input.val() || '' ).trim();
        if ( term.length < 2 ) {
            if ( term.length === 0 ) {
                clearDirectSyncSelection();
            }
            $results.html( '<div class="wbs-direct-sync-results__hint">' + escapeHtml( wbsAdmin.i18n.searchProductsMin || 'Type at least 2 characters to search.' ) + '</div>' ).prop( 'hidden', false );
            return;
        }

        $( '#wbs-direct-sync-product' ).val( '' );
        $( '#wbs-direct-sync-selected' ).prop( 'hidden', true );
        $results.html( '<div class="wbs-direct-sync-results__hint">' + escapeHtml( wbsAdmin.i18n.searchingProducts || 'Searching products…' ) + '</div>' ).prop( 'hidden', false );

        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wbs_search_sync_products',
                nonce: wbsAdmin.nonce,
                term: term
            },
            success: function ( response ) {
                if ( response && response.success && response.data ) {
                    renderDirectSyncResults( response.data.items || [] );
                    return;
                }
                $results.html( '<div class="wbs-direct-sync-results__empty">' + escapeHtml( wbsAdmin.i18n.error || 'An error occurred.' ) + '</div>' ).prop( 'hidden', false );
            },
            error: function () {
                $results.html( '<div class="wbs-direct-sync-results__empty">' + escapeHtml( wbsAdmin.i18n.error || 'An error occurred.' ) + '</div>' ).prop( 'hidden', false );
            }
        } );
    }, 250 );

    $( document ).on( 'input', '#wbs-direct-sync-search', runDirectSyncSearch );

    $( document ).on( 'focus', '#wbs-direct-sync-search', function () {
        var $results = $( '#wbs-direct-sync-results' );
        if ( ! $results.children().length ) {
            $results.html( '<div class="wbs-direct-sync-results__hint">' + escapeHtml( wbsAdmin.i18n.searchProductsHint || 'Search by product name, SKU, EAN, or ID.' ) + '</div>' );
        }
        $results.prop( 'hidden', false );
    } );

    $( document ).on( 'click', '.wbs-direct-sync-result', function () {
        setDirectSyncSelection( {
            id: String( $( this ).data( 'product-id' ) || '' ),
            label: String( $( this ).data( 'product-label' ) || '' )
        } );
    } );

    $( document ).on( 'click', '#wbs-direct-sync-clear', function ( event ) {
        event.preventDefault();
        clearDirectSyncSelection();
        $( '#wbs-direct-sync-search' ).trigger( 'focus' );
    } );

    $( document ).on( 'click', function ( event ) {
        if ( ! $( event.target ).closest( '.wbs-direct-sync-picker' ).length ) {
            $( '#wbs-direct-sync-results' ).prop( 'hidden', true );
        }
    } );

    $( document ).on( 'click', '#wbs-btn-sync-products', function () {
        runAjax( $( this ), $( '#wbs-products-message' ), 'wbs_sync_products', wbsAdmin.i18n.syncing );
    } );

    $( document ).on( 'click', '#wbs-btn-sync-selected-product', function () {
        var productId = String( $( '#wbs-direct-sync-product' ).val() || '' ).trim();
        if ( ! productId ) {
            showMessage( $( '#wbs-products-message' ), escapeHtml( wbsAdmin.i18n.selectProductFirst || 'Select a product first.' ), 'error' );
            return;
        }
        runAjax(
            $( this ),
            $( '#wbs-products-message' ),
            'wbs_sync_selected_product',
            wbsAdmin.i18n.syncSelectedProduct || wbsAdmin.i18n.syncing,
            { product_id: productId }
        );
    } );

    $( document ).on( 'click', '#wbs-btn-force-update-existing-products', function () {
        if ( ! window.confirm( wbsAdmin.i18n.confirmForceUpdate || 'Force update existing products?' ) ) {
            return;
        }
        runAjax( $( this ), $( '#wbs-products-message' ), 'wbs_force_update_existing_products', wbsAdmin.i18n.forceUpdate || wbsAdmin.i18n.syncing );
    } );

    // ── Sync Orders ───────────────────────────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-sync-orders', function () {
        runAjax( $( this ), $( '#wbs-orders-message' ), 'wbs_sync_orders', wbsAdmin.i18n.syncing );
    } );

    $( document ).on( 'click', '#wbs-btn-health-check', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-ops-message' );

        setLoading( $btn, wbsAdmin.i18n.healthCheck );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_run_health_check', nonce: wbsAdmin.nonce },
            success: function ( response ) {
                resetButton( $btn );
                var text = response.data && response.data.message ? response.data.message : wbsAdmin.i18n.error;
                var html = '<p><strong>' + escapeHtml( text ) + '</strong></p>';
                if ( response.data && response.data.report ) {
                    html += renderHealthReport( response.data.report );
                }
                showMessage( $msg, html, response.success ? 'success' : 'error' );
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    } );

    $( document ).on( 'click', '#wbs-btn-retry-products', function () {
        runAjax( $( this ), $( '#wbs-ops-message' ), 'wbs_retry_failed_products', wbsAdmin.i18n.retryProducts );
    } );

    $( document ).on( 'click', '#wbs-btn-retry-orders', function () {
        runAjax( $( this ), $( '#wbs-ops-message' ), 'wbs_retry_failed_orders', wbsAdmin.i18n.retryOrders );
    } );

    $( document ).on( 'click', '#wbs-btn-ensure-webhook', function () {
        runAjax( $( this ), $( '#wbs-ops-message' ), 'wbs_ensure_subscription', wbsAdmin.i18n.ensureWebhook );
    } );

    if ( $( '#wbs-stat-products-synced' ).length ) {
        refreshDashboardStats();
        setInterval( refreshDashboardStats, 30000 );
    }

    $( document ).on( 'input change', '#wbs-category-search, #wbs-category-show-unmapped', function () {
        var query = String( $( '#wbs-category-search' ).val() || '' ).toLowerCase().trim();
        var unmappedOnly = $( '#wbs-category-show-unmapped' ).is( ':checked' );

        $( '.wbs-category-row' ).each( function () {
            var $row = $( this );
            var name = String( $row.data( 'category-name' ) || '' );
            var mapped = String( $row.data( 'mapped' ) || '' ) === '1';
            var visible = true;

            if ( query && name.indexOf( query ) === -1 ) {
                visible = false;
            }
            if ( unmappedOnly && mapped ) {
                visible = false;
            }

            $row.toggle( visible );
        } );
    } );

    // ── Category mapping: catalog lookup by EAN ─────────────────────────────

    $( document ).on( 'click', '#wbs-btn-catalog-lookup', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-catalog-lookup-message' );
        var $out = $( '#wbs-catalog-lookup-output' );
        var $gpc = $( '#wbs-catalog-gpc-hint' );
        var ean = String( $( '#wbs-catalog-lookup-ean' ).val() || '' ).replace( /\D/g, '' );

        if ( ! ean || ean.length < 8 || ean.length > 14 ) {
            showMessage( $msg, escapeHtml( wbsAdmin.i18n.catalogLookupNeedEan || 'Enter EAN.' ), 'error' );
            $out.hide().text( '' );
            $gpc.hide().text( '' );
            return;
        }

        setLoading( $btn, wbsAdmin.i18n.catalogLookupLoading || '…' );
        $msg.slideUp( 80 );
        $out.hide().text( '' );
        $gpc.hide().text( '' );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_catalog_lookup_ean', nonce: wbsAdmin.nonce, ean: ean },
            success: function ( response ) {
                resetButton( $btn );
                if ( response.success && response.data ) {
                    var httpCode = response.data.http_code || '?';
                    var statusText = 'HTTP ' + httpCode;
                    var gpc = response.data.gpc_chunk_id;
                    if ( gpc ) {
                        var gpcLabel = wbsAdmin.i18n.chunkRecommendGpcLabel || 'GPC chunkId:';
                        $gpc.text( gpcLabel + ' ' + gpc ).show();
                    }

                    var parsedBody = parseJsonSafely( response.data.body_json || '{}' );
                    var summaryObject = buildCatalogLookupSummary( parsedBody, response.data.http_code || null );
                    var summaryJson = JSON.stringify( summaryObject, null, 2 );
                    if ( ! summaryJson ) {
                        summaryJson = '{}';
                    }
                    $out.text( summaryJson ).show();

                    var msgHtml = escapeHtml( statusText );
                    if ( response.data.http_code === 404 ) {
                        var hint404 = response.data.hint || wbsAdmin.i18n.catalog404Hint || '';
                        if ( hint404 ) {
                            msgHtml = '<strong>' + msgHtml + '</strong><br /><span style="font-weight:normal;">' + escapeHtml( hint404 ) + '</span>';
                        }
                        showMessage( $msg, msgHtml, 'error' );
                    } else {
                        showMessage( $msg, msgHtml, 'success' );
                    }
                } else {
                    var err = ( response.data && response.data.message ) ? response.data.message : wbsAdmin.i18n.error;
                    showMessage( $msg, escapeHtml( err ), 'error' );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    } );

    // ── Category mapping: chunk recommendations ───────────────────────────────

    function buildChunkSummaryHtml( data ) {
        var i18n = wbsAdmin.i18n || {};
        var preds = data.predictions_parsed;
        if ( ! preds || ! preds.length ) {
            return '';
        }
        var lines = [];
        var pname = data.product_name_sent || '';
        if ( pname ) {
            lines.push( '<p><strong>' + escapeHtml( i18n.chunkSummaryProduct || 'Product:' ) + '</strong> ' + escapeHtml( pname ) + '</p>' );
        }
        var top = preds[0];
        var topLine = ( i18n.chunkSummaryTop || 'Top: %1$s %2$s' )
            .replace( '%1$s', '<strong>' + escapeHtml( top.chunkId ) + '</strong>' )
            .replace( '%2$s', escapeHtml( top.percent ) );
        lines.push( '<p>' + topLine + '</p>' );
        if ( preds.length > 1 ) {
            var rest = [];
            for ( var i = 1; i < preds.length; i++ ) {
                rest.push( escapeHtml( preds[ i ].chunkId ) + ' (~' + escapeHtml( preds[ i ].percent ) + '%)' );
            }
            lines.push( '<p><strong>' + escapeHtml( i18n.chunkSummaryOthers || 'Other:' ) + '</strong> ' + rest.join( ', ' ) + '</p>' );
        }
        var dmUrl = i18n.chunkDatamodelUrl || 'https://developers.bol.com/en/datamodel/';
        var dmLabel = escapeHtml( i18n.chunkDatamodelLink || 'Data model' );
        lines.push(
            '<p class="wbs-chunk-summary__meta">' + escapeHtml( i18n.chunkSummaryNoNames || '' )
            + ' <a href="' + escapeHtml( dmUrl ) + '" target="_blank" rel="noopener noreferrer">' + dmLabel + '</a></p>'
        );
        return lines.join( '' );
    }

    function parseJsonSafely( text ) {
        if ( typeof text !== 'string' || text === '' ) {
            return {};
        }
        try {
            var parsed = JSON.parse( text );
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch ( e ) {
            return {};
        }
    }

    function readCatalogAttributeValue( body, attributeId ) {
        if ( ! body || ! Array.isArray( body.attributes ) ) {
            return '';
        }

        for ( var i = 0; i < body.attributes.length; i++ ) {
            var attr = body.attributes[ i ];
            if ( ! attr || attr.id !== attributeId || ! Array.isArray( attr.values ) || ! attr.values.length ) {
                continue;
            }
            var first = attr.values[0];
            if ( first && typeof first.value !== 'undefined' && first.value !== null ) {
                return String( first.value );
            }
            return '';
        }

        return '';
    }

    function buildCatalogLookupSummary( body, httpCode ) {
        var published = null;
        if ( typeof body.published === 'boolean' ) {
            published = body.published;
        }

        var enrichmentStatus = null;
        if ( body.enrichment && typeof body.enrichment === 'object' && typeof body.enrichment.status !== 'undefined' ) {
            enrichmentStatus = body.enrichment.status;
        }

        var chunkId = '';
        if ( body.gpc && typeof body.gpc === 'object' && typeof body.gpc.chunkId !== 'undefined' ) {
            chunkId = String( body.gpc.chunkId );
        }

        return {
            status: {
                http: httpCode || null,
                published: published,
                enrichmentStatus: enrichmentStatus
            },
            classification: {
                chunkId: chunkId,
                productGroup: readCatalogAttributeValue( body, 'Product Group' )
            },
            product: {
                title: readCatalogAttributeValue( body, 'Title' ),
                description: readCatalogAttributeValue( body, 'Description' ),
                appearance: readCatalogAttributeValue( body, 'Appearance Name' ),
                unitAttribute: readCatalogAttributeValue( body, 'Unit Attribute' )
            },
            manufacturerDetails: readCatalogAttributeValue( body, 'Manufacturer Details' )
        };
    }

    $( document ).on( 'click', '#wbs-btn-chunk-recommendations', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-chunk-recommend-message' );
        var $out = $( '#wbs-chunk-recommend-output' );
        var $sum = $( '#wbs-chunk-summary' );
        var name = String( $( '#wbs-chunk-product-name' ).val() || '' ).trim();

        if ( ! name ) {
            showMessage( $msg, escapeHtml( wbsAdmin.i18n.chunkRecommendNeedName || 'Enter name.' ), 'error' );
            $out.hide().text( '' );
            $sum.hide().html( '' );
            return;
        }

        setLoading( $btn, wbsAdmin.i18n.chunkRecommendLoading || '…' );
        $msg.slideUp( 80 );
        $out.hide().text( '' );
        $sum.hide().html( '' );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : {
                action             : 'wbs_chunk_recommendations',
                nonce              : wbsAdmin.nonce,
                product_name       : name,
                product_description: $( '#wbs-chunk-product-description' ).val() || '',
            },
            success: function ( response ) {
                resetButton( $btn );
                if ( response.success && response.data ) {
                    var head = 'HTTP ' + ( response.data.http_code || '?' );
                    if ( response.data.summary ) {
                        head += ' — ' + response.data.summary;
                    }
                    var sumHtml = buildChunkSummaryHtml( response.data );
                    if ( sumHtml ) {
                        $sum.html( sumHtml ).show();
                    } else {
                        $sum.hide().html( '' );
                    }
                    $out.text( head + '\n\n' + ( response.data.body_json || '{}' ) ).show();
                    showMessage( $msg, escapeHtml( head ), 'success' );
                } else {
                    var err2 = ( response.data && response.data.message ) ? response.data.message : wbsAdmin.i18n.error;
                    showMessage( $msg, escapeHtml( err2 ), 'error' );
                    $sum.hide().html( '' );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
                $sum.hide().html( '' );
            },
        } );
    } );

    // ── bol products page ─────────────────────────────────────────────────────

    function prettyJson( value ) {
        try {
            return JSON.stringify( value || {}, null, 2 );
        } catch ( e ) {
            return '{}';
        }
    }

    function extractOfferItemsFromPayload( offersData ) {
        offersData = offersData || {};
        var body = offersData.body || {};
        var keyList = [ 'offers', 'results', 'items' ];
        var i;
        var k;
        var raw;
        var dec;
        for ( i = 0; i < keyList.length; i++ ) {
            k = keyList[ i ];
            if ( Array.isArray( body[ k ] ) && body[ k ].length ) {
                return body[ k ];
            }
        }
        raw = String( offersData.summary || '' ).trim();
        if ( raw ) {
            try {
                dec = JSON.parse( raw );
                if ( dec && typeof dec === 'object' ) {
                    for ( i = 0; i < keyList.length; i++ ) {
                        k = keyList[ i ];
                        if ( Array.isArray( dec[ k ] ) && dec[ k ].length ) {
                            return dec[ k ];
                        }
                    }
                    if ( Array.isArray( dec ) && dec[ 0 ] && ( dec[ 0 ].offerId || dec[ 0 ].id ) ) {
                        return dec;
                    }
                }
            } catch ( err ) {
                /* ignore */
            }
        }
        return [];
    }

    function normalizeOfferRowsClient( items ) {
        if ( ! Array.isArray( items ) || ! items.length ) {
            return [];
        }
        return items
            .map( function ( item ) {
                if ( ! item || typeof item !== 'object' ) {
                    return null;
                }
                var offerId = String( item.offerId || item.id || '' );
                if ( ! offerId ) {
                    return null;
                }
                var ean = String( item.ean || '' ).replace( /\D/g, '' );
                var title = String( item.unknownProductTitle || item.storeProductTitle || item.title || '' );
                var stock = '';
                if ( item.stock && typeof item.stock === 'object' && item.stock.amount !== undefined ) {
                    stock = String( parseInt( item.stock.amount, 10 ) );
                }
                var priceLabel = '';
                var bp = item.pricing && item.pricing.bundlePrices;
                if ( Array.isArray( bp ) && bp[ 0 ] && bp[ 0 ].unitPrice !== undefined ) {
                    priceLabel = String( bp[ 0 ].unitPrice ).replace( ',', '.' );
                }
                var condition = '';
                if ( item.condition ) {
                    condition = typeof item.condition === 'object' && item.condition.name
                        ? String( item.condition.name )
                        : String( item.condition );
                }
                var fulfil = item.fulfilment && item.fulfilment.method ? String( item.fulfilment.method ) : '';
                var reference = String( item.reference || item.retailerOfferId || '' );
                var onHold = !! item.onHoldByRetailer;
                var forSale;
                if ( Object.prototype.hasOwnProperty.call( item, '_wbs_for_sale' ) ) {
                    forSale = !! item._wbs_for_sale;
                } else if ( typeof item.forSale === 'boolean' ) {
                    forSale = item.forSale;
                } else {
                    forSale = true;
                }
                var stParts = [];
                stParts.push( forSale ? ( wbsAdmin.i18n.bolOfferForSale || 'For sale' ) : ( wbsAdmin.i18n.bolOfferNotForSale || 'Not for sale' ) );
                if ( onHold ) {
                    stParts.push( wbsAdmin.i18n.bolOfferOnHold || 'On hold' );
                }
                return {
                    offer_id: offerId,
                    ean: ean,
                    title: title,
                    stock: stock,
                    price: priceLabel,
                    condition: condition,
                    fulfilment: fulfil,
                    reference: reference,
                    image_url: '',
                    status_display: stParts.join( ' · ' ),
                    sale_badge: forSale ? 'for_sale' : 'not_for_sale',
                    on_hold: onHold ? '1' : '0',
                };
            } )
            .filter( function ( row ) {
                return !! row;
            } );
    }

    function renderBolOffersTableRows( rows ) {
        if ( ! Array.isArray( rows ) || ! rows.length ) {
            return '';
        }
        var html = '';
        var label = wbsAdmin.i18n.deleteOfferRow || 'Delete';
        $.each( rows, function ( _, r ) {
            var oid = String( r.offer_id || '' );
            var img =
                r.image_url
                    ? '<img src="' +
                      escapeAttr( r.image_url ) +
                      '" alt="" width="48" height="48" loading="lazy" class="wbs-bol-offer-thumb-img" />'
                    : '<span class="wbs-bol-offer-thumb wbs-bol-offer-thumb--placeholder" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>';
            var cond = r.condition ? '<br><span class="description">' + escapeHtml( r.condition ) + '</span>' : '';
            html +=
                '<tr data-offer-id="' +
                escapeAttr( oid ) +
                '">' +
                '<td class="wbs-col-check"><input type="checkbox" class="wbs-bol-offer-cb" data-offer-id="' +
                escapeAttr( oid ) +
                '" /></td>' +
                '<td class="wbs-col-thumb">' +
                img +
                '</td>' +
                '<td class="wbs-bol-offer-title"><strong>' +
                escapeHtml( r.title || '' ) +
                '</strong>' +
                cond +
                '</td>' +
                '<td><code>' +
                escapeHtml( r.ean || '—' ) +
                '</code></td>' +
                '<td>' +
                escapeHtml( r.stock !== '' && r.stock !== undefined ? String( r.stock ) : '—' ) +
                '</td>' +
                '<td>' +
                escapeHtml( r.price || '—' ) +
                '</td>' +
                '<td class="wbs-col-status">' +
                '<span class="wbs-offer-status">' +
                '<span class="wbs-badge wbs-badge--' +
                String( r.sale_badge || 'for_sale' ).replace( /_/g, '-' ) +
                ( r.on_hold === '1' || r.on_hold === 1 ? ' wbs-badge--with-hold' : '' ) +
                '">' +
                escapeHtml( r.status_display || '—' ) +
                '</span></span></td>' +
                '<td>' +
                escapeHtml( r.fulfilment || '—' ) +
                '</td>' +
                '<td>' +
                escapeHtml( r.reference || '—' ) +
                '</td>' +
                '<td class="wbs-col-actions"><button type="button" class="button button-small wbs-bol-offer-delete-row" data-offer-id="' +
                escapeAttr( oid ) +
                '">' +
                escapeHtml( label ) +
                '</button></td>' +
                '</tr>';
        } );
        return html;
    }

    function bolOffersRefreshBulkState() {
        var $cbs = $( '#wbs-bol-products-offers-rows .wbs-bol-offer-cb' );
        var n = $cbs.length;
        var c = $cbs.filter( ':checked' ).length;
        $( '#wbs-bol-offers-delete-selected' ).prop( 'disabled', c < 1 );
        var $all = $( '#wbs-bol-offers-select-all' );
        $all.prop( 'checked', n > 0 && c === n );
        $all.prop( 'indeterminate', c > 0 && c < n );
    }

    function bolRemoveOfferRow( offerId ) {
        $( '#wbs-bol-products-offers-rows tr' )
            .filter( function () {
                return String( $( this ).data( 'offer-id' ) ) === String( offerId );
            } )
            .remove();
        bolOffersRefreshBulkState();
        if ( ! $( '#wbs-bol-products-offers-rows tr' ).length ) {
            $( '#wbs-bol-offers-table-wrap' ).hide();
        }
    }

    $( document ).on( 'click', '#wbs-btn-bol-products-fetch', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-bol-products-message' );
        var $catalog = $( '#wbs-bol-products-catalog' );
        var $rawPre = $( '#wbs-bol-products-offers' );
        var $rawDetails = $( '#wbs-bol-offers-raw-details' );
        var $tableWrap = $( '#wbs-bol-offers-table-wrap' );
        var $tbody = $( '#wbs-bol-products-offers-rows' );
        var ean = String( $( '#wbs-bol-products-ean' ).val() || '' ).replace( /\D/g, '' );

        setLoading( $btn, wbsAdmin.i18n.fetchingProducts || wbsAdmin.i18n.loading || 'Loading…' );
        $msg.slideUp( 80 );
        $catalog.hide().text( '' );
        $tbody.empty();
        $tableWrap.hide();
        $rawPre.text( '' );
        $rawDetails.hide();

        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: { action: 'wbs_bol_products_fetch', nonce: wbsAdmin.nonce, ean: ean, page: 1, size: 50 },
            success: function ( response ) {
                resetButton( $btn );
                if ( response.success && response.data ) {
                    if ( response.data.catalog && Object.keys( response.data.catalog ).length ) {
                        $catalog.text( prettyJson( response.data.catalog || {} ) ).show();
                    } else {
                        $catalog.hide().text( '' );
                    }
                    var rows = Array.isArray( response.data.offer_rows ) ? response.data.offer_rows : [];
                    if ( ! rows.length && response.data.offers ) {
                        rows = normalizeOfferRowsClient( extractOfferItemsFromPayload( response.data.offers ) );
                    }
                    if ( rows.length ) {
                        $tbody.html( renderBolOffersTableRows( rows ) );
                        $tableWrap.show();
                        $( '#wbs-bol-offers-select-all' ).prop( 'checked', false ).prop( 'indeterminate', false );
                        bolOffersRefreshBulkState();
                    } else {
                        $tableWrap.hide();
                    }
                    $rawPre.text( prettyJson( response.data.offers || {} ) );
                    $rawDetails.show();
                    showMessage( $msg, escapeHtml( response.data.message || 'Fetched.' ), 'success' );
                } else {
                    showMessage( $msg, escapeHtml( ( response.data && response.data.message ) || wbsAdmin.i18n.error ), 'error' );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    } );

    $( document ).on( 'change', '#wbs-bol-offers-select-all', function () {
        var on = $( this ).prop( 'checked' );
        $( '#wbs-bol-products-offers-rows .wbs-bol-offer-cb' ).prop( 'checked', on );
        bolOffersRefreshBulkState();
    } );

    $( document ).on( 'change', '.wbs-bol-offer-cb', function () {
        bolOffersRefreshBulkState();
    } );

    function bolDeleteOfferOnServer( offerId, onSuccess, onError ) {
        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: { action: 'wbs_bol_offer_delete', nonce: wbsAdmin.nonce, offer_id: offerId },
            success: function ( response ) {
                if ( response.success && response.data ) {
                    if ( typeof onSuccess === 'function' ) {
                        onSuccess( response.data.message || '', response.data );
                    }
                } else {
                    var err = ( response.data && response.data.message ) || wbsAdmin.i18n.error;
                    if ( typeof onError === 'function' ) {
                        onError( err );
                    }
                }
            },
            error: function () {
                if ( typeof onError === 'function' ) {
                    onError( wbsAdmin.i18n.error );
                }
            },
        } );
    }

    $( document ).on( 'click', '.wbs-bol-offer-delete-row', function () {
        var $btn = $( this );
        var offerId = String( $btn.data( 'offer-id' ) || '' );
        if ( ! offerId || ! window.confirm( wbsAdmin.i18n.confirmDeleteOffer || 'Delete?' ) ) {
            return;
        }
        $btn.prop( 'disabled', true );
        bolDeleteOfferOnServer(
            offerId,
            function ( msg ) {
                bolRemoveOfferRow( offerId );
                showMessage( $( '#wbs-bol-products-message' ), escapeHtml( msg ), 'success' );
            },
            function ( err ) {
                $btn.prop( 'disabled', false );
                showMessage( $( '#wbs-bol-products-message' ), escapeHtml( err ), 'error' );
            }
        );
    } );

    $( document ).on( 'click', '#wbs-bol-offers-delete-selected', function () {
        var $bulk = $( this );
        var ids = [];
        $( '#wbs-bol-products-offers-rows .wbs-bol-offer-cb:checked' ).each( function () {
            var id = String( $( this ).data( 'offer-id' ) || '' );
            if ( id ) {
                ids.push( id );
            }
        } );
        if ( ! ids.length ) {
            showMessage( $( '#wbs-bol-products-message' ), escapeHtml( wbsAdmin.i18n.selectOffersFirst || 'Select offers first.' ), 'error' );
            return;
        }
        var tmpl = wbsAdmin.i18n.confirmBulkDeleteOffer || 'Delete %d offers?';
        if ( ! window.confirm( tmpl.replace( '%d', String( ids.length ) ) ) ) {
            return;
        }
        var origText = $bulk.text();
        var i = 0;
        var errors = [];
        var ok = 0;
        $bulk.prop( 'disabled', true );
        function runNext() {
            if ( i >= ids.length ) {
                $bulk.prop( 'disabled', false ).text( origText );
                bolOffersRefreshBulkState();
                var $msg = $( '#wbs-bol-products-message' );
                if ( errors.length && ok === 0 ) {
                    showMessage( $msg, escapeHtml( errors[ 0 ] ), 'error' );
                } else if ( errors.length ) {
                    showMessage(
                        $msg,
                        escapeHtml(
                            ( wbsAdmin.i18n.offersDeletedCount || '%d removed.' ).replace( '%d', String( ok ) ) +
                                ' ' +
                                ( wbsAdmin.i18n.bulkDeleteHadErrors || 'Some deletions failed:' ) +
                                ' ' +
                                errors[ 0 ]
                        ),
                        'error'
                    );
                } else {
                    showMessage(
                        $msg,
                        escapeHtml( ( wbsAdmin.i18n.offersDeletedCount || '%d removed.' ).replace( '%d', String( ok ) ) ),
                        'success'
                    );
                }
                return;
            }
            var id = ids[ i++ ];
            $bulk.text( ( wbsAdmin.i18n.deletingOffersBulk || 'Deleting…' ) + ' (' + String( i ) + '/' + String( ids.length ) + ')' );
            bolDeleteOfferOnServer(
                id,
                function () {
                    ok++;
                    bolRemoveOfferRow( id );
                    runNext();
                },
                function ( err ) {
                    errors.push( err );
                    runNext();
                }
            );
        }
        runNext();
    } );

    $( document ).on( 'click', '#wbs-btn-bol-offer-delete', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-bol-offer-delete-message' );
        var raw = String( $( '#wbs-bol-offer-delete-id' ).val() || '' ).trim();
        if ( ! raw ) {
            showMessage( $msg, escapeHtml( wbsAdmin.i18n.deleteOfferNeedId || 'Enter a bol.com offer ID.' ), 'error' );
            return;
        }
        if ( ! window.confirm( wbsAdmin.i18n.confirmDeleteOffer || 'Delete this offer on bol.com?' ) ) {
            return;
        }
        setLoading( $btn, wbsAdmin.i18n.deletingOffer || 'Deleting…' );
        $msg.slideUp( 80 );
        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: { action: 'wbs_bol_offer_delete', nonce: wbsAdmin.nonce, offer_id: raw },
            success: function ( response ) {
                resetButton( $btn );
                if ( response.success && response.data ) {
                    bolRemoveOfferRow( raw );
                    $( '#wbs-bol-offer-delete-id' ).val( '' );
                    showMessage( $msg, escapeHtml( response.data.message || '' ), 'success' );
                } else {
                    showMessage( $msg, escapeHtml( ( response.data && response.data.message ) || wbsAdmin.i18n.error ), 'error' );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    } );

    // ── bol orders page (read-only) ──────────────────────────────────────────

    function bolOrderListStatusLabel( order ) {
        var items = order && order.orderItems;
        if ( ! Array.isArray( items ) || ! items.length ) {
            return String( order && order.status ? order.status : '' ) || '—';
        }
        var statuses = [];
        var methods = [];
        var i, st, m, f;
        for ( i = 0; i < items.length; i++ ) {
            f = items[ i ].fulfilment || {};
            st = items[ i ].fulfilmentStatus || items[ i ].status || '';
            m = items[ i ].fulfilmentMethod || f.method || '';
            if ( st && statuses.indexOf( st ) === -1 ) {
                statuses.push( st );
            }
            if ( m && methods.indexOf( m ) === -1 ) {
                methods.push( m );
            }
        }
        var out = statuses.length ? statuses.sort().join( ', ' ) : '—';
        if ( methods.length === 1 ) {
            out += ' (' + methods[ 0 ] + ')';
        } else if ( methods.length > 1 ) {
            out += ' (' + methods.sort().join( '/' ) + ')';
        }
        return out;
    }

    function renderBolOrdersTable( orders ) {
        var rows = [];
        if ( ! Array.isArray( orders ) || ! orders.length ) {
            rows.push( '<tr><td colspan="6">No orders found for this filter.</td></tr>' );
            return rows.join( '' );
        }
        $.each( orders, function ( _, order ) {
            var orderId = String( order.orderId || order.id || '' );
            var status = bolOrderListStatusLabel( order );
            var orderDate = String( order.orderPlacedDateTime || order.dateTimeOrderPlaced || '' );
            var country = String( order.countryCode || ( order.customerDetails && order.customerDetails.countryCode ) || '' );
            var itemCount = Array.isArray( order.orderItems ) ? order.orderItems.length : 0;
            rows.push(
                '<tr>' +
                    '<td><code>' + escapeHtml( orderId ) + '</code></td>' +
                    '<td>' + escapeHtml( status || '—' ) + '</td>' +
                    '<td>' + escapeHtml( orderDate || '—' ) + '</td>' +
                    '<td>' + escapeHtml( country || '—' ) + '</td>' +
                    '<td>' + escapeHtml( String( itemCount ) ) + '</td>' +
                    '<td><button type="button" class="button button-small wbs-bol-order-detail-btn" data-order-id="' + escapeHtml( orderId ) + '">Detail</button></td>' +
                '</tr>'
            );
        } );
        return rows.join( '' );
    }

    $( document ).on( 'click', '#wbs-btn-bol-orders-fetch', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-bol-orders-message' );
        var $rows = $( '#wbs-bol-orders-rows' );
        var $wrap = $( '#wbs-bol-orders-table-wrap' );
        var status = String( $( '#wbs-bol-orders-status' ).val() || 'ALL' );
        var fulfilment = String( $( '#wbs-bol-orders-fulfilment' ).val() || 'ALL' );
        var latestDate = String( $( '#wbs-bol-orders-latest-date' ).val() || '' ).trim();
        var page = Number( $( '#wbs-bol-orders-page' ).val() || 1 );
        var size = Number( $( '#wbs-bol-orders-size' ).val() || 50 );

        setLoading( $btn, wbsAdmin.i18n.fetchingOrders || wbsAdmin.i18n.loading || 'Loading…' );
        $msg.slideUp( 80 );

        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wbs_bol_orders_fetch',
                nonce: wbsAdmin.nonce,
                status: status,
                fulfilment_method: fulfilment,
                latest_change_date: latestDate,
                page: page,
                size: size
            },
            success: function ( response ) {
                resetButton( $btn );
                if ( response.success && response.data ) {
                    $rows.html( renderBolOrdersTable( response.data.orders || [] ) );
                    $wrap.show();
                    var msgText = response.data.message || 'Fetched.';
                    if ( response.data.hint && ( ! response.data.orders || ! response.data.orders.length ) ) {
                        msgText += ' ' + response.data.hint;
                    }
                    showMessage( $msg, escapeHtml( msgText ), 'success' );
                } else {
                    showMessage( $msg, escapeHtml( ( response.data && response.data.message ) || wbsAdmin.i18n.error ), 'error' );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    } );

    ( function initBolOrdersDateInput() {
        var $d = $( '#wbs-bol-orders-latest-date' );
        if ( ! $d.length ) {
            return;
        }
        var t = new Date();
        var y = t.getFullYear();
        var mo = String( t.getMonth() + 1 ).padStart( 2, '0' );
        var da = String( t.getDate() ).padStart( 2, '0' );
        $d.attr( 'max', y + '-' + mo + '-' + da );
    }() );

    function fetchBolOrderDetail( orderId, $btn ) {
        var $msg = $( '#wbs-bol-order-detail-message' );
        var $json = $( '#wbs-bol-order-detail-json' );

        if ( ! orderId ) {
            showMessage( $msg, escapeHtml( 'Order ID is required.' ), 'error' );
            return;
        }
        $( '#wbs-bol-order-id' ).val( orderId );

        if ( $btn && $btn.length ) {
            setLoading( $btn, wbsAdmin.i18n.fetchingOrderDetail || wbsAdmin.i18n.loading || 'Loading…' );
        }
        $msg.slideUp( 80 );
        $json.hide().text( '' );

        $.ajax( {
            url: wbsAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wbs_bol_order_detail_fetch',
                nonce: wbsAdmin.nonce,
                order_id: orderId
            },
            success: function ( response ) {
                if ( $btn && $btn.length ) {
                    resetButton( $btn );
                }
                if ( response.success && response.data ) {
                    $json.text( prettyJson( response.data.body || {} ) ).show();
                    showMessage( $msg, escapeHtml( response.data.message || 'Fetched.' ), 'success' );
                } else {
                    showMessage( $msg, escapeHtml( ( response.data && response.data.message ) || wbsAdmin.i18n.error ), 'error' );
                }
            },
            error: function () {
                if ( $btn && $btn.length ) {
                    resetButton( $btn );
                }
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            }
        } );
    }

    $( document ).on( 'click', '#wbs-btn-bol-order-detail-fetch', function () {
        var $btn = $( this );
        var orderId = String( $( '#wbs-bol-order-id' ).val() || '' ).trim();
        fetchBolOrderDetail( orderId, $btn );
    } );

    $( document ).on( 'click', '.wbs-bol-order-detail-btn', function () {
        var orderId = String( $( this ).data( 'order-id' ) || '' ).trim();
        fetchBolOrderDetail( orderId, $() );
    } );

    // ── Clear Logs ────────────────────────────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-clear-logs', function () {
        if ( ! window.confirm( wbsAdmin.i18n.confirmClear ) ) {
            return;
        }

        var $btn = $( this );
        var $msg = $( '#wbs-logs-message' );

        setLoading( $btn, wbsAdmin.i18n.clearing );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_clear_logs', nonce: wbsAdmin.nonce },
            success: function ( response ) {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( response.data.message ), response.success ? 'success' : 'error' );

                if ( response.success ) {
                    // Clear table body without a page reload.
                    $( '#wbs-log-tbody' ).fadeOut( 300, function () {
                        $( this ).closest( '.wbs-table-wrap' ).replaceWith(
                            '<div class="wbs-card wbs-empty-state"><p>'
                            + wbsAdmin.i18n.error.replace( wbsAdmin.i18n.error, response.data.message )
                            + '</p></div>'
                        );
                    } );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    } );

    // ── Validate License ──────────────────────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-validate-license', function () {
        var $btn = $( this );
        var $msg = $( '#wbs-license-message' );
        var key  = $( '#wbs-license-key-input' ).val().trim();

        if ( ! key ) {
            showMessage( $msg, escapeHtml( 'Please enter a license key.' ), 'error' );
            return;
        }

        setLoading( $btn, wbsAdmin.i18n.validating );
        $msg.slideUp( 80 );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_validate_license', nonce: wbsAdmin.nonce, license_key: key },
            success: function ( response ) {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( response.data.message ), response.success ? 'success' : 'error' );

                if ( response.success ) {
                    // Reload after a short delay so the status badge updates.
                    setTimeout( function () { window.location.reload(); }, 1500 );
                }
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, escapeHtml( wbsAdmin.i18n.error ), 'error' );
            },
        } );
    } );

    // ── Deactivate License ────────────────────────────────────────────────────

    $( document ).on( 'click', '#wbs-btn-deactivate-license', function () {
        if ( ! window.confirm( 'Are you sure you want to deactivate this license?' ) ) {
            return;
        }

        var $btn = $( this );
        var $msg = $( '#wbs-license-message' );

        // We reuse the validate endpoint but with an empty key to trigger deactivation.
        setLoading( $btn, 'Deactivating…' );

        $.ajax( {
            url    : wbsAdmin.ajaxUrl,
            method : 'POST',
            data   : { action: 'wbs_validate_license', nonce: wbsAdmin.nonce, license_key: '', deactivate: '1' },
            success: function () {
                resetButton( $btn );
                setTimeout( function () { window.location.reload(); }, 800 );
            },
            error: function () {
                resetButton( $btn );
                showMessage( $msg, wbsAdmin.i18n.error, 'error' );
            },
        } );
    } );

    // ── Log data expand / collapse ────────────────────────────────────────────

    $( document ).on( 'click', '.wbs-toggle-data', function ( e ) {
        e.preventDefault();

        var target    = $( this ).data( 'target' );
        var $data     = $( '#' + target );
        var expanded  = $( this ).attr( 'aria-expanded' ) === 'true';

        $data.slideToggle( 150 );
        $( this )
            .attr( 'aria-expanded', ! expanded )
            .text( expanded ? '[data ▾]' : '[data ▴]' );
    } );

    // ── Settings: tab switching ───────────────────────────────────────────────

    var wbsTabStorageKey = 'wbs_settings_active_tab';

    function wbsActivateTab( tabKey, opts ) {
        opts = opts || {};
        var $tabs   = $( '.wbs-settings-tab' );
        var $panels = $( '.wbs-settings-panel' );
        if ( ! $tabs.length || ! $panels.length ) {
            return;
        }

        var $target = $tabs.filter( '[data-wbs-tab="' + tabKey + '"]' );
        if ( ! $target.length ) {
            $target = $tabs.first();
            tabKey  = $target.data( 'wbs-tab' );
        }

        $tabs
            .removeClass( 'nav-tab-active is-active' )
            .attr( 'aria-selected', 'false' );
        $target
            .addClass( 'nav-tab-active is-active' )
            .attr( 'aria-selected', 'true' );

        $panels.each( function () {
            var $panel  = $( this );
            var matches = $panel.data( 'wbs-panel' ) === tabKey;
            $panel.toggleClass( 'is-active', matches );
            if ( matches ) {
                $panel.removeAttr( 'hidden' );
            } else {
                $panel.attr( 'hidden', 'hidden' );
            }
        } );

        $( '#wbs_active_tab' ).val( tabKey );

        try {
            window.localStorage.setItem( wbsTabStorageKey, tabKey );
        } catch ( e ) {
            // localStorage may be unavailable (private mode); ignore.
        }

        if ( opts.updateUrl && window.history && typeof window.history.replaceState === 'function' ) {
            try {
                var url = new URL( window.location.href );
                url.searchParams.set( 'tab', tabKey );
                window.history.replaceState( {}, '', url.toString() );
            } catch ( e ) {
                // URL constructor unsupported; skip.
            }
        }

        if ( typeof wbsToggleScheduleRows === 'function' ) {
            wbsToggleScheduleRows();
        }
    }

    $( document ).on( 'click', '.wbs-settings-tab', function ( e ) {
        if ( e.metaKey || e.ctrlKey || e.shiftKey ) {
            return;
        }
        var tabKey = $( this ).data( 'wbs-tab' );
        if ( ! tabKey ) {
            return;
        }
        e.preventDefault();
        wbsActivateTab( tabKey, { updateUrl: true } );
    } );

    if ( $( '.wbs-settings-tabs' ).length ) {
        var initialTab = '';

        try {
            var url = new URL( window.location.href );
            initialTab = url.searchParams.get( 'tab' ) || '';
        } catch ( e ) {
            initialTab = '';
        }

        if ( ! initialTab ) {
            try {
                initialTab = window.localStorage.getItem( wbsTabStorageKey ) || '';
            } catch ( e ) {
                initialTab = '';
            }
        }

        if ( initialTab ) {
            wbsActivateTab( initialTab, { updateUrl: false } );
        }
    }

    // ── Settings: product / order schedule field visibility ───────────────────

    function wbsToggleScheduleRows() {
        var $pMode = $( '#wbs_product_sync_mode' );
        if ( ! $pMode.length ) {
            return;
        }

        var pMode = $pMode.val();
        var pBatch = pMode !== 'wc_updates';

        $( '#wbs_product_sync_time' ).closest( 'tr' ).toggle( pBatch );
        $( '#wbs_product_sync_weekday' ).closest( 'tr' ).toggle( pBatch && pMode === 'weekly' );
        $( '#wbs_product_sync_monthday' ).closest( 'tr' ).toggle( pBatch && pMode === 'monthly' );

    }

    wbsToggleScheduleRows();
    $( document ).on( 'change', '#wbs_product_sync_mode', wbsToggleScheduleRows );

    $( document ).on( 'input change', '#_wbs_net_content_value, #_wbs_net_content_unit, #_wbs_net_content_pieces', updateNetContentPreview );
    updateNetContentPreview();

} )( jQuery );
