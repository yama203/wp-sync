<?php
// テスト用: wp eval-file tests/check.php で、同期した結果を表示する。
global $wpdb;
$p = $wpdb->prefix;
$out = array(
	'home'      => get_option( 'home' ),
	'blogname'  => get_option( 'blogname' ),
	't_serial'  => get_option( 't_serial' ),
	'blob1'     => bin2hex( (string) $wpdb->get_var( "SELECT data FROM {$p}t_blob WHERE a=1" ) ),
	'blob2'     => $wpdb->get_var( "SELECT data FROM {$p}t_blob WHERE a=2" ),
	'nopk'      => $wpdb->get_var( "SELECT COUNT(*) FROM {$p}t_nopk" ) . ' / ' . $wpdb->get_var( "SELECT v FROM {$p}t_nopk WHERE v LIKE '%1199%'" ),
	'posts'     => $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts" ),
	'bulk_urls' => substr_count( (string) $wpdb->get_var( "SELECT post_content FROM {$p}posts WHERE post_title='bulk 5'" ), parse_url( home_url(), PHP_URL_HOST ) . ':' . parse_url( home_url(), PHP_URL_PORT ) ),
	'bulk_date' => $wpdb->get_var( "SELECT post_date FROM {$p}posts WHERE post_title='bulk 5'" ),
	'backup'    => WPS_DB::backup_info(),
	'remote'    => get_option( 'wps_remote' ) ? get_option( 'wps_remote' )['home'] : null,
	'allow'     => array( WPS_Settings::allow_pull(), WPS_Settings::allow_push() ),
	'tables'    => implode( ',', $wpdb->get_col( 'SHOW TABLES' ) ),
	'admin_caps'=> get_userdata( 1 ) ? implode( ',', get_userdata( 1 )->roles ) : null,
);
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
