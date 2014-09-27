<?php

/*
  Plugin Name: Search By SKU - for Woocommerce
  Plugin URI: http://www.mattyl.co.uk/2012/12/11/woocommerce-plugin-to-search-products-by-sku/
  Description: The search functionality in woocommerce doesn't search by sku by default. This simple plugin adds this functionality to both the admin site and regular search
  Author: Matthew Lawson
  Version: 0.5.2
  Author URI: http://www.mattyl.co.uk/
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Needs to be after woocommerce has initiated but before posts_search filter has run..
add_filter('init', 'searchbysku_init', 11);
                      
function searchbysku_init() {
    include_once 'wp-filters-extra.php';
    //Added in 0.5.2 a different search for the admin site all together. 
    //Could make a pull on woocommerce repo to add this to core.
    if (is_admin()) {
        add_filter('posts_search', 'admin_product_search',9);
    } else {
        //TODO: Port this to use the same admin_product_search filter 
        // - Hopefully this will sort layered nav issues.
        add_filter('the_posts', 'variation_query');
    }
}

/**
 * A replacement of WC_Admin_Post_Types::product_search()
 */
function admin_product_search($where) {
    global $pagenow, $wpdb, $wp;
    if ('edit.php' != $pagenow || !is_search() || !isset($wp->query_vars['s']) || 'product' != $wp->query_vars['post_type']) {
        return $where;
    }

    $search_ids = array();
    $terms = explode(',', $wp->query_vars['s']);

    foreach ($terms as $term) {
        if (is_numeric($term)) {
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

function variation_query($posts, $query = false) {
    if (is_search()) {
        $ignoreIds = array(0);
        foreach ($posts as $post) {
            $ignoreIds[] = $post->ID;
        }

        $matchedSku = get_parent_post_by_sku(get_search_query(), $ignoreIds);

        if ($matchedSku) {
            foreach ($matchedSku as $product_id) {
                $posts[] = get_post($product_id->post_id);
            }
        }
        return $posts;
    }

    return $posts;
}

function get_parent_post_by_sku($sku, $ignoreIds) {
    //Check for 
    global $wpdb, $wp_query;

    //Should the query do some extra joins for WPML Enabled sites...
    $wmplEnabled = false;

    if (defined('WPML_TM_VERSION') && defined('WPML_ST_VERSION') && class_exists("woocommerce_wpml")) {
        $wmplEnabled = true;
        //What language should we search for...
        $languageCode = ICL_LANGUAGE_CODE;
    }

    $results = array();
    //Search for the sku of a variation and return the parent.
    $ignoreIdsForMySql = implode(",", $ignoreIds);
    $variationsSql = "
          SELECT p.post_parent as post_id FROM $wpdb->posts as p
          join $wpdb->postmeta pm
          on p.ID = pm.post_id
          and pm.meta_key='_sku'
          and pm.meta_value LIKE '%$sku%'
          join $wpdb->postmeta visibility
          on p.post_parent = visibility.post_id    
          and visibility.meta_key = '_visibility'
          and visibility.meta_value <> 'hidden'
            ";


    //IF WPML Plugin is enabled join and get correct language product.
    if ($wmplEnabled) {
        $variationsSql .=
                "join " . $wpdb->prefix . "icl_translations t on
         t.element_id = p.post_parent
         and t.element_type = 'post_product'
         and t.language_code = '$languageCode'";
        ;
    }

    $variationsSql .= "
          where 1
          AND p.post_parent <> 0
          and p.ID not in ($ignoreIdsForMySql)
          and p.post_status = 'publish'
          group by p.post_parent
          ";

    $variations = $wpdb->get_results($variationsSql);

    foreach ($variations as $post) {
        $ignoreIds[] = $post->post_id;
    }
    //If not variation try a regular product sku
    //Add the ids we just found to the ignore list...
    $ignoreIdsForMySql = implode(",", $ignoreIds);

    $regularProductsSql = "SELECT p.ID as post_id FROM $wpdb->posts as p
        join $wpdb->postmeta pm
        on p.ID = pm.post_id
        and  pm.meta_key='_sku' 
        AND pm.meta_value LIKE '%$sku%' 
        join $wpdb->postmeta visibility
        on p.ID = visibility.post_id    
        and visibility.meta_key = '_visibility'
        and visibility.meta_value <> 'hidden'";
    //IF WPML Plugin is enabled join and get correct language product.
    if ($wmplEnabled) {
        $regularProductsSql .=
                "join " . $wpdb->prefix . "icl_translations t on
         t.element_id = p.ID
         and t.element_type = 'post_product'
         and t.language_code = '$languageCode'";
    }
    $regularProductsSql .=
            "where 1
        and (p.post_parent = 0 or p.post_parent is null)
        and p.ID not in ($ignoreIdsForMySql)
        and p.post_status = 'publish'
        group by p.ID";

    $regular_products = $wpdb->get_results($regularProductsSql);

    $results = array_merge($variations, $regular_products);

    $wp_query->found_posts += sizeof($results);

    return $results;
}

?>
