<?php
if (!defined('ABSPATH')) exit;

/**
 * Step 1: Status Integration (Dokan Dashboard)
 * Ensures 'private' products are fetched and labeled as 'Inactive' in the top bar.
 */
add_filter('dokan_product_listing_post_statuses', 'zh_add_private_to_dokan_query');
add_filter('dokan_get_product_status_labels', 'zh_label_private_as_inactive');

// These two filters are for the row labeling - keeping them off for 1 more step to be safe
/*
add_filter('dokan_get_post_status', 'zh_label_private_as_inactive_row', 10, 2);
add_filter('dokan_get_post_status_label_class', 'zh_class_private_as_inactive', 10, 1);
*/

function zh_add_private_to_dokan_query($statuses) {
    if (!is_array($statuses)) return $statuses;
    if (!in_array('private', $statuses)) {
        $statuses[] = 'private';
    }
    return $statuses;
}

function zh_label_private_as_inactive($labels) {
    if (!is_array($labels)) return $labels;
    $labels['private'] = __('Inactive', 'zerohold');
    return $labels;
}

function zh_label_private_as_inactive_row($label, $status) {
    if ($status === 'private') {
        return __('Inactive', 'zerohold');
    }
    return $label;
}

function zh_class_private_as_inactive($class) {
    if (!is_string($class)) {
        return 'dokan-label-default';
    }
    if (strpos($class, 'private') !== false) {
        return 'dokan-label-default'; // Neutral grey
    }
    return $class;
}

/**
 * Step 1.2: Prevent Trash/Delete (DEACTIVATED for now to ensure stability)
 */
/*
add_action('wp_trash_post', 'zh_prevent_vendor_trash', 10);
add_action('before_delete_post', 'zh_prevent_vendor_delete', 10);
...
*/
