<?php
/**
 * Plugin Name: Hide Categories and Products for Woocommerce
 * Description: Plugin to hide categories and hide products from categories
 * Author: N.O.U.S. Open Useful and Simple
 * Version: 1.2.9
 * Author URI: https://apps.avecnous.eu/?mtm_campaign=wp-plugin&mtm_kwd=hide-categories-products-wc&mtm_medium=dashboard&mtm_source=author  
 * License: GPLv2
 * Text Domain: hide-categories-products-woocommerce
 * Domain Path: /languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 7.1
 */
global $Hide_Categories_Products_WC;
$Hide_Categories_Products_WC = new Hide_Categories_Products_WC();
function Hide_Categories_Products_WC(){
    global $Hide_Categories_Products_WC;
    return $Hide_Categories_Products_WC;
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

class Hide_Categories_Products_WC{

    public $dirname;
    public $baseurl;

    function __construct(){
        add_action( 'plugins_loaded', array($this, 'init') );
    }

    function init(){
        if(!function_exists('WC')){
            return;
        }
        $this->dirname = dirname(__FILE__);
        $this->baseurl = plugins_url('/', __FILE__);

        load_plugin_textdomain('hide-categories-products-woocommerce', false, 'hide-categories-products-woocommerce/languages');

        $this->register_hooks();
    }

    function register_hooks(){
        // hide products
        add_action( 'woocommerce_product_query', array($this, 'custom_pre_get_posts_query') );

        // hide categories
        add_filter( 'get_terms_args', array($this, 'term_filter') );
        add_filter( 'get_the_terms', array(&$this, 'hide_hidden_categories_single_product'), 11,3);
        add_filter('posts_search', array($this, 'posts_search'), 500, 2);


        // product add on compatibility
        add_filter( 'get_product_addons_product_terms', array($this, 'ignore_hidden_terms'), 11 , 2 );

        // storefront compatibility
        add_filter( 'storefront_featured_products_shortcode_args', array($this, 'storefront_shortcode_filter') );
        add_filter( 'storefront_popular_products_shortcode_args', array($this, 'storefront_shortcode_filter') );
        add_filter( 'storefront_recent_products_shortcode_args', array($this, 'storefront_shortcode_filter') );
        add_filter( 'storefront_best_selling_products_shortcode_args', array($this, 'storefront_shortcode_filter') );
        add_filter( 'storefront_on_sale_products_shortcode_args', array($this, 'storefront_shortcode_filter') );

        // Add settings
        add_filter('woocommerce_get_sections_products',  array( $this, 'woocommerce_get_sections_products' ), 10, 1 );
        add_filter('woocommerce_get_settings_products',  array( $this, 'woocommerce_get_settings_products' ), 10, 2 );

        // customize settings
        add_action( 'woocommerce_before_settings_products' ,  array( $this, 'woocommerce_before_settings_products' ));
        add_action( 'woocommerce_after_settings_products' ,  array( $this, 'woocommerce_after_settings_products' ));
    }

    /**
    * Get the product cats that hide products
    * @param  boolean  $ids if true, function will return ids, false, return terms object
    * @return array    array of terms or term ids
    *
    */
    function get_exluded_cats($ids=false){
        remove_filter( 'get_terms_args', array($this, 'term_filter') );
        $categories_setting = get_option('wchc_hide_products_from_cat');
        $cats = array();
        if($categories_setting){
            foreach($categories_setting as $cat=>$value){
                if($value == 'yes' || $value == '1'){
                    if(false != $term = get_term_by('slug', $cat, 'product_cat') ){
                        $cats[] = $term->term_id;
                    } else {
                        error_log( 'hide-categories-and-products-for-woocommerce: term not found: '.$cat );
                    }
                }
            }
        }
        add_filter( 'get_terms_args', array($this, 'term_filter') );
        return $cats;
    }


    /**
    * Ignore the hidden categories for product add on display
    * @return array array of term
    */
    function ignore_hidden_terms($terms,$post_id){
        remove_filter( 'get_terms_args', array($this, 'term_filter') );
        remove_filter( 'get_the_terms', array(&$this, 'hide_hidden_categories_single_product'), 11,3);
        $terms = wc_get_object_terms( $post_id, 'product_cat', 'term_id' );
        add_filter( 'get_terms_args', array($this, 'term_filter') );
        add_filter( 'get_the_terms', array(&$this, 'hide_hidden_categories_single_product'), 11,3);
        return $terms;
    }


    /**
    * Get the product cats that are hidden
    * @return array array of term ids
    */
    function get_hidden_cats(){
        $categories_setting = get_option('wchc_hide_product_cats');
        $cats = array();
        if(is_array($categories_setting)){
            foreach($categories_setting as $term_id=>$value){
                if($value == 'yes'){
                    $cats[] = $term_id;
                }
            }
        }
        return $cats;
    }

    /**
    * Exclude hidden products on storefront shortcode
    * @param  array $params shortcode parameters
    * @return array         shortcode parameters
    */
    function storefront_shortcode_filter($params){
        $params['category'] = implode(',', $this->get_exluded_cats(true));
        $params['cat_operator'] = 'NOT IN';
        return $params;
    }

    /**
    * Exclude hidden cats on storefront shortcode
    * @param  array $params shortcode parameters
    * @return array         shortcode parameters
    */
    function term_filter($params){
        if(!is_admin() && $params['taxonomy'] == array('product_cat')){
            $params['exclude'] = implode(',', $this->get_hidden_cats(true));
        }
        return $params;
    }

    /**
    * Exclude products from a particular category on the store
    * @param  object $q WP_Query
    * @return object $q WP_Query
    */
    function custom_pre_get_posts_query( $q ) {
        $tax_query = (array) $q->get( 'tax_query' );
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $this->get_exluded_cats(true),
            'operator' => 'NOT IN'
        );
        $q->set( 'tax_query', $tax_query );
        return $q;
    }

    /**
    * Hide hidden categories on single product pages
    * @param  array $terms
    * @param  int $post_ID
    * @param  object $taxonomy
    * @return array  array of terms
    */
    function hide_hidden_categories_single_product($terms, $post_ID, $taxonomy){
        if (is_product() && $taxonomy == "product_cat"){
            $excluded_cats = $this->get_hidden_cats(true);
            foreach ($terms as $key => $term) {
                if(in_array($term->term_id,$excluded_cats)){
                    unset($terms[$key]);
                }
            }
        }
        return $terms;
    }

    /**
    * Add a subsection to product settings in WC
    * @param  array $sections
    * @return array $sections
    */
    function woocommerce_get_sections_products($sections){
        $sections['hide-from-categories'] = __('Hide from categories', 'hide-categories-products-woocommerce');
        return $sections;
    }

    /**
    * Populate subsection in product settings in WC
    * @param  array $settings          wp_settings
    * @param  string $current_section
    * @return array                   $settings wp_settings
    */
    function woocommerce_get_settings_products($settings, $current_section){
        if ( 'hide-from-categories' === $current_section ) {
          wp_enqueue_style(
              'hide-from-categories-style',
              $this->baseurl.'static/css/hfc_style.css',
              filemtime($this->dirname.'/static/css/hfc_style.css')
          );
          wp_enqueue_script(
              'hide-from-categories-script',
              $this->baseurl.'static/js/hfc_script.js',
              array('jquery'),
              filemtime($this->dirname.'/static/js/hfc_script.js')
          );
            $terms = get_terms(array (
                'taxonomy' => 'product_cat',
                'orderby' => 'name',
                'order' => 'ASC',
                'hide_empty' => false,
                'fields' => 'all',
                'name' => '',
                'slug' => '',
            ));
            $settings = array();

            $settings[] = array(
                'title' => __( 'Hide categories', 'hide-categories-products-woocommerce' ),
                'desc'    => __( 'Hide these categories on the store', 'hide-categories-products-woocommerce' ),
                'type'  => 'title',
                'id'    => 'hide-categories',
            );
            foreach ($terms as $term) {
                $settings[] = array(
                    'title'   => $term->name,
                    'id'      => 'wchc_hide_product_cats['.$term->term_id.']',
                    'type'    => 'checkbox',
                    'default' => '',
                );
            }
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'hide-categories',
            );

            $settings[] = array(
                'title' => __( 'Hide from categories', 'hide-categories-products-woocommerce' ),
                'desc'    => __( 'Hide products from these categories the store', 'hide-categories-products-woocommerce' ),
                'type'  => 'title',
                'id'    => 'hide-from-categories',
            );
            foreach ($terms as $term) {
                $settings[] = array(
                    'title'   => $term->name,
                    'id'      => 'wchc_hide_products_from_cat['.$term->slug.']',
                    'type'    => 'checkbox',
                    'default' => '',
                );
            }
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'hide-from-categories',
            );
            $settings = apply_filters('woocommerce_settings_archives', $settings);
        }
        return $settings;
    }

    /**
    * Add div to tab setting
    */
    function woocommerce_before_settings_products(){
      if (isset($_GET['section']) && 'hide-from-categories' === $_GET['section'] ) {
        echo '<div class="hide-from-categories">';
      }
    }
    /**
    * Add div after tab setting
    */
    function woocommerce_after_settings_products(){
      if (isset($_GET['section']) && 'hide-from-categories' === $_GET['section'] ) {
        echo '</div>';
      }
    }

    /**
    * Hide products from hidden categories on the store
    */
    function posts_search( $search, $wp_query ){
        global $wpdb;

        if(is_admin()){
            return $search;
        }

        if(empty($search)) {
            return $search;
        }
        $q = $wp_query->query_vars;
        $n = !empty($q['exact']) ? '' : '%';
        $search =
        $searchand = '';
        foreach ((array)$q['search_terms'] as $term) {
            $term = esc_sql($wpdb->esc_like($term));
            $search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
            $searchand = ' AND ';
        }

        if (!empty($search)) {
            $search = " AND ({$search}) ";
            $excluded_cats = $this->get_exluded_cats(true);
            if (!empty($excluded_cats)){
                $search .= " AND $wpdb->posts.ID NOT IN (SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id IN (".implode(",",$excluded_cats)."))";
            }
        }
        return $search;
    }
}
