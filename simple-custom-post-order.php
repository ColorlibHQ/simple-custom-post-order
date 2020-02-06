<?php
/**
* Plugin Name: Simple Custom Post Order
* Plugin URI: https://wordpress.org/plugins-wp/simple-custom-post-order/
* Description: Order Items (Posts, Pages, and Custom Post Types) using a Drag and Drop Sortable JavaScript.
* Version: 2.4.9
* Author: Colorlib
* Author URI: https://colorlib.com/
* Tested up to: 5.3
* Requires: 4.6 or higher
* License: GPLv3 or later
* License URI: http://www.gnu.org/licenses/gpl-3.0.html
* Requires PHP: 5.6
* Text Domain: simple-custom-post-order
* Domain Path: /languages
*
* Copyright 2013-2017 Sameer Humagain im@hsameer.com.np
* Copyright 2017-2019 Colorlib support@colorlib.com
*
* SVN commit with ownership change: https://plugins.trac.wordpress.org/changeset/1590135/simple-custom-post-order
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License, version 3, as
* published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


define('SCPORDER_URL', plugins_url('', __FILE__));
define('SCPORDER_DIR', plugin_dir_path(__FILE__));
define('SCPORDER_VERSION', '2.4.9');

$scporder = new SCPO_Engine();

class SCPO_Engine {

    function __construct() {
        if (!get_option('scporder_install'))
            $this->scporder_install();

        add_action('admin_menu', array($this, 'admin_menu'));

        add_action('admin_init', array( $this, 'refresh' ) );

        add_action('admin_init', array($this, 'update_options'));
        add_action('admin_init', array($this, 'load_script_css'));

        add_action('wp_ajax_update-menu-order', array($this, 'update_menu_order'));
        add_action('wp_ajax_update-menu-order-tags', array($this, 'update_menu_order_tags'));

        add_action('pre_get_posts', array($this, 'scporder_pre_get_posts'));

        add_filter('get_previous_post_where', array($this, 'scporder_previous_post_where'));
        add_filter('get_previous_post_sort', array($this, 'scporder_previous_post_sort'));
        add_filter('get_next_post_where', array($this, 'scporder_next_post_where'));
        add_filter('get_next_post_sort', array($this, 'scporder_next_post_sort'));

        add_filter('get_terms_orderby', array($this, 'scporder_get_terms_orderby'), 10, 3);
        add_filter('wp_get_object_terms', array($this, 'scporder_get_object_terms'), 10, 3);
        add_filter('get_terms', array($this, 'scporder_get_object_terms'), 10, 3);
        
        add_action( 'admin_notices', array( $this, 'scporder_notice_not_checked' ) );
        add_action( 'wp_ajax_scporder_dismiss_notices', array( $this, 'dismiss_notices' ) );

        add_action( 'plugins_loaded', array( $this, 'load_scpo_textdomain' ) );

        add_filter('scpo_post_types_args',array($this,'scpo_filter_post_types'),10,2);

        add_action('wp_ajax_scpo_reset_order', array($this, 'scpo_ajax_reset_order'));
    }

    public function scpo_filter_post_types($args,$options){

        if(isset($options['show_advanced_view']) && '1' == $options['show_advanced_view'] ){
            unset($args['show_in_menu']);
        }

        return $args;
    }

    public function load_scpo_textdomain(){
        load_plugin_textdomain( 'simple-custom-post-order', false, basename( dirname( __FILE__ ) ) . '/languages/' );
    }

    public function dismiss_notices() {

        if ( ! check_admin_referer( 'scporder_dismiss_notice', 'scporder_nonce' ) ) {
            wp_die( 'nok' );
        }

        update_option( 'scporder_notice', '1' );

        wp_die( 'ok' );

    }

    public function scporder_notice_not_checked() {

        $settings = $this->get_scporder_options_objects();
        if ( ! empty( $settings ) ){
            return;
        }

        $screen = get_current_screen();

        if ( 'settings_page_scporder-settings' == $screen->id ) {
            return;
        }

        $dismessed = get_option( 'scporder_notice', false );

        if ( $dismessed ) {
            return;
        }

        ?>
        <div class="notice scpo-notice" id="scpo-notice">
            <img src="<?php echo esc_url( plugins_url( 'assets/logo.jpg', __FILE__ ) ); ?>" width="80">

            <h1><?php esc_html_e( 'Simple Custom Post Order', 'simple-custom-post-order' ); ?></h1>

            <p><?php esc_html_e( 'Thank you for installing our awesome plugin, in order to enable it you need to go to the settings page and select which custom post or taxonomy you want to order.', 'simple-custom-post-order' ); ?></p>

            <p><a href="<?php echo admin_url( 'options-general.php?page=scporder-settings' ) ?>" class="button button-primary button-hero"><?php esc_html_e( 'Get started !', 'simple-custom-post-order' ); ?></a></p>
            <button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'simple-custom-post-order' ); ?></span></button>
        </div>

        <style>
            .scpo-notice {
                background: #e9eff3;
                border: 10px solid #fff;
                color: #608299;
                padding: 30px;
                text-align: center;
                position: relative;
            }
        </style>
        <script>
            jQuery(document).ready(function(){
                jQuery( '#scpo-notice .notice-dismiss' ).click(function( evt ){
                    evt.preventDefault();

                    var ajaxData = {
                        'action' : 'scporder_dismiss_notices',
                        'scporder_nonce' : '<?php echo wp_create_nonce( 'scporder_dismiss_notice' ) ?>'
                    }

                    jQuery.ajax({
                        url: "<?php echo admin_url('admin-ajax.php'); ?>",
                        method: "POST",
                        data: ajaxData,
                        dataType: "html"
                    }).done(function(){
                        jQuery("#scpo-notice").hide();
                    });

                });
            })
        </script>
        <?php
    }

    public function scporder_install() {
        global $wpdb;
        $result = $wpdb->query("DESCRIBE $wpdb->terms `term_order`");
        if (!$result) {
            $query = "ALTER TABLE $wpdb->terms ADD `term_order` INT( 4 ) NULL DEFAULT '0'";
            $result = $wpdb->query($query);
        }
        update_option('scporder_install', 1);
    }

    public function admin_menu() {
        add_options_page(__('SCPOrder', 'simple-custom-post-order'), __('SCPOrder', 'simple-custom-post-order'), 'manage_options', 'scporder-settings', array($this, 'admin_page'));
    }

    public function admin_page() {
        require SCPORDER_DIR . 'settings.php';
    }

    public function _check_load_script_css() {
        $active = false;

        $objects = $this->get_scporder_options_objects();
        $tags = $this->get_scporder_options_tags();

        if (empty($objects) && empty($tags))
            return false;

        if (isset($_GET['orderby']) || strstr($_SERVER['REQUEST_URI'], 'action=edit') || strstr($_SERVER['REQUEST_URI'], 'wp-admin/post-new.php'))
            return false;

        if (!empty($objects)) {
            if (isset($_GET['post_type']) && !isset($_GET['taxonomy']) && in_array($_GET['post_type'], $objects)) { // if page or custom post types
                $active = true;
            }
            if (!isset($_GET['post_type']) && strstr($_SERVER['REQUEST_URI'], 'wp-admin/edit.php') && in_array('post', $objects)) { // if post
                $active = true;
            }
        }

        if (!empty($tags)) {
            if (isset($_GET['taxonomy']) && in_array($_GET['taxonomy'], $tags)) {
                $active = true;
            }
        }

        return $active;
    }

    public function load_script_css() {
        if ($this->_check_load_script_css()) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('scporderjs', SCPORDER_URL . '/assets/scporder.js', array('jquery'), SCPORDER_VERSION, true);

            wp_enqueue_style('scporder', SCPORDER_URL . '/assets/scporder.css', array(), SCPORDER_VERSION );
        }
    }

    public function refresh() {

        if ( scporder_doing_ajax() ) {
            return;
        }

        global $wpdb;
        $objects = $this->get_scporder_options_objects();
        $tags = $this->get_scporder_options_tags();

        if (!empty($objects)) {
            
            foreach ($objects as $object) {
                $result = $wpdb->get_results("
                    SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min
                    FROM $wpdb->posts
                    WHERE post_type = '" . $object . "' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
                ");

                if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max)
                    continue;

                // Here's the optimization
                $wpdb->query("SET @row_number = 0;");
                $wpdb->query("UPDATE $wpdb->posts as pt JOIN (
                  SELECT ID, (@row_number:=@row_number + 1) AS `rank`
                  FROM $wpdb->posts
                  WHERE post_type = '$object' AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
                  ORDER BY menu_order ASC
                ) as pt2
                ON pt.id = pt2.id
                SET pt.menu_order = pt2.`rank`;");

            }
        }

        if (!empty($tags)) {
            foreach ($tags as $taxonomy) {
                $result = $wpdb->get_results("
                    SELECT count(*) as cnt, max(term_order) as max, min(term_order) as min
                    FROM $wpdb->terms AS terms
                    INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
                    WHERE term_taxonomy.taxonomy = '" . $taxonomy . "'
                ");
                if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max)
                    continue;

                $results = $wpdb->get_results("
                    SELECT terms.term_id
                    FROM $wpdb->terms AS terms
                    INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
                    WHERE term_taxonomy.taxonomy = '" . $taxonomy . "'
                    ORDER BY term_order ASC
                ");
                foreach ($results as $key => $result) {
                    $wpdb->update($wpdb->terms, array('term_order' => $key + 1), array('term_id' => $result->term_id));
                }
            }
        }
    }

    public function update_menu_order() {
        global $wpdb;

        parse_str($_POST['order'], $data);

        if (!is_array($data))
            return false;

        $id_arr = array();
        foreach ($data as $key => $values) {
            foreach ($values as $position => $id) {
                $id_arr[] = $id;
            }
        }

        $menu_order_arr = array();
        foreach ($id_arr as $key => $id) {
            $results = $wpdb->get_results("SELECT menu_order FROM $wpdb->posts WHERE ID = " . intval($id));
            foreach ($results as $result) {
                $menu_order_arr[] = $result->menu_order;
            }
        }

        sort($menu_order_arr);

        foreach ($data as $key => $values) {
            foreach ($values as $position => $id) {
                $wpdb->update($wpdb->posts, array('menu_order' => $menu_order_arr[$position]), array('ID' => intval($id)));
            }
        }

        do_action('scp_update_menu_order');
    }

    public function update_menu_order_tags() {
        global $wpdb;

        parse_str($_POST['order'], $data);

        if (!is_array($data))
            return false;

        $id_arr = array();
        foreach ($data as $key => $values) {
            foreach ($values as $position => $id) {
                $id_arr[] = $id;
            }
        }

        $menu_order_arr = array();
        foreach ($id_arr as $key => $id) {
            $results = $wpdb->get_results("SELECT term_order FROM $wpdb->terms WHERE term_id = " . intval($id));
            foreach ($results as $result) {
                $menu_order_arr[] = $result->term_order;
            }
        }
        sort($menu_order_arr);

        foreach ($data as $key => $values) {
            foreach ($values as $position => $id) {
                $wpdb->update($wpdb->terms, array('term_order' => $menu_order_arr[$position]), array('term_id' => intval($id)));
            }
        }

        do_action('scp_update_menu_order_tags');
    }

    public function update_options() {
        global $wpdb;

        if (!isset($_POST['scporder_submit']))
            return false;

        check_admin_referer('nonce_scporder');

        $input_options = array();
        $input_options['objects'] = isset($_POST['objects']) ? $_POST['objects'] : '';
        $input_options['tags'] = isset($_POST['tags']) ? $_POST['tags'] : '';
        $input_options['show_advanced_view'] = isset($_POST['show_advanced_view']) ? $_POST['show_advanced_view'] : '';

        update_option('scporder_options', $input_options);

        $objects = $this->get_scporder_options_objects();
        $tags = $this->get_scporder_options_tags();

        if (!empty($objects)) {
            foreach ($objects as $object) {
                $result = $wpdb->get_results("
                    SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min
                    FROM $wpdb->posts
                    WHERE post_type = '" . $object . "' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
                ");
                if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max)
                    continue;

                if ($object == 'page') {
                    $results = $wpdb->get_results("
                        SELECT ID
                        FROM $wpdb->posts
                        WHERE post_type = '" . $object . "' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
                        ORDER BY post_title ASC
                    ");
                } else {
                    $results = $wpdb->get_results("
                        SELECT ID
                        FROM $wpdb->posts
                        WHERE post_type = '" . $object . "' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
                        ORDER BY post_date DESC
                    ");
                }
                foreach ($results as $key => $result) {
                    $wpdb->update($wpdb->posts, array('menu_order' => $key + 1), array('ID' => $result->ID));
                }
            }
        }

        if (!empty($tags)) {
            foreach ($tags as $taxonomy) {
                $result = $wpdb->get_results("
                    SELECT count(*) as cnt, max(term_order) as max, min(term_order) as min
                    FROM $wpdb->terms AS terms
                    INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
                    WHERE term_taxonomy.taxonomy = '" . $taxonomy . "'
                ");
                if ($result[0]->cnt == 0 || $result[0]->cnt == $result[0]->max)
                    continue;

                $results = $wpdb->get_results("
                    SELECT terms.term_id
                    FROM $wpdb->terms AS terms
                    INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
                    WHERE term_taxonomy.taxonomy = '" . $taxonomy . "'
                    ORDER BY name ASC
                ");
                foreach ($results as $key => $result) {
                    $wpdb->update($wpdb->terms, array('term_order' => $key + 1), array('term_id' => $result->term_id));
                }
            }
        }

        wp_redirect('admin.php?page=scporder-settings&msg=update');
    }

    public function scporder_previous_post_where($where) {
        global $post;

        $objects = $this->get_scporder_options_objects();
        if (empty($objects))
            return $where;

        if (isset($post->post_type) && in_array($post->post_type, $objects)) {
            $where = preg_replace("/p.post_date < \'[0-9\-\s\:]+\'/i", "p.menu_order > '" . $post->menu_order . "'", $where);
        }
        return $where;
    }

    public function scporder_previous_post_sort($orderby) {
        global $post;

        $objects = $this->get_scporder_options_objects();
        if (empty($objects))
            return $orderby;

        if (isset($post->post_type) && in_array($post->post_type, $objects)) {
            $orderby = 'ORDER BY p.menu_order ASC LIMIT 1';
        }
        return $orderby;
    }

    public function scporder_next_post_where($where) {
        global $post;

        $objects = $this->get_scporder_options_objects();
        if (empty($objects))
            return $where;

        if (isset($post->post_type) && in_array($post->post_type, $objects)) {
            $where = preg_replace("/p.post_date > \'[0-9\-\s\:]+\'/i", "p.menu_order < '" . $post->menu_order . "'", $where);
        }
        return $where;
    }

    public function scporder_next_post_sort($orderby) {
        global $post;

        $objects = $this->get_scporder_options_objects();
        if (empty($objects))
            return $orderby;

        if (isset($post->post_type) && in_array($post->post_type, $objects)) {
            $orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
        }
        return $orderby;
    }

    public function scporder_pre_get_posts($wp_query) {
        $objects = $this->get_scporder_options_objects();

        if (empty($objects))
            return false;
        if (is_admin()) {

            if (isset($wp_query->query['post_type']) && !isset($_GET['orderby'])) {
                if (in_array($wp_query->query['post_type'], $objects)) {
                    $wp_query->set('orderby', 'menu_order');
                    $wp_query->set('order', 'ASC');
                }
            }
        } else {

            $active = false;

            if (isset($wp_query->query['post_type'])) {
                if (!is_array($wp_query->query['post_type'])) {
                    if (in_array($wp_query->query['post_type'], $objects)) {
                        $active = true;
                    }
                }
            } else {
                if (in_array('post', $objects)) {
                    $active = true;
                }
            }

            if (!$active)
                return false;

            if (isset($wp_query->query['suppress_filters'])) {
                if ($wp_query->get('orderby') == 'date')
                    $wp_query->set('orderby', 'menu_order');
                if ($wp_query->get('order') == 'DESC')
                    $wp_query->set('order', 'ASC');
            } else {
                if (!$wp_query->get('orderby'))
                    $wp_query->set('orderby', 'menu_order');
                if (!$wp_query->get('order'))
                    $wp_query->set('order', 'ASC');
            }

        }
    }

    public function scporder_get_terms_orderby($orderby, $args) {
        if (is_admin())
            return $orderby;

        $tags = $this->get_scporder_options_tags();

        if (!isset($args['taxonomy']))
            return $orderby;

        if(is_array($args['taxonomy'])){
            if(isset($args['taxonomy'][0])){
                $taxonomy = $args['taxonomy'][0];
            } else {
                $taxonomy = false;
            }

        } else {
            $taxonomy = $args['taxonomy'];
        }

        if (!in_array($taxonomy, $tags))
            return $orderby;

        $orderby = 't.term_order';
        return $orderby;
    }

    public function scporder_get_object_terms($terms) {
        $tags = $this->get_scporder_options_tags();

        if (is_admin() && isset($_GET['orderby']))
            return $terms;

        foreach ($terms as $key => $term) {
            if (is_object($term) && isset($term->taxonomy)) {
                $taxonomy = $term->taxonomy;
                if (!in_array($taxonomy, $tags))
                    return $terms;
            } else {
                return $terms;
            }
        }

        usort($terms, array($this, 'taxcmp'));
        return $terms;
    }

    public function taxcmp($a, $b) {
        if ($a->term_order == $b->term_order)
            return 0;
        return ( $a->term_order < $b->term_order ) ? -1 : 1;
    }

    public function get_scporder_options_objects() {
        $scporder_options = get_option('scporder_options') ? get_option('scporder_options') : array();
        $objects = isset($scporder_options['objects']) && is_array($scporder_options['objects']) ? $scporder_options['objects'] : array();
        return $objects;
    }

    public function get_scporder_options_tags() {
        $scporder_options = get_option('scporder_options') ? get_option('scporder_options') : array();
        $tags = isset($scporder_options['tags']) && is_array($scporder_options['tags']) ? $scporder_options['tags'] : array();
        return $tags;
    }

    /**
     *  SCPO reset order for post types/taxonomies
     */
    public function scpo_ajax_reset_order() {

        global $wpdb;
        if ('scpo_reset_order' == $_POST['action']) {
            check_ajax_referer('scpo-reset-order', 'scpo_security');
            $items = $_POST['items'];

            $count   = 0;
            $in_list = "(";
            foreach ($items as $item) {

                if ($count != 0) {
                    $in_list .= ',';
                }
                $in_list .= '\'' . $item . '\'';
                $count++;
            }
            $in_list .= ")";

            $prep_posts_query = "UPDATE $wpdb->posts SET `menu_order` = 0 WHERE `post_type` IN $in_list";

            $result = $wpdb->query($prep_posts_query);

            if ($result) {
                echo 'Items have been reset';
            } else {
                echo false;
            }

            wp_die();
        }
    }

}

function scporder_doing_ajax(){

    if ( function_exists( 'wp_doing_ajax' ) ) {
        return wp_doing_ajax();
    }

    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return true;
    }

    return false;

}

/**
 * SCP Order Uninstall hook
 */
register_uninstall_hook(__FILE__, 'scporder_uninstall');

function scporder_uninstall() {
    global $wpdb;
    if (function_exists('is_multisite') && is_multisite()) {
        $curr_blog = $wpdb->blogid;
        $blogids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
        foreach ($blogids as $blog_id) {
            switch_to_blog($blog_id);
            scporder_uninstall_db();
        }
        switch_to_blog($curr_blog);
    } else {
        scporder_uninstall_db();
    }
}

function scporder_uninstall_db() {
    global $wpdb;
    $result = $wpdb->query("DESCRIBE $wpdb->terms `term_order`");
    if ($result) {
        $query = "ALTER TABLE $wpdb->terms DROP `term_order`";
        $result = $wpdb->query($query);
    }
    delete_option('scporder_install');
}