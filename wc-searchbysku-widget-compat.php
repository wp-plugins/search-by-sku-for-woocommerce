<?php

/**
 * A drop in replacement of WC_Admin_Post_Types::product_search()
 */
add_filter('posts_search', 'product_search_sku',9);
function product_search_sku($where) {
    global $pagenow, $wpdb, $wp;
    //VAR_DUMP(http_build_query(array('post_type' => array('product','boobs'))));die();
    $type = array('product', 'jam');
    
    
    //var_dump(in_array('product', $wp->query_vars['post_type']));
    if ((is_admin() && 'edit.php' != $pagenow) 
            || !is_search()  
            || !isset($wp->query_vars['s']) 
            //post_types can also be arrays..
            || (isset($wp->query_vars['post_type']) && 'product' != $wp->query_vars['post_type'])
            || (isset($wp->query_vars['post_type']) && is_array($wp->query_vars['post_type']) && !in_array('product', $wp->query_vars['post_type']) ) 
            ) {
        return $where;
    }
    $search_ids = array();
    $terms = explode(',', $wp->query_vars['s']);

    foreach ($terms as $term) {
        //Include the search by id if admin area.
        if (is_admin() && is_numeric($term)) {
            $search_ids[] = $term;
        }
        // search for variations with a matching sku and return the parent.

        $sku_to_parent_id = $wpdb->get_col($wpdb->prepare("SELECT p.post_parent as post_id FROM {$wpdb->posts} as p join {$wpdb->postmeta} pm on p.ID = pm.post_id and pm.meta_key='_sku' and pm.meta_value LIKE '%%%s%%' where p.post_parent <> 0 group by p.post_parent", wc_clean($term)));

        //Search for a regular product that matches the sku.
        $sku_to_id = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE '%%%s%%';", wc_clean($term)));

        $search_ids = array_merge($search_ids, $sku_to_id, $sku_to_parent_id);
    }

    $search_ids = array_filter(array_map('absint', $search_ids));

    if (sizeof($search_ids) > 0) {
        $where = str_replace(')))', ") OR ({$wpdb->posts}.ID IN (" . implode(',', $search_ids) . "))))", $where);
    }
    
    remove_filters_for_anonymous_class('posts_search', 'WC_Admin_Post_Types', 'product_search', 10);
    return $where;
}