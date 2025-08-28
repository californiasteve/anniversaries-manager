<?php
/**
 * Plugin Name: Anniversaries Manager
 * Description: Display anniversaries with a public submit form that creates a pending entry for admin approval. Shortcodes: [abm_form], [abm_list], [abm_calendar]. Includes CSV import/export, public calendar navigation, notifications, and custom CSS (toggle default styles). (Admin can set a custom display label like Anniversary, Birthday, Hire Date, etc.)
 * Version: 1.8.12
 * Author: California Steve
 * License: GPL-3.0-or-later
 * Text Domain: anniversaries-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class ABM_Plugin {
    const CPT = 'abm_anniv';
    const NONCE = 'abm_nonce';

    const META_DATE  = '_abm_date';
    const META_TYPE  = '_abm_type';   // legacy, always "anniversary"
    const META_NOTES = '_abm_notes';
    const META_LABEL = '_abm_label';  // admin-editable label (e.g., Anniversary, Birthday, Hire Date)

    const OPT_NOTIFY_EMAILS      = 'abm_notify_email';
    const OPT_ENABLE_DEFAULT_CSS = 'abm_enable_default_css';
    const OPT_CUSTOM_CSS         = 'abm_custom_css';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_shortcodes']);

        add_filter('manage_edit-'.self::CPT.'_columns', [$this, 'admin_columns']);
        add_action('manage_'.self::CPT.'_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
        add_filter('manage_edit-'.self::CPT.'_sortable_columns', [$this, 'admin_sortable_columns']);
        add_action('pre_get_posts', [$this, 'admin_orderby_meta']);

        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post_'.self::CPT, [$this, 'save_metabox'], 10, 3);

        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_action_abm_approve', [$this, 'handle_approve']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_abm_export_csv', [$this, 'handle_export_csv']);
        add_action('admin_post_abm_import_csv', [$this, 'handle_import_csv']);
        add_action('admin_post_abm_save_notifications', [$this, 'handle_save_notifications']);
        add_action('admin_post_abm_save_styles', [$this, 'handle_save_styles']);

        add_action('wp_enqueue_scripts', function() {
            wp_register_style('abm-css', plugins_url('assets/abm.css', __FILE__));
        });

        // Notify only for non-admin creations; admin-created draft/publish suppressed
        add_action('save_post_' . self::CPT, [$this, 'maybe_notify_on_admin_create'], 20, 3);

        // Output styles
        add_action('wp_head', [$this, 'output_styles']);
    }

    /* =========================
     * Custom Post Type
     * ========================= */
    public function register_cpt() {
        $labels = [
            'name'               => _x('Anniversaries', 'Post type general name', 'anniversaries-manager'),
            'singular_name'      => _x('Anniversary', 'Post type singular name', 'anniversaries-manager'),
            'menu_name'          => _x('Anniversaries', 'Admin Menu text', 'anniversaries-manager'),
            'name_admin_bar'     => _x('Anniversary', 'Add New on Toolbar', 'anniversaries-manager'),
            'add_new'            => _x('Add New', 'anniversary', 'anniversaries-manager'),
            'add_new_item'       => __('Add New Anniversary', 'anniversaries-manager'),
            'new_item'           => __('New Anniversary', 'anniversaries-manager'),
            'edit_item'          => __('Edit Anniversary', 'anniversaries-manager'),
            'view_item'          => __('View Anniversary', 'anniversaries-manager'),
            'all_items'          => __('All Anniversaries', 'anniversaries-manager'),
            'search_items'       => __('Search Anniversaries', 'anniversaries-manager'),
            'not_found'          => __('No entries found', 'anniversaries-manager'),
            'not_found_in_trash' => __('No entries found in Trash', 'anniversaries-manager'),
        ];

        register_post_type(self::CPT, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-buddicons-community',
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    /* =========================
     * Admin Columns / Sorting
     * ========================= */
    public function admin_columns($cols) {
        $new = [];
        $new['cb']         = $cols['cb'];
        $new['title']      = __('Name', 'anniversaries-manager'); // Title stores either just name or "Name — Label" if autotitled
        $new['abm_date']   = __('Date', 'anniversaries-manager');
        $new['abm_next']   = __('Next Occurrence', 'anniversaries-manager');
        $new['abm_years']  = __('Years', 'anniversaries-manager');
        $new['date']       = $cols['date'];
        return $new;
    }

    public function admin_column_content($col, $post_id) {
        switch ($col) {
            case 'abm_date':
                $d = get_post_meta($post_id, self::META_DATE, true);
                echo $d ? esc_html( wp_date( get_option('date_format'), strtotime($d) ) ) : '—';
                break;
            case 'abm_next':
                $d = get_post_meta($post_id, self::META_DATE, true);
                echo $d ? esc_html( wp_date( get_option('date_format'), $this->next_occurrence($d) ) ) : '—';
                break;
            case 'abm_years':
                $d = get_post_meta($post_id, self::META_DATE, true);
                echo $d ? intval($this->years_since($d)) : '—';
                break;
        }
    }

    public function admin_sortable_columns($cols) {
        $cols['title']      = 'title';
        $cols['abm_date']   = 'abm_date';
        $cols['abm_years']  = 'abm_years';
        return $cols;
    }

    public function admin_orderby_meta($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        $orderby = $query->get('orderby');

        if ($orderby === 'abm_date') {
            $query->set('meta_key', self::META_DATE);
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'abm_years') {
            $query->set('meta_key', self::META_DATE);
            $query->set('orderby', 'meta_value');
            add_filter('posts_clauses', [$this, 'orderby_years_clause'], 10, 2);
        }
    }

    public function orderby_years_clause($clauses, $query) {
        if (!is_admin() || !$query->is_main_query()) return $clauses;
        if ($query->get('orderby') !== 'meta_value' || $query->get('meta_key') !== self::META_DATE) return $clauses;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sorting only, read-only
        $requested = isset($_GET['orderby']) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
        if ($requested !== 'abm_years') return $clauses;

        $order = strtoupper($query->get('order')) === 'DESC' ? 'DESC' : 'ASC';
        $computed = " (YEAR(CURDATE()) - YEAR(mt1.meta_value)) ";
        $clauses['orderby'] = "ORDER BY {$computed} {$order}, {$clauses['orderby']}";
        remove_filter('posts_clauses', [$this, 'orderby_years_clause'], 10);
        return $clauses;
    }

    /* =========================
     * Metabox (Date, Notes, Label)
     * ========================= */
    public function add_metabox() {
        add_meta_box('abm_fields', __('Anniversary Details', 'anniversaries-manager'), [$this, 'render_metabox'], self::CPT, 'normal', 'default');
    }

    public function render_metabox($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $date  = get_post_meta($post->ID, self::META_DATE, true);
        $notes = get_post_meta($post->ID, self::META_NOTES, true);
        $label = get_post_meta($post->ID, self::META_LABEL, true);
        if ($label === '') $label = 'Anniversary'; // default
        ?>
        <table class="form-table">
            <tr>
                <th><label for="abm_date"><?php esc_html_e('Date', 'anniversaries-manager'); ?></label></th>
                <td><input type="date" id="abm_date" name="abm_date" value="<?php echo esc_attr($date); ?>" required></td>
            </tr>
            <tr>
                <th><label for="abm_label"><?php esc_html_e('Label (display text)', 'anniversaries-manager'); ?></label></th>
                <td>
                    <input type="text" id="abm_label" name="abm_label" value="<?php echo esc_attr($label); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. Anniversary, Birthday, Hire Date', 'anniversaries-manager'); ?>">
                    <p class="description"><?php esc_html_e('Shown in the title after the name (e.g., “Jane Smith — Birthday”).', 'anniversaries-manager'); ?></p>
                    <label><input type="checkbox" name="abm_autotitle" value="1" <?php checked(true, $this->should_autotitle_default($post)); ?>> <?php esc_html_e('Auto-update the title with this label', 'anniversaries-manager'); ?></label>
                </td>
            </tr>
            <tr>
                <th><label for="abm_notes"><?php esc_html_e('Notes (optional)', 'anniversaries-manager'); ?></label></th>
                <td><textarea id="abm_notes" name="abm_notes" rows="3" style="width:100%;"><?php echo esc_textarea($notes); ?></textarea></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Computed', 'anniversaries-manager'); ?></th>
                <td>
                    <?php if ($date): ?>
                        <p><strong><?php esc_html_e('Next occurrence:', 'anniversaries-manager'); ?></strong> <?php echo esc_html( wp_date( get_option('date_format'), $this->next_occurrence($date) ) ); ?></p>
                        <p><strong><?php esc_html_e('Years:', 'anniversaries-manager'); ?></strong> <?php echo intval($this->years_since($date)); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('Set a date to see next occurrence and years.', 'anniversaries-manager'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    private function should_autotitle_default($post){
        // Default ON for new posts; keep current choice for existing posts.
        return (strpos($post->post_title, '—') === false);
    }

    public function save_metabox($post_id, $post = null, $update = false) {
        $form_nonce = isset($_POST[self::NONCE]) ? sanitize_text_field( wp_unslash( $_POST[self::NONCE] ) ) : '';
        if ( empty( $form_nonce ) || ! wp_verify_nonce( $form_nonce, self::NONCE ) ) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, self::META_TYPE, 'anniversary'); // legacy

        // Date
        $date = isset($_POST['abm_date']) ? sanitize_text_field( wp_unslash( $_POST['abm_date'] ) ) : '';
        if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

        // Notes
        $notes = isset($_POST['abm_notes']) ? wp_kses_post( wp_unslash( $_POST['abm_notes'] ) ) : '';

        // Label
        $label = isset($_POST['abm_label']) ? sanitize_text_field( wp_unslash( $_POST['abm_label'] ) ) : '';
        $label = $label !== '' ? $label : 'Anniversary';
        update_post_meta($post_id, self::META_LABEL, $label);

        if ($date) update_post_meta($post_id, self::META_DATE, $date); else delete_post_meta($post_id, self::META_DATE);
        if ($notes) update_post_meta($post_id, self::META_NOTES, $notes); else delete_post_meta($post_id, self::META_NOTES);

        // Auto-title
        $autotitle = !empty($_POST['abm_autotitle']);
        if ($autotitle) {
            $current = get_post_field('post_title', $post_id);
            $base = $this->extract_base_name_from_title($current);
            /* translators: 1: name 2: label text */
            $new_title = sprintf(__(' %1$s — %2$s', 'anniversaries-manager'), $base, $label);
            $new_title = ltrim($new_title); // remove leading space from formatted string
            if ($new_title !== $current) {
                remove_action('save_post_'.self::CPT, [$this, 'save_metabox'], 10);
                wp_update_post(['ID'=>$post_id, 'post_title'=>$new_title]);
                add_action('save_post_'.self::CPT, [$this, 'save_metabox'], 10, 3);
            }
        }
    }

    private function extract_base_name_from_title($title){
        $parts = explode('—', $title, 2);
        return trim($parts[0]);
    }

    /* =========================
     * Approve action
     * ========================= */
    public function row_actions($actions, $post) {
        if ($post->post_type !== self::CPT || $post->post_status !== 'pending') return $actions;
        $url = wp_nonce_url(
            admin_url('admin.php?action=abm_approve&post_id='.$post->ID),
            'abm_approve_'.$post->ID
        );
        $actions['abm_approve'] = '<a href="'.esc_url($url).'">'.esc_html__('Approve', 'anniversaries-manager').'</a>';
        return $actions;
    }

    public function handle_approve() {
        if (!current_user_can('edit_posts')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked via wp_verify_nonce below
        $post_id = isset($_GET['post_id']) ? intval( wp_unslash( $_GET['post_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately here
        $get_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if (!$post_id || empty($get_nonce) || !wp_verify_nonce( $get_nonce, 'abm_approve_'.$post_id )) wp_die(esc_html__('Bad nonce.', 'anniversaries-manager'));
        wp_update_post(['ID'=>$post_id, 'post_status'=>'publish']);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type='.self::CPT));
        exit;
    }

    /* =========================
     * Shortcodes
     * ========================= */
    public function register_shortcodes() {
        add_shortcode('abm_form', [$this, 'sc_form']);
        add_shortcode('abm_list', [$this, 'sc_list']);
        add_shortcode('abm_calendar', [$this, 'sc_calendar']);
    }

    public function sc_form($atts = []) {
        $out = '';

        $form_nonce = isset($_POST[self::NONCE]) ? sanitize_text_field( wp_unslash( $_POST[self::NONCE] ) ) : '';
        if ( isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abm_form_submit']) && !empty($form_nonce) && wp_verify_nonce($form_nonce, self::NONCE) ) {
            $out .= $this->handle_form_submission();
        } elseif (isset($_POST['abm_form_submit'])) {
            $out .= '<div class="abm-msg abm-error">' . esc_html__( 'Security check failed.', 'anniversaries-manager' ) . '</div>';
        }

        // enqueue default CSS only if enabled
        if ( (bool) get_option(self::OPT_ENABLE_DEFAULT_CSS, true) ) {
            wp_enqueue_style('abm-css');
        }

        ob_start(); ?>
        <form class="abm-form" method="post">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <p>
                <label for="abm_name"><?php esc_html_e('Name', 'anniversaries-manager'); ?><span class="abm-req">*</span></label>
                <input type="text" id="abm_name" name="abm_name" required>
            </p>
            <p>
                <label for="abm_date"><?php esc_html_e('Date', 'anniversaries-manager'); ?><span class="abm-req">*</span></label>
                <input type="date" id="abm_date" name="abm_date" required>
            </p>
            <p>
                <label for="abm_notes"><?php esc_html_e('Notes (optional)', 'anniversaries-manager'); ?></label>
                <textarea id="abm_notes" name="abm_notes" rows="3"></textarea>
            </p>
            <?php do_action('abm_form_after_fields'); ?>
            <p><button type="submit" name="abm_form_submit" class="abm-btn"><?php esc_html_e('Submit', 'anniversaries-manager'); ?></button></p>
        </form>
        <?php
        return $out . ob_get_clean();
    }

    private function handle_form_submission() {
        $form_nonce = isset($_POST[self::NONCE]) ? sanitize_text_field( wp_unslash( $_POST[self::NONCE] ) ) : '';
        if ( empty($form_nonce) || ! wp_verify_nonce( $form_nonce, self::NONCE ) ) {
            return '<div class="abm-msg abm-error">'.esc_html__('Security check failed.', 'anniversaries-manager').'</div>';
        }

        $name  = sanitize_text_field( wp_unslash( $_POST['abm_name'] ?? '' ) );
        $date  = sanitize_text_field( wp_unslash( $_POST['abm_date'] ?? '' ) );
        $notes = isset($_POST['abm_notes']) ? wp_kses_post( wp_unslash( $_POST['abm_notes'] ) ) : '';

        if (!$name || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '<div class="abm-msg abm-error">'.esc_html__('Please fill out all required fields correctly.', 'anniversaries-manager').'</div>';
        }

        // Do NOT append label to title for public submissions (per requirement)
        $title = $name;

        $post_id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_title'  => $title,
            'post_content'=> $notes,
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id)) {
            return '<div class="abm-msg abm-error">'.esc_html__('There was an error. Please try again later.', 'anniversaries-manager').'</div>';
        }

        update_post_meta($post_id, self::META_TYPE, 'anniversary'); // legacy
        update_post_meta($post_id, self::META_DATE, $date);
        update_post_meta($post_id, self::META_LABEL, 'Anniversary'); // default label for later use
        if ($notes) update_post_meta($post_id, self::META_NOTES, $notes);

        // Notify on new public submission
        $this->send_new_entry_notification($post_id, 'form');

        do_action('abm_after_submission', $post_id, compact('name','date','notes'));

        return '<div class="abm-msg abm-success">'.esc_html__('Thank you! Your entry was submitted and is awaiting approval.', 'anniversaries-manager').'</div>';
    }

    public function sc_list($atts = []) {
        $atts = shortcode_atts([
            'orderby' => 'next',
            'order' => 'ASC',
            'limit' => 100,
            'show_next' => '1',
        ], $atts, 'abm_list');

        $args = [
            'post_type'=>self::CPT, 'post_status'=>'publish',
            'posts_per_page'=>intval($atts['limit']),
        ];

        if ($atts['orderby'] === 'name') {
            $args['orderby'] = 'title'; $args['order'] = $atts['order'];
        } elseif ($atts['orderby'] === 'date') {
            $args['meta_key'] = self::META_DATE; $args['orderby'] = 'meta_value'; $args['order'] = $atts['order'];
        } else {
            $args['orderby'] = 'title'; $args['order'] = 'ASC';
        }

        $q = new WP_Query($args);
        if (!$q->have_posts()) return '<div class="abm-list">'.esc_html__('No entries found.', 'anniversaries-manager').'</div>';

        $items = [];
        while ($q->have_posts()) { $q->the_post();
            $id    = get_the_ID();
            $name  = get_the_title(); // may include label suffix if autotitled
            $date  = get_post_meta($id, self::META_DATE, true);
            $notes = get_post_meta($id, self::META_NOTES, true);
            $next_ts = $date ? $this->next_occurrence($date) : 0;
            $years   = $date ? $this->years_since($date) : 0;
            $items[] = compact('id','name','date','notes','next_ts','years');
        }
        wp_reset_postdata();

        if ($atts['orderby'] === 'next') {
            usort($items, function($a,$b) use ($atts) {
                if ($a['next_ts'] === $b['next_ts']) return 0;
                $cmp = ($a['next_ts'] < $b['next_ts']) ? -1 : 1;
                return strtoupper($atts['order']) === 'ASC' ? $cmp : -$cmp;
            });
        }

        $show_next = apply_filters('abm_list_show_next', $atts['show_next'] === '1', $atts);

        ob_start(); ?>
        <div class="abm-list">
            <table class="abm-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'anniversaries-manager'); ?></th>
                        <th><?php esc_html_e('Date', 'anniversaries-manager'); ?></th>
                        <?php if ($show_next): ?>
                            <th><?php esc_html_e('Next', 'anniversaries-manager'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Years', 'anniversaries-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo esc_html($it['name']); ?></td>
                        <td><?php echo $it['date'] ? esc_html( wp_date( get_option('date_format'), strtotime($it['date']) ) ) : '—'; ?></td>
                        <?php if ($show_next): ?>
                            <td><?php echo $it['next_ts'] ? esc_html( wp_date( get_option('date_format'), $it['next_ts'] ) ) : '—'; ?></td>
                        <?php endif; ?>
                        <td><?php echo intval($it['years']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php return ob_get_clean();
    }

    public function sc_calendar($atts = []) {
        $now = current_time('timestamp');
        $atts = shortcode_atts([
            'month' => gmdate('n', $now),
            'year'  => gmdate('Y', $now),
            'nav'   => '1',
        ], $atts, 'abm_calendar');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Calendar navigation (read-only)
        $q_month = isset($_GET['abm_month']) ? intval( wp_unslash( $_GET['abm_month'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Calendar navigation (read-only)
        $q_year  = isset($_GET['abm_year'])  ? intval( wp_unslash( $_GET['abm_year'] ) )  : 0;
        $month   = ($q_month >= 1 && $q_month <= 12) ? $q_month : intval($atts['month']);
        $year    = ($q_year >= 1970) ? $q_year : intval($atts['year']);

        $month = max(1, min(12, $month));
        $year  = max(1970, $year);

        $start_ts = strtotime(sprintf('%04d-%02d-01', $year, $month));
        $end_ts   = strtotime('+1 month', $start_ts);

        $q = new WP_Query([
            'post_type'=>self::CPT, 'post_status'=>'publish', 'posts_per_page'=>-1,
            'no_found_rows' => true,
        ]);

        $by_day = [];
        if ($q->have_posts()) {
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $title = get_the_title(); // may include label
                $date = get_post_meta($id, self::META_DATE, true);
                if (!$date) continue;

                $md_ts = strtotime(sprintf('%04d-%s', $year, gmdate('m-d', strtotime($date))));
                if ($md_ts >= $start_ts && $md_ts < $end_ts) {
                    $dnum = intval(gmdate('j', $md_ts));
                    $by_day[$dnum][] = ['title'=>$title,'years'=>$this->years_since($date, $year)];
                }
            }
            wp_reset_postdata();
        }

        $first_w = intval(gmdate('w', $start_ts));
        $days_in_month = intval(gmdate('t', $start_ts));
        $dow = [__('Sun','anniversaries-manager'),__('Mon','anniversaries-manager'),__('Tue','anniversaries-manager'),__('Wed','anniversaries-manager'),__('Thu','anniversaries-manager'),__('Fri','anniversaries-manager'),__('Sat','anniversaries-manager')];

        $show_nav = $atts['nav'] === '1';
        $prev_month = $month - 1; $prev_year = $year; if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
        $next_month = $month + 1; $next_year = $year; if ($next_month > 12) { $next_month = 1; $next_year++; }

        $prev_month_url = $this->abm_nav_url(['abm_month'=>$prev_month, 'abm_year'=>$prev_year]);
        $next_month_url = $this->abm_nav_url(['abm_month'=>$next_month, 'abm_year'=>$next_year]);
        $prev_year_url  = $this->abm_nav_url(['abm_month'=>$month, 'abm_year'=>$year-1]);
        $next_year_url  = $this->abm_nav_url(['abm_month'=>$month, 'abm_year'=>$year+1]);

        $today_m = intval(gmdate('n', $now));
        $today_y = intval(gmdate('Y', $now));
        $today_url = $this->abm_nav_url(['abm_month'=>$today_m, 'abm_year'=>$today_y]);

        ob_start(); ?>
        <div class="abm-calendar">
            <?php if ($show_nav): ?>
                <div class="abm-cal-title"><strong><?php echo esc_html( gmdate('F Y', $start_ts) ); ?></strong></div>
                <div class="abm-cal-nav">
                    <a class="abm-cal-btn" href="<?php echo esc_url($prev_year_url); ?>">&laquo; <?php esc_html_e('Prev Year', 'anniversaries-manager'); ?></a>
                    <a class="abm-cal-btn" href="<?php echo esc_url($prev_month_url); ?>">&lsaquo; <?php esc_html_e('Prev Month', 'anniversaries-manager'); ?></a>
                    <a class="abm-cal-btn" href="<?php echo esc_url($next_month_url); ?>"><?php esc_html_e('Next Month', 'anniversaries-manager'); ?> &rsaquo;</a>
                    <a class="abm-cal-btn" href="<?php echo esc_url($next_year_url); ?>"><?php esc_html_e('Next Year', 'anniversaries-manager'); ?> &raquo;</a>
                    <a class="abm-cal-btn abm-cal-today" href="<?php echo esc_url($today_url); ?>"><?php esc_html_e('Today', 'anniversaries-manager'); ?></a>
                </div>
            <?php else: ?>
                <div class="abm-calendar-header"><strong><?php echo esc_html( gmdate('F Y', $start_ts) ); ?></strong></div>
            <?php endif; ?>

            <div class="abm-calendar-grid">
                <?php foreach ($dow as $d): ?>
                    <div class="abm-cal-cell abm-cal-head"><?php echo esc_html($d); ?></div>
                <?php endforeach; ?>

                <?php for ($i=0; $i<$first_w; $i++) echo '<div class="abm-cal-cell abm-cal-empty"></div>'; ?>

                <?php for ($day=1; $day <= $days_in_month; $day++): ?>
                    <div class="abm-cal-cell">
                        <div class="abm-cal-daynum"><?php echo intval($day); ?></div>
                        <?php if (!empty($by_day[$day])): ?>
                            <ul class="abm-cal-list">
                                <?php foreach ($by_day[$day] as $entry): ?>
                                    <li>
                                        <?php echo esc_html($entry['title']); ?>
                                        <br /><small>
                                            <?php
                                            /* translators: %d: number of years */
                                            printf(esc_html__('%d yrs', 'anniversaries-manager'), intval($entry['years']));
                                            ?>
                                        </small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =========================
     * CSV + Notifications + Styles pages (Admin)
     * ========================= */
    public function admin_menu() {
        add_submenu_page(
            'edit.php?post_type='.self::CPT,
            __('CSV Import/Export', 'anniversaries-manager'),
            __('CSV Import/Export', 'anniversaries-manager'),
            'edit_posts',
            'abm_csv',
            [$this, 'render_csv_page']
        );

        add_submenu_page(
            'edit.php?post_type='.self::CPT,
            __('Notifications', 'anniversaries-manager'),
            __('Notifications', 'anniversaries-manager'),
            'manage_options',
            'abm_notifications',
            [$this, 'render_notifications_page']
        );

        add_submenu_page(
            'edit.php?post_type='.self::CPT,
            __('Styles', 'anniversaries-manager'),
            __('Styles', 'anniversaries-manager'),
            'manage_options',
            'abm_styles',
            [$this, 'render_styles_page']
        );
    }

    public function render_csv_page() {
        if (!current_user_can('edit_posts')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice parameter
        if (isset($_GET['abm_msg'])) $notice = sanitize_text_field( wp_unslash( $_GET['abm_msg'] ) ); ?>
        <div class="wrap">
            <h1><?php esc_html_e('CSV Import/Export', 'anniversaries-manager'); ?></h1>
            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Export', 'anniversaries-manager'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('abm_export_csv', 'abm_export_nonce'); ?>
                <input type="hidden" name="action" value="abm_export_csv">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'anniversaries-manager'); ?></th>
                        <td>
                            <select name="status" id="abm_export_status">
                                <option value="all"><?php esc_html_e('All', 'anniversaries-manager'); ?></option>
                                <option value="publish"><?php esc_html_e('Published', 'anniversaries-manager'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending', 'anniversaries-manager'); ?></option>
                                <option value="draft"><?php esc_html_e('Draft', 'anniversaries-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Download CSV', 'anniversaries-manager')); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Import', 'anniversaries-manager'); ?></h2>
            <p>
                <strong><?php esc_html_e('CSV headers required:', 'anniversaries-manager'); ?></strong>
                <code>name,date,notes,status</code>
                <?php esc_html_e('(status optional; default pending). Date must be YYYY-MM-DD.', 'anniversaries-manager'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('abm_import_csv', 'abm_import_nonce'); ?>
                <input type="hidden" name="action" value="abm_import_csv">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="abm_csv_file"><?php esc_html_e('CSV File', 'anniversaries-manager'); ?></label></th>
                        <td><input type="file" name="csv_file" id="abm_csv_file" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Default status', 'anniversaries-manager'); ?></th>
                        <td>
                            <select name="default_status">
                                <option value="pending"><?php esc_html_e('Pending', 'anniversaries-manager'); ?></option>
                                <option value="publish"><?php esc_html_e('Publish', 'anniversaries-manager'); ?></option>
                                <option value="draft"><?php esc_html_e('Draft', 'anniversaries-manager'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Used when a row omits status or provides an invalid value.', 'anniversaries-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Import CSV', 'anniversaries-manager')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_export_csv() {
        if (!current_user_can('edit_posts')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $abm_export_nonce = isset($_POST['abm_export_nonce']) ? sanitize_text_field( wp_unslash( $_POST['abm_export_nonce'] ) ) : '';
        if ( empty($abm_export_nonce) || ! wp_verify_nonce( $abm_export_nonce, 'abm_export_csv' ) ) wp_die(esc_html__('Bad nonce.', 'anniversaries-manager'));

        $status = isset($_POST['status']) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'all';

        $statuses = ['publish','pending','draft','future','private'];
        $post_status = (in_array($status, $statuses, true)) ? [$status] : $statuses;

        $q = new WP_Query([
            'post_type'=>self::CPT,
            'post_status'=>$post_status,
            'posts_per_page'=>-1,
            'orderby'=>'title','order'=>'ASC',
            'no_found_rows'=>true,
        ]);

        $filename = 'anniversaries-'.gmdate('Ymd-His').'.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $rows = [];
        $rows[] = ['name','date','notes','status'];

        if ($q->have_posts()) {
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $name  = html_entity_decode(get_the_title(), ENT_QUOTES);
                $date  = get_post_meta($id, self::META_DATE, true);
                $notes = html_entity_decode(get_post_field('post_content', $id), ENT_QUOTES);
                $st    = get_post_status($id);
                $rows[] = [$name,$date,$notes,$st];
            }
            wp_reset_postdata();
        }

        // Build CSV content in memory
        $out = chr(0xEF).chr(0xBB).chr(0xBF); // UTF-8 BOM
        foreach ($rows as $r) {
            $escaped = array_map(function($v){
                $v = (string)$v;
                $v = str_replace('"', '""', $v);
                return '"'.$v.'"';
            }, $r);
            $out .= implode(',', $escaped)."\r\n";
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sending raw CSV bytes to browser.
        echo $out;
        exit;
    }

    public function handle_import_csv() {
        if (!current_user_can('edit_posts')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $abm_import_nonce = isset($_POST['abm_import_nonce']) ? sanitize_text_field( wp_unslash( $_POST['abm_import_nonce'] ) ) : '';
        if ( empty($abm_import_nonce) || ! wp_verify_nonce( $abm_import_nonce, 'abm_import_csv' ) ) wp_die(esc_html__('Bad nonce.', 'anniversaries-manager'));

        $default_status = isset($_POST['default_status']) ? sanitize_text_field( wp_unslash( $_POST['default_status'] ) ) : 'pending';
        if (!in_array($default_status, ['publish','pending','draft'], true)) $default_status = 'pending';

        // Validate upload presence safely
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- file upload check
        if ( empty($_FILES['csv_file']['tmp_name']) ) {
            $this->redirect_csv_page(__('No file uploaded.', 'anniversaries-manager'));
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is validated by is_uploaded_file
        $tmp = $_FILES['csv_file']['tmp_name'];
        if ( ! is_uploaded_file( $tmp ) ) {
            $this->redirect_csv_page(__('No file uploaded.', 'anniversaries-manager'));
        }

        // Initialize WP_Filesystem and read the uploaded file
        if ( ! function_exists('WP_Filesystem') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $tmp has been validated by is_uploaded_file()
        $contents = $wp_filesystem->get_contents( $tmp );
        if ($contents === false) {
            $this->redirect_csv_page(__('Unable to read uploaded file.', 'anniversaries-manager'));
        }

        // Normalize line endings and split
        $contents = str_replace("\r\n", "\n", $contents);
        $lines = array_filter( explode("\n", $contents), function($l){ return $l !== ''; } );
        if (empty($lines)) {
            $this->redirect_csv_page(__('Empty file.', 'anniversaries-manager'));
        }

        // Handle optional UTF-8 BOM on first line
        $first = preg_replace('/^\xEF\xBB\xBF/', '', array_shift($lines));
        $headers = str_getcsv($first);
        $headers = array_map(function($h){ return strtolower(trim($h)); }, $headers);
        foreach (['name','date'] as $req) {
            if (!in_array($req, $headers, true)) {
                /* translators: %s: column name */
                $this->redirect_csv_page( sprintf( __('Missing required column: %s', 'anniversaries-manager'), $req ) );
            }
        }
        $hidx = array_flip($headers);

        $created = 0; $skipped = 0; $errors = 0;

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (!$row || (count($row) === 1 && trim($row[0]) === '')) continue;

            $name  = isset($hidx['name']) ? sanitize_text_field( $row[$hidx['name']] ?? '' ) : '';
            $date  = isset($hidx['date']) ? sanitize_text_field( $row[$hidx['date']] ?? '' ) : '';
            $notes = isset($hidx['notes']) ? wp_kses_post( $row[$hidx['notes']] ?? '' ) : '';
            $st    = isset($hidx['status']) ? strtolower( sanitize_text_field( $row[$hidx['status']] ?? '' ) ) : $default_status;
            if (!in_array($st, ['publish','pending','draft'], true)) $st = $default_status;

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
            if (!$name) { $skipped++; continue; }

            $title = $name; // keep as-is; admin can autotitle later
            $post_id = wp_insert_post([
                'post_type'   => self::CPT,
                'post_title'  => $title,
                'post_content'=> $notes,
                'post_status' => $st,
            ], true);

            if (is_wp_error($post_id) || !$post_id) { $errors++; continue; }

            update_post_meta($post_id, self::META_TYPE, 'anniversary');
            update_post_meta($post_id, self::META_DATE, $date);
            update_post_meta($post_id, self::META_LABEL, 'Anniversary');
            if ($notes) update_post_meta($post_id, self::META_NOTES, $notes);
            $created++;

            // Notify on import
            $this->send_new_entry_notification($post_id, 'csv');
        }

        $msg = sprintf(
            /* translators: 1: created count, 2: skipped count, 3: error count */
            __('Import complete: %1$d created, %2$d skipped, %3$d errors.', 'anniversaries-manager'),
            $created, $skipped, $errors
        );
        $this->redirect_csv_page($msg);
    }

    private function redirect_csv_page($msg) {
        $url = add_query_arg(['page'=>'abm_csv','abm_msg'=>rawurlencode($msg)], admin_url('edit.php?post_type='.self::CPT));
        wp_safe_redirect($url); exit;
    }

    public function render_notifications_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $saved = get_option(self::OPT_NOTIFY_EMAILS, '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag
        $notice = isset($_GET['updated']) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Notifications', 'anniversaries-manager'); ?></h1>
            <?php if ($notice === '1'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'anniversaries-manager'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('abm_save_notifications', 'abm_notify_nonce'); ?>
                <input type="hidden" name="action" value="abm_save_notifications">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="abm_notify_email"><?php esc_html_e('Notification emails', 'anniversaries-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="abm_notify_email" name="abm_notify_email" value="<?php echo esc_attr($saved); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            <p class="description">
                                <?php esc_html_e('Comma-separated list of emails to notify when a new entry is created. Falls back to the site admin email if left blank.', 'anniversaries-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Changes', 'anniversaries-manager')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_notifications() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $abm_notify_nonce = isset($_POST['abm_notify_nonce']) ? sanitize_text_field( wp_unslash( $_POST['abm_notify_nonce'] ) ) : '';
        if ( empty($abm_notify_nonce) || ! wp_verify_nonce( $abm_notify_nonce, 'abm_save_notifications' ) ) {
            wp_die(esc_html__('Bad nonce.', 'anniversaries-manager'));
        }
        $raw_input = isset($_POST['abm_notify_email']) ? sanitize_text_field( sanitize_text_field( wp_unslash( $_POST['abm_notify_email'] ) ) ) : '';


        $clean = $this->sanitize_email_list($raw_input);
    update_option(self::OPT_NOTIFY_EMAILS, $clean);

    wp_safe_redirect(
    add_query_arg(
        ['page' => 'abm_notifications', 'updated' => '1'],
        admin_url('edit.php?post_type=' . self::CPT)
        )
    );
    exit;
    }

    public function render_styles_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $enabled = (bool) get_option(self::OPT_ENABLE_DEFAULT_CSS, true);
        $custom  = (string) get_option(self::OPT_CUSTOM_CSS, '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Styles', 'anniversaries-manager'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('abm_save_styles', 'abm_styles_nonce'); ?>
                <input type="hidden" name="action" value="abm_save_styles">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Default CSS', 'anniversaries-manager'); ?></th>
                        <td>
                            <label><input type="checkbox" name="abm_enable_default_css" value="1" <?php checked($enabled); ?>> <?php esc_html_e('Enable plugin default styles', 'anniversaries-manager'); ?></label>
                            <p class="description"><?php esc_html_e('Uncheck to disable the plugin’s default front-end styles.', 'anniversaries-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Custom CSS', 'anniversaries-manager'); ?></th>
                        <td>
                            <textarea name="abm_custom_css" rows="10" class="large-text code" placeholder="<?php esc_attr_e('/* Your CSS that affects the list and calendar */', 'anniversaries-manager'); ?>"><?php echo esc_textarea($custom); ?></textarea>
                            <p class="description"><?php esc_html_e('Custom CSS is printed on the front-end (in the head).', 'anniversaries-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Styles', 'anniversaries-manager')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_styles() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'anniversaries-manager'));
        $abm_styles_nonce = isset($_POST['abm_styles_nonce']) ? sanitize_text_field( wp_unslash( $_POST['abm_styles_nonce'] ) ) : '';
        if ( empty($abm_styles_nonce) || ! wp_verify_nonce( $abm_styles_nonce, 'abm_save_styles' ) ) {
            wp_die(esc_html__('Bad nonce.', 'anniversaries-manager'));
        }
        $enabled = !empty($_POST['abm_enable_default_css']);
        $raw_css = isset($_POST['abm_custom_css'])
    ? sanitize_textarea_field( wp_unslash( $_POST['abm_custom_css'] ) )
    : '';

        update_option(self::OPT_ENABLE_DEFAULT_CSS, $enabled ? 1 : 0);
        update_option(self::OPT_CUSTOM_CSS, wp_kses_post($raw_css));
        wp_safe_redirect(add_query_arg(['page'=>'abm_styles','updated'=>'1'], admin_url('edit.php?post_type='.self::CPT)));
        exit;
    }

    /* =========================
     * Notifications
     * ========================= */
    private function sanitize_email_list($csv) {
        if (!$csv) return '';
        $emails = array_filter(array_map('trim', explode(',', $csv)));
        $valid  = [];
        foreach ($emails as $e) {
            $e = sanitize_email($e);
            if ($e && is_email($e)) $valid[] = $e;
        }
        return implode(', ', array_unique($valid));
    }

    public function maybe_notify_on_admin_create($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($update) return;
        if ($post->post_type !== self::CPT) return;

        // Suppress for admin-created drafts and published posts
        if (in_array(get_post_status($post_id), ['draft', 'publish'], true)) return;

        update_post_meta($post_id, self::META_TYPE, 'anniversary');

        $this->send_new_entry_notification($post_id, 'admin');
    }

    private function send_new_entry_notification($post_id, $source = 'form') {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::CPT) return;

        $title = get_the_title($post_id);
        $date  = get_post_meta($post_id, self::META_DATE, true);
        $notes = get_post_meta($post_id, self::META_NOTES, true);

        $status = get_post_status($post_id);
        $edit_link = get_edit_post_link($post_id, '');
        $approve_link = '';
        if ($status === 'pending') {
            $approve_link = wp_nonce_url(
                admin_url('admin.php?action=abm_approve&post_id='.$post_id),
                'abm_approve_'.$post_id
            );
        }

        $recipients = $this->get_notify_recipients();
        if (empty($recipients)) return;

        /* translators: %s: post title */
        $subject = sprintf( __('New Anniversary submitted: %s', 'anniversaries-manager'), $title );

        $lines = [];
        /* translators: %s: post title */
        $lines[] = sprintf(__('Title: %s', 'anniversaries-manager'), $title);
        /* translators: %s: date string */
        $lines[] = sprintf(__('Date: %s', 'anniversaries-manager'), $date ?: '—');
        if (!empty($notes)) {
            $lines[] = __('Notes:', 'anniversaries-manager');
            $lines[] = wp_strip_all_tags($notes);
        }
        /* translators: %s: post status */
        $lines[] = sprintf(__('Status: %s', 'anniversaries-manager'), $status);
        if ($edit_link)  { /* translators: %s: edit URL */ $lines[] = sprintf( __('Edit: %s', 'anniversaries-manager'), $edit_link); }
        if ($approve_link) { /* translators: %s: approve URL */ $lines[] = sprintf( __('Approve: %s', 'anniversaries-manager'), $approve_link); }

        $body = implode("\n", $lines);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        foreach ($recipients as $to) {
            wp_mail($to, $subject, $body, $headers);
        }
    }

    private function get_notify_recipients() {
        $configured = get_option(self::OPT_NOTIFY_EMAILS, '');
        if ($configured) {
            $emails = array_filter(array_map('trim', explode(',', $configured)));
        } else {
            $emails = [ get_option('admin_email') ];
        }
        $emails = array_values(array_filter($emails, function($e){ return is_email($e); }));
        return $emails;
    }

    /* =========================
     * Utilities
     * ========================= */
    private function next_occurrence($ymd) {
        // Work in site timezone
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
        if (!$d) return 0;
        $candidate = $d->setDate( (int)$now->format('Y'), (int)$d->format('m'), (int)$d->format('d') );
        if ($candidate->format('Y-m-d') < $now->format('Y-m-d')) {
            $candidate = $candidate->modify('+1 year');
        }
        return $candidate->getTimestamp();
    }

    private function years_since($ymd, $asof_year = null) {
        $d = DateTime::createFromFormat('Y-m-d', $ymd, wp_timezone()); if (!$d) return 0;
        $y0 = intval($d->format('Y'));
        $asof_year = $asof_year ?: intval( wp_date('Y', current_time('timestamp')) );
        return max(0, $asof_year - $y0);
    }

    private function abm_nav_url($args) {
        $base = remove_query_arg(['abm_month','abm_year']);
        return add_query_arg(array_map('rawurlencode', $args), $base);
    }

    public function output_styles() {
        $enable_default = (bool) get_option(self::OPT_ENABLE_DEFAULT_CSS, true);
        $custom = (string) get_option(self::OPT_CUSTOM_CSS, '');

        if ($enable_default): ?>
        <style id="abm-inline-css">
            .abm-form label{display:block;font-weight:600;margin-bottom:4px}
            .abm-form input[type="text"],.abm-form input[type="date"],.abm-form textarea,.abm-form select{width:100%;max-width:480px;padding:.5rem;border:1px solid #ccc;border-radius:6px}
            .abm-form .abm-req{color:#c00;margin-left:4px}
            .abm-form .abm-btn{background:#2271b1;color:#fff;border:none;border-radius:6px;padding:.6rem 1rem;cursor:pointer}
            .abm-msg{padding:.6rem 1rem;border-radius:6px;margin:.5rem 0}
            .abm-success{background:#e7f7ec;border:1px solid #8ad4a4}
            .abm-error{background:#fdecec;border:1px solid #f3a3a3}

            .abm-table{width:100%;border-collapse:collapse}
            .abm-table th,.abm-table td{padding:.5rem;border-bottom:1px solid #eee;text-align:left}
            .abm-table th{font-weight:700}

            .abm-calendar{--gap:.5rem}
            .abm-calendar-header{margin-bottom:.5rem}
            .abm-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:var(--gap)}
            .abm-cal-cell{border:1px solid #eee;min-height:90px;padding:.25rem;border-radius:6px;position:relative}
            .abm-cal-head{font-weight:700;background:#fafafa}
            .abm-cal-title{font-size:1.1em;}
            .abm-cal-empty{background:#fff;border:none}
            .abm-cal-daynum{position:absolute;top:.25rem;right:.35rem;font-size:.8rem;color:#666}
            .abm-cal-list{margin:.8rem 0 0 .5rem;padding:0;list-style:disc}

            .abm-cal-nav{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:center;margin:.25rem 0 .75rem}
            .abm-cal-title{min-width:160px;text-align:center}
            .abm-cal-btn{display:inline-block;padding:.35rem .6rem;border:1px solid #ddd;border-radius:6px;text-decoration:none}
            .abm-cal-btn:hover{background:#f6f6f6}
            .abm-cal-today{margin-left:.5rem}

            @media(max-width:640px){
                .abm-calendar-grid{gap:.25rem}
                .abm-cal-cell{min-height:70px}
            }
        </style>
        <?php endif;

        if (!empty($custom)): ?>
            <style id="abm-custom-css">
                <?php echo wp_kses_post( $custom ); ?>
            </style>
        <?php endif;
    }
}

new ABM_Plugin();
