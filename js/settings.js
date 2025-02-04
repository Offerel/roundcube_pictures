/**
 * Roundcube Photos Plugin
 *
 * @version 1.5.6
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
window.rcmail && rcmail.addEventListener("init", function(a) {
	if(document.getElementById('pixelfed_instance')) {
		let instance = document.getElementById('pixelfed_instance');
		instance.addEventListener('change', e => {
			let url = instance.value.endsWith('/') ? instance.value.substr(0, instance.value.length - 1):instance.value;
			
			let result = sendRequest(url, null, '/api/v2/instance', 'GET');
			if(result.status == 200) {
				if(result.response.version !== undefined) {
					let version, type = result.response.title;
					switch(type) {
						case 'Pixelfed':
							version = result.response.version.split('; ')[1];
							version = substr(0, version.length - 1);
							break;
						case 'Mastodon':
							version = result.response.version;
							break;
						default:
							return false;
					}
					
					rcmail.display_message(type + ' instance with ' + version + ' found', 'confirmation');
	
					instance.classList.add('success');
					instance.classList.remove('error');
					document.getElementById('pft').value = type;
					document.getElementById('pfm').value = result.response.configuration.statuses.max_media_attachments;
					document.getElementById('pfc').value = result.response.configuration.statuses.max_characters;
				} else {
					rcmail.display_message('Invalid URL, check failed', 'error');
					instance.classList.add('error');
					instance.classList.remove('success');
				}
			}
			instance.value = url;
		});
		
		let hint_tr = document.createElement('tr');
		let hint_td = document.createElement('td');
		hint_td.colSpan = 2;
		hint_td.classList.add('tdhint');

		let auth_link = document.createElement('a');
		auth_link.id = 'pf_auth_link';
		auth_link.innerText = 'Register App';
		auth_link.href = '#';
		auth_link.addEventListener('click', registerApp);

		hint_td.appendChild(auth_link);
		hint_tr.appendChild(hint_td);

		let pft = document.getElementById('pft').parentElement.parentElement;
		pft.parentNode.insertBefore(hint_tr, pft);
	}
});

function registerApp(e) {
	e.preventDefault();
	e.stopPropagation();
	let instance = document.getElementById('pixelfed_instance');
	let url = instance.value;
	let token, client_id, client_secret;
	let scope = 'read write';
	let redirect_uri = location.protocol + '//' + location.host + location.pathname + '?_task=pictures';
	instance.value = (url.endsWith('/')) ? url.substr(0, url.length - 1):url;

	let cApp = createApp(redirect_uri, scope, instance.value);
	if(cApp.status === 200) {
		client_id = cApp.response.client_id;
		client_secret = cApp.response.client_secret;
	} else {
		rcmail.display_message(rcmail.gettext('app_reg_failed','pictures').replace('%instance%', instance.value), 'error');
		return false;
	}

	let gToken = getToken(client_id, client_secret, redirect_uri, scope, instance.value);
	if(gToken.status === 200) {
		token = gToken.response.access_token;
	} else {
		rcmail.display_message(rcmail.gettext('app_token_failed','pictures'), 'error');
		return false;
	}

	let vApp = verifyApp(token, instance.value);
	if(vApp.status !== 200) {
		rcmail.display_message(rcmail.gettext('app_verify_failed','pictures'), 'error');
		return false;
	}

	getCode();

	function createApp(redirect, scopes, instance) {
		let regData = new FormData();
		regData.append('client_name', 'Roundcube Photos');
		regData.append('redirect_uris', redirect);
		regData.append('scopes', scopes);
		regData.append('website', 'https://codeberg.org/Offerel/Roundcube_Pictures');
		let response = sendRequest(instance, regData, '/api/v1/apps');
		return response;
	}

	function getToken(client, secret, redirect, scope, instance) {
		let authData = new FormData();
		authData.append('client_id', client);
		authData.append('client_secret', secret);
		authData.append('redirect_uri', redirect);
		authData.append('grant_type', 'client_credentials');
		authData.append('scope', scope);
		result = sendRequest(instance, authData, '/oauth/token');
		return result;
	}

	function verifyApp(token, instance) {
		result = sendRequest(instance, null, '/api/v1/apps/verify_credentials', 'GET', token);
		return result;
	}

	function getCode() {
		const params = new URLSearchParams({
			client_id: client_id,
			scope: scope,
			redirect_uri: redirect_uri,
			response_type: 'code'
		});

		const data = JSON.stringify({
			client_id:client_id,
			client_secret:client_secret,
			redirect_uri:redirect_uri,
			instance:instance.value,
			token:token,
			scope:scope
		});

		localStorage.setItem('appval', data);

		let width = 700;
		let height = 580;
		let left = window.screen.availLeft + ((window.screen.availWidth / 2) - (width / 2));
		let top = (window.screen.availHeight / 2) - (height / 2);

		window.open(instance.value + '/oauth/authorize?' + params.toString(), 'popup', 'width='+ width +',height='+ height +',left='+ left +',top='+ top / 2 +',menubar=no,status=no');
	}
}

function ProcessChildMessage(message) {
	document.getElementById('pixelfed_token').value = message;
}

function sendRequest(url, data, endpoint, method = 'POST', token) {
	let result = {};
	let xhr = new XMLHttpRequest();
	xhr.onload = function() {
		result.status = xhr.status;
		if(xhr.status === 200) {
			result.response = JSON.parse(this.response);
		}
	}

	xhr.open(method, url + endpoint, false);
	if(token !== undefined) xhr.setRequestHeader('Authorization', 'Bearer ' + token);
	xhr.send(data);
	return result;
}