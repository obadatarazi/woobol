(function ($) {
    'use strict';

    if (typeof window.wbsStaging === 'undefined') {
        return;
    }

    var cfg = window.wbsStaging;
    var t = cfg.i18n || {};
    var STORAGE_DIFF_KEY = 'wbsStagingDiffOnlyChanged';
    var STORAGE_UI_KEY = 'wbsStagingCompactMode';

    var $status   = $('#wbs-staging-status');
    var $selectionSummary = $('#wbs-staging-selection-summary');
    var $tableRows = $('#wbs-staging-rows');
    var $editPane = $('#wbs-staging-edit');
    var $modal = $('#wbs-staging-modal');

    function normalizeEditPaneElement() {
        if (!$editPane.length) {
            return;
        }
        if (($editPane.prop('tagName') || '').toLowerCase() !== 'aside') {
            return;
        }

        var $replacement = $('<div/>', {
            id: $editPane.attr('id') || 'wbs-staging-edit',
            'class': $editPane.attr('class') || 'wbs-staging-edit',
            'aria-live': $editPane.attr('aria-live') || 'polite'
        }).html($editPane.html());

        $editPane.replaceWith($replacement);
        $editPane = $replacement;
    }

    function ensureModalContainer() {
        if (!$editPane.length) {
            return;
        }

        if (!$modal.length) {
            $modal = $(
                '<div class="wbs-staging-modal" id="wbs-staging-modal" aria-hidden="true">' +
                    '<div class="wbs-staging-modal__dialog" role="dialog" aria-modal="true" aria-label="Edit draft">' +
                        '<div class="wbs-staging-modal__header">' +
                            '<strong>Edit Draft</strong>' +
                            '<button type="button" class="button button-secondary" id="wbs-staging-close-modal">Close</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
            $('body').append($modal);
        }

        var $dialog = $modal.find('.wbs-staging-modal__dialog').first();
        if ($dialog.length && !$editPane.closest('#wbs-staging-modal').length) {
            $dialog.append($editPane);
        }
        if ($editPane.length) {
            $editPane.prop('hidden', true);
        }

        // Hide any legacy inline editor pane from old cached templates.
        $('.wbs-staging-layout > .wbs-staging-edit').hide();
    }

    var state = {
        currentDraftId: 0,
        selectedItemKeys: {},
        diffOnlyChanged: false,
        compactMode: false,
        quickTab: 'all',
        filters: {
            search: '',
            validation_status: '',
            review_status: '',
            sync_status: '',
            include_variations: 1
        }
    };

    function setStatus(text, isError) {
        $status.text(text || '');
        $status.css('color', isError ? '#a30000' : '#555');
    }

    function loadDiffPreference() {
        try {
            var raw = window.localStorage.getItem(STORAGE_DIFF_KEY);
            state.diffOnlyChanged = raw === '1';
        } catch (e) {
            state.diffOnlyChanged = false;
        }
    }

    function saveDiffPreference() {
        try {
            window.localStorage.setItem(STORAGE_DIFF_KEY, state.diffOnlyChanged ? '1' : '0');
        } catch (e) {
            // ignore storage failures
        }
    }

    function loadUiPreference() {
        try {
            state.compactMode = window.localStorage.getItem(STORAGE_UI_KEY) === '1';
        } catch (e) {
            state.compactMode = false;
        }
    }

    function saveUiPreference() {
        try {
            window.localStorage.setItem(STORAGE_UI_KEY, state.compactMode ? '1' : '0');
        } catch (e) {
            // ignore storage failures
        }
    }

    function applyCompactMode() {
        $('.wbs-staging-wrap').toggleClass('is-compact', !!state.compactMode);
        $('#wbs-staging-compact-mode').prop('checked', !!state.compactMode);
    }

    function ajax(action, payload) {
        var data = Object.assign({ action: action, nonce: cfg.nonce }, payload || {});
        return $.post(cfg.ajaxUrl, data).then(function (resp) {
            if (!resp || !resp.success) {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : t.error;
                return $.Deferred().reject(msg).promise();
            }
            return resp.data;
        }, function () {
            return t.error;
        });
    }

    function chip(type, value) {
        if (!value) { return ''; }
        var cls = value.toLowerCase().replace(/[^a-z_]/g, '');
        return '<span class="wbs-chip ' + cls + '">' + escapeHtml(value) + '</span>';
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateCounters(counts) {
        if (!counts) { return; }
        Object.keys(counts).forEach(function (key) {
            $('[data-count="' + key + '"]').text(counts[key]);
        });
    }

    function plainText(value) {
        var html = String(value == null ? '' : value);
        if (html === '') { return ''; }
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return String(tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
    }

    function renderRow(row) {
        var isVariationRow = row.item_type === 'variation';
        var editDraftId = isVariationRow ? Number(row.parent_draft_id || 0) : Number(row.id || 0);
        var syncDraftId = isVariationRow ? 0 : editDraftId;
        var variationDraftId = isVariationRow ? Number(row.id || 0) : 0;
        var selectKey = isVariationRow ? ('v:' + variationDraftId) : ('d:' + syncDraftId);
        var checked = !!state.selectedItemKeys[selectKey];
        var price = row.regular_price == null ? '—' : row.regular_price;
        var stock = row.stock_quantity == null ? '—' : row.stock_quantity;
        var sku   = row.sku || '';
        var ean   = row.ean || '';
        var shortDescription = plainText(row.short_description || '');
        var longDescription = plainText(row.description || '');
        var variationSummary = row.variation_summary || {};
        var variationText = (variationSummary.total || 0) + ' total · ' +
            (variationSummary.ready || 0) + ' ready · ' +
            (variationSummary.blocked || 0) + ' blocked';
        var syncFields = [
            row.sync_price ? 'price' : '',
            row.sync_stock ? 'stock' : '',
            row.sync_content ? 'content' : '',
            row.sync_images ? 'images' : ''
        ].filter(Boolean).join(', ');
        var variationHasImage = isVariationRow ? (normalizeText(row.image_url || '') !== '' ? 1 : 0) : 1;
        return (
            '<tr data-draft-id="' + editDraftId + '" data-sync-draft-id="' + syncDraftId + '"' + (editDraftId === state.currentDraftId ? ' class="is-selected"' : '') + (isVariationRow ? ' data-item-type="variation"' : '') + ' data-variation-has-image="' + variationHasImage + '">' +
                '<td><input type="checkbox" class="wbs-row-select" data-sync-draft-id="' + syncDraftId + '" data-variation-draft-id="' + variationDraftId + '" data-select-key="' + escapeHtml(selectKey) + '"' + (checked ? ' checked' : '') + ' /></td>' +
                '<td title="' + escapeHtml(row.name || ('#' + row.wc_product_id)) + '"><strong>' + escapeHtml(row.name || ('#' + row.wc_product_id)) + '</strong><br><small>ID #' + row.wc_product_id + ' — ' + escapeHtml(row.product_type) + '</small></td>' +
                '<td>' + escapeHtml(sku) + '<br><small>' + escapeHtml(ean) + '</small></td>' +
                '<td title="' + escapeHtml(shortDescription) + '">' + escapeHtml(shortDescription || '—') + '</td>' +
                '<td title="' + escapeHtml(longDescription) + '">' + escapeHtml(longDescription || '—') + '</td>' +
                '<td>' + escapeHtml(price) + '</td>' +
                '<td>' + escapeHtml(stock) + '</td>' +
                '<td>' + escapeHtml(variationText) + '</td>' +
                '<td><small>' + escapeHtml(syncFields || 'none') + '</small></td>' +
                '<td>' + chip('validation', row.validation_status) + '</td>' +
                '<td>' + chip('review', row.review_status) + '</td>' +
                '<td>' + chip('sync', row.sync_status) + '</td>' +
            '</tr>'
        );
    }

    function loadList() {
        setStatus(t.loading);
        var payload = Object.assign({}, state.filters, { limit: 100, offset: 0 });
        return ajax('wbs_staging_list', payload).done(function (data) {
            var rows = (data && data.items) || [];
            if (!rows.length) {
                $tableRows.html('<tr><td colspan="12">' + escapeHtml(t.noDraftSelected || 'No drafts.') + '</td></tr>');
            } else {
                $tableRows.html(rows.map(renderRow).join(''));
            }
            applyQuickTabRowFilter();
            pruneSelectionToVisibleRows();
            refreshSelectionUi();
            updateCounters(data.counts);
            setStatus('');
        }).fail(function (msg) {
            setStatus(msg || t.error, true);
        });
    }

    function applyQuickTabRowFilter() {
        if (state.quickTab !== 'variation_missing_images') {
            return;
        }
        var visible = 0;
        $tableRows.find('tr[data-draft-id]').each(function () {
            var $row = $(this);
            var isVariation = $row.attr('data-item-type') === 'variation';
            var hasImage = Number($row.attr('data-variation-has-image') || 0) === 1;
            var show = isVariation && !hasImage;
            $row.toggle(show);
            if (show) { visible += 1; }
        });
        if (visible === 0) {
            $tableRows.html('<tr><td colspan="12">No variation rows missing images in current result set.</td></tr>');
        }
    }

    function selectedIdsPayload() {
        var payload = {
            draftIds: [],
            variationDraftIds: []
        };
        Object.keys(state.selectedItemKeys).forEach(function (key) {
            var parts = String(key).split(':');
            if (parts.length !== 2) { return; }
            var type = parts[0];
            var id = Number(parts[1]);
            if (!Number.isFinite(id) || id <= 0) { return; }
            if (type === 'd') {
                payload.draftIds.push(id);
            } else if (type === 'v') {
                payload.variationDraftIds.push(id);
            }
        });
        return payload;
    }

    function selectedIdsPayloadFromCheckedRows() {
        var payload = {
            draftIds: [],
            variationDraftIds: []
        };
        var seen = {};

        $tableRows.find('input.wbs-row-select:checked').each(function () {
            var variationDraftId = Number($(this).attr('data-variation-draft-id') || 0);
            var syncDraftId = Number($(this).attr('data-sync-draft-id') || 0);
            if (Number.isFinite(variationDraftId) && variationDraftId > 0) {
                var vKey = 'v:' + variationDraftId;
                if (!seen[vKey]) {
                    payload.variationDraftIds.push(variationDraftId);
                    seen[vKey] = 1;
                }
                return;
            }
            if (Number.isFinite(syncDraftId) && syncDraftId > 0) {
                var dKey = 'd:' + syncDraftId;
                if (!seen[dKey]) {
                    payload.draftIds.push(syncDraftId);
                    seen[dKey] = 1;
                }
            }
        });

        return payload;
    }

    function pruneSelectionToVisibleRows() {
        var visibleKeys = {};
        $tableRows.find('input.wbs-row-select').each(function () {
            var key = String($(this).attr('data-select-key') || '');
            if (key) {
                visibleKeys[key] = 1;
            }
        });

        Object.keys(state.selectedItemKeys).forEach(function (key) {
            if (!visibleKeys[key]) {
                delete state.selectedItemKeys[key];
            }
        });
    }

    function refreshSelectionUi() {
        var selected = selectedIdsPayload();
        var productCount = selected.draftIds.length;
        var variationCount = selected.variationDraftIds.length;
        var selectedCount = productCount + variationCount;
        $('#wbs-staging-sync-selected').prop('disabled', selectedCount === 0);
        if ($selectionSummary.length) {
            $selectionSummary.text(selectedCount > 0
                ? (productCount + ' products + ' + variationCount + ' variations selected')
                : ''
            );
        }
        var $boxes = $tableRows.find('input.wbs-row-select');
        var allChecked = $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length;
        $('#wbs-staging-select-all').prop('checked', allChecked);
    }

    function selectDraft(draftId) {
        if (!Number.isFinite(draftId) || draftId <= 0) {
            return $.Deferred().reject('invalid_draft_id').promise();
        }
        state.currentDraftId = draftId;
        $('#wbs-staging-rows tr').removeClass('is-selected');
        $('#wbs-staging-rows tr[data-draft-id="' + draftId + '"]').addClass('is-selected');
        openEditModal();
        if ($editPane.length) {
            $editPane.html('<p class="wbs-empty-state">' + escapeHtml(t.loading || 'Loading...') + '</p>');
        }
        setStatus(t.loading);
        return ajax('wbs_staging_get', { draft_id: draftId }).done(function (data) {
            renderEditor(data);
            setStatus('');
        }).fail(function (msg) {
            setStatus(msg || t.error, true);
            if ($editPane.length) {
                $editPane.html('<p class="wbs-empty-state">' + escapeHtml(msg || t.error || 'Failed to load draft details.') + '</p>');
            }
        });
    }

    function openEditModal() {
        if (!$modal.length) { return; }
        if ($editPane.length) {
            $editPane.prop('hidden', false);
        }
        $modal.addClass('is-open').attr('aria-hidden', 'false');
    }

    function closeEditModal() {
        if (!$modal.length) { return; }
        $modal.removeClass('is-open').attr('aria-hidden', 'true');
        if ($editPane.length) {
            $editPane.prop('hidden', true);
        }
    }

    function renderValidationMessages(product) {
        var errors = product.validation_errors || [];
        var warnings = product.validation_warnings || [];
        var out = '';
        if (errors.length) {
            out += '<div class="wbs-validation-messages error"><strong>' + escapeHtml('Validation errors') + ':</strong><ul>' +
                errors.map(function (e) { return '<li>' + escapeHtml(e) + '</li>'; }).join('') + '</ul></div>';
        }
        if (warnings.length) {
            out += '<div class="wbs-validation-messages"><strong>' + escapeHtml('Warnings') + ':</strong><ul>' +
                warnings.map(function (e) { return '<li>' + escapeHtml(e) + '</li>'; }).join('') + '</ul></div>';
        }
        return out;
    }

    function renderGallery(gallery) {
        if (!gallery || !gallery.length) {
            return '<p><em>' + escapeHtml('No gallery images.') + '</em></p>';
        }
        return '<div class="wbs-gallery-preview">' + gallery.map(function (img, i) {
            return '<div class="wbs-gallery-item" data-index="' + i + '">' +
                   '<img src="' + escapeHtml(img.url || '') + '" alt="" />' +
                   '<button type="button" class="wbs-remove-gallery" title="' + escapeHtml(t.removeGalleryImage) + '">×</button>' +
                   '</div>';
        }).join('') + '</div>';
    }

    function renderVariations(variations) {
        if (!variations || !variations.length) {
            return '<p><em>' + escapeHtml(t.noVariations) + '</em></p>';
        }
        return variations.map(function (v) {
            var attrs = v.attributes && typeof v.attributes === 'object' ? v.attributes : {};
            var attrChips = Object.keys(attrs).map(function (k) {
                return '<code>' + escapeHtml(k) + ' = ' + escapeHtml(attrs[k]) + '</code>';
            }).join('');
            return (
                '<details class="wbs-variation-block" data-variation-draft-id="' + v.id + '">' +
                    '<summary>' +
                        escapeHtml(v.sku || ('Variation #' + v.wc_variation_id)) +
                        ' ' + chip('validation', v.validation_status) +
                        ' ' + chip('sync', v.sync_status) +
                    '</summary>' +
                    '<div class="wbs-variation-attrs">' + attrChips + '</div>' +
                    '<div class="wbs-edit-grid">' +
                        fieldInput('sku', 'SKU', v.sku) +
                        fieldInput('ean', 'EAN', v.ean) +
                        fieldInput('regular_price', 'Regular price', v.regular_price, 'number', '0.01') +
                        fieldInput('sale_price', 'Sale price', v.sale_price, 'number', '0.01') +
                        fieldInput('stock_quantity', 'Stock quantity', v.stock_quantity, 'number', '1') +
                        fieldSelect('stock_status', 'Stock status', v.stock_status, [
                            ['instock', 'In stock'],
                            ['outofstock', 'Out of stock'],
                            ['onbackorder', 'On backorder']
                        ]) +
                        '<label class="wbs-field-full">' + escapeHtml('Image URL') +
                            '<input type="text" name="image_url" value="' + escapeHtml(v.image_url || '') + '" />' +
                            '<button type="button" class="button wbs-pick-image" data-target="image_url" data-id-target="image_id">' + escapeHtml(t.chooseImage) + '</button>' +
                            '<input type="hidden" name="image_id" value="' + (v.image_id || 0) + '" />' +
                        '</label>' +
                        '<label class="wbs-field-full">' + escapeHtml('Description') +
                            '<textarea name="description">' + escapeHtml(plainText(v.description || '')) + '</textarea>' +
                        '</label>' +
                    '</div>' +
                    '<div class="wbs-edit-actions">' +
                        '<button type="button" class="button button-primary wbs-save-variation">' + escapeHtml('Save variation') + '</button>' +
                        (v.validation_errors && v.validation_errors.length ? '<span class="wbs-validation-messages error" style="margin:0">' + v.validation_errors.map(escapeHtml).join(', ') + '</span>' : '') +
                        (v.last_sync_error ? '<span class="wbs-validation-messages error" style="margin:0">' + escapeHtml(v.last_sync_error) + '</span>' : '') +
                    '</div>' +
                '</details>'
            );
        }).join('');
    }

    function fieldInput(name, label, value, type, step) {
        return '<label>' + escapeHtml(label) +
            '<input type="' + (type || 'text') + '"' + (step ? ' step="' + step + '"' : '') +
            ' name="' + name + '" value="' + escapeHtml(value == null ? '' : value) + '" /></label>';
    }
    function fieldSelect(name, label, value, options) {
        var opts = options.map(function (o) {
            return '<option value="' + o[0] + '"' + (o[0] === value ? ' selected' : '') + '>' + escapeHtml(o[1]) + '</option>';
        }).join('');
        return '<label>' + escapeHtml(label) + '<select name="' + name + '">' + opts + '</select></label>';
    }

    function normalizeText(v) {
        return String(v == null ? '' : v).replace(/\s+/g, ' ').trim();
    }

    function normalizeNumber(v) {
        if (v == null || v === '') { return ''; }
        var n = Number(v);
        return Number.isFinite(n) ? String(n) : normalizeText(v);
    }

    function normalizeStock(v) {
        if (v == null || v === '') { return ''; }
        var n = Number(v);
        return Number.isFinite(n) ? String(Math.max(0, parseInt(n, 10))) : normalizeText(v);
    }

    function extractBolOfferValue(offerSnapshot, field) {
        var item = offerSnapshot && Array.isArray(offerSnapshot.offers) ? offerSnapshot.offers[0] : null;
        if (!item || typeof item !== 'object') { return ''; }
        if (field === 'price') {
            return normalizeNumber(item.price || (item.pricing && item.pricing.bundlePrices && item.pricing.bundlePrices[0] && item.pricing.bundlePrices[0].unitPrice) || '');
        }
        if (field === 'stock') {
            return normalizeStock(item.stockAmount || (item.stock && item.stock.amount) || '');
        }
        if (field === 'title') {
            return normalizeText(item.title || item.unknownProductTitle || '');
        }
        return '';
    }

    function extractBolContentValue(contentSnapshot, field) {
        if (!contentSnapshot || typeof contentSnapshot !== 'object') { return ''; }
        if (field === 'title') {
            return normalizeText(contentSnapshot.title || contentSnapshot.name || '');
        }
        if (field === 'description') {
            return normalizeText(plainText(contentSnapshot.description || ''));
        }
        if (field === 'image') {
            var url = '';
            if (Array.isArray(contentSnapshot.images) && contentSnapshot.images[0]) {
                url = contentSnapshot.images[0].url || '';
            } else if (Array.isArray(contentSnapshot.assets) && contentSnapshot.assets[0]) {
                url = contentSnapshot.assets[0].url || '';
            }
            return normalizeText(url);
        }
        return '';
    }

    function diffRow(label, draftValue, bolValue) {
        var isChanged = normalizeText(draftValue) !== normalizeText(bolValue);
        return (
            '<tr class="' + (isChanged ? 'is-changed' : 'is-same') + '">' +
                '<th>' + escapeHtml(label) + '</th>' +
                '<td><code>' + escapeHtml(draftValue || '—') + '</code></td>' +
                '<td><code>' + escapeHtml(bolValue || '—') + '</code></td>' +
                '<td>' + (isChanged ? '<span class="wbs-diff-badge changed">changed</span>' : '<span class="wbs-diff-badge same">same</span>') + '</td>' +
            '</tr>'
        );
    }

    function renderVisualDiff(product, bolOfferSnapshot, bolContentSnapshot) {
        var draftTitle = normalizeText(product.name || '');
        var draftDescription = normalizeText(plainText(product.description || product.short_description || ''));
        var draftPrice = normalizeNumber(product.regular_price);
        var draftStock = normalizeStock(product.stock_quantity);
        var draftImage = normalizeText(product.main_image_url || '');

        var bolTitle = extractBolContentValue(bolContentSnapshot, 'title') || extractBolOfferValue(bolOfferSnapshot, 'title');
        var bolDescription = extractBolContentValue(bolContentSnapshot, 'description');
        var bolPrice = extractBolOfferValue(bolOfferSnapshot, 'price');
        var bolStock = extractBolOfferValue(bolOfferSnapshot, 'stock');
        var bolImage = extractBolContentValue(bolContentSnapshot, 'image');

        return (
            '<h3 class="wbs-section-title">Draft vs bol.com (quick diff)</h3>' +
            '<label class="wbs-diff-toggle"><input type="checkbox" id="wbs-diff-only-changed" ' + (state.diffOnlyChanged ? 'checked' : '') + ' /> Show only changed fields</label>' +
            '<table class="widefat striped wbs-diff-table">' +
                '<thead><tr><th>Field</th><th>Draft value</th><th>bol value</th><th>Status</th></tr></thead>' +
                '<tbody>' +
                    diffRow('Title', draftTitle, bolTitle) +
                    diffRow('Description', draftDescription, bolDescription) +
                    diffRow('Price', draftPrice, bolPrice) +
                    diffRow('Stock', draftStock, bolStock) +
                    diffRow('Main image URL', draftImage, bolImage) +
                '</tbody>' +
            '</table>'
        );
    }

    function renderEditor(data) {
        var p = data.product || {};
        var variations = data.variations || [];
        var bolOfferSnapshot = p.bol_offer_snapshot || {};
        var bolContentSnapshot = p.bol_content_snapshot || {};

        var html = (
            '<div class="wbs-edit-header">' +
                '<div>' +
                    '<h3>' + escapeHtml(p.name || ('#' + p.wc_product_id)) + '</h3>' +
                    '<div>' + chip('validation', p.validation_status) + ' ' + chip('review', p.review_status) + ' ' + chip('sync', p.sync_status) + '</div>' +
                    '<small>ID #' + p.wc_product_id + ' · ' + escapeHtml(p.product_type || '') + '</small>' +
                '</div>' +
                '<div class="wbs-edit-actions">' +
                    '<button type="button" class="button" id="wbs-btn-validate">' + escapeHtml('Re-validate') + '</button>' +
                    '<button type="button" class="button button-primary" id="wbs-btn-approve">' + escapeHtml('Approve') + '</button>' +
                    '<button type="button" class="button" id="wbs-btn-reject">' + escapeHtml('Reject') + '</button>' +
                '</div>' +
            '</div>' +
            renderValidationMessages(p) +
            (p.last_sync_error ? '<div class="wbs-validation-messages error"><strong>' + escapeHtml('Last sync error') + ':</strong> ' + escapeHtml(p.last_sync_error) + '</div>' : '') +
            '<form id="wbs-product-form" data-draft-id="' + p.id + '">' +
                '<div class="wbs-edit-grid">' +
                    '<label class="wbs-field-full">' + escapeHtml('Name') + '<input type="text" name="name" value="' + escapeHtml(p.name || '') + '" /></label>' +
                    fieldInput('sku', 'SKU', p.sku) +
                    fieldInput('ean', 'EAN', p.ean) +
                    fieldInput('regular_price', 'Regular price', p.regular_price, 'number', '0.01') +
                    fieldInput('sale_price', 'Sale price', p.sale_price, 'number', '0.01') +
                    fieldInput('stock_quantity', 'Stock quantity', p.stock_quantity, 'number', '1') +
                    fieldSelect('stock_status', 'Stock status', p.stock_status, [
                        ['instock', 'In stock'],
                        ['outofstock', 'Out of stock'],
                        ['onbackorder', 'On backorder']
                    ]) +
                    '<label><input type="checkbox" name="sync_price" value="1" ' + (Number(p.sync_price) === 1 ? 'checked' : '') + ' /> Sync price to bol.com</label>' +
                    '<label><input type="checkbox" name="sync_stock" value="1" ' + (Number(p.sync_stock) === 1 ? 'checked' : '') + ' /> Sync stock to bol.com</label>' +
                    '<label><input type="checkbox" name="sync_content" value="1" ' + (Number(p.sync_content) === 1 ? 'checked' : '') + ' /> Sync title/description to bol.com</label>' +
                    '<label><input type="checkbox" name="sync_images" value="1" ' + (Number(p.sync_images) === 1 ? 'checked' : '') + ' /> Sync images to bol.com</label>' +
                    '<label class="wbs-field-full">' + escapeHtml(t.mainImage) +
                        '<div class="wbs-main-image-preview">' +
                            (p.main_image_url ? '<img src="' + escapeHtml(p.main_image_url) + '" alt="" />' : '<span><em>no image</em></span>') +
                            '<div>' +
                                '<input type="text" name="main_image_url" value="' + escapeHtml(p.main_image_url || '') + '" style="min-width:260px;" /><br>' +
                                '<button type="button" class="button wbs-pick-image" data-target="main_image_url" data-id-target="main_image_id">' + escapeHtml(t.chooseImage) + '</button>' +
                                '<input type="hidden" name="main_image_id" value="' + (p.main_image_id || 0) + '" />' +
                            '</div>' +
                        '</div>' +
                    '</label>' +
                    '<label class="wbs-field-full">' + escapeHtml('Short description') + '<textarea name="short_description">' + escapeHtml(plainText(p.short_description || '')) + '</textarea></label>' +
                    '<label class="wbs-field-full">' + escapeHtml('Description') + '<textarea name="description">' + escapeHtml(plainText(p.description || '')) + '</textarea></label>' +
                    '<div class="wbs-field-full">' +
                        '<strong>' + escapeHtml(t.images) + '</strong>' +
                        renderGallery(p.gallery) +
                        '<button type="button" class="button wbs-add-gallery">' + escapeHtml(t.addGalleryImage) + '</button>' +
                        '<input type="hidden" name="gallery_json" value=\'' + escapeHtml(JSON.stringify(p.gallery || [])) + '\' />' +
                    '</div>' +
                '</div>' +
                '<div class="wbs-edit-actions">' +
                    '<button type="submit" class="button button-primary">' + escapeHtml('Save product draft') + '</button>' +
                '</div>' +
            '</form>' +
            renderVisualDiff(p, bolOfferSnapshot, bolContentSnapshot) +
            '<h3 class="wbs-section-title">Current bol.com snapshot</h3>' +
            '<div class="wbs-edit-grid">' +
                '<label class="wbs-field-full">Offer (read-only)<textarea readonly>' + escapeHtml(JSON.stringify(bolOfferSnapshot, null, 2)) + '</textarea></label>' +
                '<label class="wbs-field-full">Content (read-only)<textarea readonly>' + escapeHtml(JSON.stringify(bolContentSnapshot, null, 2)) + '</textarea></label>' +
            '</div>' +
            '<h3 class="wbs-section-title">' + escapeHtml(t.variations) + '</h3>' +
            '<div class="wbs-edit-actions wbs-variation-tools">' +
                '<button type="button" class="button" id="wbs-expand-all-variations">Expand all variations</button>' +
                '<button type="button" class="button" id="wbs-collapse-all-variations">Collapse all variations</button>' +
            '</div>' +
            '<div id="wbs-variations">' + renderVariations(variations) + '</div>'
        );
        $editPane.html(html);
        applyDiffFilter();
    }

    function applyDiffFilter() {
        var $rows = $editPane.find('.wbs-diff-table tbody tr');
        if (!state.diffOnlyChanged) {
            $rows.show();
            return;
        }
        $rows.each(function () {
            var $row = $(this);
            $row.toggle($row.hasClass('is-changed'));
        });
    }

    function openMediaPicker(onSelect) {
        if (typeof wp === 'undefined' || !wp.media) { alert(t.error); return; }
        var frame = wp.media({
            title: t.chooseImage,
            multiple: false,
            button: { text: t.useImage },
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            onSelect(attachment);
        });
        frame.open();
    }

    function serializeForm($form) {
        var out = {};
        $form.find('input, select, textarea').each(function () {
            var $el = $(this);
            var name = $el.attr('name');
            if (!name) { return; }
            if ($el.is(':checkbox')) {
                out[name] = $el.is(':checked') ? 1 : 0;
            } else {
                out[name] = $el.val();
            }
        });
        return out;
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    $('#wbs-staging-ingest').on('click', function () {
        if (!confirm(t.ingesting + '?')) { return; }
        setStatus(t.ingesting);
        ajax('wbs_staging_ingest', {}).done(function (data) {
            setStatus(data.message || t.saved);
            loadList();
        }).fail(function (msg) {
            setStatus(msg || t.error, true);
        });
    });

    $('#wbs-staging-sync-approved').on('click', function () {
        if (!confirm(t.confirmSync)) { return; }
        setStatus(t.syncing);
        ajax('wbs_staging_sync', {}).done(function (data) {
            setStatus(data.message || t.saved);
            loadList();
            if (state.currentDraftId) { selectDraft(state.currentDraftId); }
        }).fail(function (msg) {
            setStatus(msg || t.error, true);
        });
    });

    $('#wbs-staging-sync-selected').on('click', function () {
        var selected = selectedIdsPayloadFromCheckedRows();
        if (!selected.draftIds.length && !selected.variationDraftIds.length) {
            setStatus(t.noSelectedDrafts || t.error, true);
            return;
        }
        if (!confirm(t.confirmSyncSelected || t.confirmSync)) { return; }
        setStatus(t.syncing);
        ajax('wbs_staging_sync', {
            draft_ids: selected.draftIds.join(','),
            variation_draft_ids: selected.variationDraftIds.join(',')
        }).done(function (data) {
            setStatus(data.message || t.saved);
            loadList();
            if (state.currentDraftId) { selectDraft(state.currentDraftId); }
        }).fail(function (msg) {
            setStatus(msg || t.error, true);
        });
    });

    $('#wbs-staging-refresh').on('click', loadList);

    $('#wbs-staging-search').on('input', debounce(function () {
        state.filters.search = $(this).val();
        loadList();
    }, 300));
    $('#wbs-staging-filter-validation').on('change', function () { state.filters.validation_status = $(this).val(); loadList(); });
    $('#wbs-staging-filter-review').on('change',     function () { state.filters.review_status     = $(this).val(); loadList(); });
    $('#wbs-staging-filter-sync').on('change',       function () { state.filters.sync_status       = $(this).val(); loadList(); });
    $('#wbs-staging-include-variations').on('change', function () {
        state.filters.include_variations = $(this).is(':checked') ? 1 : 0;
        loadList();
    });
    $('#wbs-staging-compact-mode').on('change', function () {
        state.compactMode = $(this).is(':checked');
        saveUiPreference();
        applyCompactMode();
    });
    $('.wbs-staging-tabs').on('click', '.wbs-tab', function () {
        var tab = $(this).data('tab');
        state.quickTab = tab;
        $('.wbs-staging-tabs .wbs-tab').removeClass('is-active');
        $(this).addClass('is-active');

        if (tab === 'all') {
            state.filters.validation_status = '';
            state.filters.review_status = '';
            state.filters.sync_status = '';
        } else if (tab === 'ready') {
            state.filters.validation_status = 'ready';
            state.filters.review_status = 'pending';
            state.filters.sync_status = 'not_synced';
        } else if (tab === 'needs_review') {
            state.filters.validation_status = 'warning';
            state.filters.review_status = 'pending';
            state.filters.sync_status = '';
        } else if (tab === 'approved') {
            state.filters.validation_status = '';
            state.filters.review_status = 'approved';
            state.filters.sync_status = 'not_synced';
        } else if (tab === 'variation_missing_images') {
            state.filters.validation_status = '';
            state.filters.review_status = '';
            state.filters.sync_status = '';
            state.filters.include_variations = 1;
            $('#wbs-staging-include-variations').prop('checked', true);
        }

        $('#wbs-staging-filter-validation').val(state.filters.validation_status);
        $('#wbs-staging-filter-review').val(state.filters.review_status);
        $('#wbs-staging-filter-sync').val(state.filters.sync_status);
        loadList();
    });

    $(document).on('click', '#wbs-staging-rows tr[data-draft-id]', function (e) {
        if ($(e.target).closest('input.wbs-row-select').length) {
            return;
        }
        var rawDraftId = $(this).attr('data-draft-id');
        var draftId = Number(rawDraftId);
        if (!Number.isFinite(draftId) || draftId <= 0) {
            setStatus(t.error || 'Invalid draft ID.', true);
            return;
        }
        selectDraft(draftId);
    });
    $(document).on('change', '#wbs-staging-select-all', function () {
        var checked = $(this).is(':checked');
        $tableRows.find('input.wbs-row-select').each(function () {
            var selectKey = String($(this).attr('data-select-key') || '');
            if (!selectKey) { return; }
            $(this).prop('checked', checked);
            if (checked) {
                state.selectedItemKeys[selectKey] = 1;
            } else {
                delete state.selectedItemKeys[selectKey];
            }
        });
        refreshSelectionUi();
    });
    $(document).on('change', 'input.wbs-row-select', function () {
        var selectKey = String($(this).attr('data-select-key') || '');
        if (!selectKey) { return; }
        if ($(this).is(':checked')) {
            state.selectedItemKeys[selectKey] = 1;
        } else {
            delete state.selectedItemKeys[selectKey];
        }
        refreshSelectionUi();
    });
    $(document).on('click', '#wbs-staging-close-modal', function () {
        closeEditModal();
    });
    $(document).on('click', '#wbs-staging-modal', function (e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    $editPane.on('submit', '#wbs-product-form', function (e) {
        e.preventDefault();
        var $form  = $(this);
        var id     = $form.data('draft-id');
        var fields = serializeForm($form);
        setStatus(t.saving);
        ajax('wbs_staging_save', { draft_id: id, fields: fields }).done(function (data) {
            setStatus(t.saved);
            selectDraft(id);
            loadList();
        }).fail(function (msg) { setStatus(msg || t.error, true); });
    });

    $editPane.on('click', '#wbs-btn-validate', function () {
        if (!state.currentDraftId) { return; }
        setStatus(t.validating);
        ajax('wbs_staging_validate', { draft_id: state.currentDraftId }).done(function () {
            selectDraft(state.currentDraftId);
            loadList();
            setStatus(t.saved);
        }).fail(function (msg) { setStatus(msg || t.error, true); });
    });

    $editPane.on('click', '#wbs-btn-approve', function () {
        if (!state.currentDraftId) { return; }
        if (!confirm(t.confirmApprove)) { return; }
        setStatus(t.approving);
        ajax('wbs_staging_approve', { draft_id: state.currentDraftId }).done(function () {
            selectDraft(state.currentDraftId);
            loadList();
            setStatus(t.saved);
        }).fail(function (msg) { setStatus(msg || t.error, true); });
    });

    $editPane.on('click', '#wbs-btn-reject', function () {
        if (!state.currentDraftId) { return; }
        setStatus(t.saving);
        ajax('wbs_staging_reject', { draft_id: state.currentDraftId }).done(function () {
            selectDraft(state.currentDraftId);
            loadList();
            setStatus(t.saved);
        }).fail(function (msg) { setStatus(msg || t.error, true); });
    });

    $editPane.on('click', '.wbs-save-variation', function () {
        var $block = $(this).closest('.wbs-variation-block');
        var id     = $block.data('variation-draft-id');
        var fields = {};
        $block.find('input, select, textarea').each(function () {
            var name = $(this).attr('name');
            if (!name) { return; }
            fields[name] = $(this).val();
        });
        var attrs = {};
        $block.find('.wbs-variation-attrs code').each(function () {
            var txt = $(this).text();
            var idx = txt.indexOf('=');
            if (idx > 0) {
                attrs[$.trim(txt.substring(0, idx))] = $.trim(txt.substring(idx + 1));
            }
        });
        fields.attributes_json = JSON.stringify(attrs);
        setStatus(t.saving);
        ajax('wbs_staging_save_variation', { variation_draft_id: id, fields: fields }).done(function () {
            setStatus(t.saved);
            selectDraft(state.currentDraftId);
            loadList();
        }).fail(function (msg) { setStatus(msg || t.error, true); });
    });
    $editPane.on('click', '#wbs-expand-all-variations', function () {
        $editPane.find('.wbs-variation-block').prop('open', true);
    });
    $editPane.on('click', '#wbs-collapse-all-variations', function () {
        $editPane.find('.wbs-variation-block').prop('open', false);
    });

    $editPane.on('click', '.wbs-pick-image', function () {
        var $btn = $(this);
        var urlTarget = $btn.data('target');
        var idTarget  = $btn.data('id-target');
        var $container = $btn.closest('label, details, form');
        openMediaPicker(function (attachment) {
            $container.find('input[name="' + urlTarget + '"]').val(attachment.url);
            if (idTarget) {
                $container.find('input[name="' + idTarget + '"]').val(attachment.id);
            }
        });
    });

    $editPane.on('click', '.wbs-add-gallery', function () {
        var $hidden = $editPane.find('input[name="gallery_json"]');
        var gallery = [];
        try { gallery = JSON.parse($hidden.val() || '[]'); } catch (e) { gallery = []; }
        openMediaPicker(function (attachment) {
            gallery.push({ id: attachment.id, url: attachment.url, alt: attachment.alt || '' });
            $hidden.val(JSON.stringify(gallery));
            $editPane.find('.wbs-gallery-preview').replaceWith(renderGallery(gallery));
        });
    });

    $editPane.on('click', '.wbs-remove-gallery', function () {
        var idx = parseInt($(this).closest('.wbs-gallery-item').data('index'), 10);
        var $hidden = $editPane.find('input[name="gallery_json"]');
        var gallery = [];
        try { gallery = JSON.parse($hidden.val() || '[]'); } catch (e) { gallery = []; }
        if (!isNaN(idx)) {
            gallery.splice(idx, 1);
        }
        $hidden.val(JSON.stringify(gallery));
        $editPane.find('.wbs-gallery-preview').replaceWith(renderGallery(gallery));
    });

    $editPane.on('change', '#wbs-diff-only-changed', function () {
        state.diffOnlyChanged = $(this).is(':checked');
        saveDiffPreference();
        applyDiffFilter();
    });

    function debounce(fn, wait) {
        var timer;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    $(function () {
        normalizeEditPaneElement();
        ensureModalContainer();
        loadDiffPreference();
        loadUiPreference();
        applyCompactMode();
        $('#wbs-staging-include-variations').prop('checked', true);
        loadList();
    });

})(jQuery);
