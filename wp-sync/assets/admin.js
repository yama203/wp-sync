/* global wpsAdmin */
( function () {
	'use strict';

	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var info = null;
	var running = false;

	function request( action, data ) {
		var body = new FormData();
		body.append( 'action', 'wps_' + action );
		body.append( '_ajax_nonce', wpsAdmin.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( wpsAdmin.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var json;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						throw new Error( 'サーバーの応答を読めませんでした（HTTP ' + res.status + '）: ' + text.replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 300 ) );
					}
					if ( ! json.success ) {
						throw new Error( ( json.data && json.data.message ) || 'エラーが起きました。' );
					}
					return json.data;
				} );
			} );
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function direction() {
		return document.querySelector( 'input[name="wps-direction"]:checked' ).value;
	}

	function notice( target, message, type ) {
		target.innerHTML = '';
		if ( message ) {
			var box = el( 'div', { class: 'notice notice-' + ( type || 'error' ) + ' inline' } );
			box.appendChild( el( 'p', {}, message ) );
			target.appendChild( box );
		}
	}

	function render() {
		if ( ! info ) {
			return;
		}
		var r = info.remote;
		$( 'wps-remote-home' ).textContent = r.name ? r.name + '（' + r.home + '）' : r.home;
		$( 'wps-remote-meta' ).textContent =
			'WP ' + r.wp + ' / PHP ' + r.php + ' / DB ' + r.db + ' / テーブル ' + r.size.tables + ' 個（' + r.size.bytes + '）' +
			( r.url !== r.home ? ' / 接続 URL: ' + r.url : '' );

		var dir = direction();
		var warnings = [];
		if ( ! r.compatible ) {
			warnings.push( '接続先の WP Sync（' + r.version + '）とこのサイトのバージョンが合いません。両方を同じバージョンにしてください。' );
		}
		if ( r.multisite ) {
			warnings.push( '接続先はマルチサイトです。マルチサイトにはまだ対応していません。' );
		}
		if ( dir === 'pull' && ! r.allow_pull ) {
			warnings.push( '接続先で pull が許可されていません。接続先の「ツール → WP Sync」で許可してください。' );
		}
		if ( dir === 'push' && ! r.allow_push ) {
			warnings.push( '接続先で push が許可されていません。接続先の「ツール → WP Sync」で「このサイトへの push を許可」をオンにしてください。' );
		}
		notice( $( 'wps-remote-warning' ), warnings.join( ' ' ), 'warning' );

		var auto = $( 'wps-pairs-auto' );
		auto.innerHTML = '';
		info.pairs[ dir ].forEach( function ( pair ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', { class: 'code' }, pair[ 0 ] ) );
			tr.appendChild( el( 'td', {}, '→' ) );
			tr.appendChild( el( 'td', { class: 'code' }, pair[ 1 ] ) );
			tr.appendChild( el( 'td', { class: 'description' }, '自動' ) );
			auto.appendChild( tr );
		} );

		$( 'wps-start' ).textContent = dir === 'pull' ? 'Pull を始める' : 'Push を始める';
		$( 'wps-start' ).disabled = running || warnings.length > 0;

		renderBackup( 'local', info.local.backup );
		renderBackup( 'remote', r.backup );
		document.querySelector( '[data-restore="remote"]' ).disabled = running || ! r.backup || ! r.allow_push;
	}

	function renderBackup( target, backup ) {
		var cell = $( 'wps-backup-' + target );
		var button = document.querySelector( '[data-restore="' + target + '"]' );
		if ( ! backup ) {
			cell.textContent = 'バックアップはありません';
			button.disabled = true;
			return;
		}
		cell.textContent = ( backup.time || '日時不明' ) + ' に取り込む前の DB' + ( backup.from ? '（' + backup.from + ' から取り込み）' : '' );
		button.disabled = running;
	}

	function load() {
		notice( $( 'wps-remote-warning' ), '' );
		return request( 'info' )
			.then( function ( data ) {
				info = data;
				render();
			} )
			.catch( function ( err ) {
				notice( $( 'wps-remote-warning' ), err.message );
				$( 'wps-start' ).disabled = true;
			} );
	}

	function addPair( from, to ) {
		var tr = el( 'tr' );
		var a = el( 'input', { type: 'text', class: 'regular-text code', placeholder: '置き換える前' } );
		var b = el( 'input', { type: 'text', class: 'regular-text code', placeholder: '置き換えた後' } );
		a.value = from || '';
		b.value = to || '';
		var remove = el( 'button', { type: 'button', class: 'button-link button-link-delete' }, '削除' );
		remove.addEventListener( 'click', function () {
			tr.remove();
		} );
		[ a, el( 'span', {}, '→' ), b, remove ].forEach( function ( node ) {
			var td = el( 'td' );
			td.appendChild( node );
			tr.appendChild( td );
		} );
		$( 'wps-pairs-extra' ).appendChild( tr );
	}

	function extraPairs() {
		var pairs = [];
		$( 'wps-pairs-extra' ).querySelectorAll( 'tr' ).forEach( function ( tr ) {
			var inputs = tr.querySelectorAll( 'input' );
			if ( inputs[ 0 ].value.trim() ) {
				pairs.push( [ inputs[ 0 ].value, inputs[ 1 ].value ] );
			}
		} );
		return pairs;
	}

	function log( message, cls ) {
		$( 'wps-log' ).appendChild( el( 'li', cls ? { class: cls } : {}, message ) );
	}

	function show( status ) {
		$( 'wps-progress-label' ).textContent = '（' + status.step + ' / ' + status.steps + '）' + status.label;
		$( 'wps-progress-detail' ).textContent = status.detail;
		$( 'wps-bar-fill' ).style.width = status.percent + '%';
		status.log.forEach( function ( line ) {
			log( line );
		} );
	}

	function setRunning( value ) {
		running = value;
		document.querySelectorAll( '.wps-direction input, .wps-what input, .wps-pairs input, .wps-pairs button, #wps-start' ).forEach( function ( node ) {
			node.disabled = value;
		} );
		if ( ! value ) {
			render();
		}
	}

	function finish( status ) {
		setRunning( false );
		$( 'wps-bar-fill' ).classList.add( 'is-done' );
		if ( status.skipped ) {
			log( status.skipped + ' 件のファイルは読めなかったので飛ばしました。', 'is-warning' );
		}
		if ( status.relogin ) {
			log( 'このサイトの DB を入れ替えたので、ログインし直してください（ユーザーとパスワードは接続先のものになっています）。', 'is-warning' );
			var link = el( 'a', { href: wpsAdmin.login, class: 'button button-primary' }, 'ログインし直す' );
			var li = el( 'li' );
			li.appendChild( link );
			$( 'wps-log' ).appendChild( li );
			return;
		}
		load();
	}

	function fail( err ) {
		setRunning( false );
		$( 'wps-bar-fill' ).classList.add( 'is-error' );
		log( 'エラー: ' + err.message, 'is-error' );
		log( '途中で止まりました。DB は入れ替える前なら元のままです。もう一度始めると最初からやり直します。', 'is-warning' );
	}

	function step( id ) {
		return request( 'step', { id: id } ).then( function ( status ) {
			show( status );
			if ( status.done ) {
				finish( status );
				return;
			}
			return step( id );
		} );
	}

	function start() {
		var dir = direction();
		var db = $( 'wps-db' ).checked;
		var media = $( 'wps-media' ).checked;
		if ( ! db && ! media ) {
			window.alert( 'データベースとメディアのどちらかを選んでください。' );
			return;
		}
		var target = dir === 'pull' ? 'このサイト（' + info.local.home + '）' : '接続先（' + info.remote.home + '）';
		var what = [ db ? 'データベース' : null, media ? 'メディア' : null ].filter( Boolean ).join( 'と' );
		var message = target + 'の' + what + 'を上書きします。';
		if ( db && dir === 'pull' ) {
			message += '\n終わったら、接続先のユーザーでログインし直すことになります。';
		}
		if ( ! window.confirm( message + '\nよろしいですか？' ) ) {
			return;
		}

		$( 'wps-progress' ).hidden = false;
		$( 'wps-log' ).innerHTML = '';
		$( 'wps-bar-fill' ).className = 'wps-bar-fill';
		$( 'wps-bar-fill' ).style.width = '0';
		setRunning( true );

		request( 'start', {
			direction: dir,
			db: db ? '1' : '0',
			media: media ? '1' : '0',
			media_delete: $( 'wps-media-delete' ).checked ? '1' : '0',
			keep_active_plugins: $( 'wps-keep-active' ).checked ? '1' : '0',
			pairs: JSON.stringify( extraPairs() ),
		} )
			.then( function ( status ) {
				show( status );
				return step( status.id );
			} )
			.catch( fail );
	}

	function restore( target ) {
		var name = target === 'local' ? 'このサイト' : '接続先';
		if ( ! window.confirm( name + 'の DB を、直前に取り込む前の状態に戻します。よろしいですか？' + ( target === 'local' ? '\n戻したら、ログインし直すことがあります。' : '' ) ) ) {
			return;
		}
		setRunning( true );
		request( 'restore', { target: target } )
			.then( function ( result ) {
				setRunning( false );
				window.alert( name + 'の DB を戻しました（' + result.tables + ' 個のテーブル）。' );
				if ( target === 'local' ) {
					window.location.reload();
					return;
				}
				load();
			} )
			.catch( function ( err ) {
				setRunning( false );
				window.alert( 'エラー: ' + err.message );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var copy = $( 'wps-copy-key' );
		copy.addEventListener( 'click', function () {
			var key = $( 'wps-own-key' );
			key.select();
			( navigator.clipboard ? navigator.clipboard.writeText( key.value ) : Promise.reject() )
				.catch( function () {
					document.execCommand( 'copy' );
				} )
				.then( function () {
					copy.textContent = 'コピーしました';
					setTimeout( function () {
						copy.textContent = 'コピー';
					}, 2000 );
				} );
		} );

		var connect = $( 'wps-connect-button' );
		connect.addEventListener( 'click', function () {
			connect.disabled = true;
			request( 'connect', { key: $( 'wps-key' ).value, url: $( 'wps-url' ).value } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( err ) {
					connect.disabled = false;
					window.alert( err.message );
				} );
		} );

		document.querySelectorAll( 'input[name="wps-direction"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', render );
		} );
		$( 'wps-media' ).addEventListener( 'change', function () {
			$( 'wps-media-delete' ).disabled = ! this.checked;
		} );
		$( 'wps-db' ).addEventListener( 'change', function () {
			$( 'wps-keep-active' ).disabled = ! this.checked;
		} );
		// 「プラグインの設定は送らない」は前回の選び方を覚えておく。
		var keepActive = $( 'wps-keep-active' );
		try {
			if ( window.localStorage.getItem( 'wpsKeepActive' ) === '0' ) {
				keepActive.checked = false;
			}
		} catch ( e ) {}
		keepActive.addEventListener( 'change', function () {
			try {
				window.localStorage.setItem( 'wpsKeepActive', keepActive.checked ? '1' : '0' );
			} catch ( e ) {}
		} );
		$( 'wps-add-pair' ).addEventListener( 'click', function () {
			addPair();
		} );
		$( 'wps-start' ).addEventListener( 'click', start );
		document.querySelectorAll( '[data-restore]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				restore( button.getAttribute( 'data-restore' ) );
			} );
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		if ( wpsAdmin.connected ) {
			load();
		}
	} );
} )();
