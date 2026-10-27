<?php
// テスト用。もう片方のコンテナから host.docker.internal で来た要求を、このネットワークのドメイン（localhost:ポート）として扱う。
if ( isset( $_SERVER['HTTP_HOST'] ) && 0 === strpos( $_SERVER['HTTP_HOST'], 'host.docker.internal:' ) ) {
	$_SERVER['HTTP_HOST'] = 'localhost:' . substr( $_SERVER['HTTP_HOST'], strlen( 'host.docker.internal:' ) );
}
