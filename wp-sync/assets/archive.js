/* global wpsAdmin */
/**
 * エクスポート・インポートの画面。
 */
( function () {
	'use strict';

	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var busy = false;

	function request( action, data ) {
		var body = new FormData();
		body.append( 'action', 'wps_' + action );
		body.append( '_ajax_nonce', wpsAdmin.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			if ( data[ key ] instanceof Blob ) {
				body.append( key, data[ key ], 'chunk' );
			} else {
				body.append( key, data[ key ] );
			}
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

	function size( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i ? bytes.toFixed( 1 ) : bytes ) + ' ' + units[ i ];
	}

	function setBusy( value ) {
		busy = value;
		document.querySelectorAll( '[data-panel="file"] button, [data-panel="file"] input' ).forEach( function ( node ) {
			if ( value ) {
				node.dataset.wasDisabled = node.disabled ? '1' : '';
				node.disabled = true;
			} else {
				node.disabled = node.dataset.wasDisabled === '1';
			}
		} );
	}

	/* ---------- 進み具合 ---------- */

	/**
	 * 進み具合の欄を作る。同期の画面と同じ見た目（バー・説明・結果・記録）。
	 */
	function progressBox( id ) {
		var box = $( id );
		box.innerHTML = '';
		box.hidden = false;
		var head = box.appendChild( el( 'div', { class: 'wps-progress-head' } ) );
		var parts = {
			label: head.appendChild( el( 'strong', {}, '準備しています…' ) ),
			percent: head.appendChild( el( 'span', { class: 'wps-percent' } ) ),
		};
		parts.fill = box.appendChild( el( 'div', { class: 'wps-bar' } ) ).appendChild( el( 'div', { class: 'wps-bar-fill' } ) );
		parts.detail = box.appendChild( el( 'p', { class: 'wps-progress-detail' } ) );
		parts.result = box.appendChild( el( 'div' ) );
		var details = box.appendChild( el( 'details', { class: 'wps-details', open: '' } ) );
		details.appendChild( el( 'summary', {}, '記録' ) );
		parts.log = details.appendChild( el( 'ol', { class: 'wps-log' } ) );
		parts.line = function ( message, cls ) {
			parts.log.appendChild( el( 'li', cls ? { class: cls } : {}, message ) );
			parts.log.scrollTop = parts.log.scrollHeight;
		};
		parts.show = function ( status ) {
			parts.label.textContent = status.label + '（' + status.step + ' / ' + status.steps + '）';
			parts.percent.textContent = status.percent + '%';
			parts.detail.textContent = status.detail;
			parts.fill.style.width = status.percent + '%';
			status.log.forEach( function ( line ) {
				parts.line( line );
			} );
		};
		parts.message = function ( message, type ) {
			parts.result.innerHTML = '';
			var notice = parts.result.appendChild( el( 'div', { class: 'notice notice-' + ( type || 'error' ) + ' inline' } ) );
			return notice.appendChild( el( 'p', {}, message ) );
		};
		return parts;
	}

	/**
	 * 終わるか、確認で止まるまで進める。
	 */
	function run( id, box ) {
		return request( 'file_step', { id: id } ).then( function ( status ) {
			box.show( status );
			if ( status.done || status.waiting ) {
				return status;
			}
			return run( id, box );
		} );
	}

	/**
	 * 止まったとき。ID があれば「続きから再開」を出す。
	 */
	function failed( box, err, id, resume ) {
		setBusy( false );
		box.fill.classList.add( 'is-error' );
		box.line( 'エラー: ' + err.message, 'is-error' );
		var p = box.message( '途中で止まりました：' + err.message + ( id ? '　止まったところから再開できます。' : '' ) );
		if ( id ) {
			var again = el( 'button', { type: 'button', class: 'button button-primary' }, '続きから再開' );
			again.addEventListener( 'click', function () {
				box.result.innerHTML = '';
				box.fill.classList.remove( 'is-error' );
				box.line( '続きから再開します。' );
				setBusy( true );
				resume();
			} );
			p.appendChild( document.createTextNode( ' ' ) );
			p.appendChild( again );
		}
	}

	/* ---------- 置換の組 ---------- */

	function countPairs( tbody ) {
		var count = $( tbody.id + '-count' ) || $( tbody.id.replace( /-pairs$/, '-pairs-count' ) );
		if ( ! count ) {
			return;
		}
		var auto = tbody.parentNode.querySelectorAll( 'tbody[id$="-auto"] tr' ).length;
		var extra = tbody.querySelectorAll( 'tr' ).length;
		var parts = [];
		if ( auto ) {
			parts.push( '自動 ' + auto + ' 件' );
		}
		if ( extra ) {
			parts.push( '追加 ' + extra + ' 件' );
		}
		count.textContent = parts.join( '・' );
	}

	function addPair( tbody ) {
		var tr = el( 'tr' );
		var a = el( 'input', { type: 'text', placeholder: '置き換える前' } );
		var b = el( 'input', { type: 'text', placeholder: '置き換えた後' } );
		var remove = el( 'button', { type: 'button', class: 'button-link button-link-delete' }, '削除' );
		remove.addEventListener( 'click', function () {
			tr.remove();
			countPairs( tbody );
		} );
		tr.appendChild( el( 'td' ) ).appendChild( a );
		tr.appendChild( el( 'td', { class: 'wps-arrow' }, '→' ) );
		tr.appendChild( el( 'td' ) ).appendChild( b );
		tr.appendChild( el( 'td' ) ).appendChild( remove );
		tbody.appendChild( tr );
		countPairs( tbody );
		a.focus();
	}

	function pairs( tbody ) {
		var out = [];
		tbody.querySelectorAll( 'tr' ).forEach( function ( tr ) {
			var inputs = tr.querySelectorAll( 'input' );
			if ( inputs[ 0 ].value.trim() ) {
				out.push( [ inputs[ 0 ].value, inputs[ 1 ].value ] );
			}
		} );
		return out;
	}

	/* ---------- エクスポート ---------- */

	function startExport() {
		var box = progressBox( 'wps-export-progress' );
		var id = null;
		setBusy( true );
		var go = function ( promise ) {
			promise
				.then( function ( status ) {
					id = status.id;
					box.show( status );
					return status.done ? status : run( id, box );
				} )
				.then( function ( status ) {
					setBusy( false );
					box.fill.classList.add( 'is-done' );
					box.detail.textContent = '';
					var url = wpsAdmin.download + '&id=' + encodeURIComponent( id );
					var p = box.message( '書き出しました（' + status.file + '、' + status.size + '）。ダウンロードが始まらないときは、右のボタンを押してください。', 'success' );
					p.appendChild( document.createTextNode( ' ' ) );
					p.appendChild( el( 'a', { href: url, class: 'button button-primary' }, 'ダウンロード' ) );
					window.location.href = url;
				} )
				.catch( function ( err ) {
					failed( box, err, id, function () {
						go( request( 'file_step', { id: id } ) );
					} );
				} );
		};
		go( request( 'export', { pairs: JSON.stringify( pairs( $( 'wps-export-pairs' ) ) ) } ) );
	}

	/* ---------- インポート ---------- */

	var importId = null;
	var importBox = null;

	function upload( file, box ) {
		var chunk = wpsAdmin.chunk;
		var send = function ( id, offset ) {
			if ( offset >= file.size ) {
				return Promise.resolve( id );
			}
			box.label.textContent = 'ファイルをアップロードしています';
			box.percent.textContent = Math.floor( ( offset / file.size ) * 100 ) + '%';
			box.fill.style.width = ( offset / file.size ) * 100 + '%';
			box.detail.textContent = size( offset ) + ' / ' + size( file.size );
			return request( 'upload', { id: id || '', offset: offset, chunk: file.slice( offset, offset + chunk ) } ).then( function ( res ) {
				return send( res.id, res.size );
			} );
		};
		return send( '', 0 );
	}

	function startUpload() {
		var file = $( 'wps-import-file' ).files[ 0 ];
		if ( ! file ) {
			return;
		}
		$( 'wps-import-confirm' ).hidden = true;
		importBox = progressBox( 'wps-import-progress' );
		importId = null;
		setBusy( true );
		var box = importBox;
		upload( file, box )
			.then( function ( id ) {
				box.line( 'アップロードしました（' + size( file.size ) + '）。' );
				return request( 'import', { id: id, name: file.name } );
			} )
			.then( function ( status ) {
				importId = status.id;
				box.show( status );
				return status.waiting ? status : run( importId, box );
			} )
			.then( showConfirm )
			.catch( function ( err ) {
				failed( box, err, importId, function () {
					run( importId, box ).then( showConfirm ).catch( function ( e ) {
						failed( box, e, importId );
					} );
				} );
			} );
	}

	function showConfirm( status ) {
		setBusy( false );
		var p = status.preview;
		var box = importBox;
		box.label.textContent = 'ファイルを読み込みました';
		box.percent.textContent = '';
		box.detail.textContent = '';
		box.fill.style.width = '100%';

		var facts = $( 'wps-import-facts' );
		facts.innerHTML = '';
		var add = function ( term, value ) {
			if ( value ) {
				facts.appendChild( el( 'dt', {}, term ) );
				facts.appendChild( el( 'dd', {}, value ) );
			}
		};
		add( 'ファイル', p.file );
		add( '書き出したサイト', ( p.name ? p.name + '（' + p.home + '）' : p.home ) );
		add( '書き出した日時', p.time );
		add( '書き出した道具', p.guessed ? 'WP Sync ではない道具（mysqldump など）' : 'WP Sync ' + p.version );
		add( 'テーブルの接頭辞', p.prefix + ( p.tables ? '（' + p.tables + ' 個のテーブル）' : '' ) );
		add( 'マルチサイト', p.sites ? p.sites + ' サイト' : '' );
		add( '取り込む先', 'このサイト（' + p.local + '）' );

		var auto = $( 'wps-import-pairs-auto' );
		auto.innerHTML = '';
		p.pairs.forEach( function ( pair ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', {}, pair[ 0 ] ) );
			tr.appendChild( el( 'td', { class: 'wps-arrow' }, '→' ) );
			tr.appendChild( el( 'td', {}, pair[ 1 ] ) );
			tr.appendChild( el( 'td', {} ) ).appendChild( el( 'span', { class: 'wps-tag' }, '自動' ) );
			auto.appendChild( tr );
		} );
		countPairs( $( 'wps-import-pairs' ) );
		$( 'wps-import-confirm' ).hidden = false;
		$( 'wps-import-confirm' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function startImport() {
		if ( ! window.confirm( 'このサイトの DB をファイルの中身で上書きします。よろしいですか？\n終わったら、ファイルの DB のユーザーでログインし直すことになります。' ) ) {
			return;
		}
		var box = importBox;
		$( 'wps-import-confirm' ).hidden = true;
		box.result.innerHTML = '';
		box.fill.style.width = '0';
		setBusy( true );
		var go = function ( promise ) {
			promise
				.then( function ( status ) {
					box.show( status );
					return status.done ? status : run( importId, box );
				} )
				.then( function () {
					setBusy( false );
					box.fill.classList.add( 'is-done' );
					box.detail.textContent = '';
					var p = box.message( 'インポートが終わりました。このサイトの DB を入れ替えたので、ファイルの DB のユーザーでログインし直してください。', 'success' );
					p.appendChild( document.createTextNode( ' ' ) );
					p.appendChild( el( 'a', { href: wpsAdmin.login, class: 'button button-primary' }, 'ログインし直す' ) );
				} )
				.catch( function ( err ) {
					failed( box, err, importId, function () {
						go( request( 'file_step', { id: importId } ) );
					} );
				} );
		};
		go(
			request( 'confirm', {
				id: importId,
				pairs: JSON.stringify( pairs( $( 'wps-import-pairs' ) ) ),
				keep_active_plugins: $( 'wps-import-keep-active' ).checked ? '1' : '0',
			} )
		);
	}

	function cancelImport() {
		$( 'wps-import-confirm' ).hidden = true;
		$( 'wps-import-progress' ).hidden = true;
		$( 'wps-import-file' ).value = '';
		$( 'wps-import-upload' ).disabled = true;
		importId = null;
	}

	function restore() {
		if ( ! window.confirm( 'このサイトの DB を、直前に取り込む前の状態に戻します。よろしいですか？\n戻したら、ログインし直すことがあります。' ) ) {
			return;
		}
		var button = $( 'wps-file-restore' );
		setBusy( true );
		request( 'restore', { target: 'local' } )
			.then( function ( result ) {
				setBusy( false );
				button.disabled = true;
				var info = button.parentNode.querySelector( '.wps-backup-info' );
				info.textContent = result.tables + ' 個のテーブルを戻しました。ログインし直してください。';
				info.appendChild( document.createTextNode( ' ' ) );
				info.appendChild( el( 'a', { href: wpsAdmin.login }, 'ログインし直す' ) );
			} )
			.catch( function ( err ) {
				setBusy( false );
				window.alert( err.message );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! $( 'wps-export-start' ) ) {
			return;
		}
		document.querySelectorAll( '[data-add-pair]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				addPair( $( button.getAttribute( 'data-add-pair' ) ) );
			} );
		} );
		$( 'wps-export-start' ).addEventListener( 'click', startExport );
		$( 'wps-import-file' ).addEventListener( 'change', function () {
			$( 'wps-import-upload' ).disabled = ! this.files.length;
		} );
		$( 'wps-import-upload' ).addEventListener( 'click', startUpload );
		$( 'wps-import-start' ).addEventListener( 'click', startImport );
		$( 'wps-import-cancel' ).addEventListener( 'click', cancelImport );
		$( 'wps-file-restore' ).addEventListener( 'click', restore );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( busy ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	} );
} )();
