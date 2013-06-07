<?php

/*
  Plugin Name: Search By SKU - for Woocommerce
  Plugin URI: http://www.mattyl.co.uk/2012/12/11/woocommerce-plugin-to-search-products-by-sku/
  Description: The search functionality in woocommerce doesn't search by sku by default. This simple plugin adds this functionality to both the admin site and regular search
  Author: Matthew Lawson
  Version: 0.4
  Author URI: http://www.mattyl.co.uk/
 */


add_filter('the_posts', 'variation_query');

function variation_query($posts, $query = false) {
//var_dump($posts);die();    
    if (is_search()) 
    {
        $ignoreIds = array(0);
        foreach($posts as $post)
        {
            $ignoreIds[] = $post->ID;
        }
        
        //get_search_query does sanitization
        $matchedSku = get_parent_post_by_sku(get_search_query(), $ignoreIds);
        
        if ($matchedSku) 
        {
            foreach($matchedSku as $product_id)
            {
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
    
    $results = array();
    //Search for the sku of a variation and return the parent.
    $ignoreIdsForMySql = implode(",", $ignoreIds);
    $variations = $wpdb->get_results(
                    "
          SELECT p.post_parent as post_id FROM $wpdb->posts as p
          join $wpdb->postmeta pm
          on p.ID = pm.post_id
          and pm.meta_key='_sku'
          and pm.meta_value LIKE '%$sku%'
          join $wpdb->postmeta visibility
          on p.post_parent = visibility.post_id    
          and visibility.meta_key = '_visibility'
          and visibility.meta_value <> 'hidden'
          where 1
          AND p.post_parent <> 0
          and p.ID not in ($ignoreIdsForMySql)
          and p.post_status = 'publish'
          group by p.post_parent
          "
    );

    //var_dump($variations);die();
    foreach($variations as $post)
    {
        //var_dump($var);
        $ignoreIds[] = $post->post_id;
    }
    //If not variation try a regular product sku
    //Add the ids we just found to the ignore list...
    $ignoreIdsForMySql = implode(",", $ignoreIds);
    //var_dump($ignoreIds,$ignoreIdsForMySql);die();
    $regular_products = $wpdb->get_results(
        "SELECT p.ID as post_id FROM $wpdb->posts as p
        join $wpdb->postmeta pm
        on p.ID = pm.post_id
        and  pm.meta_key='_sku' 
        AND pm.meta_value LIKE '%$sku%' 
        join $wpdb->postmeta visibility
        on p.ID = visibility.post_id    
        and visibility.meta_key = '_visibility'
        and visibility.meta_value <> 'hidden'
        where 1
        and (p.post_parent = 0 or p.post_parent is null)
        and p.ID not in ($ignoreIdsForMySql)
        and p.post_status = 'publish'
        group by p.ID

");
    
    $results = array_merge($variations, $regular_products);
    #var_dump($variations,$regular_products);
    //var_dump($results);
    $wp_query->found_posts += sizeof($results);
    
    return $results;
}

?>
