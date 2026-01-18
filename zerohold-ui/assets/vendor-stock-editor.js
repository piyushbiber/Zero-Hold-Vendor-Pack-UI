jQuery(function ($) {

    /**
     * Entry Point: Click "Edit Stock" Link
     */
    $(document).on('click', '.zh-edit-stock-btn', function (e) {
        e.preventDefault();
        const pid = $(this).data('product-id');
        const $link = $(this);

        $link.text('LOADING...');

        $.post(ZHVStock.ajax, {
            action: 'zh_vendor_fetch_stock',
            pid: pid,
            nonce: ZHVStock.nonce
        }, function (res) {
            $link.text('EDIT STOCK');
            if (res.success) {
                renderModal(res.data);
            } else {
                toast(res.data.msg || 'Error loading data', 'error');
            }
        });
    });

    /**
     * Render the v1.0 Modal
     */
    function renderModal(data) {
        const s = data.summary;
        const modalHtml = `
            <div class="zh-modal">
                <div class="zh-modal-box">
                    <div class="zh-modal-header">
                        <h3>Edit Stock: ${data.title}</h3>
                        <span class="zh-close-x">&times;</span>
                    </div>
                    
                    <div class="zh-product-summary">
                        <div class="zh-summary-item"><span>Wear:</span> ${s.wear_type || 'N/A'}</div>
                        <div class="zh-summary-item"><span>Fabric:</span> ${s.fabric || 'N/A'}</div>
                        <div class="zh-summary-item"><span>Pattern:</span> ${s.pattern || 'N/A'}</div>
                        <div class="zh-summary-item"><span>Gender:</span> ${s.gender || 'N/A'}</div>
                        <div class="zh-summary-item"><span>Color:</span> ${s.color || 'N/A'}</div>
                        <div class="zh-summary-item"><span>Pack Type:</span> ${s.pack_type || 'N/A'}</div>
                    </div>

                    <div class="zh-stock-table-header">
                        <span>Size</span>
                        <span>Stock (Boxes)</span>
                    </div>

                    <div class="zh-vars-list" data-pid="${data.pid}">
                        ${data.vars.map(v => `
                            <div class="zh-stock-row">
                                <label>${v.label.toLowerCase()}</label>
                                <input type="number" data-vid="${v.vid}" value="${v.stock}" min="0">
                            </div>
                        `).join('')}
                    </div>

                    <div class="zh-modal-footer">
                        <button class="zh-cancel-btn">Cancel</button>
                        <button class="zh-save-btn">Save Updates</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
    }

    /**
     * Save Updates
     */
    $(document).on('click', '.zh-save-btn', function () {
        const $modal = $(this).closest('.zh-modal');
        const pid = $modal.find('.zh-vars-list').data('pid');
        const updates = {};

        $modal.find('.zh-vars-list input').each(function () {
            updates[$(this).data('vid')] = $(this).val();
        });

        const $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');

        $.post(ZHVStock.ajax, {
            action: 'zh_vendor_save_stock',
            updates: updates,
            pid: pid,
            nonce: ZHVStock.nonce
        }, function (res) {
            if (!res.success) {
                toast(res.data.msg, 'error');
                $btn.prop('disabled', false).text('Save Updates');
                return;
            }

            // Success Flow:
            $('.zh-modal').remove();
            updateDashboardRow(pid, updates);
            toast('Stock updated');
        });
    });

    /**
     * Update Dashboard Row (Real-time, No Reload)
     */
    function updateDashboardRow(pid, updates) {
        let total = 0;
        Object.values(updates).forEach(v => {
            total += parseInt(v || 0);
        });

        // Find the row containing our button
        const $btn = $(`.zh-edit-stock-btn[data-product-id="${pid}"]`);
        const $row = $btn.closest('tr');

        if ($row.length) {
            // 1. Update Stock Column
            const $stockCell = $row.find('td[data-title="Stock"], td.column-stock');
            if (total > 0) {
                $stockCell.html(`<mark class="instock">In stock &times; ${total}</mark>`);
                // Add the button back below since we cleared the HTML
                $stockCell.append(`<div style="margin-top: 7px;"><a href="javascript:void(0);" class="zh-edit-stock-btn" data-product-id="${pid}" style="background: #f3f3f3; color: #333; border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; border-radius: 3px; text-decoration: none; font-weight: 600; text-transform: uppercase;">Edit Stock</a></div>`);
            } else {
                $stockCell.html(`<mark class="outofstock">Out of stock</mark>`);
                $stockCell.append(`<div style="margin-top: 7px;"><a href="javascript:void(0);" class="zh-edit-stock-btn" data-product-id="${pid}" style="background: #f3f3f3; color: #333; border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; border-radius: 3px; text-decoration: none; font-weight: 600; text-transform: uppercase;">Edit Stock</a></div>`);
            }

            // 2. Update Status Column
            const $statusCell = $row.find('td.post-status, td[data-title="Status"]');
            if ($statusCell.length) {
                $statusCell.find('label, span').first().attr('class', 'dokan-label dokan-label-success').text('Online');
                if (total === 0) {
                    $statusCell.find('label, span').first().attr('class', 'dokan-label dokan-label-danger').text('Out of Stock');
                }
            }
        }
    }

    /**
     * Toast System
     */
    function toast(msg, type = 'success') {
        const bg = (type === 'error') ? '#fef2f2' : '#f0fdf4';
        const border = (type === 'error') ? '#fecaca' : '#bbf7d0';
        const text = (type === 'error') ? '#991b1b' : '#166534';

        const $box = $(`
            <div style="position:fixed; bottom:30px; right:30px; background:${bg}; border:1px solid ${border}; color:${text}; padding:12px 24px; border-radius:8px; z-index:99999; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); font-weight:500; font-family:sans-serif;">
                ${msg}
            </div>
        `);
        $('body').append($box);
        setTimeout(() => $box.fadeOut(300, () => $box.remove()), 3000);
    }

    /**
     * Close Modal
     */
    $(document).on('click', '.zh-close-x, .zh-cancel-btn', function () {
        $(this).closest('.zh-modal').remove();
    });

});
