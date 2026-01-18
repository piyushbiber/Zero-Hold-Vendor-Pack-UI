jQuery(function ($) {

    /**
     * Entry Point: Click "See All SKU" Link
     */
    $(document).on('click', '.zh-see-sku-btn', function (e) {
        e.preventDefault();
        const pid = $(this).data('product-id');
        const $link = $(this);

        const originalText = $link.text();
        $link.text('LOADING...');

        $.post(ZHSkuViewer.ajax, {
            action: 'zh_get_variation_skus',
            pid: pid,
            nonce: ZHSkuViewer.nonce
        }, function (res) {
            $link.text(originalText);
            if (res.success) {
                renderSkuModal(res.data);
            } else {
                alert(res.data.msg || 'Error loading SKUs');
            }
        });
    });

    /**
     * Render the SKU Modal
     */
    function renderSkuModal(data) {
        const s = data.summary;
        const modalHtml = `
            <div class="zh-modal">
                <div class="zh-modal-box">
                    <div class="zh-modal-header">
                        <h3>Variation SKUs: ${data.title}</h3>
                        <span class="zh-close-sku-x" style="cursor:pointer; font-size:24px; color:#999;">&times;</span>
                    </div>
                    
                    <div class="zh-product-summary" style="background: #f9fafb; padding: 15px 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; border-bottom: 1px solid #eee;">
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Wear:</span> ${s.wear_type || 'N/A'}</div>
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Fabric:</span> ${s.fabric || 'N/A'}</div>
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Pattern:</span> ${s.pattern || 'N/A'}</div>
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Gender:</span> ${s.gender || 'N/A'}</div>
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Color:</span> ${s.color || 'N/A'}</div>
                        <div class="zh-summary-item" style="font-size: 12px; color: #4b5563;"><span style="font-weight: 600; color: #111; width: 70px; display: inline-block;">Pack Type:</span> ${s.pack_type || 'N/A'}</div>
                    </div>

                    <div class="zh-sku-table-header" style="display: flex; gap: 15px; padding: 10px 20px; background: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #f3f4f6;">
                        <span style="width: 80px;">Size</span>
                        <span>SKU Code</span>
                    </div>

                    <div class="zh-sku-list" style="padding: 10px 20px; max-height: 300px; overflow-y: auto;">
                        ${data.skus.map(s => `
                            <div class="zh-sku-row" style="display: flex; justify-content: flex-start; align-items: center; margin: 12px 0; gap: 15px;">
                                <label style="font-size: 14px; color: #374151; font-weight: 600; width: 80px; flex-shrink: 0;">${s.label.toUpperCase()}</label>
                                <span style="font-size: 14px; color: #d63384; font-weight: 700; background: #fff1f8; padding: 4px 10px; border: 1px solid #fbcfe8; border-radius: 4px; font-family: monospace;">${s.sku}</span>
                            </div>
                        `).join('')}
                    </div>

                    <div class="zh-modal-footer" style="padding: 15px 20px; background: #f9fafb; border-top: 1px solid #eee; display: flex; justify-content: flex-end;">
                        <button class="zh-close-sku-btn" style="padding: 8px 24px; background: #fff; border: 1px solid #d1d5db; color: #374151; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">Close</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
    }

    /**
     * Close Modal
     */
    $(document).on('click', '.zh-close-sku-x, .zh-close-sku-btn', function () {
        $(this).closest('.zh-modal').remove();
    });

});
