<?php
/**
 * SEAL_Page_status_confirmation — /status-confirmation: suspend or reactivate lending for a whole library
 * system at once (capability seal_status; system-wide changes are limited as below).
 *
 * task=suspend (with enddate, default one week) or task=activate, for system=<code>. Administrators may do this
 * for any system; Library System Staff only for their own home system (the posted system is never trusted).
 * Shows a confirmation step first, then updates suspend / SuspendDateEnd on every library in that system.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_status_confirmation extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db      = SEAL_DB::get();
        $lib_tbl = SEAL_DB::$sealLIB;

        $task    = sanitize_key( wp_unslash( $_REQUEST['task']    ?? '' ) );
        $proceed = sanitize_text_field( wp_unslash( $_REQUEST['proceed'] ?? '' ) );
        $enddate = sanitize_text_field( wp_unslash( $_REQUEST['enddate'] ?? '' ) );
        $system  = sanitize_text_field( wp_unslash( $_REQUEST['system']  ?? '' ) );

        if ( ! in_array($task, ['suspend','activate'], true) ) { $task = ''; }

        // Normalize enddate
        if ( $task === 'suspend' && ( $enddate === '' || strlen($enddate) < 2 ) ) {
            $enddate = date('Y-m-d', strtotime('+7 day'));
        } elseif ( $enddate !== '' ) {
            $ts = strtotime( str_replace('-','/',$enddate) );
            $enddate = $ts ? date('Y-m-d',$ts) : date('Y-m-d',strtotime('+7 day'));
        }

        $sys_names = SEAL_Functions::system_options();
        $displaysystem = $sys_names[$system] ?? $system;

        // Suspending/activating a whole system: administrators for any system, library-system staff
        // for their own home system only (never trust the posted system name).
        if ( $system !== '' && ! SEAL_Roles::is_admin() ) {
            $home = (string) get_user_meta( $this->user->ID, 'home_system', true );
            if ( ! current_user_can( 'seal_adminlib_ls' ) || $home === '' || strcasecmp( $home, $system ) !== 0 ) {
                $this->alert( 'You can only suspend or activate libraries in your own library system.', 'error' );
                return;
            }
        }

        if ( $system === '' || $task === '' ) {
            $this->alert('Sorry — cannot complete this action.','error');
            return;
        }

        if ( $proceed === 'Proceed' ) {
            // Execute the update
            $this->verify_nonce('seal_status_confirm');
            $mod_email = esc_html( $this->user->user_email );
            $ts        = current_time('mysql');

            // participant / suspend are 1/0 numbers on current tables but 'Yes'/'No' text on older ones; CAST AS CHAR
            // matches both (comparing a number column with 'Yes' or a text column with '0' silently matches nothing).
            $is_part = "CAST(`participant` AS CHAR) IN ('1','Yes')";
            $is_susp = "CAST(`suspend` AS CHAR) IN ('1','Yes')";
            $where   = "`loc`<>'' AND {$is_part} AND " . ( $task === 'suspend' ? "NOT IFNULL({$is_susp}, 0)" : $is_susp ) . ' AND `system`=%s';
            $sets    = [ 'suspend' => $task === 'suspend' ? '1' : '0', 'ModifyDate' => $ts, 'ModEmail' => $mod_email ];
            if ( $task === 'suspend' ) { $sets['SuspendDateEnd'] = $enddate; }
            // Only the columns this install's library table has: one unknown column fails the whole UPDATE.
            $result = SEAL_DB::update_existing( $lib_tbl, $sets, $where, $system );

            if ( $result !== false ) {
                $count = SEAL_DB::affected_rows();
                $verb  = $task === 'suspend' ? 'suspended' : 'activated';
                $this->alert( "Libraries in <strong>" . esc_html($displaysystem) . "</strong> have been <strong>{$verb}</strong>. Affected: {$count} libraries.", 'success' );
                echo '<p><a href="' . esc_url(home_url('/adminlib-ls')) . '" class="seal-btn-primary">Back to Library Admin</a></p>';
            } else {
                $why = current_user_can( 'seal_admin' ) ? ' Database said: ' . esc_html( SEAL_DB::last_error() ) : '';   // detail for administrators only
                $this->alert( 'Update failed. Please try again.' . $why, 'error' );
            }
        } else {
            // Confirmation step
            $verb = $task === 'suspend' ? 'suspend' : 'activate';
            echo '<div class="seal-page-wrap">';
            echo '<h3>Confirm: ' . esc_html(ucfirst($verb)) . ' Lending</h3>';
            echo '<p>You have chosen to <strong>' . esc_html($verb) . ' lending</strong> for all libraries in <strong>' . esc_html($displaysystem) . '</strong>.</p>';
            echo '<p>This will overwrite the setting for all libraries in this system. Are you sure?</p>';
            if ( $task === 'suspend' ) {
                echo '<p>Suspend until: <strong>' . esc_html($enddate) . '</strong></p>';
            }
            ?>
            <form method="post" action="<?php echo esc_url(home_url('/status-confirmation')); ?>">
                <?php $this->nonce_field('seal_status_confirm'); ?>
                <input type="hidden" name="task"    value="<?php echo esc_attr($task);    ?>">
                <input type="hidden" name="system"  value="<?php echo esc_attr($system);  ?>">
                <input type="hidden" name="enddate" value="<?php echo esc_attr($enddate); ?>">
                <button type="submit" name="proceed" value="Proceed" class="seal-btn-danger">Proceed</button>
                <a href="<?php echo esc_url(home_url('/adminlib-ls')); ?>" class="seal-btn-secondary" style="margin-left:10px;">Cancel</a>
            </form>
            <?php
            echo '</div>';
        }
    }
}
