<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$ota_labels = [
    'getyourguide' => 'GetYourGuide',
    'viator'       => 'Viator',
    'gowithguide'  => 'GoWithGuide',
];

$edit_feed = null;
if ( $edit_id ) {
    global $wpdb;
    $edit_feed = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ota_sync_feeds WHERE id = %d", $edit_id
    ) );
}
?>
<div class="wrap">
    <h1><?php _e( 'OTA Calendar Sync', 'ota-calendar-sync' ); ?></h1>

    <?php if ( ! $bookly_active ): ?>
    <div class="notice notice-error">
        <p><?php _e( '<strong>A Bookly plugin nem aktív.</strong> Telepítsd és aktiváld a Bookly-t a folytatáshoz.', 'ota-calendar-sync' ); ?></p>
    </div>
    <?php endif; ?>

    <?php if ( ! $wpcron_ok ): ?>
    <div class="notice notice-warning">
        <p><?php _e( '<strong>Ajánlott:</strong> A WP-Cron nem megbízható alacsony forgalmú oldalakon. Adj hozzá valódi rendszer cront a megbízható 30 perces szinkronhoz:', 'ota-calendar-sync' ); ?></p>
        <code>*/30 * * * * wget -q -O - <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?></code>
        <p><?php _e( 'Majd add hozzá a wp-config.php-hoz: <code>define(\'DISABLE_WP_CRON\', true);</code>', 'ota-calendar-sync' ); ?></p>
    </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['saved'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Feed elmentve.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Feed törölve.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['synced'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Szinkron kész.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['enabled'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Feed újra engedélyezve.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['ota_error'] ) ): ?><div class="notice notice-error"><p><?php echo esc_html( urldecode( $_GET['ota_error'] ) ); ?></p></div><?php endif; ?>

    <h2><?php echo $edit_id ? __( 'Feed szerkesztése', 'ota-calendar-sync' ) : __( 'Feed hozzáadása', 'ota-calendar-sync' ); ?></h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action"  value="ota_sync_save_feed">
        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $edit_id ); ?>">
        <?php wp_nonce_field( 'ota_sync_save_feed' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="ota_name"><?php _e( 'OTA platform', 'ota-calendar-sync' ); ?></label></th>
                <td>
                    <select name="ota_name" id="ota_name" required>
                        <?php foreach ( $ota_labels as $val => $label ): ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $edit_feed->ota_name ?? '', $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ical_url"><?php _e( 'iCal URL', 'ota-calendar-sync' ); ?></label></th>
                <td>
                    <input type="url" name="ical_url" id="ical_url" class="large-text" required
                           placeholder="https://..."
                           value="<?php echo esc_attr( $edit_feed->ical_url ?? '' ); ?>">
                    <p class="description"><?php _e( 'Csak HTTPS. Az OTA admin felületén találod a naptár export menüpontban.', 'ota-calendar-sync' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bookly_staff_id"><?php _e( 'Munkatárs (Bookly)', 'ota-calendar-sync' ); ?></label></th>
                <td>
                    <select name="bookly_staff_id" id="bookly_staff_id" required>
                        <option value=""><?php _e( '— Válassz munkatársat —', 'ota-calendar-sync' ); ?></option>
                        <?php foreach ( $staff as $s ): ?>
                        <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( (int)($edit_feed->bookly_staff_id ?? 0), $s['id'] ); ?>>
                            <?php echo esc_html( $s['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e( 'Melyik munkatárs naptárát blokkolja ez a feed?', 'ota-calendar-sync' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bookly_service_id"><?php _e( 'Szolgáltatás (opcionális)', 'ota-calendar-sync' ); ?></label></th>
                <td>
                    <select name="bookly_service_id" id="bookly_service_id">
                        <option value="0"><?php _e( '— Automatikus (OTA Sync Block) —', 'ota-calendar-sync' ); ?></option>
                        <?php foreach ( $services as $svc ): ?>
                        <option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( (int)($edit_feed->bookly_service_id ?? 0), $svc['id'] ); ?>>
                            <?php echo esc_html( $svc['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e( 'Ha üresen hagyod, egy belső "OTA Sync Block" szolgáltatással blokkolódik az időpont.', 'ota-calendar-sync' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="label"><?php _e( 'Cimke', 'ota-calendar-sync' ); ?></label></th>
                <td>
                    <input type="text" name="label" id="label" class="regular-text"
                           placeholder="<?php esc_attr_e( 'pl. GYG - City Tour', 'ota-calendar-sync' ); ?>"
                           value="<?php echo esc_attr( $edit_feed->label ?? '' ); ?>">
                </td>
            </tr>
        </table>

        <?php submit_button( $edit_id ? __( 'Feed frissítése', 'ota-calendar-sync' ) : __( 'Feed hozzáadása', 'ota-calendar-sync' ) ); ?>
        <?php if ( $edit_id ): ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ota-calendar-sync' ) ); ?>" class="button"><?php _e( 'Mégse', 'ota-calendar-sync' ); ?></a>
        <?php endif; ?>
    </form>

    <hr>

    <h2 style="display:flex;align-items:center;gap:12px;">
        <?php _e( 'Aktív feedek', 'ota-calendar-sync' ); ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
            <input type="hidden" name="action" value="ota_sync_now">
            <?php wp_nonce_field( 'ota_sync_now' ); ?>
            <?php submit_button( __( 'Szinkronizálás most', 'ota-calendar-sync' ), 'secondary small', 'submit', false ); ?>
        </form>
    </h2>

    <?php if ( empty( $feeds ) ): ?>
    <p><?php _e( 'Még nincs feed beállítva. Adj hozzá egyet fent.', 'ota-calendar-sync' ); ?></p>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e( 'OTA', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Cimke', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Munkatárs ID', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Utolsó szinkron', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Státusz', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Műveletek', 'ota-calendar-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $feeds as $feed ): ?>
        <?php
            $disabled     = ! $feed->enabled;
            $status_icon  = $feed->last_status === 'ok' ? '✅' : ( $feed->last_status === 'error' ? '❌' : '⏳' );
            $last_sync_str = $feed->last_sync
                ? human_time_diff( strtotime( $feed->last_sync ) ) . ' ' . __( 'ezelőtt', 'ota-calendar-sync' )
                : '—';
        ?>
        <tr<?php if ( $disabled ) echo ' style="opacity:0.55"'; ?>>
            <td><?php echo esc_html( $ota_labels[ $feed->ota_name ] ?? $feed->ota_name ); ?></td>
            <td><?php echo esc_html( $feed->label ?: '—' ); ?></td>
            <td><?php echo esc_html( $feed->bookly_staff_id ); ?></td>
            <td><?php echo esc_html( $last_sync_str ); ?></td>
            <td>
                <?php echo $status_icon . ' ' . esc_html( $feed->last_message ?? '—' ); ?>
                <?php if ( $disabled ) echo ' <strong>(letiltva)</strong>'; ?>
            </td>
            <td>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ota-calendar-sync&edit=' . $feed->id ) ); ?>">
                    <?php _e( 'Szerkesztés', 'ota-calendar-sync' ); ?>
                </a>
                <?php if ( $disabled ): ?>
                &nbsp;|&nbsp;
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action"  value="ota_sync_enable_feed">
                    <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed->id ); ?>">
                    <?php wp_nonce_field( 'ota_sync_enable_feed' ); ?>
                    <button type="submit" class="button-link"><?php _e( 'Újraengedélyezés', 'ota-calendar-sync' ); ?></button>
                </form>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                      onsubmit="return confirm('<?php esc_attr_e( 'Biztosan törlöd ezt a feedet és az összes blokkját?', 'ota-calendar-sync' ); ?>')">
                    <input type="hidden" name="action"  value="ota_sync_delete_feed">
                    <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed->id ); ?>">
                    <?php wp_nonce_field( 'ota_sync_delete_feed' ); ?>
                    <button type="submit" class="button-link" style="color:#a00"><?php _e( 'Törlés', 'ota-calendar-sync' ); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
