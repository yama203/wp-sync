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
		return fetch( wpsAdmin.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( res ) {
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

	function notice( target, message, type ) {
		target.innerHTML = '';
		if ( message ) {
			var box = el( 'div', { class: 'notice notice-' + ( type || 'error' ) + ' inline' } );
			box.appendChild( el( 'p', {}, message ) );
			target.appendChild( box );
		}
		return target.firstChild;
	}

	/* ---------- タブ ---------- */

	function initTabs() {
		document.querySelectorAll( '.wps-tabs .nav-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var name = tab.getAttribute( 'data-tab' );
				document.querySelectorAll( '.wps-tabs .nav-tab' ).forEach( function ( t ) {
					t.classList.toggle( 'nav-tab-active', t === tab );
				} );
				document.querySelectorAll( '.wps-panel' ).forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-panel' ) !== name;
				} );
				window.history.replaceState( null, '', tab.href );
			} );
		} );
	}

	/* ---------- 同期の画面 ---------- */

	function direction() {
		return document.querySelector( 'input[name="wps-direction"]:checked' ).value;
	}

	function siteBox( id, role, isDest, name, meta ) {
		var box = $( id );
		box.classList.toggle( 'is-dest', isDest );
		box.querySelector( '.wps-role' ).textContent = role;
		if ( name !== null ) {
			box.querySelector( '.wps-site-name' ).textContent = name;
		}
		box.querySelector( '.wps-site-meta' ).textContent = meta;
	}

	function warnings() {
		var r = info.remote;
		var list = [];
		if ( ! r.compatible ) {
			list.push( '接続先の WP Sync（' + r.version + '）とこのサイト（' + wpsAdmin.version + '）のバージョンが合いません。両方を同じバージョンにしてください。' );
		}
		if ( r.multisite ) {
			list.push( '接続先はマルチサイトです。マルチサイトにはまだ対応していません。' );
		}
		if ( direction() === 'pull' && ! r.allow_pull ) {
			list.push( '接続先で pull が許可されていません。接続先の「ツール → WP Sync → このサイトの設定」で許可してください。' );
		}
		if ( direction() === 'push' && ! r.allow_push ) {
			list.push( '接続先で push が許可されていません。接続先の「ツール → WP Sync → このサイトの設定」で「このサイトへ push するのを許可する」をオンにしてください。' );
		}
		return list;
	}

	function syncOptions() {
		[ [ 'wps-db', 'wps-keep-active' ], [ 'wps-media', 'wps-media-delete' ] ].forEach( function ( pair ) {
			var sub = $( pair[ 1 ] );
			sub.disabled = running || ! $( pair[ 0 ] ).checked;
			sub.closest( '.wps-option' ).classList.toggle( 'is-disabled', sub.disabled );
		} );
	}

	function summary() {
		var db = $( 'wps-db' ).checked;
		var media = $( 'wps-media' ).checked;
		var target = $( 'wps-summary' );
		target.innerHTML = '';
		if ( ! db && ! media ) {
			target.textContent = 'データベースとメディアのどちらかを選んでください。';
			return;
		}
		var pull = direction() === 'pull';
		var what = [ db ? 'データベース' : null, media ? 'メディア' : null ].filter( Boolean ).join( 'と' );
		target.appendChild( el( 'strong', {}, pull ? '接続先' : 'このサイト' ) );
		target.appendChild( document.createTextNode( 'の' + what + 'で、' ) );
		target.appendChild( el( 'strong', {}, pull ? 'このサイト' : '接続先' ) );
		target.appendChild( document.createTextNode( 'を上書きします。' ) );
	}

	function render() {
		if ( ! info ) {
			return;
		}
		var r = info.remote;
		var pull = direction() === 'pull';

		siteBox( 'wps-site-local', pull ? '送り先・上書きされます' : '送り元', pull, info.local.name || null,
			'テーブル ' + info.local.size.tables + ' 個（' + info.local.size.bytes + '）' );
		siteBox( 'wps-site-remote', pull ? '送り元' : '送り先・上書きされます', ! pull, r.name || r.home,
			'WP ' + r.wp + ' · PHP ' + r.php + ' · テーブル ' + r.size.tables + ' 個（' + r.size.bytes + '）' );
		document.querySelector( '.wps-flow .dashicons' ).className = 'dashicons ' + ( pull ? 'dashicons-arrow-left-alt' : 'dashicons-arrow-right-alt' );

		var list = warnings();
		notice( $( 'wps-remote-warning' ), list.join( ' ' ), 'warning' );

		var auto = $( 'wps-pairs-auto' );
		auto.innerHTML = '';
		info.pairs[ direction() ].forEach( function ( pair ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', {}, pair[ 0 ] ) );
			tr.appendChild( el( 'td', { class: 'wps-arrow' }, '→' ) );
			tr.appendChild( el( 'td', {}, pair[ 1 ] ) );
			tr.appendChild( el( 'td', {} ) ).appendChild( el( 'span', { class: 'wps-tag' }, '自動' ) );
			auto.appendChild( tr );
		} );
		countPairs();

		$( 'wps-start' ).textContent = pull ? 'Pull を始める' : 'Push を始める';
		$( 'wps-start' ).disabled = running || list.length > 0 || ( ! $( 'wps-db' ).checked && ! $( 'wps-media' ).checked );
		syncOptions();
		summary();

		renderBackup( 'local', info.local.backup, true );
		renderBackup( 'remote', r.backup, r.allow_push );
	}

	function countPairs() {
		var extra = $( 'wps-pairs-extra' ).querySelectorAll( 'tr' ).length;
		var auto = info ? info.pairs[ direction() ].length : 0;
		$( 'wps-pairs-count' ).textContent = '自動 ' + auto + ' 件' + ( extra ? '・追加 ' + extra + ' 件' : '' );
	}

	function renderBackup( target, backup, allowed ) {
		var cell = $( 'wps-backup-' + target );
		var button = document.querySelector( '[data-restore="' + target + '"]' );
		if ( ! backup ) {
			cell.textContent = 'バックアップはありません';
			button.disabled = true;
			return;
		}
		cell.textContent = ( backup.time || '日時不明' ) + ' に取り込む前の DB' + ( backup.from ? '（' + backup.from + ' から取り込み）' : '' );
		button.disabled = running || ! allowed;
		button.title = allowed ? '' : '接続先で push が許可されていないので戻せません';
	}

	function load() {
		return request( 'info' )
			.then( function ( data ) {
				info = data;
				render();
			} )
			.catch( function ( err ) {
				notice( $( 'wps-remote-warning' ), err.message );
				$( 'wps-site-remote' ).querySelector( '.wps-site-name' ).textContent = 'つながりません';
				$( 'wps-start' ).disabled = true;
			} );
	}

	function addPair() {
		var tr = el( 'tr' );
		var a = el( 'input', { type: 'text', placeholder: '置き換える前' } );
		var b = el( 'input', { type: 'text', placeholder: '置き換えた後' } );
		var remove = el( 'button', { type: 'button', class: 'button-link button-link-delete' }, '削除' );
		remove.addEventListener( 'click', function () {
			tr.remove();
			countPairs();
		} );
		[ a, el( 'span', { class: 'wps-arrow' }, '→' ), b, remove ].forEach( function ( node ) {
			tr.appendChild( el( 'td', node.classList.contains( 'wps-arrow' ) ? { class: 'wps-arrow' } : {} ) ).appendChild( node );
		} );
		$( 'wps-pairs-extra' ).appendChild( tr );
		countPairs();
		a.focus();
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

	/* ---------- 実行 ---------- */

	function log( message, cls ) {
		var list = $( 'wps-log' );
		list.appendChild( el( 'li', cls ? { class: cls } : {}, message ) );
		list.scrollTop = list.scrollHeight;
	}

	function show( status ) {
		$( 'wps-progress-label' ).textContent = status.label + '（' + status.step + ' / ' + status.steps + '）';
		$( 'wps-progress-percent' ).textContent = status.percent + '%';
		$( 'wps-progress-detail' ).textContent = status.detail;
		$( 'wps-bar-fill' ).style.width = status.percent + '%';
		status.log.forEach( function ( line ) {
			log( line );
		} );
	}

	function setRunning( value ) {
		running = value;
		document.querySelectorAll( '.wps-direction input, .wps-options input, .wps-pairs input, .wps-pairs button, #wps-start, [data-restore]' ).forEach( function ( node ) {
			node.disabled = value;
		} );
		if ( ! value ) {
			render();
		}
	}

	function finish( status ) {
		setRunning( false );
		$( 'wps-bar-fill' ).classList.add( 'is-done' );
		$( 'wps-progress-detail' ).textContent = '';
		var message = '同期が終わりました。' + ( status.skipped ? '読めなかったファイルが ' + status.skipped + ' 件あり、飛ばしました。' : '' );
		if ( status.relogin ) {
			var box = notice( $( 'wps-result' ), message + 'このサイトの DB を入れ替えたので、接続先のユーザーでログインし直してください。', 'success' );
			box.querySelector( 'p' ).appendChild( el( 'a', { href: wpsAdmin.login, class: 'button button-primary' }, 'ログインし直す' ) );
			return;
		}
		notice( $( 'wps-result' ), message, 'success' );
		load();
	}

	function fail( err ) {
		setRunning( false );
		$( 'wps-bar-fill' ).classList.add( 'is-error' );
		log( 'エラー: ' + err.message, 'is-error' );
		notice( $( 'wps-result' ), '途中で止まりました：' + err.message + '　DB は入れ替える前なら元のままです。もう一度始めると最初からやり直します。' );
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
		var pull = direction() === 'pull';
		var db = $( 'wps-db' ).checked;
		var media = $( 'wps-media' ).checked;
		var target = pull ? 'このサイト（' + info.local.home + '）' : '接続先（' + info.remote.home + '）';
		var what = [ db ? 'データベース' : null, media ? 'メディア' : null ].filter( Boolean ).join( 'と' );
		var message = target + 'の' + what + 'を上書きします。';
		if ( db && pull ) {
			message += '\n終わったら、接続先のユーザーでログインし直すことになります。';
		}
		if ( ! window.confirm( message + '\nよろしいですか？' ) ) {
			return;
		}

		var progress = $( 'wps-progress' );
		progress.hidden = false;
		$( 'wps-log' ).innerHTML = '';
		$( 'wps-result' ).innerHTML = '';
		$( 'wps-bar-fill' ).className = 'wps-bar-fill';
		$( 'wps-bar-fill' ).style.width = '0';
		$( 'wps-progress-label' ).textContent = '準備しています…';
		$( 'wps-progress-percent' ).textContent = '';
		progress.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		setRunning( true );

		request( 'start', {
			direction: direction(),
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

	/* ---------- 起動 ---------- */

	function initSync() {
		document.querySelectorAll( 'input[name="wps-direction"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', render );
		} );
		[ 'wps-db', 'wps-media', 'wps-media-delete' ].forEach( function ( id ) {
			$( id ).addEventListener( 'change', function () {
				syncOptions();
				summary();
				$( 'wps-start' ).disabled = running || ! info || warnings().length > 0 || ( ! $( 'wps-db' ).checked && ! $( 'wps-media' ).checked );
			} );
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

		$( 'wps-add-pair' ).addEventListener( 'click', addPair );
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

		$( 'wps-start' ).disabled = true;
		syncOptions();
		load();
	}

	function initConnect() {
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
	}

	function initCopy() {
		var copy = $( 'wps-copy-key' );
		var label = copy.innerHTML;
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
						copy.innerHTML = label;
					}, 2000 );
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initCopy();
		if ( $( 'wps-connect-button' ) ) {
			initConnect();
		}
		if ( wpsAdmin.connected && $( 'wps-start' ) ) {
			initSync();
		}
	} );
} )();
