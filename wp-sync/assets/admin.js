/* global wpsAdmin */
( function () {
	'use strict';

	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var info = null;
	var running = false;
	// 動いている（止まった）同期の ID。止まったら、これで続きから再開する。
	var jobId = null;
	// 選んだテーマ・プラグイン（向きごと）。null はまだ初期値を決めていない。
	var selected = { pull: null, push: null };
	var ROOTS = { themes: 'テーマ', plugins: 'プラグイン' };

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

	function networkText( network ) {
		return network ? 'マルチサイト（' + network.sites + ' サイト）· ' : '';
	}

	function warnings() {
		var r = info.remote;
		var list = [];
		if ( ! r.compatible ) {
			list.push( '接続先の WP Sync（' + r.version + '）とこのサイト（' + wpsAdmin.version + '）のバージョンが合いません。両方を同じバージョンにしてください。' );
		}
		var l = info.local;
		if ( !! r.network !== !! l.network ) {
			list.push( l.network ? 'このサイトはマルチサイト、接続先はシングルサイトです。シングルサイトとマルチサイトの間では同期できません。' : 'このサイトはシングルサイト、接続先はマルチサイトです。シングルサイトとマルチサイトの間では同期できません。' );
		}
		if ( direction() === 'pull' && ! r.allow_pull ) {
			list.push( '接続先で pull が許可されていません。接続先の WP Sync の「このサイトの設定」で許可してください。' );
		}
		if ( direction() === 'push' && ! r.allow_push ) {
			list.push( '接続先で push が許可されていません。接続先の WP Sync の「このサイトの設定」で「このサイトへ push するのを許可する」をオンにしてください。' );
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

	function chosenCode() {
		var code = { themes: [], plugins: [] };
		var sel = selected[ direction() ];
		Object.keys( ROOTS ).forEach( function ( root ) {
			if ( sel && $( 'wps-' + root ).checked ) {
				code[ root ] = Object.keys( sel[ root ] ).filter( function ( slug ) {
					return sel[ root ][ slug ];
				} );
			}
		} );
		return code;
	}

	function whatList() {
		var code = chosenCode();
		return [
			$( 'wps-db' ).checked ? 'データベース' : null,
			$( 'wps-media' ).checked ? 'メディア' : null,
			code.themes.length ? 'テーマ ' + code.themes.length + ' 個' : null,
			code.plugins.length ? 'プラグイン ' + code.plugins.length + ' 個' : null,
		].filter( Boolean );
	}

	function canStart() {
		return ! running && !! info && warnings().length === 0 && whatList().length > 0;
	}

	function summary() {
		var target = $( 'wps-summary' );
		target.innerHTML = '';
		var list = whatList();
		if ( ! list.length ) {
			target.textContent = '送るものを選んでください。';
			return;
		}
		var pull = direction() === 'pull';
		var what = list.join( '・' );
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
			networkText( info.local.network ) + 'テーブル ' + info.local.size.tables + ' 個（' + info.local.size.bytes + '）' );
		siteBox( 'wps-site-remote', pull ? '送り元' : '送り先・上書きされます', ! pull, r.name || r.home,
			networkText( r.network ) + 'WP ' + r.wp + ' · PHP ' + r.php + ' · テーブル ' + r.size.tables + ' 個（' + r.size.bytes + '）' );
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
		renderCode();
		$( 'wps-start' ).disabled = ! canStart();
		syncOptions();
		summary();

		renderBackup( 'local', info.local.backup, true );
		renderBackup( 'remote', r.backup, r.allow_push );
	}

	/* ---------- テーマ・プラグインの一覧 ---------- */

	function codeLists() {
		var pull = direction() === 'pull';
		return {
			src: pull ? info.remote.code : info.local.code,
			dst: pull ? info.local.code : info.remote.code,
		};
	}

	// 初期値：プラグインは「送り先にもあって版が違うもの」。テーマは何も選ばない（Git で管理していることが多いので）。
	function defaults() {
		var lists = codeLists();
		var sel = { themes: {}, plugins: {} };
		Object.keys( ROOTS ).forEach( function ( root ) {
			var dst = {};
			lists.dst[ root ].forEach( function ( item ) {
				dst[ item.slug ] = item;
			} );
			lists.src[ root ].forEach( function ( item ) {
				sel[ root ][ item.slug ] = root === 'plugins' && !! dst[ item.slug ] && dst[ item.slug ].version !== item.version;
			} );
		} );
		return sel;
	}

	function renderCode() {
		var dir = direction();
		if ( ! selected[ dir ] ) {
			selected[ dir ] = defaults();
		}
		var sel = selected[ dir ];
		var lists = codeLists();

		Object.keys( ROOTS ).forEach( function ( root ) {
			var box = $( 'wps-' + root + '-list' );
			box.hidden = ! $( 'wps-' + root ).checked;
			var dst = {};
			lists.dst[ root ].forEach( function ( item ) {
				dst[ item.slug ] = item;
			} );
			var ul = box.querySelector( '.wps-code-items' );
			ul.innerHTML = '';
			lists.src[ root ].forEach( function ( item ) {
				var other = dst[ item.slug ];
				var li = el( 'li', { class: 'wps-code-item' } );
				var label = el( 'label' );
				var check = el( 'input', { type: 'checkbox', value: item.slug } );
				check.checked = !! sel[ root ][ item.slug ];
				check.disabled = running;
				check.addEventListener( 'change', function () {
					sel[ root ][ item.slug ] = check.checked;
					countCode( root );
					summary();
					$( 'wps-start' ).disabled = ! canStart();
				} );
				label.appendChild( check );
				var text = el( 'span', { class: 'wps-code-name' } );
				text.appendChild( el( 'strong', {}, item.name || item.slug ) );
				text.appendChild( el( 'span', { class: 'wps-code-slug' }, item.slug ) );
				label.appendChild( text );

				var state = el( 'span', { class: 'wps-code-state' } );
				if ( ! other ) {
					state.appendChild( el( 'span', { class: 'wps-badge is-new' }, '送り先にない' ) );
					state.appendChild( el( 'span', { class: 'wps-code-ver' }, item.version || '' ) );
				} else if ( other.version !== item.version ) {
					state.appendChild( el( 'span', { class: 'wps-badge is-diff' }, '版が違う' ) );
					state.appendChild( el( 'span', { class: 'wps-code-ver' }, ( item.version || '—' ) + ' → ' + ( other.version || '—' ) ) );
				} else {
					state.appendChild( el( 'span', { class: 'wps-badge' }, '同じ版' ) );
					state.appendChild( el( 'span', { class: 'wps-code-ver' }, item.version || '' ) );
				}
				if ( item.active ) {
					state.insertBefore( el( 'span', { class: 'wps-badge is-active' }, '有効' ), state.firstChild );
				}
				label.appendChild( state );
				li.appendChild( label );
				ul.appendChild( li );
			} );
			countCode( root );
		} );
	}

	function countCode( root ) {
		var sel = selected[ direction() ][ root ];
		var total = Object.keys( sel ).length;
		var on = Object.keys( sel ).filter( function ( slug ) {
			return sel[ slug ];
		} ).length;
		$( 'wps-' + root + '-list' ).querySelector( '.wps-code-count' ).textContent = '送り元の ' + total + ' 個のうち ' + on + ' 個を送ります';
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
		document.querySelectorAll( '.wps-direction input, .wps-options input, .wps-pairs input, .wps-pairs button, .wps-code input, .wps-code button, #wps-start, [data-restore]' ).forEach( function ( node ) {
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
		if ( ! jobId ) {
			notice( $( 'wps-result' ), '途中で止まりました：' + err.message + '　DB は入れ替える前なら元のままです。もう一度始めると最初からやり直します。' );
			return;
		}
		var box = notice( $( 'wps-result' ), '途中で止まりました：' + err.message + '　DB は入れ替える前なら元のままです。止まったところから再開できます（もう一度始めると最初からやり直します）。' );
		var again = el( 'button', { type: 'button', class: 'button button-primary' }, '続きから再開' );
		again.addEventListener( 'click', resume );
		box.appendChild( el( 'p' ) ).appendChild( again );
	}

	function resume() {
		notice( $( 'wps-result' ), '' );
		$( 'wps-bar-fill' ).classList.remove( 'is-error' );
		setRunning( true );
		log( '続きから再開します。' );
		step( jobId ).catch( fail );
	}

	function step( id ) {
		jobId = id;
		return request( 'step', { id: id } ).then( function ( status ) {
			show( status );
			if ( status.done ) {
				jobId = null;
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
		var code = chosenCode();
		var target = pull ? 'このサイト（' + info.local.home + '）' : '接続先（' + info.remote.home + '）';
		var message = target + 'の' + whatList().join( '・' ) + 'を上書きします。';
		[ [ 'themes', 'テーマ' ], [ 'plugins', 'プラグイン' ] ].forEach( function ( r ) {
			if ( code[ r[ 0 ] ].length ) {
				message += '\n' + r[ 1 ] + ': ' + code[ r[ 0 ] ].join( ', ' );
			}
		} );
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
		jobId = null;

		request( 'start', {
			direction: direction(),
			db: db ? '1' : '0',
			media: media ? '1' : '0',
			media_delete: $( 'wps-media-delete' ).checked ? '1' : '0',
			keep_active_plugins: $( 'wps-keep-active' ).checked ? '1' : '0',
			code: JSON.stringify( code ),
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
		[ 'wps-db', 'wps-media', 'wps-media-delete', 'wps-themes', 'wps-plugins' ].forEach( function ( id ) {
			$( id ).addEventListener( 'change', function () {
				syncOptions();
				Object.keys( ROOTS ).forEach( function ( root ) {
					$( 'wps-' + root + '-list' ).hidden = ! $( 'wps-' + root ).checked;
				} );
				summary();
				$( 'wps-start' ).disabled = ! canStart();
			} );
		} );
		Object.keys( ROOTS ).forEach( function ( root ) {
			$( 'wps-' + root + '-list' ).querySelectorAll( '[data-select]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var sel = selected[ direction() ][ root ];
					var on = button.getAttribute( 'data-select' ) === 'all';
					Object.keys( sel ).forEach( function ( slug ) {
						sel[ slug ] = on;
					} );
					renderCode();
					summary();
					$( 'wps-start' ).disabled = ! canStart();
				} );
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
